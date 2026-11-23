<?php
/**
 * User Role Manager: storage.
 *
 * Role metadata, custom capabilities, and migration state live in the
 * `user_role_manager` slice of the autoloaded `admin_site_enhancements_extra`
 * option. This class is the only place that reads or writes that slice: each
 * write re-reads the option, replaces only this module's key, and saves it, so
 * data from other modules is never overwritten.
 *
 * Undo history and pinned snapshots live in the non-autoloaded
 * `admin_site_enhancements_role_manager` option. A full snapshot of every role
 * is too large to load on every request.
 *
 * Extra slice layout:
 * - roles_meta  (free) role slug => array( description, source, created ).
 * - custom_caps (free) capability => array( label, description ).
 * - migration   (Pro)  migration state and results.
 *
 * Role-manager option layout:
 * - history     (Pro)  reverse-patch undo entries.
 * - pinned      (Pro)  named full snapshots.
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
 * Storage for the User Role Manager module.
 */
class Role_Manager_Storage {

	/**
	 * Key of this module's slice inside the `admin_site_enhancements_extra` option.
	 */
	const SLICE_KEY = 'user_role_manager';

	/**
	 * Keys stored in the non-autoloaded role-manager option.
	 *
	 * @var string[]
	 */
	const ARCHIVE_KEYS = array(
		'history',
		'pinned',
	);

	/**
	 * Read the whole `admin_site_enhancements_extra` option as an array.
	 *
	 * @return array
	 */
	private static function read_extra_option() {
		$option_name = ASENHA_SLUG_U . '_extra';

		if ( function_exists( 'asenha_get_option_array' ) ) {
			return asenha_get_option_array( $option_name );
		}

		$options_extra = get_option( $option_name, array() );

		return is_array( $options_extra ) ? $options_extra : array();
	}

	/**
	 * Get this module's whole slice.
	 *
	 * @return array
	 */
	public static function get_slice() {
		$options_extra = self::read_extra_option();

		if ( isset( $options_extra[ self::SLICE_KEY ] ) && is_array( $options_extra[ self::SLICE_KEY ] ) ) {
			return $options_extra[ self::SLICE_KEY ];
		}

		return array();
	}

	/**
	 * Get a value from the slice.
	 *
	 * @param string $key     Slice key, e.g. `roles_meta`.
	 * @param mixed  $default Value to return when the key is not set.
	 * @return mixed
	 */
	public static function get( $key, $default = array() ) {
		if ( self::is_archive_key( $key ) ) {
			$archive = self::get_or_migrate_archive();

			return array_key_exists( $key, $archive ) ? $archive[ $key ] : $default;
		}

		$slice = self::get_slice();

		return array_key_exists( $key, $slice ) ? $slice[ $key ] : $default;
	}

	/**
	 * Set a value in the slice.
	 *
	 * @param string $key   Slice key.
	 * @param mixed  $value Value to store. Passing null removes the key.
	 * @return bool True when the option was saved or did not need to change.
	 */
	public static function set( $key, $value ) {
		return self::mutate(
			$key,
			static function () use ( $value ) {
				return $value;
			}
		);
	}

	/**
	 * Read-modify-write a single key of the slice.
	 *
	 * The option is re-read immediately before writing so that changes made by
	 * other modules in the meantime are preserved.
	 *
	 * @param string   $key      Slice key.
	 * @param callable $callback Receives the current value (array by default) and returns the new value. Return null to remove the key.
	 * @return bool
	 */
	public static function mutate( $key, callable $callback ) {
		if ( self::is_archive_key( $key ) ) {
			return self::mutate_archive( $key, $callback );
		}

		$options_extra = self::read_extra_option();
		$slice         = isset( $options_extra[ self::SLICE_KEY ] ) && is_array( $options_extra[ self::SLICE_KEY ] ) ? $options_extra[ self::SLICE_KEY ] : array();
		$current       = array_key_exists( $key, $slice ) ? $slice[ $key ] : array();
		$new_value     = call_user_func( $callback, $current );

		if ( null === $new_value ) {
			unset( $slice[ $key ] );
		} else {
			$slice[ $key ] = $new_value;
		}

		$slice = self::omit_archive_keys_when_archive_exists( $slice );

		if ( empty( $slice ) ) {
			unset( $options_extra[ self::SLICE_KEY ] );
		} else {
			$options_extra[ self::SLICE_KEY ] = $slice;
		}

		$option_name = ASENHA_SLUG_U . '_extra';

		// update_option() returns false when nothing changed, which is not a failure.
		$saved = update_option( $option_name, $options_extra, true );

		if ( ! $saved ) {
			return self::read_extra_option() === $options_extra;
		}

		return true;
	}

	/**
	 * Get the descriptive metadata of roles.
	 *
	 * @return array Role slug => array( description, source, created ).
	 */
	public static function get_roles_meta() {
		$roles_meta = self::get( 'roles_meta', array() );

		return is_array( $roles_meta ) ? $roles_meta : array();
	}

	/**
	 * Update the metadata of one role.
	 *
	 * @param string     $slug Role slug.
	 * @param array|null $meta Metadata to merge into the existing one, or null to remove all metadata of the role.
	 * @return bool
	 */
	public static function update_role_meta( $slug, $meta ) {
		return self::mutate(
			'roles_meta',
			static function ( $roles_meta ) use ( $slug, $meta ) {
				$roles_meta = is_array( $roles_meta ) ? $roles_meta : array();

				if ( null === $meta ) {
					unset( $roles_meta[ $slug ] );
				} else {
					$existing            = isset( $roles_meta[ $slug ] ) && is_array( $roles_meta[ $slug ] ) ? $roles_meta[ $slug ] : array();
					$roles_meta[ $slug ] = array_merge( $existing, $meta );
				}

				// Drop empty entries so the autoloaded slice stays small.
				foreach ( $roles_meta as $role_slug => $role_meta ) {
					if ( empty( array_filter( (array) $role_meta ) ) ) {
						unset( $roles_meta[ $role_slug ] );
					}
				}

				return empty( $roles_meta ) ? null : $roles_meta;
			}
		);
	}

	/**
	 * Get custom capabilities registered through this module.
	 *
	 * @return array Capability => array( label, description ).
	 */
	public static function get_custom_caps() {
		$custom_caps = self::get( 'custom_caps', array() );

		return is_array( $custom_caps ) ? $custom_caps : array();
	}

	/**
	 * Register a custom capability.
	 *
	 * @param string $cap         Capability slug.
	 * @param string $label       Human-readable label.
	 * @param string $description Optional description.
	 * @return bool
	 */
	public static function add_custom_cap( $cap, $label, $description = '' ) {
		return self::mutate(
			'custom_caps',
			static function ( $custom_caps ) use ( $cap, $label, $description ) {
				$custom_caps         = is_array( $custom_caps ) ? $custom_caps : array();
				$custom_caps[ $cap ] = array(
					'label'       => $label,
					'description' => $description,
				);

				return $custom_caps;
			}
		);
	}

	/**
	 * Forget custom capabilities.
	 *
	 * @param array $caps Capability slugs.
	 * @return bool
	 */
	public static function remove_custom_caps( array $caps ) {
		return self::mutate(
			'custom_caps',
			static function ( $custom_caps ) use ( $caps ) {
				$custom_caps = is_array( $custom_caps ) ? $custom_caps : array();

				foreach ( $caps as $cap ) {
					unset( $custom_caps[ $cap ] );
				}

				return empty( $custom_caps ) ? null : $custom_caps;
			}
		);
	}

	/**
	 * Get the size, in bytes, of the serialized slice.
	 *
	 * @return int
	 */
	public static function get_slice_size() {
		return strlen( maybe_serialize( self::get_slice() ) );
	}

	/**
	 * Move history and pinned snapshots out of the extra slice, or drop leftover copies.
	 *
	 * Safe to call on every wp-admin request. Once the role-manager option exists,
	 * leftover keys on the extra slice are removed and are not copied again.
	 *
	 * @since 9.2.0
	 *
	 * @return void
	 */
	public static function migrate_role_archive() {
		$archive = get_option( self::get_archive_option_name(), null );

		if ( null === $archive ) {
			self::migrate_archive_from_extra();
			return;
		}

		self::strip_archive_keys_from_extra();
	}

	/**
	 * Whether a key belongs in the role-manager option.
	 *
	 * @param string $key Slice key.
	 * @return bool
	 */
	private static function is_archive_key( $key ) {
		return in_array( $key, self::ARCHIVE_KEYS, true );
	}

	/**
	 * Option name for history and pinned snapshots. Not autoloaded.
	 *
	 * @return string
	 */
	private static function get_archive_option_name() {
		return ASENHA_SLUG_U . '_role_manager';
	}

	/**
	 * Read the role-manager option, or move history and pinned snapshots into it once.
	 *
	 * @return array
	 */
	private static function get_or_migrate_archive() {
		$archive = get_option( self::get_archive_option_name(), null );

		if ( null !== $archive ) {
			return is_array( $archive ) ? $archive : array();
		}

		return self::migrate_archive_from_extra();
	}

	/**
	 * Copy history and pinned snapshots into their own option and remove them from the extra slice.
	 *
	 * @return array
	 */
	private static function migrate_archive_from_extra() {
		$options_extra = self::read_extra_option();
		$slice         = isset( $options_extra[ self::SLICE_KEY ] ) && is_array( $options_extra[ self::SLICE_KEY ] ) ? $options_extra[ self::SLICE_KEY ] : array();
		$archive       = array();
		$moved         = false;

		foreach ( self::ARCHIVE_KEYS as $archive_key ) {
			if ( isset( $slice[ $archive_key ] ) && is_array( $slice[ $archive_key ] ) ) {
				$archive[ $archive_key ] = $slice[ $archive_key ];
			}

			if ( array_key_exists( $archive_key, $slice ) ) {
				unset( $slice[ $archive_key ] );
				$moved = true;
			}
		}

		update_option( self::get_archive_option_name(), $archive, false );

		if ( $moved ) {
			self::write_extra_slice( $options_extra, $slice );
		}

		return $archive;
	}

	/**
	 * Remove history and pinned snapshots from the extra slice without reading them into the role-manager option.
	 *
	 * @return void
	 */
	private static function strip_archive_keys_from_extra() {
		$options_extra = self::read_extra_option();
		$slice         = isset( $options_extra[ self::SLICE_KEY ] ) && is_array( $options_extra[ self::SLICE_KEY ] ) ? $options_extra[ self::SLICE_KEY ] : array();
		$changed       = false;

		foreach ( self::ARCHIVE_KEYS as $archive_key ) {
			if ( array_key_exists( $archive_key, $slice ) ) {
				unset( $slice[ $archive_key ] );
				$changed = true;
			}
		}

		if ( ! $changed ) {
			return;
		}

		self::write_extra_slice( $options_extra, $slice );
	}

	/**
	 * Drop archive keys from a slice write once those keys have their own option.
	 *
	 * @param array $slice Module slice.
	 * @return array
	 */
	private static function omit_archive_keys_when_archive_exists( $slice ) {
		if ( null === get_option( self::get_archive_option_name(), null ) ) {
			return $slice;
		}

		foreach ( self::ARCHIVE_KEYS as $archive_key ) {
			unset( $slice[ $archive_key ] );
		}

		return $slice;
	}

	/**
	 * Save the module slice back onto the autoloaded extra option.
	 *
	 * @param array $options_extra Whole extra option.
	 * @param array $slice         Module slice, without history or pinned snapshots when those have moved.
	 * @return void
	 */
	private static function write_extra_slice( $options_extra, $slice ) {
		if ( empty( $slice ) ) {
			unset( $options_extra[ self::SLICE_KEY ] );
		} else {
			$options_extra[ self::SLICE_KEY ] = $slice;
		}

		update_option( ASENHA_SLUG_U . '_extra', $options_extra, true );
	}

	/**
	 * Read-modify-write one key of the role-manager option.
	 *
	 * @param string   $key      `history` or `pinned`.
	 * @param callable $callback Receives the current value and returns the new value. Return null to remove the key.
	 * @return bool
	 */
	private static function mutate_archive( $key, callable $callback ) {
		$archive   = self::get_or_migrate_archive();
		$current   = array_key_exists( $key, $archive ) ? $archive[ $key ] : array();
		$new_value = call_user_func( $callback, $current );

		if ( null === $new_value ) {
			unset( $archive[ $key ] );
		} else {
			$archive[ $key ] = $new_value;
		}

		$option_name = self::get_archive_option_name();
		$saved       = update_option( $option_name, $archive, false );

		if ( ! $saved ) {
			return get_option( $option_name, null ) === $archive;
		}

		return true;
	}
}
