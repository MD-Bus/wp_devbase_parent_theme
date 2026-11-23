<?php
/**
 * User Role Manager: capability catalog.
 *
 * Builds the logical grouping of capabilities shown in the editor: core groups,
 * post type and taxonomy matrices, third-party capabilities attributed to the
 * plugin they most likely belong to, custom capabilities registered through this
 * module, and unrecognized capabilities (found on roles, but not known to any of
 * the above).
 *
 * @package ASENHA\UserRoleManager
 * @since   9.2.0
 */

namespace ASENHA\UserRoleManager;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Capability catalog.
 */
class Role_Manager_Capability_Catalog {

	/**
	 * Cached core definitions.
	 *
	 * @var array|null
	 */
	private static $definitions = null;

	/**
	 * Cached baseline of WordPress default roles.
	 *
	 * @var array|null
	 */
	private static $baseline = null;

	/**
	 * Get the core capability definitions (groups, essentials, sensitive list, matrix columns).
	 *
	 * @return array
	 */
	public static function get_definitions() {
		if ( null === self::$definitions ) {
			self::$definitions = include ASENHA_USER_ROLE_MANAGER_PATH . 'data/core-capabilities.php';
		}

		return self::$definitions;
	}

	/**
	 * Get the WordPress default roles and their capabilities.
	 *
	 * @return array Role slug => array( name, caps ) where caps is a list of capability slugs.
	 */
	public static function get_baseline() {
		if ( null === self::$baseline ) {
			self::$baseline = include ASENHA_USER_ROLE_MANAGER_PATH . 'data/core-roles-baseline.php';
		}

		return self::$baseline;
	}

	/**
	 * Get the baseline capabilities of a core role as a capability => true map.
	 *
	 * @param string $slug Core role slug.
	 * @return array Empty when the role is not a core role.
	 */
	public static function get_baseline_caps( $slug ) {
		$baseline = self::get_baseline();

		if ( ! isset( $baseline[ $slug ] ) ) {
			return array();
		}

		return array_fill_keys( $baseline[ $slug ]['caps'], true );
	}

	/**
	 * Turn a capability slug into a readable label.
	 *
	 * @param string $cap Capability slug.
	 * @return string
	 */
	public static function humanize( $cap ) {
		$label = trim( str_replace( array( '_', '-' ), ' ', $cap ) );

		return '' === $label ? $cap : ucfirst( $label );
	}

	/**
	 * Whether a capability is considered sensitive.
	 *
	 * @param string $cap Capability slug.
	 * @return bool
	 */
	public static function is_sensitive( $cap ) {
		$definitions = self::get_definitions();
		$sensitive   = $definitions['sensitive'];

		if ( in_array( $cap, $sensitive, true ) ) {
			$is_sensitive = true;
		} else {
			// Capabilities that control role or capability management of other plugins.
			$is_sensitive = 1 === preg_match( '/^(ure_|edit_roles$|create_roles$|delete_roles$|manage_capabilities)/', $cap );
		}

		/**
		 * Filters whether a capability is sensitive.
		 *
		 * Granting a sensitive capability to a non-administrator role asks for confirmation.
		 *
		 * @since 9.2.0
		 *
		 * @param bool   $is_sensitive Whether the capability is sensitive.
		 * @param string $cap          Capability slug.
		 */
		return (bool) apply_filters( 'asenha_role_manager_is_sensitive_cap', $is_sensitive, $cap );
	}

	/**
	 * Build a capability entry.
	 *
	 * @param string $cap   Capability slug.
	 * @param string $label Optional label.
	 * @return array
	 */
	private static function cap_entry( $cap, $label = '' ) {
		return array(
			'cap'       => $cap,
			'label'     => '' !== $label ? $label : self::humanize( $cap ),
			'sensitive' => self::is_sensitive( $cap ),
		);
	}

	/**
	 * Build the catalog.
	 *
	 * @param array $roles_data Role slug => array( name, capabilities ) currently stored, used to find third-party and unrecognized capabilities.
	 * @return array
	 */
	public static function get_catalog( array $roles_data ) {
		$definitions = self::get_definitions();
		$known       = array();
		$groups      = array();

		// Core groups.
		foreach ( $definitions['groups'] as $group_id => $group ) {
			if ( ! empty( $group['multisite'] ) && ! is_multisite() ) {
				continue;
			}

			$caps = array();

			foreach ( $group['caps'] as $cap => $label ) {
				$caps[]        = self::cap_entry( $cap, $label );
				$known[ $cap ] = true;
			}

			$groups[] = array(
				'id'          => $group_id,
				'label'       => $group['label'],
				'description' => $group['description'],
				'caps'        => $caps,
			);
		}

		// Legacy user levels (hidden by default in the interface).
		$legacy_caps = array();

		for ( $level = 0; $level <= 10; $level++ ) {
			$cap           = 'level_' . $level;
			$known[ $cap ] = true;
			$legacy_caps[] = array(
				'cap'       => $cap,
				'label'     => sprintf(
					/* translators: %d is a legacy user level number from 0 to 10 */
					__( 'User level %d (legacy)', 'admin-site-enhancements' ),
					$level
				),
				'sensitive' => false,
			);
		}

		$groups[] = array(
			'id'          => 'legacy',
			'label'       => __( 'Legacy user levels', 'admin-site-enhancements' ),
			'description' => __( 'Deprecated capabilities from very old WordPress versions. Only change these if you know you need them.', 'admin-site-enhancements' ),
			'hidden'      => true,
			'caps'        => $legacy_caps,
		);

		// Post types.
		$post_types = self::get_post_type_matrix( $definitions['post_type_columns'], $known );

		// Taxonomies.
		$taxonomies = self::get_taxonomy_matrix( $definitions['taxonomy_columns'], $known );

		// Custom capabilities registered through this module.
		$custom_caps = array();

		foreach ( Role_Manager_Storage::get_custom_caps() as $cap => $details ) {
			$custom_caps[]     = array(
				'cap'         => $cap,
				'label'       => isset( $details['label'] ) && '' !== $details['label'] ? $details['label'] : self::humanize( $cap ),
				'description' => isset( $details['description'] ) ? $details['description'] : '',
				'sensitive'   => self::is_sensitive( $cap ),
			);
			$known[ $cap ]     = true;
		}

		// Capabilities found on roles that no built-in group knows about.
		$leftover_caps = array();

		foreach ( $roles_data as $role ) {
			foreach ( array_keys( (array) $role['capabilities'] ) as $cap ) {
				if ( ! isset( $known[ $cap ] ) ) {
					$leftover_caps[ $cap ] = true;
				}
			}
		}

		$third_party  = array();
		$unrecognized = array();

		foreach ( array_keys( $leftover_caps ) as $cap ) {
			$owner = self::attribute_third_party( (string) $cap );

			if ( '' === $owner ) {
				$unrecognized[] = self::cap_entry( (string) $cap );
			} else {
				$third_party[ $owner ][] = self::cap_entry( (string) $cap );
			}
		}

		ksort( $third_party );
		usort(
			$unrecognized,
			static function ( $a, $b ) {
				return strcmp( $a['cap'], $b['cap'] );
			}
		);

		$third_party_groups = array();

		foreach ( $third_party as $owner => $caps ) {
			usort(
				$caps,
				static function ( $a, $b ) {
					return strcmp( $a['cap'], $b['cap'] );
				}
			);

			$third_party_groups[] = array(
				'id'    => 'plugin-' . sanitize_key( $owner ),
				'label' => $owner,
				'caps'  => $caps,
			);
		}

		// Essentials with their sensitive flag.
		$essentials = array();

		foreach ( $definitions['essentials'] as $section ) {
			$items = array();

			foreach ( $section['items'] as $item ) {
				$item['sensitive'] = false;

				foreach ( $item['caps'] as $cap ) {
					if ( self::is_sensitive( $cap ) ) {
						$item['sensitive'] = true;
						break;
					}
				}

				$items[] = $item;
			}

			$essentials[] = array(
				'id'    => $section['id'],
				'label' => $section['label'],
				'items' => $items,
			);
		}

		$baseline_caps = array();

		foreach ( self::get_baseline() as $slug => $details ) {
			$baseline_caps[ $slug ] = $details['caps'];
		}

		return array(
			'groups'           => $groups,
			'postTypes'        => $post_types['items'],
			'postTypeColumns'  => $post_types['columns'],
			'taxonomies'       => $taxonomies['items'],
			'taxonomyColumns'  => $taxonomies['columns'],
			'thirdParty'       => $third_party_groups,
			'custom'           => $custom_caps,
			'unrecognized'     => $unrecognized,
			'essentials'       => $essentials,
			'protectedAdmin'   => Role_Manager_Guard::get_protected_admin_caps(),
			'baseline'         => $baseline_caps,
		);
	}

	/**
	 * Remember the first object with a capability map, and name it when a later object matches.
	 *
	 * Matching uses the slug so two objects with the same label stay separate unless their maps match.
	 *
	 * @param array  $signatures Maps already seen, updated by reference. Signature => array( label, slug ).
	 * @param string $signature  JSON capability map.
	 * @param string $label      Object label.
	 * @param string $slug       Object slug.
	 * @return array{label: string, slug: string} Lead label and slug, or empty strings for the lead itself.
	 */
	private static function capability_share( array &$signatures, $signature, $label, $slug ) {
		if ( isset( $signatures[ $signature ] ) ) {
			return $signatures[ $signature ];
		}

		$signatures[ $signature ] = array(
			'label' => $label,
			'slug'  => $slug,
		);

		return array(
			'label' => '',
			'slug'  => '',
		);
	}

	/**
	 * Build the post type matrix.
	 *
	 * @param array $columns Column definitions.
	 * @param array $known   Known capabilities, updated by reference.
	 * @return array
	 */
	private static function get_post_type_matrix( array $columns, array &$known ) {
		$column_list = array();

		foreach ( $columns as $key => $column ) {
			$column_list[] = array(
				'key'   => $key,
				'label' => $column[1],
			);
		}

		$items      = array();
		$signatures = array();

		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $post_type ) {
			if ( empty( $post_type->cap ) ) {
				continue;
			}

			$caps = array();

			foreach ( $columns as $key => $column ) {
				$cap_key = $column[0];

				if ( ! isset( $post_type->cap->$cap_key ) ) {
					continue;
				}

				$raw_cap = $post_type->cap->$cap_key;

				if ( empty( $raw_cap ) || 'do_not_allow' === $raw_cap ) {
					continue;
				}

				$resolved = self::resolve_mapped_cap( $raw_cap );

				$caps[ $key ]       = $resolved;
				$known[ $raw_cap ]  = true;
				$known[ $resolved ] = true;
			}

			if ( empty( $caps ) ) {
				continue;
			}

			$share   = self::capability_share( $signatures, wp_json_encode( $caps ), $post_type->labels->name, $post_type->name );
			$items[] = array(
				'slug'       => $post_type->name,
				'label'      => $post_type->labels->name,
				'shares'     => $share['label'],
				'sharesSlug' => $share['slug'],
				'caps'       => $caps,
			);
		}

		return array(
			'columns' => $column_list,
			'items'   => $items,
		);
	}

	/**
	 * Build the taxonomy matrix.
	 *
	 * @param array $columns Column definitions.
	 * @param array $known   Known capabilities, updated by reference.
	 * @return array
	 */
	private static function get_taxonomy_matrix( array $columns, array &$known ) {
		$column_list = array();

		foreach ( $columns as $key => $column ) {
			$column_list[] = array(
				'key'   => $key,
				'label' => $column[1],
			);
		}

		$items      = array();
		$signatures = array();

		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->cap ) ) {
				continue;
			}

			$caps = array();

			foreach ( $columns as $key => $column ) {
				$cap_key = $column[0];

				if ( ! isset( $taxonomy->cap->$cap_key ) ) {
					continue;
				}

				$raw_cap = $taxonomy->cap->$cap_key;

				if ( empty( $raw_cap ) || 'do_not_allow' === $raw_cap ) {
					continue;
				}

				$resolved = self::resolve_mapped_cap( $raw_cap );

				$caps[ $key ]       = $resolved;
				$known[ $raw_cap ]  = true;
				$known[ $resolved ] = true;
			}

			if ( empty( $caps ) ) {
				continue;
			}

			$share   = self::capability_share( $signatures, wp_json_encode( $caps ), $taxonomy->labels->name, $taxonomy->name );
			$items[] = array(
				'slug'       => $taxonomy->name,
				'label'      => $taxonomy->labels->name,
				'shares'     => $share['label'],
				'sharesSlug' => $share['slug'],
				'caps'       => $caps,
			);
		}

		return array(
			'columns' => $column_list,
			'items'   => $items,
		);
	}

	/**
	 * Resolve a matrix capability to the primitive WordPress actually checks.
	 *
	 * Some registered names are meta capabilities. Category and tag names such as
	 * edit_categories and manage_post_tags map to manage_categories or edit_posts.
	 * Pattern names such as publish_blocks map to the matching post capability.
	 * A single mapped capability is used so the matrix checkbox grants and revokes
	 * the capability that current_user_can() checks. Any other result keeps the
	 * original name.
	 *
	 * @param string $cap Capability stored on the post type or taxonomy object.
	 * @return string
	 */
	private static function resolve_mapped_cap( $cap ) {
		if ( ! is_string( $cap ) || '' === $cap || ! function_exists( 'map_meta_cap' ) ) {
			return $cap;
		}

		$mapped = map_meta_cap( $cap, 0 );

		if (
			! is_array( $mapped )
			|| 1 !== count( $mapped )
			|| ! is_string( $mapped[0] )
			|| '' === $mapped[0]
			|| 'do_not_allow' === $mapped[0]
		) {
			return $cap;
		}

		return $mapped[0];
	}

	/**
	 * Attribute a capability to the plugin it most likely belongs to.
	 *
	 * First the known patterns are tried, then the folder names of active plugins
	 * are matched against the words of the capability.
	 *
	 * @param string $cap Capability slug.
	 * @return string Plugin label, or an empty string when the capability is unrecognized.
	 */
	public static function attribute_third_party( $cap ) {
		$definitions = self::get_definitions();

		/**
		 * Filters the regular expressions used to attribute capabilities to plugins.
		 *
		 * @since 9.2.0
		 *
		 * @param array $patterns Regular expression => plugin label.
		 */
		$patterns = apply_filters( 'asenha_role_manager_third_party_patterns', $definitions['third_party_patterns'] );

		foreach ( (array) $patterns as $pattern => $label ) {
			if ( 1 === preg_match( $pattern, $cap ) ) {
				return (string) $label;
			}
		}

		foreach ( self::get_active_plugin_tokens() as $token => $label ) {
			if ( 1 === preg_match( '/(^|_)' . preg_quote( $token, '/' ) . '(_|$)/', $cap ) ) {
				return $label;
			}
		}

		return '';
	}

	/**
	 * Get lookup tokens derived from the folder names of active plugins.
	 *
	 * @return array Token => plugin name.
	 */
	private static function get_active_plugin_tokens() {
		static $tokens = null;

		if ( null !== $tokens ) {
			return $tokens;
		}

		$tokens = array();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$all_plugins = get_plugins();
		$stop_words  = array( 'wp', 'wordpress', 'admin', 'site', 'simple', 'advanced', 'the', 'custom', 'pro', 'lite', 'free', 'plugin', 'plugins', 'user', 'users', 'role', 'roles', 'page', 'pages', 'post', 'posts', 'media', 'theme', 'themes', 'edit', 'manage', 'read', 'delete', 'publish', 'export', 'import', 'options', 'settings' );

		foreach ( $active_plugins as $plugin_file ) {
			if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
				continue;
			}

			$folder = dirname( $plugin_file );
			$folder = '.' === $folder ? basename( $plugin_file, '.php' ) : $folder;
			$full   = str_replace( '-', '_', strtolower( $folder ) );
			$name   = $all_plugins[ $plugin_file ]['Name'];
			$first  = explode( '_', $full )[0];

			if ( strlen( $full ) >= 4 && ! in_array( $full, $stop_words, true ) ) {
				$tokens[ $full ] = $name;
			}

			if ( strlen( $first ) >= 4 && ! in_array( $first, $stop_words, true ) && ! isset( $tokens[ $first ] ) ) {
				$tokens[ $first ] = $name;
			}
		}

		return $tokens;
	}
}
