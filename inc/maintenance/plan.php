<?php

// What the site's maintenance plan includes, as Brand Relay last said.
//
// Brand Relay is the authority (it refuses a call for a service the plan lacks,
// with `403 feature_off`); the site's job is to not make useless calls. Every
// pull of `GET /v1/site` carries the `licence` object (plan, status, services
// and limits in force, start and expiry), and devbase_relay_fetch_site() keeps it
// here. Every service the site can use (site health now, error events and backups
// later) asks the same question: devbase_relay_service_in_force().
//
// The expiry date is checked HERE, against the clock, so a plan that ends does
// not keep being used until the next pull.

/** The saved answer: { fetched_at: int, ttl: int (Brand Relay's own hint, kept for reference), licence: array|null }. */
const DEVBASE_RELAY_PLAN_OPTION = 'devbase_relay_plan';

/**
 * Keeps the `licence` part of a `/v1/site` answer. A site with no subscription
 * has none, which is kept as null (it is an answer too).
 *
 * @param array $data The decoded answer.
 */
function devbase_relay_store_plan( array $data ) {
	$licence = isset( $data['licence'] ) && is_array( $data['licence'] ) ? $data['licence'] : null;
	$problem = '';

	// A date that is there but cannot be read switches the plan's services off (see
	// devbase_relay_service_in_force()). Said once, here, where the plan arrives, and kept with it.
	foreach ( array( 'starts_at', 'expires_at' ) as $field ) {
		if ( $licence && ! empty( $licence[ $field ] ) && false === devbase_relay_time( $licence[ $field ] ) ) {
			$problem = "the plan's {$field} cannot be read";
			error_log( "devbase_parent_theme: {$problem}, so its services are treated as not in force" );
		}
	}

	update_option( DEVBASE_RELAY_PLAN_OPTION, array(
		'fetched_at' => time(),
		'ttl'        => isset( $data['ttl'] ) ? (int) $data['ttl'] : 0,
		'licence'    => $licence,
		'problem'    => $problem,
	), false );
}

/**
 * A time from Brand Relay as a timestamp in seconds, or false when it cannot be read. Brand Relay sends
 * epoch MILLISECONDS in places (1793823580262) and ISO dates in others; plain seconds are understood too.
 *
 * @param mixed $value
 * @return int|false
 */
function devbase_relay_time( $value ) {
	if ( is_numeric( $value ) ) {
		$number = (float) $value;

		return $number > 100000000000 ? (int) floor( $number / 1000 ) : (int) $number;
	}

	return is_string( $value ) && '' !== trim( $value ) ? strtotime( $value ) : false;
}

/** The saved plan answer, or an empty array when the site has never pulled one. */
function devbase_relay_plan() {
	$plan = get_option( DEVBASE_RELAY_PLAN_OPTION );

	return is_array( $plan ) ? $plan : array();
}

/**
 * Whether a service is in force right now: there is a subscription, it is active,
 * it has started and not expired, and it lists the service.
 *
 * @param string   $service uptime, health, events or backup.
 * @param int|null $now     A timestamp (tests); the clock by default.
 */
function devbase_relay_service_in_force( $service, $now = null ) {
	$now     = null === $now ? time() : (int) $now;
	$plan    = devbase_relay_plan();
	$licence = isset( $plan['licence'] ) && is_array( $plan['licence'] ) ? $plan['licence'] : null;

	if ( ! $licence || 'active' !== ( $licence['status'] ?? '' ) ) {
		return false;
	}

	if ( ! in_array( $service, (array) ( $licence['services'] ?? array() ), true ) ) {
		return false;
	}

	// A date that is present but unreadable is NOT "no date": reading it as one would keep an expired
	// plan in force for good. Brand Relay refuses the calls anyway; the site's job is not to make them.
	if ( ! empty( $licence['starts_at'] ) ) {
		$starts = devbase_relay_time( $licence['starts_at'] );

		if ( false === $starts || $starts > $now ) {
			return false;
		}
	}

	if ( ! empty( $licence['expires_at'] ) ) {
		$ends = devbase_relay_time( $licence['expires_at'] );

		if ( false === $ends || $ends < $now ) {
			return false;
		}
	}

	return true;
}

/**
 * The plan's settings for a service in force (such as `array( 'interval' => 15 )`
 * for health), or null when it is not in force.
 *
 * @param string $service uptime, health, events or backup.
 * @return array|null
 */
function devbase_relay_service_limits( $service ) {
	if ( ! devbase_relay_service_in_force( $service ) ) {
		return null;
	}

	$limits = devbase_relay_plan()['licence']['limits'][ $service ] ?? array();

	return is_array( $limits ) ? $limits : array();
}
