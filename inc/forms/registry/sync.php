<?php

// Syncs inc/forms/registry/registered-forms.json into registered_form posts, so a
// form appears in wp-admin (ready to configure Destinations for) as soon
// as it's registered in code — even before its first submission.
//
// Hooked on 'init' at priority 20, after post-types.php's registration
// (default priority 10), so the CPT is guaranteed to exist first.
//
// Additive for anything still present in the JSON, matching this theme's
// "fill gaps, never overwrite" rule: a registered_form post's title or
// status are never touched once created, even if the JSON label for that
// name changes later — the admin's own edits are authoritative from that
// point on. NOT additive-only overall, though: a registered_form post
// whose name has been REMOVED from the JSON is pruned automatically (see
// devbase_forms_prune_deregistered() below) — that post exists solely to
// mirror the JSON, so once its JSON entry is gone, the post has nothing
// left to mirror. This is also why registered_form's delete capabilities
// are 'do_not_allow' (post-types.php): the only supported way to remove
// one of these posts is deregistering it in the JSON, never a manual
// admin-UI delete.
add_action( 'init', 'devbase_forms_sync_registry', 20 );

/**
 * The resolved registry: this theme's own registered-forms.json, plus
 * whatever a child theme contributes via the 'devbase/forms/registered_forms'
 * filter. Returns a list of array( 'name' => ..., 'label' => ... ).
 *
 * A child theme is where a specific client site's own forms belong — this
 * parent theme ships identically to every site, so a client's contact form
 * must never be added to the JSON file here. A child contributes with a
 * plain top-level add_filter() in its own functions.php; that file loads
 * BEFORE this one (WordPress includes the child's functions.php first), so
 * the filter is always registered by the time this runs on 'init'.
 */
function devbase_forms_get_registry() {
	$forms = array();

	$json_path = __DIR__ . '/registered-forms.json';

	if ( file_exists( $json_path ) ) {
		$data = json_decode( (string) file_get_contents( $json_path ), true );

		if ( ! empty( $data['forms'] ) && is_array( $data['forms'] ) ) {
			$forms = $data['forms'];
		}
	}

	$forms = apply_filters( 'devbase/forms/registered_forms', $forms );

	return is_array( $forms ) ? $forms : array();
}

function devbase_forms_sync_registry() {
	$forms = devbase_forms_get_registry();

	// Skip the DB work entirely unless the RESOLVED registry actually
	// changed since the last sync — this runs on every 'init', so it needs
	// to be cheap on the common case (nothing changed) rather than running
	// a WP_Query on every single front-end request.
	//
	// Deliberately hashes the resolved list rather than md5_file()'ing the
	// JSON alone (which is what this did before child themes existed): a
	// child adding, renaming or removing a form changes nothing about this
	// theme's own JSON file, so a file hash would never notice and the
	// child's forms would silently never sync.
	$current_hash = md5( (string) wp_json_encode( $forms ) );
	$synced_hash  = get_option( 'devbase_registered_forms_synced_hash' );

	if ( $synced_hash === $current_hash ) {
		return;
	}

	$names_in_json = array();

	if ( ! empty( $forms ) ) {
		foreach ( $forms as $form ) {
			if ( empty( $form['name'] ) ) {
				continue;
			}

			$name  = sanitize_key( $form['name'] );
			$label = ! empty( $form['label'] ) ? $form['label'] : $name;

			$names_in_json[] = $name;

			$existing = get_posts( array(
				'post_type'      => 'registered_form',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_key'       => 'form_name', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $name,       // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'         => 'ids',
			) );

			if ( ! empty( $existing ) ) {
				continue;
			}

			wp_insert_post( array(
				'post_type'   => 'registered_form',
				'post_status' => 'publish',
				'post_title'  => $label,
				'meta_input'  => array(
					'form_name' => $name,
				),
			) );
		}
	}

	devbase_forms_prune_deregistered( $names_in_json );

	update_option( 'devbase_registered_forms_synced_hash', $current_hash, false );
}

// Deletes any registered_form post whose form_name is no longer present in
// the JSON registry, and strips that form_name from every Form Destinations
// / Retention Policy row that referenced it by name — those two options
// pages match purely on the form_name string (see inc/forms/AGENT.md's
// "Dispatch matching" section), so a deleted registered_form's name would
// otherwise sit there as a dangling reference forever, silently pointing at
// a form that no longer exists.
//
// Uses wp_delete_post() directly rather than going through any admin-UI
// path — this is the one supported way to remove a registered_form post,
// since its delete capabilities are 'do_not_allow' (post-types.php).
function devbase_forms_prune_deregistered( array $names_in_json ) {
	$existing_posts = get_posts( array(
		'post_type'      => 'registered_form',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );

	foreach ( $existing_posts as $post_id ) {
		$name = get_post_meta( $post_id, 'form_name', true );

		if ( '' === $name || in_array( $name, $names_in_json, true ) ) {
			continue;
		}

		devbase_forms_strip_apply_to( 'destinations', 'destinations-settings', $name );
		devbase_forms_strip_apply_to( 'retention_policies', 'miscellaneous-settings', $name );

		wp_delete_post( $post_id, true );
	}
}

// Removes $form_name from every row's `apply_to` in the given options-page
// repeater field, if function_exists( 'get_field' ) — ACF may not be
// loaded in every context this could run in (e.g. a unit test bootstrapping
// only this file). $options_page_slug is only used for context in case this
// ever needs per-page storage; ACF options pages under this theme all share
// the generic 'option' store (see inc/theme-settings/destinations/AGENT.md).
function devbase_forms_strip_apply_to( $field_name, $options_page_slug, $form_name ) {
	unset( $options_page_slug ); // reserved for future per-page storage, unused while all options pages share 'option'

	if ( ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) ) {
		return;
	}

	$rows = get_field( $field_name, 'option' );

	if ( empty( $rows ) || ! is_array( $rows ) ) {
		return;
	}

	$changed = false;

	foreach ( $rows as &$row ) {
		if ( empty( $row['apply_to'] ) || ! is_array( $row['apply_to'] ) ) {
			continue;
		}

		$filtered = array_values( array_diff( $row['apply_to'], array( $form_name ) ) );

		if ( count( $filtered ) !== count( $row['apply_to'] ) ) {
			$row['apply_to'] = $filtered;
			$changed          = true;
		}
	}
	unset( $row );

	if ( $changed ) {
		update_field( $field_name, $rows, 'option' );
	}
}
