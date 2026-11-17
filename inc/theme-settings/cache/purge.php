<?php

// What clears caches, and when. The settings live on the Cache page (settings.php).
//
// TWO KINDS OF CHANGE.
//   - A change to every page (a settings page saved, menus or widgets, an update installed, a new brand credit):
//     every cache is cleared. Each host cache the site has (hosts.php) right away, while response headers can still
//     be sent (a LiteSpeed server is cleared by a header); Cloudflare at the end of the request.
//   - A post, page or comment changed: only Cloudflare, and only the pages that show it (or everything, as set).
//     Hosts already clear a post's pages themselves; Cloudflare clears nothing on its own.
//
// ONE CLEAR PER REQUEST. Requests are queued and sent once, at shutdown: saving a page that changes ten values, or a
// bulk edit of 40 posts, makes one Cloudflare call (or a few, 100 URLs each), never one per change. Past
// DEVBASE_CACHE_MAX_URLS URLs all of the site's pages are cleared instead. A Cloudflare failure never stops the save: it
// is recorded on the Status tab, and the site's pages are cleared once more five minutes later.
//
// "Every cache" on Cloudflare means this site's PAGES (by their cache tag, cloudflare.php), never the whole zone.
//
// OFF MEANS OFF. Every entry point checks devbase_cache_enabled() first.

/** The page lifetime used until one is saved: four hours. */
const DEVBASE_CACHE_DEFAULT_LIFETIME = 14400;

/** Past this many URLs in one request, the whole Cloudflare zone is cleared instead. */
const DEVBASE_CACHE_MAX_URLS = 300;

/** Status option (last clear, last error, the cache rule, the last header check). Not autoloaded. */
const DEVBASE_CACHE_STATUS_OPTION = 'devbase_cache_status';

/** The one-off event that retries a failed Cloudflare clear. */
const DEVBASE_CACHE_RETRY_HOOK = 'devbase_cache_cf_retry';

/**
 * A saved value of the Cache page. null when it was never saved.
 *
 * @param string $name The field name.
 * @return mixed
 */
function devbase_cache_setting( $name ) {
	if ( function_exists( 'get_field' ) ) {
		return get_field( $name, 'option' );
	}

	$value = get_option( 'options_' . $name, null );

	return $value;
}

/**
 * A switch with a default for a page never saved (SCF's default_value only fills the form).
 *
 * @param string $name    The field name.
 * @param bool   $default Used while it has never been saved.
 */
function devbase_cache_flag( $name, $default ) {
	$value = devbase_cache_setting( $name );

	if ( null === $value || '' === $value ) {
		return $default;
	}

	return (bool) $value;
}

/** The master switch. */
function devbase_cache_enabled() {
	return devbase_cache_flag( 'cache_enabled', false );
}

/** id => label of what clears every cache. */
function devbase_cache_trigger_choices() {
	return array(
		'settings' => __( 'A settings page is saved (every page under Theme Settings, the child theme\'s too)', 'devbase_parent_theme' ),
		'menus'    => __( 'Menus, widgets or the Customizer change', 'devbase_parent_theme' ),
		'updates'  => __( 'An update installs (a theme, a plugin or WordPress)', 'devbase_parent_theme' ),
		'credit'   => __( 'Brand Relay sends a new brand credit', 'devbase_parent_theme' ),
	);
}

/** True when the integration is on and this trigger is ticked (all are, until the page is saved). */
function devbase_cache_trigger_on( $trigger ) {
	if ( ! devbase_cache_enabled() ) {
		return false;
	}

	$value = devbase_cache_setting( 'cache_triggers' );

	if ( null === $value ) {
		return true;
	}

	return in_array( $trigger, (array) $value, true );
}

/** seconds => label for "Keep pages for". */
function devbase_cache_lifetime_choices() {
	return array(
		'3600'   => __( '1 hour', 'devbase_parent_theme' ),
		'14400'  => __( '4 hours', 'devbase_parent_theme' ),
		'43200'  => __( '12 hours', 'devbase_parent_theme' ),
		'86400'  => __( '1 day', 'devbase_parent_theme' ),
		'604800' => __( '1 week', 'devbase_parent_theme' ),
	);
}

/** True when Cloudflare is on and has a zone ID and a token. */
function devbase_cache_cf_active() {
	return devbase_cache_enabled() && devbase_cache_flag( 'cache_cf_enabled', false ) && '' !== devbase_cache_cf_zone_id() && '' !== devbase_cache_cf_token();
}

/**
 * True when the theme marks public pages as cacheable for Cloudflare. Deliberately does not read the token (a secret,
 * not autoloaded): this runs on every page.
 */
function devbase_cache_cf_page_cache() {
	return devbase_cache_enabled() && devbase_cache_flag( 'cache_cf_enabled', false ) && devbase_cache_flag( 'cache_cf_page_cache', false );
}

/** How long Cloudflare may keep a page, in seconds. */
function devbase_cache_cf_lifetime() {
	$value = (string) devbase_cache_setting( 'cache_cf_lifetime' );

	return array_key_exists( $value, devbase_cache_lifetime_choices() ) ? (int) $value : DEVBASE_CACHE_DEFAULT_LIFETIME;
}

/* -------------------------------------------------------------------------------------------------------------------
 * Status.
 * ---------------------------------------------------------------------------------------------------------------- */

/** @return array The status option. */
function devbase_cache_get_status() {
	$status = get_option( DEVBASE_CACHE_STATUS_OPTION, array() );

	return is_array( $status ) ? $status : array();
}

/**
 * Replaces the given top-level keys of the status.
 *
 * @param array $changes key => value.
 */
function devbase_cache_update_status( array $changes ) {
	update_option( DEVBASE_CACHE_STATUS_OPTION, array_merge( devbase_cache_get_status(), $changes ), false );
}

/* -------------------------------------------------------------------------------------------------------------------
 * The queue.
 * ---------------------------------------------------------------------------------------------------------------- */

/** @return array The request's queue, by reference. */
function &devbase_cache_queue() {
	static $queue = null;

	if ( null === $queue ) {
		$queue = devbase_cache_empty_queue();
	}

	return $queue;
}

/** @return array An empty queue. */
function devbase_cache_empty_queue() {
	return array(
		'all'      => false,
		'twig'     => false,
		'urls'     => array(),
		'reasons'  => array(),
		'results'  => array(),
		'scheduled' => false,
	);
}

/** Empties the queue without sending it (tests). */
function devbase_cache_reset_queue() {
	$queue = &devbase_cache_queue();
	$queue = devbase_cache_empty_queue();
}

/** Sends the queue at the end of the request, once. */
function devbase_cache_schedule_flush() {
	$queue = &devbase_cache_queue();

	if ( ! $queue['scheduled'] ) {
		$queue['scheduled'] = true;
		add_action( 'shutdown', 'devbase_cache_flush_at_shutdown', 5 );
	}
}

/**
 * Asks for every cache to be cleared. Host caches are cleared now (once per request), Cloudflare and the template
 * cache at shutdown.
 *
 * @param string $reason What changed, for the Status tab.
 * @param bool   $twig   Also empty the compiled templates (an update).
 * @return bool False when the integration is off.
 */
function devbase_cache_request_all( $reason, $twig = false ) {
	if ( ! devbase_cache_enabled() ) {
		return false;
	}

	$queue = &devbase_cache_queue();

	$queue['reasons'][] = (string) $reason;
	$queue['twig']      = $queue['twig'] || $twig;

	if ( ! $queue['all'] ) {
		$queue['all']     = true;
		$queue['results'] = devbase_cache_purge_hosts();
	}

	devbase_cache_schedule_flush();

	return true;
}

/**
 * Asks Cloudflare to clear these URLs at the end of the request.
 *
 * @param string[] $urls   Full URLs.
 * @param string   $reason What changed.
 * @return bool False when Cloudflare is not on.
 */
function devbase_cache_request_urls( array $urls, $reason ) {
	if ( ! devbase_cache_cf_active() ) {
		return false;
	}

	$queue = &devbase_cache_queue();

	$queue['urls']      = array_merge( $queue['urls'], array_map( 'strval', $urls ) );
	$queue['reasons'][] = (string) $reason;

	devbase_cache_schedule_flush();

	return true;
}

/**
 * Clears every detected host cache, if the Host cache switch is on.
 *
 * @return array[] One { label, ok, message } per cache.
 */
function devbase_cache_purge_hosts() {
	if ( ! devbase_cache_flag( 'cache_host_purge', true ) ) {
		return array();
	}

	$results = array();

	foreach ( devbase_cache_detected_hosts() as $id => $host ) {
		try {
			$message        = call_user_func( $host['purge'] );
			$results[ $id ] = array( 'label' => (string) $host['label'], 'ok' => true, 'message' => is_string( $message ) ? $message : '' );
		} catch ( Throwable $e ) {
			$results[ $id ] = array( 'label' => (string) $host['label'], 'ok' => false, 'message' => $e->getMessage() );
		}
	}

	return $results;
}

/**
 * Sends what was queued: Cloudflare (everything, or the URLs) and the template cache, then records the result.
 * Runs at shutdown; tests call it directly.
 *
 * @return array[] The results, one per cache.
 */
function devbase_cache_flush() {
	$queue = &devbase_cache_queue();
	$sent  = $queue;
	$queue = devbase_cache_empty_queue();

	if ( ! $sent['all'] && ! $sent['urls'] ) {
		return array();
	}

	$results = $sent['results'];
	$urls    = devbase_cache_cf_valid_urls( $sent['urls'] );
	$error   = '';

	if ( devbase_cache_cf_active() && ( $sent['all'] || $urls ) ) {
		if ( $sent['all'] || count( $urls ) > (int) apply_filters( 'devbase/cache/max_urls', DEVBASE_CACHE_MAX_URLS ) ) {
			$cf    = devbase_cache_cf_purge_site();
			$label = __( 'Cloudflare (this site\'s pages)', 'devbase_parent_theme' );
		} else {
			$cf = devbase_cache_cf_purge_urls( $urls );
			/* translators: %d: number of addresses */
			$label = sprintf( _n( 'Cloudflare (%d page)', 'Cloudflare (%d pages)', count( $urls ), 'devbase_parent_theme' ), count( $urls ) );
		}

		$results['cloudflare'] = array( 'label' => $label, 'ok' => $cf['ok'], 'message' => $cf['error'] );

		if ( ! $cf['ok'] ) {
			$error = 'Cloudflare: ' . $cf['error'];

			if ( ! wp_next_scheduled( DEVBASE_CACHE_RETRY_HOOK ) ) {
				wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, DEVBASE_CACHE_RETRY_HOOK );
			}
		}
	}

	if ( $sent['twig'] ) {
		$results['twig'] = array( 'label' => __( 'Template cache', 'devbase_parent_theme' ), 'ok' => devbase_cache_twig_clear(), 'message' => '' );
	}

	foreach ( $results as $result ) {
		if ( empty( $result['ok'] ) && '' === $error ) {
			$error = (string) $result['label'] . ': ' . (string) $result['message'];
		}
	}

	$changes = array(
		'last' => array(
			'at'      => time(),
			'reasons' => array_values( array_unique( $sent['reasons'] ) ),
			'results' => $results,
		),
	);

	if ( '' !== $error ) {
		$changes['error'] = array( 'at' => time(), 'message' => $error );
	}

	devbase_cache_update_status( $changes );

	return $results;
}

/** The shutdown hook (an action returns nothing). */
function devbase_cache_flush_at_shutdown() {
	devbase_cache_flush();
}

// One more try, five minutes after a failed Cloudflare clear: all of this site's pages, which covers whatever was missed.
add_action( DEVBASE_CACHE_RETRY_HOOK, function () {
	if ( ! devbase_cache_cf_active() ) {
		return;
	}

	$cf     = devbase_cache_cf_purge_site();
	$status = array(
		'last' => array(
			'at'      => time(),
			'reasons' => array( 'retry' ),
			'results' => array( 'cloudflare' => array( 'label' => __( 'Cloudflare (this site\'s pages)', 'devbase_parent_theme' ), 'ok' => $cf['ok'], 'message' => $cf['error'] ) ),
		),
	);

	if ( ! $cf['ok'] ) {
		$status['error'] = array( 'at' => time(), 'message' => 'Cloudflare (retry): ' . $cf['error'] );
	}

	devbase_cache_update_status( $status );
} );

/**
 * "Clear all caches now": everything, right away, and the object cache too.
 *
 * @return array[] The results.
 */
function devbase_cache_clear_all_now() {
	devbase_cache_reset_queue();

	if ( ! devbase_cache_request_all( 'button', true ) ) {
		return array();
	}

	$results = devbase_cache_flush();

	$results['object'] = array( 'label' => __( 'Object cache', 'devbase_parent_theme' ), 'ok' => wp_cache_flush(), 'message' => '' );

	return $results;
}

/* -------------------------------------------------------------------------------------------------------------------
 * What a post change clears on Cloudflare.
 * ---------------------------------------------------------------------------------------------------------------- */

/**
 * The pages that show a post: itself, the home and blog pages, its archive, its terms' pages, its author's page and
 * the feed. Filter `devbase/cache/post_urls` for a child's own (a landing page that lists a CPT, for one).
 *
 * @param WP_Post $post The post.
 * @return string[]
 */
function devbase_cache_post_urls( $post ) {
	$urls = array( home_url( '/' ) );

	$link = get_permalink( $post );

	if ( $link ) {
		$urls[] = $link;
	}

	$page_for_posts = (int) get_option( 'page_for_posts' );

	if ( 'post' === $post->post_type && $page_for_posts ) {
		$urls[] = (string) get_permalink( $page_for_posts );
	}

	$archive = get_post_type_archive_link( $post->post_type );

	if ( $archive ) {
		$urls[] = $archive;
	}

	foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
		if ( ! is_taxonomy_viewable( $taxonomy ) ) {
			continue;
		}

		$terms = get_the_terms( $post, $taxonomy );

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$term_link = get_term_link( $term );

				if ( is_string( $term_link ) ) {
					$urls[] = $term_link;
				}
			}
		}
	}

	if ( post_type_supports( $post->post_type, 'author' ) && $post->post_author ) {
		$urls[] = get_author_posts_url( (int) $post->post_author );
	}

	if ( 'post' === $post->post_type ) {
		$urls[] = get_feed_link();
	}

	$filtered = apply_filters( 'devbase/cache/post_urls', $urls, $post );
	$urls     = is_array( $filtered ) ? $filtered : $urls;

	return array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
}

/**
 * Queues a post's pages, or the whole zone, as "When a post or page changes" says.
 *
 * @param WP_Post $post   The post.
 * @param string  $reason What changed.
 * @param string  $extra  Another URL (the post's address before a slug change).
 */
function devbase_cache_post_changed( $post, $reason, $extra = '' ) {
	if ( ! $post instanceof WP_Post || ! devbase_cache_cf_active() || ! is_post_type_viewable( $post->post_type ) ) {
		return;
	}

	$mode = (string) devbase_cache_setting( 'cache_cf_post_purge' );

	if ( 'none' === $mode ) {
		return;
	}

	if ( 'everything' === $mode ) {
		$queue = &devbase_cache_queue();
		$queue['all']       = true;
		$queue['reasons'][] = $reason;
		devbase_cache_schedule_flush();

		return;
	}

	$urls = devbase_cache_post_urls( $post );

	if ( '' !== $extra ) {
		$urls[] = $extra;
	}

	devbase_cache_request_urls( $urls, $reason );
}

// A post saved, published, unpublished or trashed. Only one that is or was public: a draft saved again changes no
// page anyone sees.
add_action( 'wp_after_insert_post', function ( $post_id, $post, $update, $post_before ) {
	if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
		return;
	}

	$was = $post_before instanceof WP_Post ? $post_before->post_status : '';

	if ( 'publish' !== $post->post_status && 'publish' !== $was ) {
		return;
	}

	$old_link = '';

	if ( 'publish' === $was && $post_before->post_name !== $post->post_name ) {
		$old_link = (string) get_permalink( $post_before );
	}

	devbase_cache_post_changed( $post, 'post', $old_link );
}, 20, 4 );

// Deleted for good while still published (trashing goes through the hook above).
add_action( 'before_delete_post', function ( $post_id, $post = null ) {
	$post = $post instanceof WP_Post ? $post : get_post( $post_id );

	if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
		devbase_cache_post_changed( $post, 'post' );
	}
}, 20, 2 );

// A comment that is or was shown: the post's pages change (its comment count, its comments).
add_action( 'transition_comment_status', function ( $new_status, $old_status, $comment ) {
	if ( 'approved' === $new_status || 'approved' === $old_status ) {
		devbase_cache_post_changed( get_post( (int) $comment->comment_post_ID ), 'comment' );
	}
}, 20, 3 );

add_action( 'comment_post', function ( $comment_id, $approved ) {
	if ( 1 === $approved || '1' === $approved ) {
		$comment = get_comment( $comment_id );

		if ( $comment ) {
			devbase_cache_post_changed( get_post( (int) $comment->comment_post_ID ), 'comment' );
		}
	}
}, 20, 2 );

/* -------------------------------------------------------------------------------------------------------------------
 * What clears every cache.
 * ---------------------------------------------------------------------------------------------------------------- */

/** The SCF stores of every options page (most use 'options'). */
function devbase_cache_option_stores() {
	$stores = array( 'options', 'option' );

	if ( function_exists( 'acf_get_options_pages' ) ) {
		foreach ( (array) acf_get_options_pages() as $page ) {
			if ( is_array( $page ) && ! empty( $page['post_id'] ) && is_string( $page['post_id'] ) ) {
				$stores[] = $page['post_id'];
			}
		}
	}

	return array_values( array_unique( $stores ) );
}

// A settings page saved (any options page: the classic page, the settings app or a child's page).
add_action( 'acf/save_post', function ( $post_id ) {
	if ( is_string( $post_id ) && in_array( $post_id, devbase_cache_option_stores(), true ) && devbase_cache_trigger_on( 'settings' ) ) {
		devbase_cache_request_all( 'settings' );
	}
}, 20 );

// Menus, widgets, the Customizer.
add_action( 'wp_update_nav_menu', function () {
	if ( devbase_cache_trigger_on( 'menus' ) ) {
		devbase_cache_request_all( 'menus' );
	}
} );

add_action( 'customize_save_after', function () {
	if ( devbase_cache_trigger_on( 'menus' ) ) {
		devbase_cache_request_all( 'customizer' );
	}
} );

add_action( 'updated_option', function ( $option ) {
	if ( ( 'sidebars_widgets' === $option || 0 === strpos( (string) $option, 'widget_' ) ) && devbase_cache_trigger_on( 'menus' ) ) {
		devbase_cache_request_all( 'widgets' );
	}
} );

// A theme, plugin or WordPress update. The compiled templates go too: a theme update can change any of them.
add_action( 'upgrader_process_complete', function ( $upgrader, $hook_extra ) {
	if ( is_array( $hook_extra ) && 'update' === ( $hook_extra['action'] ?? '' ) && devbase_cache_trigger_on( 'updates' ) ) {
		devbase_cache_request_all( 'update: ' . (string) ( $hook_extra['type'] ?? '' ), true );
	}
}, 30, 2 );

// A new brand credit (Brand Relay): it is in every page's footer.
add_action( 'devbase/credit/changed', function () {
	if ( devbase_cache_trigger_on( 'credit' ) ) {
		devbase_cache_request_all( 'credit' );
	}
} );

/* -------------------------------------------------------------------------------------------------------------------
 * The Twig template cache.
 * ---------------------------------------------------------------------------------------------------------------- */

/**
 * The folder for compiled templates, created when missing. '' while the integration or the switch is off, or when
 * the folder cannot be written (Twig would fail on every page otherwise).
 */
function devbase_cache_twig_dir() {
	if ( ! devbase_cache_enabled() || ! devbase_cache_flag( 'cache_twig', true ) ) {
		return '';
	}

	$dir = (string) apply_filters( 'devbase/cache/twig_dir', WP_CONTENT_DIR . '/cache/devbase-twig' );

	if ( ! is_dir( $dir ) ) {
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		// Compiled templates are PHP: keep them unreachable from the web on hosts that read .htaccess.
		@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	return wp_is_writable( $dir ) ? $dir : '';
}

/** Empties the compiled templates (the folder and its two guard files stay). */
function devbase_cache_twig_clear() {
	$dir = (string) apply_filters( 'devbase/cache/twig_dir', WP_CONTENT_DIR . '/cache/devbase-twig' );

	if ( ! is_dir( $dir ) ) {
		return true;
	}

	$ok    = true;
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );

	foreach ( $items as $item ) {
		$path = $item->getPathname();

		if ( dirname( $path ) === $dir && in_array( $item->getFilename(), array( '.htaccess', 'index.php' ), true ) ) {
			continue;
		}

		$ok = ( $item->isDir() ? @rmdir( $path ) : @unlink( $path ) ) && $ok; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	return $ok;
}

// Twig keeps compiled templates on disk. auto_reload: an edited template is compiled again on its own.
add_filter( 'timber/twig/environment/options', function ( $options ) {
	$dir = devbase_cache_twig_dir();

	if ( '' !== $dir && is_array( $options ) ) {
		$options['cache']       = $dir;
		$options['auto_reload'] = true;
	}

	return $options;
} );

/* -------------------------------------------------------------------------------------------------------------------
 * The rest of the theme.
 * ---------------------------------------------------------------------------------------------------------------- */

// The values every public page reads come with WordPress's one options query (autoload.php). Not the token.
add_filter( 'devbase/settings/autoload_fields', function ( $names ) {
	return array_merge( (array) $names, array( 'cache_enabled', 'cache_twig', 'cache_exclusions', 'cache_cf_enabled', 'cache_cf_page_cache', 'cache_cf_lifetime' ) );
} );

// The health report (heartbeat.php) says what caches the site has, while the integration is on.
add_filter( 'devbase/maintenance/report', function ( $report ) {
	if ( ! is_array( $report ) || ! devbase_cache_enabled() ) {
		return $report;
	}

	$status = devbase_cache_get_status();

	$report['cache'] = array(
		'hosts'      => array_keys( devbase_cache_detected_hosts() ),
		'cloudflare' => devbase_cache_cf_active(),
		'page_cache' => devbase_cache_cf_page_cache(),
		'last_clear' => isset( $status['last']['at'] ) ? (int) $status['last']['at'] : null,
		'last_error' => isset( $status['error']['message'] ) ? (string) $status['error']['message'] : null,
	);

	return $report;
} );
