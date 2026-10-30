<?php

/**
 * Rollback versions class.
 *
 * @since 8.7.3
 */
// If this file is called directly, abort.
if ( !defined( 'WPINC' ) ) {
    die;
}
/**
 * Class ASENHA_Rollback_Versions
 */
class ASENHA_Rollback_Versions {
    /**
     * Helper instance.
     *
     * @var ASENHA_Rollback_Helper
     */
    private $helper;

    /**
     * Archives instance. Null when local archives are unavailable.
     *
     * @var ASENHA_Rollback_Archives|null
     */
    private $archives;

    /**
     * Newest WordPress.org versions kept in the rollback dropdown, besides trunk,
     * the current version, and local archives.
     *
     * @since 9.2.0
     */
    const MAX_WORDPRESS_ORG_VERSIONS = 50;

    /**
     * How long a successful latest-version entry is kept.
     *
     * Matches the WordPress.org version-list transient.
     *
     * @since 9.2.1
     */
    const LATEST_VERSION_TTL = 12 * HOUR_IN_SECONDS;

    /**
     * How long a failed WordPress.org lookup is remembered.
     *
     * @since 9.2.1
     */
    const VERSION_FAILURE_TTL = 30 * MINUTE_IN_SECONDS;

    /**
     * Maximum assets resolved in one admin-ajax batch.
     *
     * @since 9.2.1
     */
    const VERSION_BATCH_LIMIT = 5;

    /**
     * Stop starting new remote lookups after this many seconds.
     *
     * @since 9.2.1
     */
    const VERSION_BATCH_TIME_BUDGET = 8;

    /**
     * Per-request timeout for WordPress.org API calls during a batch.
     *
     * @since 9.2.1
     */
    const VERSION_API_TIMEOUT = 5;

    /**
     * In-request copy of the small latest-version cache.
     *
     * @var array|null
     */
    private $version_cache = null;

    /**
     * Whether the in-request cache has changes to persist.
     *
     * @var bool
     */
    private $version_cache_dirty = false;

    /**
     * Whether short WordPress.org API timeouts are currently applied.
     *
     * @var bool
     */
    private $limit_api_timeout = false;

    /**
     * Constructor.
     *
     * @param ASENHA_Rollback_Helper        $helper   Helper instance.
     * @param ASENHA_Rollback_Archives|null $archives Archives instance.
     */
    public function __construct( $helper, $archives = null ) {
        $this->helper = $helper;
        $this->archives = $archives;
    }

    /**
     * Get the asset rows for a rollback tab.
     *
     * @param string $asset_type Asset type.
     * @return array
     */
    public function get_assets_for_tab( $asset_type ) {
        $rows = array();
        $catalog = array();
        $existing_catalog = $this->helper->get_catalog( $asset_type );
        if ( 'plugin' === $asset_type ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $plugins = get_plugins();
            uasort( $plugins, function ( $first, $second ) {
                return strcasecmp( $first['Name'], $second['Name'] );
            } );
            foreach ( $plugins as $plugin_file => $plugin_data ) {
                $context = $this->get_asset_context(
                    'plugin',
                    $plugin_file,
                    $plugin_data,
                    false
                );
                if ( empty( $context ) || !$this->should_include_asset( $context ) ) {
                    continue;
                }
                $rows[] = $this->build_row_from_context( $context );
                $existing_entry = ( isset( $existing_catalog[$plugin_file] ) && is_array( $existing_catalog[$plugin_file] ) ? $existing_catalog[$plugin_file] : array() );
                $catalog[$plugin_file] = $this->build_catalog_entry_from_context( $context, $existing_entry );
            }
        } else {
            $themes = wp_get_themes();
            uasort( $themes, function ( $first, $second ) {
                return strcasecmp( $first->get( 'Name' ), $second->get( 'Name' ) );
            } );
            foreach ( $themes as $theme_slug => $theme ) {
                $context = $this->get_asset_context(
                    'theme',
                    $theme_slug,
                    $theme,
                    false
                );
                if ( empty( $context ) || !$this->should_include_asset( $context ) ) {
                    continue;
                }
                $rows[] = $this->build_row_from_context( $context );
                $existing_entry = ( isset( $existing_catalog[$theme_slug] ) && is_array( $existing_catalog[$theme_slug] ) ? $existing_catalog[$theme_slug] : array() );
                $catalog[$theme_slug] = $this->build_catalog_entry_from_context( $context, $existing_entry );
            }
        }
        if ( $catalog != $existing_catalog ) {
            $this->helper->set_catalog( $asset_type, $catalog );
        }
        return $rows;
    }

    /**
     * Get a full asset context.
     *
     * @param string $asset_type             Asset type.
     * @param string $asset_key              Asset key.
     * @param mixed  $raw_data               Optional raw data.
     * @param bool   $include_remote_versions Whether to request WordPress.org version history.
     * @return array
     */
    public function get_asset_context(
        $asset_type,
        $asset_key,
        $raw_data = null,
        $include_remote_versions = true
    ) {
        if ( 'plugin' === $asset_type ) {
            return $this->get_plugin_context( $asset_key, $raw_data, $include_remote_versions );
        }
        return $this->get_theme_context( $asset_key, $raw_data, $include_remote_versions );
    }

    /**
     * Determine whether a plugin is hosted on WordPress.org without fetching version history.
     *
     * @param string     $plugin_file Plugin basename.
     * @param array|null $plugin_data Optional plugin data.
     * @return bool
     */
    public function is_plugin_hosted_on_wordpress_org( $plugin_file, $plugin_data = null ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if ( null === $plugin_data ) {
            $plugins = get_plugins();
            if ( !isset( $plugins[$plugin_file] ) ) {
                return false;
            }
            $plugin_data = $plugins[$plugin_file];
        }
        $updates = get_site_transient( 'update_plugins' );
        $update_entry = $this->get_plugin_update_entry( $plugin_file, $updates );
        $package_slug = $this->helper->get_plugin_package_slug( $plugin_file, $plugin_data );
        $wordpress_slug = $this->get_plugin_wordpress_org_slug(
            $plugin_file,
            $plugin_data,
            $update_entry,
            $package_slug
        );
        return $this->is_plugin_wordpress_org( $plugin_data, $update_entry, $wordpress_slug );
    }

    /**
     * Determine whether a theme is hosted on WordPress.org without fetching version history.
     *
     * @param string        $theme_slug Theme stylesheet.
     * @param WP_Theme|null $theme      Optional theme object.
     * @return bool
     */
    public function is_theme_hosted_on_wordpress_org( $theme_slug, $theme = null ) {
        if ( null === $theme ) {
            $theme = wp_get_theme( $theme_slug );
        }
        if ( !$theme || !$theme->exists() ) {
            return false;
        }
        $updates = get_site_transient( 'update_themes' );
        $update_entry = $this->get_theme_update_entry( $theme_slug, $updates );
        $wordpress_slug = $this->get_theme_wordpress_org_slug( $theme_slug, $theme, $update_entry );
        return $this->is_theme_wordpress_org( $theme, $update_entry, $wordpress_slug );
    }

    /**
     * Get theme stylesheets that can show a Rollback action.
     *
     * Free includes WordPress.org themes. Pro includes every installed theme.
     *
     * @return array
     */
    public function get_eligible_theme_slugs() {
        $slugs = array();
        $themes = wp_get_themes();
        foreach ( $themes as $theme_slug => $theme ) {
            $include_theme = $this->is_theme_hosted_on_wordpress_org( $theme_slug, $theme );
            if ( $include_theme ) {
                $slugs[] = $theme_slug;
            }
        }
        return $slugs;
    }

    /**
     * Determine whether the currently installed version should be archived before replacement.
     *
     * @param array $asset_context Asset context.
     * @return bool
     */
    public function should_archive_existing_version( $asset_context ) {
        if ( empty( $asset_context['current_version'] ) ) {
            return false;
        }
        if ( empty( $asset_context['is_wordpress_org'] ) || empty( $asset_context['wordpress_org_slug'] ) ) {
            return true;
        }
        $remote_versions = $this->get_wordpress_org_versions( $asset_context['asset_type'], $asset_context['wordpress_org_slug'] );
        if ( empty( $remote_versions ) || is_wp_error( $remote_versions ) ) {
            return true;
        }
        return !array_key_exists( $asset_context['current_version'], $remote_versions );
    }

    /**
     * Resolve the package source for a requested rollback version.
     *
     * @param string $asset_type     Asset type.
     * @param string $asset_key      Asset key.
     * @param string $target_version Target version.
     * @return array|WP_Error
     */
    public function resolve_rollback_source( $asset_type, $asset_key, $target_version ) {
        $asset_context = $this->get_asset_context( $asset_type, $asset_key );
        if ( empty( $asset_context ) ) {
            return new WP_Error('asenha_rollback_asset_not_found', __( 'The selected plugin or theme could not be found.', 'admin-site-enhancements' ));
        }
        if ( !empty( $asset_context['is_wordpress_org'] ) && !empty( $asset_context['wordpress_org_slug'] ) ) {
            $remote_versions = $this->get_wordpress_org_versions( $asset_type, $asset_context['wordpress_org_slug'] );
            if ( !is_wp_error( $remote_versions ) && isset( $remote_versions[$target_version] ) && $this->helper->is_wordpress_org_url( $remote_versions[$target_version] ) ) {
                return array(
                    'source'  => 'wordpress_org',
                    'package' => esc_url_raw( $remote_versions[$target_version] ),
                );
            }
        }
        $local_source = null;
        if ( is_array( $local_source ) ) {
            return $local_source;
        }
        return new WP_Error('asenha_rollback_version_not_available', __( 'The selected rollback version is no longer available.', 'admin-site-enhancements' ));
    }

    /**
     * Infer an existing installed asset from an unpacked upload source.
     *
     * @param string $asset_type Asset type.
     * @param string $source     Unpacked source path.
     * @return array
     */
    public function infer_existing_asset_from_source( $asset_type, $source ) {
        if ( 'plugin' === $asset_type ) {
            return $this->infer_plugin_from_source( $source );
        }
        return $this->infer_theme_from_source( $source );
    }

    /**
     * Determine whether an asset should appear in the free or Pro rollback UI.
     *
     * Free includes WordPress.org assets. Pro includes every installed asset.
     *
     * @param array $asset_context Asset context.
     * @return bool
     */
    private function should_include_asset( $asset_context ) {
        $include_asset = !empty( $asset_context['is_wordpress_org'] );
        return $include_asset;
    }

    /**
     * Get plugin context data.
     *
     * @param string     $plugin_file             Plugin basename.
     * @param array|null $plugin_data             Plugin data.
     * @param bool       $include_remote_versions Whether to request WordPress.org version history.
     * @return array
     */
    private function get_plugin_context( $plugin_file, $plugin_data = null, $include_remote_versions = true ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if ( null === $plugin_data ) {
            $plugins = get_plugins();
            if ( !isset( $plugins[$plugin_file] ) ) {
                return array();
            }
            $plugin_data = $plugins[$plugin_file];
        }
        $updates = get_site_transient( 'update_plugins' );
        $update_entry = $this->get_plugin_update_entry( $plugin_file, $updates );
        $package_slug = $this->helper->get_plugin_package_slug( $plugin_file, $plugin_data );
        $wordpress_slug = $this->get_plugin_wordpress_org_slug(
            $plugin_file,
            $plugin_data,
            $update_entry,
            $package_slug
        );
        $is_wordpress_org = $this->is_plugin_wordpress_org( $plugin_data, $update_entry, $wordpress_slug );
        $archives = array();
        $remote_versions = array();
        $latest_version = '';
        $latest_pending = false;
        $versions_deferred = false;
        if ( $is_wordpress_org && '' !== $wordpress_slug ) {
            if ( $include_remote_versions ) {
                $remote_versions = $this->get_wordpress_org_versions( 'plugin', $wordpress_slug );
                if ( is_wp_error( $remote_versions ) ) {
                    $remote_versions = array();
                } else {
                    $latest_version = $this->get_wordpress_org_latest_version( 'plugin', $wordpress_slug );
                }
            } else {
                $versions_deferred = true;
                $cached_latest = $this->get_cached_latest_version( 'plugin', $wordpress_slug );
                if ( null !== $cached_latest ) {
                    $latest_version = $cached_latest;
                } elseif ( !$this->is_version_lookup_failed( 'plugin', $wordpress_slug ) ) {
                    $latest_pending = true;
                }
            }
        }
        $current_version = ( isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '' );
        $available_versions = array();
        if ( !$versions_deferred ) {
            $available_versions = $this->merge_versions( $current_version, $remote_versions, $archives );
        }
        return array(
            'asset_type'         => 'plugin',
            'asset_key'          => $plugin_file,
            'name'               => wp_strip_all_tags( $plugin_data['Name'] ),
            'slug'               => ( '' !== $wordpress_slug ? $wordpress_slug : $package_slug ),
            'package_slug'       => $package_slug,
            'author'             => ( !empty( $plugin_data['AuthorName'] ) ? wp_strip_all_tags( $plugin_data['AuthorName'] ) : wp_strip_all_tags( $plugin_data['Author'] ) ),
            'current_version'    => $current_version,
            'latest_version'     => $latest_version,
            'latest_pending'     => $latest_pending,
            'versions_deferred'  => $versions_deferred,
            'is_wordpress_org'   => $is_wordpress_org,
            'wordpress_org_slug' => $wordpress_slug,
            'archives'           => $archives,
            'available_versions' => $available_versions,
        );
    }

    /**
     * Get theme context data.
     *
     * @param string        $theme_slug              Theme stylesheet.
     * @param WP_Theme|null $theme                   Theme object.
     * @param bool          $include_remote_versions Whether to request WordPress.org version history.
     * @return array
     */
    private function get_theme_context( $theme_slug, $theme = null, $include_remote_versions = true ) {
        if ( null === $theme ) {
            $theme = wp_get_theme( $theme_slug );
        }
        if ( !$theme || !$theme->exists() ) {
            return array();
        }
        $updates = get_site_transient( 'update_themes' );
        $update_entry = $this->get_theme_update_entry( $theme_slug, $updates );
        $wordpress_slug = $this->get_theme_wordpress_org_slug( $theme_slug, $theme, $update_entry );
        $is_wordpress_org = $this->is_theme_wordpress_org( $theme, $update_entry, $wordpress_slug );
        $archives = array();
        $remote_versions = array();
        $latest_version = '';
        $latest_pending = false;
        $versions_deferred = false;
        if ( $is_wordpress_org && '' !== $wordpress_slug ) {
            if ( $include_remote_versions ) {
                $remote_versions = $this->get_wordpress_org_versions( 'theme', $wordpress_slug );
                if ( is_wp_error( $remote_versions ) ) {
                    $remote_versions = array();
                } else {
                    $latest_version = $this->get_wordpress_org_latest_version( 'theme', $wordpress_slug );
                }
            } else {
                $versions_deferred = true;
                $cached_latest = $this->get_cached_latest_version( 'theme', $wordpress_slug );
                if ( null !== $cached_latest ) {
                    $latest_version = $cached_latest;
                } elseif ( !$this->is_version_lookup_failed( 'theme', $wordpress_slug ) ) {
                    $latest_pending = true;
                }
            }
        }
        $current_version = (string) $theme->get( 'Version' );
        $available_versions = array();
        if ( !$versions_deferred ) {
            $available_versions = $this->merge_versions( $current_version, $remote_versions, $archives );
        }
        return array(
            'asset_type'         => 'theme',
            'asset_key'          => $theme_slug,
            'name'               => $theme->get( 'Name' ),
            'slug'               => ( '' !== $wordpress_slug ? $wordpress_slug : $theme_slug ),
            'package_slug'       => $theme_slug,
            'author'             => wp_strip_all_tags( $theme->get( 'Author' ) ),
            'current_version'    => $current_version,
            'latest_version'     => $latest_version,
            'latest_pending'     => $latest_pending,
            'versions_deferred'  => $versions_deferred,
            'is_wordpress_org'   => $is_wordpress_org,
            'wordpress_org_slug' => $wordpress_slug,
            'archives'           => $archives,
            'available_versions' => $available_versions,
        );
    }

    /**
     * Merge remote and local versions into selector options.
     *
     * @param string $current_version Current installed version.
     * @param array  $remote_versions Remote version map.
     * @param array  $archives        Local archive map.
     * @return array
     */
    private function merge_versions( $current_version, $remote_versions, $archives ) {
        $merged_versions = array();
        foreach ( $remote_versions as $version => $package ) {
            $merged_versions[$version] = array(
                'version'    => (string) $version,
                'source'     => 'wordpress_org',
                'package'    => esc_url_raw( $package ),
                'is_current' => (string) $version === (string) $current_version,
            );
        }
        foreach ( $archives as $version => $archive ) {
            if ( isset( $merged_versions[$version] ) ) {
                continue;
            }
            $merged_versions[$version] = array(
                'version'    => (string) $version,
                'source'     => 'local_archive',
                'is_current' => (string) $version === (string) $current_version,
            );
        }
        uksort( $merged_versions, function ( $first, $second ) {
            if ( 'trunk' === $first ) {
                return -1;
            }
            if ( 'trunk' === $second ) {
                return 1;
            }
            return version_compare( (string) $second, (string) $first );
        } );
        $merged_versions = $this->limit_merged_versions( $merged_versions, $current_version, $archives );
        foreach ( $merged_versions as $version => $version_data ) {
            $source_label = ( 'wordpress_org' === $version_data['source'] ? __( 'WordPress.org', 'admin-site-enhancements' ) : __( 'Local archive', 'admin-site-enhancements' ) );
            $label = sprintf( '%1$s (%2$s)', $version, $source_label );
            if ( !empty( $version_data['is_current'] ) ) {
                $label .= ' ' . __( '- installed', 'admin-site-enhancements' );
            }
            $merged_versions[$version]['label'] = $label;
        }
        return array_values( $merged_versions );
    }

    /**
     * Keep trunk, the current version, every local archive, and the newest WordPress.org versions.
     *
     * Older WordPress.org versions stay in the version transient. They are omitted from the dropdown.
     *
     * @since 9.2.0
     *
     * @param array  $merged_versions Versions sorted newest first, with trunk first.
     * @param string $current_version Current installed version.
     * @param array  $archives        Local archive map, keyed by version.
     * @return array
     */
    private function limit_merged_versions( $merged_versions, $current_version, $archives ) {
        $limited = array();
        $wordpress_org_kept = 0;
        $archives = ( is_array( $archives ) ? $archives : array() );
        foreach ( $merged_versions as $version => $version_data ) {
            if ( !is_array( $version_data ) ) {
                continue;
            }
            $source = ( isset( $version_data['source'] ) ? (string) $version_data['source'] : '' );
            $is_trunk = 'trunk' === (string) $version;
            $is_current = (string) $version === (string) $current_version;
            $is_local = 'local_archive' === $source;
            $has_local_archive = array_key_exists( (string) $version, $archives );
            if ( $is_trunk || $is_current || $is_local || $has_local_archive ) {
                $limited[$version] = $version_data;
                continue;
            }
            if ( 'wordpress_org' === $source && $wordpress_org_kept < self::MAX_WORDPRESS_ORG_VERSIONS ) {
                $limited[$version] = $version_data;
                $wordpress_org_kept++;
            }
        }
        return $limited;
    }

    /**
     * Build a row payload from an asset context.
     *
     * @param array $asset_context Asset context.
     * @return array
     */
    private function build_row_from_context( $asset_context ) {
        return array(
            'asset_type'         => $asset_context['asset_type'],
            'asset_key'          => $asset_context['asset_key'],
            'name'               => $asset_context['name'],
            'slug'               => $asset_context['slug'],
            'author'             => $asset_context['author'],
            'current_version'    => $asset_context['current_version'],
            'latest_version'     => ( isset( $asset_context['latest_version'] ) ? (string) $asset_context['latest_version'] : '' ),
            'latest_pending'     => !empty( $asset_context['latest_pending'] ),
            'versions_deferred'  => !empty( $asset_context['versions_deferred'] ),
            'is_wordpress_org'   => $asset_context['is_wordpress_org'],
            'wordpress_org_slug' => $asset_context['wordpress_org_slug'],
            'available_versions' => $asset_context['available_versions'],
        );
    }

    /**
     * Build a catalog entry from an asset context.
     *
     * @param array $asset_context  Asset context.
     * @param array $existing_entry Stored catalog entry, when one exists.
     * @return array
     */
    private function build_catalog_entry_from_context( $asset_context, $existing_entry = array() ) {
        $existing_entry = ( is_array( $existing_entry ) ? $existing_entry : array() );
        $entry = $existing_entry;
        $entry['asset_type'] = $asset_context['asset_type'];
        $entry['asset_key'] = $asset_context['asset_key'];
        $entry['title'] = $asset_context['name'];
        $entry['slug'] = $asset_context['slug'];
        $entry['package_slug'] = $asset_context['package_slug'];
        $entry['author'] = $asset_context['author'];
        $entry['current_version'] = $asset_context['current_version'];
        $entry['is_wordpress_org'] = $asset_context['is_wordpress_org'];
        $entry['wordpress_org_slug'] = $asset_context['wordpress_org_slug'];
        unset($entry['available_versions']);
        $entry['archives'] = ( isset( $asset_context['archives'] ) && is_array( $asset_context['archives'] ) ? $asset_context['archives'] : array() );
        $compare_new = $entry;
        $compare_old = $existing_entry;
        unset(
            $compare_new['updated_at'],
            $compare_old['updated_at'],
            $compare_new['available_versions'],
            $compare_old['available_versions']
        );
        if ( $compare_new != $compare_old || !isset( $entry['updated_at'] ) ) {
            $entry['updated_at'] = time();
        }
        return $entry;
    }

    /**
     * Resolve latest versions, and optionally version options, for a small batch of assets.
     *
     * Remote lookups are capped so one admin-ajax request cannot run long enough to 504.
     * Assets left out of the result still need to be requested.
     *
     * @since 9.2.1
     *
     * @param string $asset_type       Asset type. Accepts plugin or theme.
     * @param array  $asset_keys       Asset keys. Only the first five are processed.
     * @param bool   $include_versions Whether to include rollback options for a single asset.
     * @return array
     */
    public function load_versions_batch( $asset_type, $asset_keys, $include_versions = false ) {
        if ( !in_array( $asset_type, array('plugin', 'theme'), true ) ) {
            return array();
        }
        $asset_keys = array_values( array_unique( array_filter( array_map( 'strval', (array) $asset_keys ), function ( $asset_key ) {
            return '' !== $asset_key;
        } ) ) );
        $asset_keys = array_slice( $asset_keys, 0, self::VERSION_BATCH_LIMIT );
        $include_versions = (bool) $include_versions && 1 === count( $asset_keys );
        $results = array();
        $started = microtime( true );
        $this->begin_short_api_timeout();
        try {
            foreach ( $asset_keys as $asset_key ) {
                $context = $this->get_asset_context(
                    $asset_type,
                    $asset_key,
                    null,
                    false
                );
                if ( empty( $context['asset_key'] ) ) {
                    $results[] = $this->empty_versions_payload( $asset_key, $include_versions );
                    continue;
                }
                if ( $this->context_needs_remote_lookup( $context, $include_versions ) && !empty( $results ) && microtime( true ) - $started >= self::VERSION_BATCH_TIME_BUDGET ) {
                    break;
                }
                $results[] = $this->build_versions_payload( $context, $include_versions );
            }
        } finally {
            $this->persist_version_cache();
            $this->end_short_api_timeout();
        }
        return $results;
    }

    /**
     * Payload for an asset that could not be resolved.
     *
     * @since 9.2.1
     *
     * @param string $asset_key        Asset key.
     * @param bool   $include_versions Whether the caller asked for version options.
     * @return array
     */
    private function empty_versions_payload( $asset_key, $include_versions ) {
        $payload = array(
            'asset_key'       => $asset_key,
            'latest_version'  => '',
            'latest_is_newer' => false,
            'status'          => 'failed',
        );
        if ( $include_versions ) {
            $payload['versions'] = array();
        }
        return $payload;
    }

    /**
     * Whether the next asset still needs a WordPress.org request.
     *
     * Cached latest versions and remembered failures are local reads.
     *
     * @since 9.2.1
     *
     * @param array $context          Asset context built without remote history.
     * @param bool  $include_versions Whether version options were requested.
     * @return bool
     */
    private function context_needs_remote_lookup( $context, $include_versions ) {
        if ( empty( $context['is_wordpress_org'] ) || empty( $context['wordpress_org_slug'] ) ) {
            return false;
        }
        if ( $include_versions ) {
            return true;
        }
        if ( null !== $this->get_cached_latest_version( $context['asset_type'], $context['wordpress_org_slug'] ) ) {
            return false;
        }
        if ( $this->is_version_lookup_failed( $context['asset_type'], $context['wordpress_org_slug'] ) ) {
            return false;
        }
        return true;
    }

    /**
     * Build the admin-ajax payload for one asset.
     *
     * @since 9.2.1
     *
     * @param array $context          Asset context built without remote history.
     * @param bool  $include_versions Whether to include rollback options.
     * @return array
     */
    private function build_versions_payload( $context, $include_versions ) {
        $asset_type = $context['asset_type'];
        $slug = ( isset( $context['wordpress_org_slug'] ) ? (string) $context['wordpress_org_slug'] : '' );
        $is_wporg = !empty( $context['is_wordpress_org'] ) && '' !== $slug;
        $latest = '';
        $status = 'ready';
        $remote = array();
        if ( $is_wporg ) {
            $cached_latest = $this->get_cached_latest_version( $asset_type, $slug );
            $lookup_failed = $this->is_version_lookup_failed( $asset_type, $slug );
            if ( !$include_versions && null !== $cached_latest ) {
                $latest = $cached_latest;
            } elseif ( !$include_versions && $lookup_failed ) {
                $status = 'failed';
            } else {
                $remote = $this->get_wordpress_org_versions( $asset_type, $slug );
                if ( is_wp_error( $remote ) || empty( $remote ) ) {
                    $this->remember_version_failure( $asset_type, $slug );
                    $status = 'failed';
                    $remote = array();
                    if ( null !== $cached_latest ) {
                        $latest = $cached_latest;
                    }
                } else {
                    $latest = $this->get_wordpress_org_latest_version( $asset_type, $slug );
                    $this->remember_latest_version( $asset_type, $slug, $latest );
                }
            }
        }
        $payload = array(
            'asset_key'       => $context['asset_key'],
            'latest_version'  => $latest,
            'latest_is_newer' => '' !== $latest && version_compare( $latest, (string) $context['current_version'], '>' ),
            'status'          => $status,
        );
        if ( $include_versions ) {
            $archives = ( isset( $context['archives'] ) && is_array( $context['archives'] ) ? $context['archives'] : array() );
            $merged = $this->merge_versions( (string) $context['current_version'], $remote, $archives );
            $payload['versions'] = $this->format_public_version_options( $merged );
            if ( !empty( $payload['versions'] ) ) {
                $payload['status'] = 'ready';
            }
        }
        return $payload;
    }

    /**
     * Version options safe to return to the browser.
     *
     * Package URLs stay on the server and are resolved again when a rollback is submitted.
     *
     * @since 9.2.1
     *
     * @param array $versions Merged version options.
     * @return array
     */
    private function format_public_version_options( $versions ) {
        $public = array();
        foreach ( $versions as $version_option ) {
            if ( !is_array( $version_option ) || empty( $version_option['version'] ) ) {
                continue;
            }
            $public[] = array(
                'version' => (string) $version_option['version'],
                'source'  => ( isset( $version_option['source'] ) ? (string) $version_option['source'] : '' ),
                'label'   => ( isset( $version_option['label'] ) ? (string) $version_option['label'] : (string) $version_option['version'] ),
            );
        }
        return $public;
    }

    /**
     * Option that stores latest versions and recent lookup failures.
     *
     * This stays separate from the full version-list transients so the rollback
     * table can render without unserializing those maps.
     *
     * @since 9.2.1
     *
     * @return string
     */
    private function get_version_cache_option_key() {
        return ASENHA_SLUG_U . '_rollback_latest_versions';
    }

    /**
     * Map key for one WordPress.org asset.
     *
     * @since 9.2.1
     *
     * @param string $asset_type Asset type.
     * @param string $wporg_slug WordPress.org slug.
     * @return string
     */
    private function get_version_cache_map_key( $asset_type, $wporg_slug ) {
        return $asset_type . '|' . $wporg_slug;
    }

    /**
     * Read the small latest-version cache once per request.
     *
     * @since 9.2.1
     *
     * @return array
     */
    private function get_version_cache() {
        if ( is_array( $this->version_cache ) ) {
            return $this->version_cache;
        }
        $stored = get_option( $this->get_version_cache_option_key(), array() );
        if ( !is_array( $stored ) ) {
            $stored = array();
        }
        if ( !isset( $stored['latest'] ) || !is_array( $stored['latest'] ) ) {
            $stored['latest'] = array();
        }
        if ( !isset( $stored['failures'] ) || !is_array( $stored['failures'] ) ) {
            $stored['failures'] = array();
        }
        $this->version_cache = $stored;
        return $this->version_cache;
    }

    /**
     * Latest stable version from the small cache.
     *
     * Null means the slug has not been cached, or the entry has expired.
     *
     * @since 9.2.1
     *
     * @param string $asset_type Asset type.
     * @param string $wporg_slug WordPress.org slug.
     * @return string|null
     */
    private function get_cached_latest_version( $asset_type, $wporg_slug ) {
        $cache = $this->get_version_cache();
        $key = $this->get_version_cache_map_key( $asset_type, $wporg_slug );
        if ( !isset( $cache['latest'][$key] ) || !is_array( $cache['latest'][$key] ) ) {
            return null;
        }
        $entry = $cache['latest'][$key];
        if ( empty( $entry['expires'] ) || (int) $entry['expires'] < time() ) {
            return null;
        }
        return ( isset( $entry['version'] ) ? (string) $entry['version'] : '' );
    }

    /**
     * Whether a WordPress.org lookup recently failed.
     *
     * @since 9.2.1
     *
     * @param string $asset_type Asset type.
     * @param string $wporg_slug WordPress.org slug.
     * @return bool
     */
    private function is_version_lookup_failed( $asset_type, $wporg_slug ) {
        $cache = $this->get_version_cache();
        $key = $this->get_version_cache_map_key( $asset_type, $wporg_slug );
        $expires = ( isset( $cache['failures'][$key] ) ? (int) $cache['failures'][$key] : 0 );
        return $expires >= time();
    }

    /**
     * Store a stable version string for the rollback table.
     *
     * @since 9.2.1
     *
     * @param string $asset_type    Asset type.
     * @param string $wporg_slug    WordPress.org slug.
     * @param string $latest_version Stable version.
     * @return void
     */
    private function remember_latest_version( $asset_type, $wporg_slug, $latest_version ) {
        $cache = $this->get_version_cache();
        $key = $this->get_version_cache_map_key( $asset_type, $wporg_slug );
        $cache['latest'][$key] = array(
            'version' => (string) $latest_version,
            'expires' => time() + self::LATEST_VERSION_TTL,
        );
        unset($cache['failures'][$key]);
        $this->version_cache = $cache;
        $this->version_cache_dirty = true;
    }

    /**
     * Remember a failed lookup so the next page view does not repeat it immediately.
     *
     * @since 9.2.1
     *
     * @param string $asset_type Asset type.
     * @param string $wporg_slug WordPress.org slug.
     * @return void
     */
    private function remember_version_failure( $asset_type, $wporg_slug ) {
        $cache = $this->get_version_cache();
        $key = $this->get_version_cache_map_key( $asset_type, $wporg_slug );
        $cache['failures'][$key] = time() + self::VERSION_FAILURE_TTL;
        $this->version_cache = $cache;
        $this->version_cache_dirty = true;
    }

    /**
     * Persist latest versions and drop expired entries.
     *
     * @since 9.2.1
     *
     * @return void
     */
    private function persist_version_cache() {
        if ( !$this->version_cache_dirty ) {
            return;
        }
        $cache = $this->get_version_cache();
        $now = time();
        foreach ( $cache['latest'] as $key => $entry ) {
            if ( !is_array( $entry ) || empty( $entry['expires'] ) || (int) $entry['expires'] < $now ) {
                unset($cache['latest'][$key]);
            }
        }
        foreach ( $cache['failures'] as $key => $expires ) {
            if ( (int) $expires < $now ) {
                unset($cache['failures'][$key]);
            }
        }
        $this->version_cache = $cache;
        $this->version_cache_dirty = false;
        update_option( $this->get_version_cache_option_key(), $cache, false );
    }

    /**
     * Apply a short timeout to WordPress.org API calls for the current batch.
     *
     * @since 9.2.1
     *
     * @return void
     */
    private function begin_short_api_timeout() {
        $this->limit_api_timeout = true;
        add_filter( 'plugins_api_timeout', array($this, 'filter_remote_api_timeout') );
        add_filter( 'themes_api_timeout', array($this, 'filter_remote_api_timeout') );
        add_filter(
            'http_request_args',
            array($this, 'filter_remote_http_args'),
            10,
            2
        );
    }

    /**
     * Remove the short WordPress.org API timeout.
     *
     * @since 9.2.1
     *
     * @return void
     */
    private function end_short_api_timeout() {
        $this->limit_api_timeout = false;
        remove_filter( 'plugins_api_timeout', array($this, 'filter_remote_api_timeout') );
        remove_filter( 'themes_api_timeout', array($this, 'filter_remote_api_timeout') );
        remove_filter( 'http_request_args', array($this, 'filter_remote_http_args'), 10 );
    }

    /**
     * Cap Plugin and Theme Installation API timeouts during a rollback batch.
     *
     * @since 9.2.1
     *
     * @param int $timeout Timeout in seconds.
     * @return int
     */
    public function filter_remote_api_timeout( $timeout ) {
        if ( $this->limit_api_timeout ) {
            return self::VERSION_API_TIMEOUT;
        }
        return $timeout;
    }

    /**
     * Cap HTTP timeouts for api.wordpress.org while a rollback batch is running.
     *
     * Theme requests do not always pass through themes_api_timeout, so this
     * filter covers both plugins and themes.
     *
     * @since 9.2.1
     *
     * @param array  $args Request arguments.
     * @param string $url  Request URL.
     * @return array
     */
    public function filter_remote_http_args( $args, $url ) {
        if ( !$this->limit_api_timeout || !is_string( $url ) ) {
            return $args;
        }
        if ( false === strpos( $url, 'api.wordpress.org' ) ) {
            return $args;
        }
        $args['timeout'] = self::VERSION_API_TIMEOUT;
        return $args;
    }

    /**
     * Get a cached WordPress.org version list.
     *
     * @param string $asset_type Asset type.
     * @param string $wporg_slug WordPress.org slug.
     * @return array|WP_Error
     */
    public function get_wordpress_org_versions( $asset_type, $wporg_slug ) {
        $transient_key = $this->get_wordpress_org_versions_transient_key( $asset_type, $wporg_slug );
        $cached = get_transient( $transient_key );
        if ( is_array( $cached ) ) {
            return $this->get_wordpress_org_versions_from_cache( $cached );
        }
        $latest = '';
        if ( 'plugin' === $asset_type ) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            $api = plugins_api( 'plugin_information', (object) array(
                'slug'   => $wporg_slug,
                'fields' => array(
                    'sections'          => false,
                    'tags'              => false,
                    'rating'            => false,
                    'ratings'           => false,
                    'downloaded'        => false,
                    'active_installs'   => false,
                    'homepage'          => false,
                    'icons'             => false,
                    'banners'           => false,
                    'contributors'      => false,
                    'donate_link'       => false,
                    'short_description' => false,
                    'versions'          => true,
                ),
            ) );
            if ( is_wp_error( $api ) ) {
                return $api;
            }
            $versions = ( isset( $api->versions ) && is_array( $api->versions ) ? $api->versions : array() );
            $latest = ( isset( $api->version ) ? (string) $api->version : '' );
        } else {
            require_once ABSPATH . 'wp-admin/includes/theme-install.php';
            $api = themes_api( 'theme_information', array(
                'slug'   => $wporg_slug,
                'fields' => array(
                    'description'     => false,
                    'sections'        => false,
                    'rating'          => false,
                    'ratings'         => false,
                    'downloaded'      => false,
                    'download_link'   => false,
                    'last_updated'    => false,
                    'homepage'        => false,
                    'tags'            => false,
                    'template'        => false,
                    'parent'          => false,
                    'screenshot_url'  => false,
                    'active_installs' => false,
                    'versions'        => true,
                ),
            ) );
            if ( is_wp_error( $api ) ) {
                return $api;
            }
            $versions = ( isset( $api->versions ) && is_array( $api->versions ) ? $api->versions : array() );
            $latest = ( isset( $api->version ) ? (string) $api->version : '' );
        }
        if ( '' === $latest || 'trunk' === $latest ) {
            $latest = $this->get_latest_version_from_map( $versions );
        }
        if ( !empty( $versions ) ) {
            set_transient( $transient_key, array(
                'latest'   => $latest,
                'versions' => $versions,
            ), 12 * HOUR_IN_SECONDS );
        }
        return $versions;
    }

    /**
     * Read the stable WordPress.org version stored with the version list.
     *
     * Older transients store only the version map. Those fall back to the
     * highest version key other than trunk.
     *
     * @param string $asset_type Asset type.
     * @param string $wporg_slug WordPress.org slug.
     * @return string
     */
    private function get_wordpress_org_latest_version( $asset_type, $wporg_slug ) {
        $cached = get_transient( $this->get_wordpress_org_versions_transient_key( $asset_type, $wporg_slug ) );
        if ( !is_array( $cached ) ) {
            return '';
        }
        if ( isset( $cached['versions'] ) && is_array( $cached['versions'] ) ) {
            $latest = ( isset( $cached['latest'] ) ? (string) $cached['latest'] : '' );
            if ( '' !== $latest && 'trunk' !== $latest ) {
                return $latest;
            }
            return $this->get_latest_version_from_map( $cached['versions'] );
        }
        return $this->get_latest_version_from_map( $cached );
    }

    /**
     * Transient key for a WordPress.org version list.
     *
     * @param string $asset_type Asset type.
     * @param string $wporg_slug WordPress.org slug.
     * @return string
     */
    private function get_wordpress_org_versions_transient_key( $asset_type, $wporg_slug ) {
        return 'asenha_rollback_versions_' . md5( $asset_type . '|' . $wporg_slug );
    }

    /**
     * Return the version map from either cache shape.
     *
     * @param array $cached Cached transient value.
     * @return array
     */
    private function get_wordpress_org_versions_from_cache( $cached ) {
        if ( isset( $cached['versions'] ) && is_array( $cached['versions'] ) ) {
            return $cached['versions'];
        }
        return $cached;
    }

    /**
     * Get the newest stable version from a WordPress.org version map.
     *
     * Trunk is a development build and is excluded.
     *
     * @param array $remote_versions Version map keyed by version string.
     * @return string
     */
    private function get_latest_version_from_map( $remote_versions ) {
        if ( !is_array( $remote_versions ) || empty( $remote_versions ) ) {
            return '';
        }
        $latest = '';
        foreach ( array_keys( $remote_versions ) as $version ) {
            $version = (string) $version;
            if ( '' === $version || 'trunk' === $version ) {
                continue;
            }
            if ( '' === $latest || version_compare( $version, $latest, '>' ) ) {
                $latest = $version;
            }
        }
        return $latest;
    }

    /**
     * Get a plugin update entry from plugin update transients.
     *
     * @param string     $plugin_file Plugin file.
     * @param stdClass   $updates     Plugin update transient.
     * @return object|null
     */
    private function get_plugin_update_entry( $plugin_file, $updates ) {
        if ( is_object( $updates ) ) {
            if ( isset( $updates->response[$plugin_file] ) ) {
                return $updates->response[$plugin_file];
            }
            if ( isset( $updates->no_update[$plugin_file] ) ) {
                return $updates->no_update[$plugin_file];
            }
        }
        return null;
    }

    /**
     * Get a theme update entry from theme update transients.
     *
     * @param string   $theme_slug Theme slug.
     * @param stdClass $updates    Theme update transient.
     * @return array
     */
    private function get_theme_update_entry( $theme_slug, $updates ) {
        if ( is_object( $updates ) ) {
            if ( isset( $updates->response[$theme_slug] ) && is_array( $updates->response[$theme_slug] ) ) {
                return $updates->response[$theme_slug];
            }
            if ( isset( $updates->no_update[$theme_slug] ) && is_array( $updates->no_update[$theme_slug] ) ) {
                return $updates->no_update[$theme_slug];
            }
        }
        return array();
    }

    /**
     * Determine the WordPress.org slug for a plugin.
     *
     * @param string      $plugin_file  Plugin file.
     * @param array       $plugin_data  Plugin data.
     * @param object|null $update_entry Update entry.
     * @param string      $package_slug Package slug.
     * @return string
     */
    private function get_plugin_wordpress_org_slug(
        $plugin_file,
        $plugin_data,
        $update_entry,
        $package_slug
    ) {
        if ( is_object( $update_entry ) && !empty( $update_entry->slug ) ) {
            return sanitize_title( $update_entry->slug );
        }
        if ( !empty( $plugin_data['PluginURI'] ) && false !== strpos( $plugin_data['PluginURI'], 'wordpress.org/plugins/' ) ) {
            $parts = wp_parse_url( $plugin_data['PluginURI'] );
            if ( !empty( $parts['path'] ) ) {
                $segments = array_values( array_filter( explode( '/', trim( $parts['path'], '/' ) ) ) );
                $index = array_search( 'plugins', $segments, true );
                if ( false !== $index && !empty( $segments[$index + 1] ) ) {
                    return sanitize_title( $segments[$index + 1] );
                }
            }
        }
        return sanitize_title( $package_slug );
    }

    /**
     * Determine the WordPress.org slug for a theme.
     *
     * @param string   $theme_slug    Theme slug.
     * @param WP_Theme $theme         Theme object.
     * @param array    $update_entry  Update entry.
     * @return string
     */
    private function get_theme_wordpress_org_slug( $theme_slug, $theme, $update_entry ) {
        if ( !empty( $update_entry['theme'] ) ) {
            return sanitize_title( $update_entry['theme'] );
        }
        if ( !empty( $update_entry['slug'] ) ) {
            return sanitize_title( $update_entry['slug'] );
        }
        $theme_uri = $theme->get( 'ThemeURI' );
        if ( $theme_uri && false !== strpos( $theme_uri, 'wordpress.org/themes/' ) ) {
            $parts = wp_parse_url( $theme_uri );
            if ( !empty( $parts['path'] ) ) {
                $segments = array_values( array_filter( explode( '/', trim( $parts['path'], '/' ) ) ) );
                $index = array_search( 'themes', $segments, true );
                if ( false !== $index && !empty( $segments[$index + 1] ) ) {
                    return sanitize_title( $segments[$index + 1] );
                }
            }
        }
        return sanitize_title( $theme_slug );
    }

    /**
     * Determine whether a plugin is hosted on WordPress.org.
     *
     * @param array       $plugin_data  Plugin data.
     * @param object|null $update_entry Update entry.
     * @param string      $wordpress_slug WordPress.org slug.
     * @return bool
     */
    private function is_plugin_wordpress_org( $plugin_data, $update_entry, $wordpress_slug ) {
        if ( is_object( $update_entry ) ) {
            if ( !empty( $update_entry->id ) && false !== strpos( (string) $update_entry->id, 'w.org/plugins/' ) ) {
                return true;
            }
            if ( !empty( $update_entry->package ) && $this->helper->is_wordpress_org_url( $update_entry->package ) ) {
                return true;
            }
            if ( !empty( $update_entry->url ) && false !== strpos( (string) $update_entry->url, 'wordpress.org/plugins/' ) ) {
                return true;
            }
        }
        if ( !empty( $plugin_data['PluginURI'] ) && false !== strpos( $plugin_data['PluginURI'], 'wordpress.org/plugins/' ) ) {
            return true;
        }
        return false;
    }

    /**
     * Determine whether a theme is hosted on WordPress.org.
     *
     * @param WP_Theme $theme         Theme object.
     * @param array    $update_entry  Update entry.
     * @param string   $wordpress_slug WordPress.org slug.
     * @return bool
     */
    private function is_theme_wordpress_org( $theme, $update_entry, $wordpress_slug ) {
        if ( !empty( $update_entry['package'] ) && $this->helper->is_wordpress_org_url( $update_entry['package'] ) ) {
            return true;
        }
        if ( !empty( $update_entry['url'] ) && false !== strpos( (string) $update_entry['url'], 'wordpress.org/themes/' ) ) {
            return true;
        }
        $theme_uri = $theme->get( 'ThemeURI' );
        if ( $theme_uri && false !== strpos( $theme_uri, 'wordpress.org/themes/' ) ) {
            return true;
        }
        return false;
    }

    /**
     * Infer a plugin context from an unpacked source directory.
     *
     * @param string $source Unpacked source path.
     * @return array
     */
    private function infer_plugin_from_source( $source ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = get_plugins();
        $source_slug = sanitize_title( basename( untrailingslashit( $source ) ) );
        $plugin_file = $this->detect_plugin_file_from_source( $source );
        $file_slug = ( '' !== $plugin_file ? sanitize_title( basename( $plugin_file, '.php' ) ) : '' );
        foreach ( $plugins as $installed_plugin => $plugin_data ) {
            $package_slug = $this->helper->get_plugin_package_slug( $installed_plugin, $plugin_data );
            if ( $source_slug === $package_slug || '' !== $file_slug && $file_slug === sanitize_title( basename( $installed_plugin, '.php' ) ) ) {
                return $this->get_plugin_context( $installed_plugin, $plugin_data );
            }
        }
        return array();
    }

    /**
     * Infer a theme context from an unpacked source directory.
     *
     * @param string $source Unpacked source path.
     * @return array
     */
    private function infer_theme_from_source( $source ) {
        $theme_slug = sanitize_title( basename( untrailingslashit( $source ) ) );
        $theme = wp_get_theme( $theme_slug );
        if ( $theme && $theme->exists() ) {
            return $this->get_theme_context( $theme_slug, $theme );
        }
        return array();
    }

    /**
     * Detect the main plugin file from an unpacked source directory.
     *
     * @param string $source Unpacked source path.
     * @return string
     */
    private function detect_plugin_file_from_source( $source ) {
        $source = untrailingslashit( $source );
        if ( !is_dir( $source ) ) {
            return '';
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ( $iterator as $file_info ) {
            if ( !$file_info->isFile() || 'php' !== strtolower( $file_info->getExtension() ) ) {
                continue;
            }
            $plugin_headers = get_file_data( $file_info->getPathname(), array(
                'Name' => 'Plugin Name',
            ), 'plugin' );
            if ( !empty( $plugin_headers['Name'] ) ) {
                return $file_info->getFilename();
            }
        }
        return '';
    }

}
