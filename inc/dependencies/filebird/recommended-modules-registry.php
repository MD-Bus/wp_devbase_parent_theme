<?php

// An empty stand-in for the registry FileBird's "recommended modules" (YayMail adverts) use. See filebird.php: loaded
// only when no other plugin has the real one, so FileBird's modules register into nothing and nothing is logged.
// FileBird's own registry.php declares the real class only when this name is still free, so it leaves this one in place.

namespace YayRecommendedModules;

defined( 'ABSPATH' ) || exit;

class Registry {

	/** Takes the module and does nothing with it. */
	public static function register( string $name, string $version, string $init_path ): void {
	}

	public static function active_version( string $name ): ?string {
		return null;
	}

	public static function active_path( string $name ): ?string {
		return null;
	}

	public static function register_ads_consumer( string $consumer_slug, string $ad_slug ): void {
	}

	public static function get_ads_consumers( string $ad_slug ): array {
		return array();
	}

	public static function is_ads_consumer( string $consumer_slug ): bool {
		return false;
	}
}
