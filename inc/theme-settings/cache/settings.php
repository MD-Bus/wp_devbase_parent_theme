<?php

// Registers the Cache sub-page (under Theme Settings, a "Cache" section in the settings app), in four tabs:
//   - General: the master switch, what clears caches, the Twig template cache, the paths never cached and a
//     "Clear all caches now" button.
//   - Host cache: what was detected on this server and whether the theme clears it.
//   - Cloudflare: zone ID and API token (a secret, or both in wp-config.php), what a post change clears, page
//     caching and the buttons that test the token and create or remove the theme's cache rule.
//   - Status: the last clear, its result per cache, the last error and any cache plugin that could clash.
//
// Everything is off until "Enable cache integration" is on: with it off, no header is sent, no cache is cleared
// and no request goes to Cloudflare (purge.php checks devbase_cache_enabled() first, every time).
//
// The message fields (buttons, detection, status) are filled in by acf/prepare_field, only when the page is drawn,
// so building the field group on every request costs nothing.

add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_options_sub_page' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_options_sub_page( array(
		'page_title'  => __( 'Cache', 'devbase_parent_theme' ),
		'menu_title'  => __( 'Cache', 'devbase_parent_theme' ),
		'menu_slug'   => 'cache-settings',
		'capability'  => 'manage_options',
		'parent_slug' => 'theme-settings',
		'devbase_app' => array(
			'group'       => 'settings',
			'icon'        => 'dashicons-performance',
			'description' => __( 'Work with the host\'s cache and Cloudflare: clear them when the site changes, and keep private pages out of them.', 'devbase_parent_theme' ),
			'after'       => 'tracking',
		),
	) );

	$on = array(
		array(
			array(
				'field'    => 'field_devbase_cache_enabled',
				'operator' => '==',
				'value'    => '1',
			),
		),
	);

	$cf_on = array(
		array(
			array(
				'field'    => 'field_devbase_cache_enabled',
				'operator' => '==',
				'value'    => '1',
			),
			array(
				'field'    => 'field_devbase_cache_cf_enabled',
				'operator' => '==',
				'value'    => '1',
			),
		),
	);

	$cf_pages_on = array(
		array(
			array(
				'field'    => 'field_devbase_cache_enabled',
				'operator' => '==',
				'value'    => '1',
			),
			array(
				'field'    => 'field_devbase_cache_cf_enabled',
				'operator' => '==',
				'value'    => '1',
			),
			array(
				'field'    => 'field_devbase_cache_cf_page_cache',
				'operator' => '==',
				'value'    => '1',
			),
		),
	);

	acf_add_local_field_group( array(
		'key'      => 'group_devbase_cache',
		'title'    => __( 'Cache', 'devbase_parent_theme' ),
		'fields'   => array(
			// Tab 1: General.
			array(
				'key'   => 'field_devbase_cache_general_tab',
				'label' => __( 'General', 'devbase_parent_theme' ),
				'name'  => '',
				'type'  => 'tab',
			),
			array(
				'key'           => 'field_devbase_cache_enabled',
				'label'         => __( 'Enable cache integration', 'devbase_parent_theme' ),
				'name'          => 'cache_enabled',
				'type'          => 'true_false',
				'ui'            => 1,
				'default_value' => 0,
				'instructions'  => __( 'Off: the theme does nothing with caching at all. On a site where a cache plugin already does all of this, leave it off.', 'devbase_parent_theme' ),
			),
			array(
				'key'               => 'field_devbase_cache_triggers',
				'label'             => __( 'Clear every cache when', 'devbase_parent_theme' ),
				'name'              => 'cache_triggers',
				'type'              => 'checkbox',
				'choices'           => devbase_cache_trigger_choices(),
				'default_value'     => array_keys( devbase_cache_trigger_choices() ),
				'layout'            => 'vertical',
				'instructions'      => __( 'These change every page at once, and hosts do not clear their cache for them. Editing a post is not here: hosts clear that page themselves, and Cloudflare has its own setting on the Cloudflare tab.', 'devbase_parent_theme' ),
				'conditional_logic' => $on,
			),
			array(
				'key'               => 'field_devbase_cache_twig',
				'label'             => __( 'Template cache', 'devbase_parent_theme' ),
				'name'              => 'cache_twig',
				'type'              => 'true_false',
				'ui'                => 1,
				'default_value'     => 1,
				'instructions'      => __( 'Keeps the compiled Twig templates on disk (wp-content/cache/devbase-twig) instead of compiling them on every page. An edited template is picked up by itself.', 'devbase_parent_theme' ),
				'conditional_logic' => $on,
			),
			array(
				'key'               => 'field_devbase_cache_exclusions',
				'label'             => __( 'Never cache these paths', 'devbase_parent_theme' ),
				'name'              => 'cache_exclusions',
				'type'              => 'repeater',
				'layout'            => 'table',
				'button_label'      => __( 'Add Path', 'devbase_parent_theme' ),
				'instructions'      => __( 'Pages sent with "do not cache" headers, which every host cache and Cloudflare obey. The path as it shows in the address bar, e.g. /members/ or /quote. Logged-in visitors, previews and password-protected pages are never cached anyway.', 'devbase_parent_theme' ),
				'conditional_logic' => $on,
				'sub_fields'        => array(
					array(
						'key'         => 'field_devbase_cache_exclusion_path',
						'label'       => __( 'Path', 'devbase_parent_theme' ),
						'name'        => 'path',
						'type'        => 'text',
						'placeholder' => '/members/',
					),
					array(
						'key'           => 'field_devbase_cache_exclusion_match',
						'label'         => __( 'Match', 'devbase_parent_theme' ),
						'name'          => 'match',
						'type'          => 'select',
						'choices'       => array(
							'prefix' => __( 'Starts with', 'devbase_parent_theme' ),
							'exact'  => __( 'Exactly', 'devbase_parent_theme' ),
						),
						'default_value' => 'prefix',
					),
				),
			),
			array(
				'key'               => 'field_devbase_cache_clear_button',
				'label'             => __( 'Clear now', 'devbase_parent_theme' ),
				'name'              => '',
				'type'              => 'message',
				'message'           => '',
				'conditional_logic' => $on,
			),

			// Tab 2: Host cache.
			array(
				'key'   => 'field_devbase_cache_host_tab',
				'label' => __( 'Host cache', 'devbase_parent_theme' ),
				'name'  => '',
				'type'  => 'tab',
			),
			array(
				'key'     => 'field_devbase_cache_host_detected',
				'label'   => __( 'Detected', 'devbase_parent_theme' ),
				'name'    => '',
				'type'    => 'message',
				'message' => '',
			),
			array(
				'key'               => 'field_devbase_cache_host_purge',
				'label'             => __( 'Clear the host\'s cache', 'devbase_parent_theme' ),
				'name'              => 'cache_host_purge',
				'type'              => 'true_false',
				'ui'                => 1,
				'default_value'     => 1,
				'instructions'      => __( 'On the changes picked on the General tab, through the host\'s own function. A host that is not detected is left alone.', 'devbase_parent_theme' ),
				'conditional_logic' => $on,
			),

			// Tab 3: Cloudflare.
			array(
				'key'   => 'field_devbase_cache_cf_tab',
				'label' => __( 'Cloudflare', 'devbase_parent_theme' ),
				'name'  => '',
				'type'  => 'tab',
			),
			array(
				'key'               => 'field_devbase_cache_cf_enabled',
				'label'             => __( 'Enable Cloudflare', 'devbase_parent_theme' ),
				'name'              => 'cache_cf_enabled',
				'type'              => 'true_false',
				'ui'                => 1,
				'default_value'     => 0,
				'instructions'      => __( 'For a site whose domain goes through Cloudflare. If another plugin already talks to Cloudflare (the Cloudflare plugin, WP Rocket, LiteSpeed Cache), use only one of them.', 'devbase_parent_theme' ),
				'conditional_logic' => $on,
			),
			array(
				'key'               => 'field_devbase_cache_cf_help',
				'label'             => __( 'API token', 'devbase_parent_theme' ),
				'name'              => '',
				'type'              => 'message',
				'message'           => '',
				'conditional_logic' => $cf_on,
			),
			array(
				'key'               => 'field_devbase_cache_cf_zone_id',
				'label'             => __( 'Zone ID', 'devbase_parent_theme' ),
				'name'              => 'cache_cf_zone_id',
				'type'              => 'text',
				'required'          => 1,
				'instructions'      => __( 'On the domain\'s Overview page in Cloudflare, under API. 32 characters.', 'devbase_parent_theme' ),
				'wrapper'           => array( 'width' => '50' ),
				'conditional_logic' => $cf_on,
			),
			array(
				'key'               => 'field_devbase_cache_cf_api_token',
				'label'             => __( 'API token', 'devbase_parent_theme' ),
				'name'              => 'cache_cf_api_token',
				'type'              => 'password',
				'required'          => 1,
				'wrapper'           => array( 'width' => '50' ),
				'conditional_logic' => $cf_on,
			),
			array(
				'key'               => 'field_devbase_cache_cf_test',
				'label'             => __( 'Test', 'devbase_parent_theme' ),
				'name'              => '',
				'type'              => 'message',
				'message'           => '',
				'conditional_logic' => $cf_on,
			),
			array(
				'key'               => 'field_devbase_cache_cf_post_purge',
				'label'             => __( 'When a post or page changes', 'devbase_parent_theme' ),
				'name'              => 'cache_cf_post_purge',
				'type'              => 'select',
				'choices'           => array(
					'urls'       => __( 'Clear only the pages that show it (recommended)', 'devbase_parent_theme' ),
					'everything' => __( 'Clear all of this site\'s pages', 'devbase_parent_theme' ),
					'none'       => __( 'Clear nothing', 'devbase_parent_theme' ),
				),
				'default_value'     => 'urls',
				'instructions'      => __( 'The pages that show it: the post itself, the home page, the blog page, its archive, category, tag and author pages, and the feed. Comments count as a change. Cloudflare only ever clears this site\'s pages: never images, CSS or JavaScript, and never another site on the same domain.', 'devbase_parent_theme' ),
				'conditional_logic' => $cf_on,
			),
			array(
				'key'               => 'field_devbase_cache_cf_page_cache',
				'label'             => __( 'Let Cloudflare cache pages', 'devbase_parent_theme' ),
				'name'              => 'cache_cf_page_cache',
				'type'              => 'true_false',
				'ui'                => 1,
				'default_value'     => 0,
				'instructions'      => __( 'Cloudflare caches images, CSS and JavaScript on its own, but not pages. On, the theme marks each public page as cacheable for the time below, and the cache rule (button below) lets Cloudflare keep it. Logged-in visitors, previews, searches, carts and the paths on the General tab are never cached.', 'devbase_parent_theme' ),
				'conditional_logic' => $cf_on,
			),
			array(
				'key'               => 'field_devbase_cache_cf_lifetime',
				'label'             => __( 'Keep pages for', 'devbase_parent_theme' ),
				'name'              => 'cache_cf_lifetime',
				'type'              => 'select',
				'choices'           => devbase_cache_lifetime_choices(),
				'default_value'     => (string) DEVBASE_CACHE_DEFAULT_LIFETIME,
				'instructions'      => __( 'A page is cleared as soon as it changes, so this is only the longest a page can be out of date if a clear ever fails.', 'devbase_parent_theme' ),
				'conditional_logic' => $cf_pages_on,
			),
			array(
				'key'               => 'field_devbase_cache_cf_rule',
				'label'             => __( 'Cache rule', 'devbase_parent_theme' ),
				'name'              => '',
				'type'              => 'message',
				'message'           => '',
				'conditional_logic' => $cf_pages_on,
			),

			// Tab 4: Status.
			array(
				'key'   => 'field_devbase_cache_status_tab',
				'label' => __( 'Status', 'devbase_parent_theme' ),
				'name'  => '',
				'type'  => 'tab',
			),
			array(
				'key'     => 'field_devbase_cache_status',
				'label'   => __( 'Status', 'devbase_parent_theme' ),
				'name'    => '',
				'type'    => 'message',
				'message' => '',
			),
		),
		'location' => array(
			array(
				array(
					'param'    => 'options_page',
					'operator' => '==',
					'value'    => 'cache-settings',
				),
			),
		),
	) );
} );

/**
 * A button for cache.js, with its result line.
 *
 * @param string $action The AJAX action (devbase_cache_<action>).
 * @param string $label  The button text.
 * @param string $op     An extra value sent as `op`.
 */
function devbase_cache_button( $action, $label, $op = '' ) {
	return '<button type="button" class="button devbase-cache-action" data-action="' . esc_attr( $action ) . '" data-op="' . esc_attr( $op ) . '">'
		. esc_html( $label )
		. '</button> <span class="devbase-cache-result" aria-live="polite" style="white-space:pre-line"></span>';
}

// The message fields, drawn only when the page is.
add_filter( 'acf/prepare_field', function ( $field ) {
	if ( ! is_array( $field ) || empty( $field['key'] ) ) {
		return $field;
	}

	switch ( $field['key'] ) {
		case 'field_devbase_cache_clear_button':
			$field['message'] = devbase_cache_button( 'devbase_cache_clear', __( 'Clear all caches now', 'devbase_parent_theme' ) )
				. '<p class="description">' . esc_html__( 'The host\'s cache, Cloudflare (when on), the template cache and the object cache. Uses the saved settings: save first.', 'devbase_parent_theme' ) . '</p>';
			break;

		case 'field_devbase_cache_host_detected':
			$field['message'] = devbase_cache_host_html();
			break;

		case 'field_devbase_cache_cf_help':
			$field['message'] = devbase_cache_cf_help_html();
			break;

		case 'field_devbase_cache_cf_test':
			$field['message'] = devbase_cache_button( 'devbase_cache_cf_test', __( 'Test connection', 'devbase_parent_theme' ) )
				. '<p class="description">' . esc_html__( 'Checks the token, the zone and that the token may clear the cache. Uses the saved settings: save first.', 'devbase_parent_theme' ) . '</p>';
			break;

		case 'field_devbase_cache_cf_rule':
			$field['message'] = devbase_cache_cf_rule_html();
			break;

		case 'field_devbase_cache_status':
			$field['message'] = devbase_cache_status_html();
			break;

		case 'field_devbase_cache_cf_zone_id':
		case 'field_devbase_cache_cf_api_token':
			$constant = 'field_devbase_cache_cf_zone_id' === $field['key'] ? 'DEVBASE_CLOUDFLARE_ZONE_ID' : 'DEVBASE_CLOUDFLARE_API_TOKEN';

			if ( defined( $constant ) ) {
				$field['disabled']     = 1;
				$field['required']     = 0;
				$field['value']        = '';
				$field['placeholder']  = __( 'Set in wp-config.php', 'devbase_parent_theme' );
				/* translators: %s: a PHP constant name */
				$field['instructions'] = sprintf( __( 'Set in wp-config.php (%s). Change it there.', 'devbase_parent_theme' ), $constant );
			}
			break;
	}

	return $field;
}, 100 );

// A value set in wp-config.php is not asked for on the page.
add_filter( 'acf/validate_value', function ( $valid, $value, $field ) {
	if ( true === $valid || ! is_array( $field ) || empty( $field['key'] ) ) {
		return $valid;
	}

	if ( 'field_devbase_cache_cf_zone_id' === $field['key'] ) {
		if ( defined( 'DEVBASE_CLOUDFLARE_ZONE_ID' ) ) {
			return true;
		}

		if ( '' !== trim( (string) $value ) && ! devbase_cache_cf_valid_zone_id( (string) $value ) ) {
			return __( 'A zone ID is 32 letters and digits (a to f, 0 to 9).', 'devbase_parent_theme' );
		}
	}

	if ( 'field_devbase_cache_cf_api_token' === $field['key'] && defined( 'DEVBASE_CLOUDFLARE_API_TOKEN' ) ) {
		return true;
	}

	return $valid;
}, 30, 3 );

/** The Host cache tab: what this server has. */
function devbase_cache_host_html() {
	$found = devbase_cache_detected_hosts();
	$out   = '';

	if ( $found ) {
		$out .= '<p><strong>' . esc_html( implode( ', ', wp_list_pluck( $found, 'label' ) ) ) . '</strong></p>';
	} else {
		$out .= '<p>' . esc_html__( 'No host cache the theme knows how to clear. Nothing to set up: if the host has a cache it does not tell WordPress about, clear it from the host\'s panel.', 'devbase_parent_theme' ) . '</p>';
	}

	$out .= '<p class="description">' . esc_html__( 'Known: LiteSpeed (Hostinger and many others), SiteGround, WP Engine, Kinsta, Cloudways (Breeze), Pantheon, Nginx Helper, and the WP Rocket, W3 Total Cache, WP Super Cache and LiteSpeed Cache plugins.', 'devbase_parent_theme' ) . '</p>';
	$out .= devbase_cache_button( 'devbase_cache_detect', __( 'Check the home page\'s cache headers', 'devbase_parent_theme' ) );

	return $out;
}

/** The Cloudflare tab's token instructions. */
function devbase_cache_cf_help_html() {
	return '<p>' . esc_html__( 'In Cloudflare: My Profile > API Tokens > Create Token > Custom token, limited to this site\'s zone, with these permissions:', 'devbase_parent_theme' ) . '</p>'
		. '<ul style="list-style:disc;margin-left:1.5em">'
		. '<li><code>Zone · Zone · Read</code> ' . esc_html__( '(to check the zone)', 'devbase_parent_theme' ) . '</li>'
		. '<li><code>Zone · Cache Purge · Purge</code> ' . esc_html__( '(to clear the cache)', 'devbase_parent_theme' ) . '</li>'
		. '<li><code>Zone · Cache Rules · Edit</code> ' . esc_html__( '(only to let Cloudflare cache pages)', 'devbase_parent_theme' ) . '</li>'
		. '</ul>'
		. '<p class="description">' . esc_html__( 'Or put both in wp-config.php: DEVBASE_CLOUDFLARE_ZONE_ID and DEVBASE_CLOUDFLARE_API_TOKEN.', 'devbase_parent_theme' ) . '</p>';
}

/** The Cloudflare tab's cache rule line and buttons. */
function devbase_cache_cf_rule_html() {
	$status = devbase_cache_get_status();
	$rule   = $status['rule'] ?? array();
	$out    = '<p>' . esc_html__( 'Cloudflare keeps a page only when this rule exists and the theme marks the page as cacheable. The rule caches nothing on its own: pages without the theme\'s header pass through, so switching page caching off here is enough to stop it.', 'devbase_parent_theme' ) . '</p>';

	if ( ! empty( $rule['id'] ) ) {
		/* translators: %s: a date and time */
		$out .= '<p><strong style="color:#008a20">' . esc_html( sprintf( __( 'Rule in place (since %s).', 'devbase_parent_theme' ), devbase_cache_time( (int) ( $rule['at'] ?? 0 ) ) ) ) . '</strong></p>';
	} else {
		$out .= '<p><strong>' . esc_html__( 'No rule created from this site yet.', 'devbase_parent_theme' ) . '</strong></p>';
	}

	return $out
		. devbase_cache_button( 'devbase_cache_cf_rule', __( 'Create or update the cache rule', 'devbase_parent_theme' ), 'install' )
		. '<br><br>'
		. devbase_cache_button( 'devbase_cache_cf_rule', __( 'Remove the cache rule', 'devbase_parent_theme' ), 'remove' );
}

/** The Status tab. */
function devbase_cache_status_html() {
	$status = devbase_cache_get_status();
	$rows   = array();

	$rows[ __( 'Cache integration', 'devbase_parent_theme' ) ] = devbase_cache_enabled() ? __( 'On', 'devbase_parent_theme' ) : __( 'Off', 'devbase_parent_theme' );

	$hosts = devbase_cache_detected_hosts();
	$rows[ __( 'Host cache', 'devbase_parent_theme' ) ] = $hosts ? implode( ', ', wp_list_pluck( $hosts, 'label' ) ) : __( 'None detected', 'devbase_parent_theme' );

	if ( devbase_cache_cf_active() ) {
		$cloudflare = devbase_cache_cf_page_cache() ? __( 'On, pages cached', 'devbase_parent_theme' ) : __( 'On, files only', 'devbase_parent_theme' );
	} elseif ( devbase_cache_behind_cloudflare() ) {
		$cloudflare = __( 'Off (this site is behind Cloudflare)', 'devbase_parent_theme' );
	} else {
		$cloudflare = __( 'Off', 'devbase_parent_theme' );
	}
	$rows[ __( 'Cloudflare', 'devbase_parent_theme' ) ] = $cloudflare;

	$rows[ __( 'Template cache', 'devbase_parent_theme' ) ] = devbase_cache_twig_dir() ? __( 'On', 'devbase_parent_theme' ) : __( 'Off', 'devbase_parent_theme' );
	$rows[ __( 'Object cache', 'devbase_parent_theme' ) ] = devbase_cache_object_cache_label();

	if ( ! empty( $status['last']['at'] ) ) {
		$last  = $status['last'];
		$parts = array();

		foreach ( (array) ( $last['results'] ?? array() ) as $result ) {
			$parts[] = ( ! empty( $result['ok'] ) ? '✓ ' : '✗ ' ) . (string) ( $result['label'] ?? '' ) . ( '' !== (string) ( $result['message'] ?? '' ) ? ' (' . $result['message'] . ')' : '' );
		}

		/* translators: 1: a date and time, 2: what caused it */
		$rows[ __( 'Last cleared', 'devbase_parent_theme' ) ] = sprintf( __( '%1$s, after: %2$s', 'devbase_parent_theme' ), devbase_cache_time( (int) $last['at'] ), implode( ', ', (array) ( $last['reasons'] ?? array() ) ) )
			. ( $parts ? "\n" . implode( "\n", $parts ) : '' );
	} else {
		$rows[ __( 'Last cleared', 'devbase_parent_theme' ) ] = __( 'Never', 'devbase_parent_theme' );
	}

	if ( ! empty( $status['error']['at'] ) ) {
		/* translators: 1: a date and time, 2: the error */
		$rows[ __( 'Last error', 'devbase_parent_theme' ) ] = sprintf( __( '%1$s: %2$s', 'devbase_parent_theme' ), devbase_cache_time( (int) $status['error']['at'] ), (string) $status['error']['message'] );
	}

	$out = '<table class="widefat striped" style="max-width:720px"><tbody>';

	foreach ( $rows as $label => $value ) {
		$out .= '<tr><th style="width:180px">' . esc_html( $label ) . '</th><td style="white-space:pre-line">' . esc_html( $value ) . '</td></tr>';
	}

	$out .= '</tbody></table>';

	foreach ( devbase_cache_conflicts() as $warning ) {
		$out .= '<div class="notice notice-warning inline" style="margin-top:12px"><p>' . esc_html( $warning ) . '</p></div>';
	}

	return $out;
}

/** A date and time in the site's format and timezone. */
function devbase_cache_time( $timestamp ) {
	return $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : '—';
}

// The buttons' script, on this page only.
add_action( 'admin_enqueue_scripts', function () {
	if ( ! function_exists( 'devbase_settings_page_is' ) || ! devbase_settings_page_is( 'cache-settings' ) ) {
		return;
	}

	$file = get_template_directory() . '/inc/theme-settings/cache/cache.js';

	wp_enqueue_script(
		'devbase-cache',
		get_template_directory_uri() . '/inc/theme-settings/cache/cache.js',
		array(),
		file_exists( $file ) ? (string) filemtime( $file ) : '1',
		true
	);

	wp_localize_script( 'devbase-cache', 'devbaseCache', array(
		'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
		'nonce'       => wp_create_nonce( 'devbase_cache' ),
		'runningText' => __( 'Working…', 'devbase_parent_theme' ),
		'errorText'   => __( 'Something went wrong. Please try again.', 'devbase_parent_theme' ),
	) );
} );
