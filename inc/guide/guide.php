<?php

// The Guide: the manual for the people who run a site, inside the Theme Settings app (Help > Guide). One guide, in tabs:
//
//   This site        the child theme's chapters (what this site has: its pages, its post types, its shop). Shown only
//                    when the child adds a chapter.
//   Theme features   this theme's chapters (forms, email, spam protection, images, SEO, cache, updates...), the same on
//                    every site, written once here and updated with the theme.
//
// Why in the admin and not a PDF or a page: it is where editors already are, it ships and updates with the themes (so it
// never describes an older site), nobody can delete or edit it by accident, and none of it is public. Print > Save as
// PDF gives a PDF of the open tab (guide.css has the print rules).
//
//   inc/guide/guide.php              this file: the section, the chapter registry, the screenshot helper, the Dashboard box
//   inc/guide/guide.css              its look, screen and print
//   views/devbase-guide/guide.twig   the page: tabs, table of contents, chapters
//   views/devbase-guide/theme/*.twig this theme's chapters
//   assets/guide/*.jpg               this theme's screenshots
//
// The views live under devbase-guide/, not guide/: Timber looks in the child's views first, so a child's own
// guide/guide.twig would otherwise replace this page.
//
// A CHILD THEME adds its chapters through `devbase/guide/chapters`:
//
//   add_filter( 'devbase/guide/chapters', function ( $chapters ) {
//       $chapters['news'] = array(
//           'title'    => 'News articles',
//           'icon'     => 'dashicons-megaphone',
//           'tab'      => 'site',                       // the default for a child's chapter is 'site'
//           'template' => 'guide/chapters/news.twig',    // in the child's views/
//       );
//       return $chapters;
//   } );
//
// and its own screen links and values through `devbase/guide/context` (merged into `go` and the rest). Its screenshots go
// in its own assets/guide/: `shot( 'name' )` looks there first, then here, so a child can also replace one of these.
// A chapter may set `capability` (default edit_posts), `when` (a callable; false leaves the chapter out) and `after`
// (another chapter's id). `devbase/guide/tabs` adds, renames or reorders tabs.

const DEVBASE_GUIDE_SECTION = 'guide';

add_filter( 'devbase/app/groups', function ( $groups ) {
	return (array) $groups + array( 'help' => __( 'Help', 'devbase_parent_theme' ) );
} );

add_filter( 'devbase/app/sections', function ( $sections ) {
	if ( ! is_array( $sections ) ) {
		return $sections;
	}

	$sections[ DEVBASE_GUIDE_SECTION ] = array(
		'title'       => __( 'Guide', 'devbase_parent_theme' ),
		'icon'        => 'dashicons-book',
		'description' => __( 'How to run this site, step by step: its own pages and content, and the features every site built on this theme shares.', 'devbase_parent_theme' ),
		'group'       => 'help',
		'capability'  => devbase_guide_capability(),
		'render'      => 'devbase_guide_render',
	);

	return $sections;
}, 5 );

/** Who may read the guide at all (each chapter can ask for more). Filter `devbase/guide/capability`. */
function devbase_guide_capability() {
	return (string) apply_filters( 'devbase/guide/capability', 'edit_posts' );
}

/** The tabs, in order: id => title. Filter `devbase/guide/tabs`. A tab with no chapter for the reader is not shown. */
function devbase_guide_tabs() {
	$tabs = array(
		'site'  => __( 'This site', 'devbase_parent_theme' ),
		'theme' => __( 'Theme features', 'devbase_parent_theme' ),
	);

	$filtered = apply_filters( 'devbase/guide/tabs', $tabs );

	return is_array( $filtered ) && $filtered ? $filtered : $tabs;
}

/** This theme's own chapters (the Theme features tab). */
function devbase_guide_theme_chapters() {
	$admin = 'manage_options';

	return array(
		'settings'  => array(
			'title' => __( 'Theme Settings at a glance', 'devbase_parent_theme' ),
			'icon'  => 'dashicons-admin-settings',
		),
		'images'    => array(
			'title'      => __( 'Images and uploads', 'devbase_parent_theme' ),
			'icon'       => 'dashicons-format-image',
			'capability' => 'upload_files',
		),
		'seo'       => array(
			'title' => __( 'Search engines (SEO)', 'devbase_parent_theme' ),
			'icon'  => 'dashicons-search',
			'when'  => function () {
				return function_exists( 'devbase_tsf_enabled' ) && devbase_tsf_enabled();
			},
		),
		'forms'     => array(
			'title'      => __( 'Forms and submissions', 'devbase_parent_theme' ),
			'icon'       => 'dashicons-forms',
			'capability' => $admin,
			'when'       => function () {
				return post_type_exists( 'form_data' );
			},
		),
		'email'     => array(
			'title'      => __( 'Email delivery', 'devbase_parent_theme' ),
			'icon'       => 'dashicons-email-alt',
			'capability' => $admin,
		),
		'spam'      => array(
			'title'      => __( 'Spam protection', 'devbase_parent_theme' ),
			'icon'       => 'dashicons-shield',
			'capability' => $admin,
		),
		'tracking'  => array(
			'title'      => __( 'Analytics and tracking codes', 'devbase_parent_theme' ),
			'icon'       => 'dashicons-chart-area',
			'capability' => $admin,
		),
		'cache'     => array(
			'title'      => __( 'Cache and speed', 'devbase_parent_theme' ),
			'icon'       => 'dashicons-performance',
			'capability' => $admin,
		),
		'retention' => array(
			'title'      => __( 'Old submissions and system status', 'devbase_parent_theme' ),
			'icon'       => 'dashicons-admin-tools',
			'capability' => $admin,
		),
		'ai'        => array(
			'title'      => __( 'AI assistants (MCP)', 'devbase_parent_theme' ),
			'icon'       => 'dashicons-superhero-alt',
			'capability' => $admin,
		),
		'updates'   => array(
			'title'      => __( 'Updates and backups', 'devbase_parent_theme' ),
			'icon'       => 'dashicons-update',
			'capability' => $admin,
		),
		'trouble'   => array(
			'title' => __( 'When something goes wrong', 'devbase_parent_theme' ),
			'icon'  => 'dashicons-sos',
		),
	);
}

/**
 * Every chapter, whoever is reading, in order, each filled in with its defaults. A chapter is: title, icon (dashicon
 * class), tab, template (a Twig path) or render (a callable that prints it), capability, when, after.
 *
 * @return array<string,array>
 */
function devbase_guide_all_chapters() {
	$theme = array();

	foreach ( devbase_guide_theme_chapters() as $id => $chapter ) {
		$chapter['tab']      = 'theme';
		$chapter['template'] = 'devbase-guide/theme/' . $id . '.twig';
		$theme[ $id ]        = $chapter;
	}

	$filtered = apply_filters( 'devbase/guide/chapters', $theme );
	$filtered = is_array( $filtered ) ? $filtered : $theme;

	if ( function_exists( 'devbase_app_apply_after' ) ) {
		$filtered = devbase_app_apply_after( $filtered );
	}

	$tabs = devbase_guide_tabs();
	$out  = array();

	foreach ( $filtered as $id => $chapter ) {
		if ( ! is_array( $chapter ) ) {
			continue;
		}

		$id      = sanitize_key( (string) $id );
		$chapter = array_merge(
			array(
				'title'      => '',
				'icon'       => 'dashicons-media-text',
				// A chapter that is not the theme's own is the child's: "This site" unless it says otherwise.
				'tab'        => isset( $theme[ $id ] ) ? 'theme' : 'site',
				'template'   => '',
				'render'     => null,
				'capability' => 'edit_posts',
				'when'       => null,
			),
			$chapter
		);

		$has_body = '' !== (string) $chapter['template'] || is_callable( $chapter['render'] );

		if ( '' === $id || '' === (string) $chapter['title'] || ! $has_body || ! isset( $tabs[ $chapter['tab'] ] ) ) {
			continue;
		}

		$chapter['id'] = $id;
		$out[ $id ]    = $chapter;
	}

	return $out;
}

/**
 * The chapters the current reader gets, optionally of one tab: their capability, and their `when` when they have one.
 *
 * @param string $tab A tab id, or '' for every tab.
 * @return array<string,array>
 */
function devbase_guide_chapters( $tab = '' ) {
	return array_filter( devbase_guide_all_chapters(), function ( $chapter ) use ( $tab ) {
		if ( '' !== $tab && $tab !== $chapter['tab'] ) {
			return false;
		}

		if ( ! current_user_can( (string) $chapter['capability'] ) ) {
			return false;
		}

		return ! is_callable( $chapter['when'] ) || (bool) call_user_func( $chapter['when'] );
	} );
}

/** The tabs the current reader gets (those with at least one chapter for them): id => title. */
function devbase_guide_visible_tabs() {
	$used = array();

	foreach ( devbase_guide_chapters() as $chapter ) {
		$used[ $chapter['tab'] ] = true;
	}

	return array_intersect_key( devbase_guide_tabs(), $used );
}

/** The open tab: ?tab= when the reader has it, otherwise the first they have. '' when there are none. */
function devbase_guide_current_tab() {
	$tabs   = devbase_guide_visible_tabs();
	$wanted = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	return isset( $tabs[ $wanted ] ) ? $wanted : (string) array_key_first( $tabs );
}

/**
 * The guide's address: on a chapter (its tab, scrolled to it), on a tab, or the guide itself.
 *
 * @param string $chapter A chapter id, '' for none.
 * @param string $tab     A tab id; found from the chapter when it is not given.
 */
function devbase_guide_url( $chapter = '', $tab = '' ) {
	$all = devbase_guide_all_chapters();

	if ( '' === $tab && '' !== $chapter && isset( $all[ $chapter ] ) ) {
		$tab = $all[ $chapter ]['tab'];
	}

	$url = function_exists( 'devbase_app_url' ) ? devbase_app_url( DEVBASE_GUIDE_SECTION, '' !== $tab ? array( 'tab' => $tab ) : array() ) : admin_url();

	return '' !== $chapter ? $url . '#' . rawurlencode( $chapter ) : $url;
}

/** True while the guide is open. */
function devbase_guide_is_open() {
	return is_admin() && function_exists( 'devbase_app_is_section' ) && devbase_app_is_section( DEVBASE_GUIDE_SECTION );
}

/**
 * A screenshot's address: the child's assets/guide/<name> first, then this theme's, as .jpg, .png or .webp. '' when
 * neither has it (the guide then leaves the picture out).
 *
 * @param string $name The file name without its extension.
 */
function devbase_guide_shot( $name ) {
	$base  = '/assets/guide/' . preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $name ) );
	$roots = array_unique( array(
		get_stylesheet_directory() => get_stylesheet_directory_uri(),
		get_template_directory()   => get_template_directory_uri(),
	) );

	foreach ( $roots as $dir => $uri ) {
		foreach ( array( '.jpg', '.png', '.webp' ) as $ext ) {
			$path = $dir . $base . $ext;

			if ( is_readable( $path ) ) {
				return $uri . $base . $ext . '?v=' . filemtime( $path );
			}
		}
	}

	return '';
}

/** The figure `shot()` prints in a chapter, or '' when the screenshot is missing. */
function devbase_guide_shot_html( $name, $caption = '' ) {
	$url = devbase_guide_shot( $name );

	if ( '' === $url ) {
		return '';
	}

	return '<figure class="guide-shot"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( (string) $caption ) . '" loading="lazy"></a>'
		. ( '' !== (string) $caption ? '<figcaption>' . esc_html( (string) $caption ) . '</figcaption>' : '' ) . '</figure>';
}

/**
 * A chapter's template names, the site's language first: devbase-guide/theme/fr_FR/email.twig, then
 * devbase-guide/theme/email.twig. Twig takes the first that exists, so a translation can be added one chapter at a time.
 *
 * @param string $template The chapter's template.
 * @return string[]
 */
function devbase_guide_template_names( $template ) {
	$template = (string) $template;
	$locale   = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
	$names    = array();

	if ( '' !== $locale && 'en_US' !== $locale ) {
		$names[] = dirname( $template ) . '/' . $locale . '/' . basename( $template );
	}

	$names[] = $template;

	return $names;
}

/** What every chapter can use: the site, who is reading, and `go`, the admin address of each screen it mentions. */
function devbase_guide_context() {
	$app = function ( $section, array $args = array() ) {
		return function_exists( 'devbase_app_url' ) ? devbase_app_url( $section, $args ) : admin_url();
	};

	$context = array(
		'site'     => get_bloginfo( 'name' ),
		'home'     => home_url( '/' ),
		'is_admin' => current_user_can( 'manage_options' ),
		// This theme's version (the Theme features tab) and the active theme's (the child's chapters).
		'version'      => (string) wp_get_theme( get_template() )->get( 'Version' ),
		'site_version' => (string) wp_get_theme()->get( 'Version' ),
		'tsf'      => function_exists( 'devbase_tsf_enabled' ) && devbase_tsf_enabled(),
		'go'       => array(
			'dashboard'     => admin_url(),
			'app'           => $app( '' ),
			'media'         => admin_url( 'upload.php' ),
			'media_new'     => admin_url( 'media-new.php' ),
			'pages'         => admin_url( 'edit.php?post_type=page' ),
			'profile'       => admin_url( 'profile.php' ),
			'email'         => $app( 'smtp' ),
			'security'      => $app( 'security' ),
			'tracking'      => $app( 'tracking' ),
			'cache'         => $app( 'cache-settings' ),
			'destinations'  => $app( 'destinations' ),
			'miscellaneous' => $app( 'miscellaneous' ),
			'licence'       => $app( 'licence' ),
			'organization'  => $app( 'organization' ),
			'forms'         => $app( 'forms' ),
			'submissions'   => $app( 'submissions' ),
			'guide'         => devbase_guide_url(),
		),
	);

	$filtered = apply_filters( 'devbase/guide/context', $context );

	return is_array( $filtered ) ? array_replace_recursive( $context, $filtered ) : $context;
}

/** Draws the guide (the section's `render`). */
function devbase_guide_render() {
	$tabs    = devbase_guide_visible_tabs();
	$current = devbase_guide_current_tab();

	if ( ! class_exists( 'Timber\Timber' ) ) {
		return;
	}

	if ( ! $tabs ) {
		echo '<p>' . esc_html__( 'There is nothing in the guide for your account yet.', 'devbase_parent_theme' ) . '</p>';
		return;
	}

	// ?tab=all: every tab the reader has, one after the other, for a PDF of the whole guide.
	$all   = count( $tabs ) > 1 && isset( $_GET['tab'] ) && 'all' === sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$parts = array();

	foreach ( $all ? array_keys( $tabs ) : array( $current ) as $tab ) {
		$parts[] = array(
			'id'       => $tab,
			'title'    => $tabs[ $tab ],
			'chapters' => devbase_guide_chapter_views( $tab ),
		);
	}

	$links = array();

	foreach ( $tabs as $id => $title ) {
		$links[] = array(
			'id'      => $id,
			'title'   => $title,
			'url'     => devbase_guide_url( '', $id ),
			'current' => ! $all && $id === $current,
		);
	}

	Timber\Timber::render( 'devbase-guide/guide.twig', array_merge( devbase_guide_context(), array(
		'tabs'     => $links,
		'tab'      => $all ? 'all' : $current,
		'tab_title' => $all ? '' : $tabs[ $current ],
		'today'    => wp_date( (string) get_option( 'date_format' ) ),
		'parts'    => $parts,
		'chapters' => $parts[0]['chapters'],
		// "Whole guide" opens this view and prints it straight away.
		'all_url'  => count( $tabs ) > 1 ? add_query_arg( 'print', '1', devbase_guide_url( '', 'all' ) ) : '',
		'print'    => $all && isset( $_GET['print'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	) ) );
}

/** One tab's chapters as the template draws them: id, title, icon, templates (or html, for a `render` chapter). */
function devbase_guide_chapter_views( $tab ) {
	$chapters = array();

	foreach ( devbase_guide_chapters( $tab ) as $id => $chapter ) {
		$chapters[] = array(
			'id'        => $id,
			'title'     => $chapter['title'],
			'icon'      => $chapter['icon'],
			'templates' => '' !== (string) $chapter['template'] ? devbase_guide_template_names( $chapter['template'] ) : array(),
			'html'      => '' === (string) $chapter['template'] && is_callable( $chapter['render'] ) ? devbase_guide_capture( $chapter['render'], $chapter ) : '',
		);
	}
	return $chapters;
}

/** What a `render` chapter prints, as a string. */
function devbase_guide_capture( $render, array $chapter ) {
	ob_start();
	call_user_func( $render, $chapter );
	return (string) ob_get_clean();
}

// {{ shot( 'name', 'caption' ) }} in a chapter: the screenshot as a figure, or nothing when it is missing. A child that
// still defines its own `shot` gets this one instead (the same markup; it looks in the child's folder first).
add_filter( 'timber/twig', function ( $twig ) {
	$twig->addFunction( new \Twig\TwigFunction( 'shot', 'devbase_guide_shot_html', array( 'is_safe' => array( 'html' ) ) ) );
	return $twig;
} );

add_action( 'admin_enqueue_scripts', function () {
	if ( ! devbase_guide_is_open() ) {
		return;
	}

	$css = __DIR__ . '/guide.css';
	wp_enqueue_style( 'devbase-guide', get_template_directory_uri() . '/inc/guide/guide.css', array(), (string) filemtime( $css ) );
} );

// A box on the Dashboard pointing to the guide, for everyone who can read it. `devbase/guide/dashboard` false removes it.
add_action( 'wp_dashboard_setup', function () {
	if ( ! current_user_can( devbase_guide_capability() ) || ! apply_filters( 'devbase/guide/dashboard', true ) ) {
		return;
	}

	wp_add_dashboard_widget( 'devbase_guide', __( 'Guide', 'devbase_parent_theme' ), function () {
		echo '<p>' . esc_html__( 'Step-by-step help for this site: editing its pages and content, and the features behind it (forms, email, images, search engines, updates).', 'devbase_parent_theme' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( devbase_guide_url() ) . '">' . esc_html__( 'Open the guide', 'devbase_parent_theme' ) . '</a></p>';
	} );
} );
