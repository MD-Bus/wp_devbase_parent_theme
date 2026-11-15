<?php

// The Theme Settings app: one wp-admin screen with a sidebar of sections, replacing the stack of separate
// Theme Settings sub-pages, the Registered Forms and Form Data lists and the Archive pages. See
// inc/settings-app/AGENT.md for the design and the migration status.
//
// Two kinds of section:
//   - a FIELD section names SCF field groups and renders them with acf_form(). No new storage and NO new save
//     code: validation, nonces, conditional logic and the rules in inc/theme-settings/access.php apply exactly as
//     they do on the classic pages.
//   - a SCREEN section has a `render` callable and draws its own read-only screen (forms.php).
//
// Every section has its own capability, so one screen serves administrators (everything) and Editors (the Archive
// pages only). The classic pages stay registered, hidden from the menu, and still answer at their own addresses.

/** The sidebar's group headings, in order: id => label. Filter `devbase/app/groups`. */
function devbase_app_groups() {
	$groups = array(
		'settings' => __( 'Settings', 'devbase_parent_theme' ),
		'seo'      => __( 'SEO', 'devbase_parent_theme' ),
		'forms'    => __( 'Forms', 'devbase_parent_theme' ),
		'content'  => __( 'Content', 'devbase_parent_theme' ),
	);

	$filtered = apply_filters( 'devbase/app/groups', $groups );

	return is_array( $filtered ) ? $filtered : $groups;
}

/**
 * Every section that exists, whoever is asking, in sidebar order, each filled in with its defaults.
 *
 * A section is: title (string), icon (dashicon class), description (string), group (a devbase_app_groups() id),
 * capability (default devbase_settings_capability()), and EITHER groups (SCF field group keys) + post_id (the SCF
 * store, default 'options') OR render (a callable that draws the screen). classic (the menu_slug of the classic page
 * it replaces) is what devbase_settings_page_is() matches on. A child theme adds, removes or reorders sections
 * through `devbase/app/sections`.
 *
 * @return array<string,array>
 */
function devbase_app_all_sections() {
	$sections = array(
		'smtp'          => array(
			'title'       => __( 'Email (SMTP)', 'devbase_parent_theme' ),
			'icon'        => 'dashicons-email-alt',
			'description' => __( 'Send the site\'s email through a real mail server instead of PHP mail().', 'devbase_parent_theme' ),
			'groups'      => array( 'group_devbase_notifications_smtp' ),
			'classic'     => 'smtp-settings',
		),
		'security'      => array(
			'title'       => __( 'Security', 'devbase_parent_theme' ),
			'icon'        => 'dashicons-shield',
			'description' => __( 'Spam protection for forms: Cloudflare Turnstile or Google reCAPTCHA.', 'devbase_parent_theme' ),
			'groups'      => array( 'group_devbase_security' ),
			'classic'     => 'security-settings',
		),
		'tracking'      => array(
			'title'       => __( 'Tracking', 'devbase_parent_theme' ),
			'icon'        => 'dashicons-chart-area',
			'description' => __( 'Analytics IDs and custom scripts for the site\'s head and footer.', 'devbase_parent_theme' ),
			'groups'      => array( 'group_devbase_tracking' ),
			'classic'     => 'tracking-settings',
		),
		'organization'  => array(
			'title'       => __( 'Organization Details', 'devbase_parent_theme' ),
			'icon'        => 'dashicons-building',
			'description' => __( 'Who the business is, for search engines (schema.org).', 'devbase_parent_theme' ),
			'group'       => 'seo',
			'groups'      => array( 'group_devbase_schema' ),
			'classic'     => 'schema-settings',
		),
		'destinations'  => array(
			'title'       => __( 'Form Destinations', 'devbase_parent_theme' ),
			'icon'        => 'dashicons-randomize',
			'description' => __( 'Where each form\'s submissions are sent: email or webhook.', 'devbase_parent_theme' ),
			'groups'      => array( 'group_devbase_form_destinations' ),
			'classic'     => 'destinations-settings',
		),
		'miscellaneous' => array(
			'title'       => __( 'Miscellaneous', 'devbase_parent_theme' ),
			'icon'        => 'dashicons-admin-tools',
			'description' => __( 'Data retention, system status and MCP access.', 'devbase_parent_theme' ),
			'groups'      => array( 'group_devbase_miscellaneous' ),
			'classic'     => 'miscellaneous-settings',
		),
		'licence'       => array(
			'title'       => __( 'Licence & Updates', 'devbase_parent_theme' ),
			'icon'        => 'dashicons-update',
			'description' => __( 'Licence key, update status and backups.', 'devbase_parent_theme' ),
			'groups'      => array( 'group_devbase_licence' ),
			'classic'     => 'licence-settings',
		),
	);

	$sections = array_merge( $sections, devbase_app_discovered_sections( $sections ) );

	$filtered = apply_filters( 'devbase/app/sections', $sections );
	$filtered = is_array( $filtered ) ? $filtered : $sections;
	$filtered = devbase_app_apply_after( $filtered );

	$defaults = array(
		'title'       => '',
		'icon'        => 'dashicons-admin-generic',
		'description' => '',
		'group'       => 'settings',
		'capability'  => '',
		'post_id'     => 'options',
	);

	$groups = array_keys( devbase_app_groups() );
	$kept   = array();

	foreach ( $filtered as $id => $section ) {
		if ( ! is_array( $section ) ) {
			continue;
		}

		$section               = array_merge( $defaults, $section );
		$section['capability'] = '' !== (string) $section['capability'] ? (string) $section['capability'] : devbase_settings_capability();

		// A field section whose groups are not registered shows an empty form that saves nothing. Organization
		// Details is the real case: its page and group exist only while The SEO Framework is switched on
		// (Miscellaneous > Management). Left in the sidebar it would look broken, so it is left out, exactly as the
		// classic menu left the page out.
		if ( empty( $section['render'] ) && function_exists( 'acf_get_field_group' ) && did_action( 'acf/init' ) ) {
			$found = false;

			foreach ( (array) ( $section['groups'] ?? array() ) as $key ) {
				if ( acf_get_field_group( $key ) ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				continue;
			}
		}

		if ( ! empty( $section['render'] ) && ! is_callable( $section['render'] ) ) {
			continue;
		}

		$kept[ (string) $id ] = $section;
	}

	// Sidebar order: by group, then as given. A group the registry does not know goes last.
	$order = array_flip( $groups );
	$index = 0;

	foreach ( $kept as $id => $section ) {
		$kept[ $id ]['_index'] = $index++;
	}

	uasort( $kept, function ( $a, $b ) use ( $order ) {
		$ga = $order[ $a['group'] ] ?? PHP_INT_MAX;
		$gb = $order[ $b['group'] ] ?? PHP_INT_MAX;

		return $ga === $gb ? $a['_index'] <=> $b['_index'] : $ga <=> $gb;
	} );

	return $kept;
}

/**
 * Moves each section that names another in `after` to just behind it (it then also takes that section's group, unless
 * it names its own). An `after` that names no section is ignored and the section stays where it was.
 *
 * @param array $sections id => section.
 * @return array
 */
function devbase_app_apply_after( array $sections ) {
	foreach ( array_keys( $sections ) as $id ) {
		$after = is_array( $sections[ $id ] ) ? (string) ( $sections[ $id ]['after'] ?? '' ) : '';

		if ( '' === $after || $after === (string) $id || ! isset( $sections[ $after ] ) ) {
			continue;
		}

		$moving = $sections[ $id ];
		unset( $sections[ $id ] );

		if ( empty( $moving['group'] ) && is_array( $sections[ $after ] ) && ! empty( $sections[ $after ]['group'] ) ) {
			$moving['group'] = $sections[ $after ]['group'];
		}

		$out = array();

		foreach ( $sections as $key => $section ) {
			$out[ $key ] = $section;

			if ( (string) $key === $after ) {
				$out[ $id ] = $moving;
			}
		}

		$sections = $out;
	}

	return $sections;
}

/**
 * A section for every options page under Theme Settings that no section above replaces, so a page added later (by
 * the parent or a child theme, with acf_add_options_sub_page( parent_slug 'theme-settings' )) shows up in the app on
 * its own. Without this it would vanish: the classic Theme Settings menu, which it would have been listed in, is
 * hidden. Its title, capability and store come from the page; its field groups from the groups located on it.
 *
 * SCF turns the parent page into its first sub-page in the menu and may rewrite the children's parent_slug to that
 * sub-page's slug, so both are accepted as "under Theme Settings".
 *
 * @param array $known The sections already defined (their `classic` slugs are skipped).
 * @return array<string,array>
 */
function devbase_app_discovered_sections( array $known ) {
	if ( ! function_exists( 'acf_get_options_pages' ) || ! function_exists( 'acf_get_field_groups' ) ) {
		return array();
	}

	$pages = acf_get_options_pages();

	if ( ! is_array( $pages ) || ! $pages ) {
		return array();
	}

	$parents = array( 'theme-settings' );

	if ( isset( $pages['smtp-settings']['parent_slug'] ) ) {
		$parents[] = (string) $pages['smtp-settings']['parent_slug'];
	}

	$skip  = array_merge( array( 'theme-settings' ), array_filter( array_map( function ( $section ) {
		return is_array( $section ) ? (string) ( $section['classic'] ?? '' ) : '';
	}, $known ) ) );
	$found = array();

	foreach ( $pages as $slug => $page ) {
		$slug = (string) ( $page['menu_slug'] ?? $slug );

		if ( in_array( $slug, $skip, true ) || ! in_array( (string) ( $page['parent_slug'] ?? '' ), $parents, true ) || isset( $known[ $slug ] ) ) {
			continue;
		}

		// How the page sits in the app, from the page itself: 'devbase_app' => array( group, icon, description, title,
		// after ). SCF keeps unknown keys on a page, so a child theme sets it in its acf_add_options_sub_page() call.
		$app = isset( $page['devbase_app'] ) && is_array( $page['devbase_app'] ) ? $page['devbase_app'] : array();
		$app = array_intersect_key( $app, array_flip( array( 'group', 'icon', 'description', 'title', 'after' ) ) );

		$found[ $slug ] = $app + array(
			'title'      => (string) ( $page['menu_title'] ?? '' ) !== '' ? (string) $page['menu_title'] : (string) ( $page['page_title'] ?? $slug ),
			'groups'     => array_values( array_filter( array_map( function ( $group ) {
				return (string) ( $group['key'] ?? '' );
			}, (array) acf_get_field_groups( array( 'options_page' => $slug ) ) ) ) ),
			'post_id'    => (string) ( $page['post_id'] ?? '' ) !== '' ? (string) $page['post_id'] : 'options',
			'capability' => (string) ( $page['capability'] ?? '' ),
			'classic'    => $slug,
		);
	}

	return $found;
}

/** The sections the current user may open: devbase_app_all_sections() minus the ones whose capability they lack. */
function devbase_app_sections() {
	return array_filter( devbase_app_all_sections(), function ( $section ) {
		return current_user_can( $section['capability'] );
	} );
}

/** The section being viewed: ?section= when the user may open it, otherwise the first they may. '' when there are none. */
function devbase_app_current_section() {
	$sections = devbase_app_sections();
	$wanted   = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( isset( $sections[ $wanted ] ) ) {
		return $wanted;
	}

	return (string) array_key_first( $sections );
}

/** True while the app screen is open on the given section. For enqueue conditions in a section's own script. */
function devbase_app_is_section( $id ) {
	return isset( $_GET['page'] ) && 'devbase-app' === $_GET['page'] && devbase_app_current_section() === $id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * True while the given CLASSIC options page, or the app section that replaces it, is open. Every page-gated script or
 * save hook in the theme asks this instead of reading $_GET['page'] itself, so it works on both screens.
 *
 * @param string $slug A classic page's menu_slug, e.g. 'smtp-settings'.
 */
function devbase_settings_page_is( $slug ) {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( $slug === $page ) {
		return true;
	}

	if ( 'devbase-app' !== $page ) {
		return false;
	}

	$sections = devbase_app_sections();
	$current  = devbase_app_current_section();

	return isset( $sections[ $current ]['classic'] ) && $slug === $sections[ $current ]['classic'];
}

/**
 * Where to send someone to edit what a classic settings page used to hold: the app section that replaces it, or the
 * classic page's own address when no section does (a section is left out when its group is not registered).
 *
 * @param string $slug A classic page's menu_slug, e.g. 'destinations-settings'.
 */
function devbase_settings_page_url( $slug ) {
	foreach ( devbase_app_all_sections() as $id => $section ) {
		if ( isset( $section['classic'] ) && $slug === $section['classic'] ) {
			return devbase_app_url( (string) $id );
		}
	}

	return admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
}

/** URL of the app on a section. */
function devbase_app_url( $section = '', array $args = array() ) {
	$args = array_merge( array( 'page' => 'devbase-app' ), $args );

	if ( '' !== $section ) {
		$args['section'] = $section;
	}

	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

add_action( 'admin_menu', function () {
	$sections = devbase_app_sections();

	if ( ! $sections ) {
		return;
	}

	// The page itself needs the capability of a section this user may open; each section then checks its own.
	$capability = (string) reset( $sections )['capability'];

	$icon_path = get_template_directory() . '/assets/icons/theme-settings-icon.svg';
	$icon_url  = file_exists( $icon_path ) ? 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $icon_path ) ) : 'dashicons-admin-settings';

	$hook = add_menu_page(
		__( 'Theme Settings', 'devbase_parent_theme' ),
		__( 'Theme Settings', 'devbase_parent_theme' ),
		$capability,
		'devbase-app',
		'devbase_app_render',
		$icon_url,
		81
	);

	if ( ! $hook ) {
		return;
	}

	// acf_form_head() does the save and the redirect, and it checks the nonce but NOT who is asking, and it saves to
	// whatever store the form POSTS (_acf_post_id). So, before it runs: the section must be one a field section the
	// user may open, and the posted store must be that section's own. Without the second check an Editor could post
	// an Archive form with _acf_post_id=options and write to the admin-only store.
	add_action( 'load-' . $hook, function () {
		$sections = devbase_app_sections();
		$current  = devbase_app_current_section();
		$section  = $sections[ $current ] ?? null;

		if ( ! $section || ! empty( $section['render'] ) || ! function_exists( 'acf_form_head' ) ) {
			return;
		}

		if ( isset( $_POST['_acf_post_id'] ) && (string) wp_unslash( $_POST['_acf_post_id'] ) !== (string) $section['post_id'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
			wp_die( esc_html__( 'That form does not belong to this section.', 'devbase_parent_theme' ), 403 );
		}

		acf_form_head();
	} );
} );

/**
 * Removes the top-level menu whose sub-menu holds the given page. SCF turns a parent options page into its first
 * sub-page, so the parent's own slug is not the one in the menu; it is found by what it holds.
 *
 * @param string[] $page_slugs Slugs of pages that sit under the menu to remove.
 */
function devbase_app_remove_menu_holding( array $page_slugs ) {
	global $submenu;

	foreach ( (array) $submenu as $parent => $items ) {
		foreach ( (array) $items as $item ) {
			if ( isset( $item[2] ) && in_array( $item[2], $page_slugs, true ) ) {
				remove_menu_page( (string) $parent );

				return;
			}
		}
	}
}

/**
 * Hides the classic Theme Settings menu. Every SCF page under it is a section (devbase_app_discovered_sections()), but
 * a page added there with plain add_submenu_page() is not one, and hiding the whole menu would leave it with no link
 * anywhere. So when the menu holds such a page, only the pages the app replaces are taken out of it and the menu stays
 * with the rest.
 *
 * @return string[] The slugs of the pages left in the classic menu (none in the normal case).
 */
function devbase_app_hide_classic_theme_settings_menu() {
	global $submenu;

	$covered = array_filter( array_map( function ( $section ) {
		return (string) ( $section['classic'] ?? '' );
	}, devbase_app_all_sections() ) );

	foreach ( (array) $submenu as $parent => $items ) {
		$slugs = array_map( function ( $item ) {
			return (string) ( $item[2] ?? '' );
		}, (array) $items );

		if ( ! in_array( 'smtp-settings', $slugs, true ) ) {
			continue;
		}

		$left = array_values( array_diff( $slugs, $covered, array( 'theme-settings' ) ) );

		if ( ! $left ) {
			remove_menu_page( (string) $parent );

			return array();
		}

		foreach ( $slugs as $slug ) {
			if ( in_array( $slug, $covered, true ) ) {
				remove_submenu_page( (string) $parent, $slug );
			}
		}

		return $left;
	}

	return array();
}

// The classic menus are hidden from the sidebar but stay REGISTERED: the pages still answer at their own addresses,
// and the MCP options-page abilities and the "Applies To"/MCP Access lists read them.
add_action( 'admin_menu', function () {
	devbase_app_hide_classic_theme_settings_menu();

	foreach ( array( 'registered_form', 'form_data' ) as $type ) {
		remove_menu_page( 'edit.php?post_type=' . $type );
	}

	$archive = array_keys( devbase_app_archive_pages() );

	if ( $archive ) {
		devbase_app_remove_menu_holding( $archive );
	}

	remove_menu_page( 'archive-settings' );
}, 999 );

add_action( 'admin_enqueue_scripts', function () {
	if ( ! isset( $_GET['page'] ) || 'devbase-app' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$file = get_template_directory() . '/inc/settings-app/app.css';

	wp_enqueue_style(
		'devbase-app',
		get_template_directory_uri() . '/inc/settings-app/app.css',
		array(),
		file_exists( $file ) ? (string) filemtime( $file ) : '1'
	);

	// In the footer, after SCF's own scripts (acf_form_head() enqueues them): app.js waits for SCF's ready event.
	$js       = get_template_directory() . '/inc/settings-app/app.js';
	$sections = devbase_app_sections();
	$current  = $sections[ devbase_app_current_section() ] ?? null;

	// SCF's scripts exist only on a field section (a read-only screen has no SCF form to wait for).
	wp_enqueue_script(
		'devbase-app',
		get_template_directory_uri() . '/inc/settings-app/app.js',
		$current && empty( $current['render'] ) ? array( 'acf-input' ) : array(),
		file_exists( $js ) ? (string) filemtime( $js ) : '1',
		true
	);
} );

/** Draws the app: sidebar, then the current section. */
function devbase_app_render() {
	$sections = devbase_app_sections();

	if ( ! $sections ) {
		wp_die( esc_html__( 'You do not have permission to open this page.', 'devbase_parent_theme' ), 403 );
	}

	$current = devbase_app_current_section();
	$section = $sections[ $current ];
	$groups  = devbase_app_groups();
	$last    = null;

	echo '<div class="wrap devbase-app">';
	echo '<h1 class="screen-reader-text">' . esc_html__( 'Theme Settings', 'devbase_parent_theme' ) . '</h1>';
	echo '<div class="devbase-app__shell">';

	echo '<nav class="devbase-app__nav" aria-label="' . esc_attr__( 'Settings sections', 'devbase_parent_theme' ) . '">';
	echo '<div class="devbase-app__brand">' . esc_html__( 'Theme Settings', 'devbase_parent_theme' ) . '</div>';

	foreach ( $sections as $id => $item ) {
		if ( $item['group'] !== $last ) {
			if ( null !== $last ) {
				echo '</ul>';
			}

			echo '<p class="devbase-app__group">' . esc_html( (string) ( $groups[ $item['group'] ] ?? $item['group'] ) ) . '</p><ul>';
			$last = $item['group'];
		}

		printf(
			'<li><a href="%1$s" class="%2$s"%3$s><span class="devbase-app__icon dashicons %4$s" aria-hidden="true"></span><span>%5$s</span></a></li>',
			esc_url( devbase_app_url( (string) $id ) ),
			$id === $current ? 'is-active' : '',
			$id === $current ? ' aria-current="page"' : '',
			esc_attr( (string) $item['icon'] ),
			esc_html( (string) $item['title'] )
		);
	}
	echo '</ul></nav>';

	echo '<main class="devbase-app__main">';

	echo '<header class="devbase-app__header"><span class="devbase-app__headicon dashicons ' . esc_attr( (string) $section['icon'] ) . '" aria-hidden="true"></span><div><h2>' . esc_html( (string) $section['title'] ) . '</h2>';
	if ( '' !== (string) $section['description'] ) {
		echo '<p>' . esc_html( (string) $section['description'] ) . '</p>';
	}
	echo '</div></header>';

	if ( ! empty( $section['render'] ) ) {
		echo '<div class="devbase-app__body">';
		call_user_func( $section['render'], $section, $current );
		echo '</div>';
	} elseif ( function_exists( 'acf_form' ) ) {
		if ( isset( $_GET['saved'] ) || isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="devbase-app__saved" role="status">' . esc_html__( 'Settings saved.', 'devbase_parent_theme' ) . '</div>';
		}

		echo '<div class="devbase-app__body">';
		acf_form( array(
			'id'                 => 'devbase-app-form',
			'post_id'            => $section['post_id'],
			'field_groups'       => (array) ( $section['groups'] ?? array() ),
			'form'               => true,
			'return'             => devbase_app_url( $current, array( 'saved' => 1 ) ),
			'submit_value'       => __( 'Save changes', 'devbase_parent_theme' ),
			'updated_message'    => false,
			'html_submit_button' => '<button type="submit" class="button button-primary button-large">%s</button>',
			// acf_form() runs wp_kses_post over everything posted unless told not to; the classic options pages never
			// did. Left on, it removed <script> from the Tracking codes and stored "&" as "&amp;". Off, a save stores
			// exactly what the classic page stored (who may save what is the save guard's job, not kses').
			'kses'               => false,
			// acf_form()'s honeypot is a spam trap for public front-end forms. Here the nonce and the save guard protect
			// the form, and the hidden field was saved with everything else: each save wrote an empty
			// `{store}__validate_email` value the classic pages never wrote (found by a real save, 2026-11-15).
			'honeypot'           => false,
		) );
		echo '</div>';
	}

	echo '</main></div></div>';
}

require_once __DIR__ . '/forms.php';
require_once __DIR__ . '/archive.php';
