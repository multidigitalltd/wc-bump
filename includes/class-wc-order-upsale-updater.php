<?php

defined( 'ABSPATH' ) || exit;

/**
 * Self-hosted update checker.
 *
 * Lets the site pull new plugin versions straight from wp-admin (Plugins →
 * "There is a new version…" and Dashboard → Updates) without the wordpress.org
 * repository. The build comes from the licence server and nowhere else: there
 * is no repository or token for a shop to configure, and nothing it can point
 * at instead.
 *
 * The remote lookup is cached in a transient so the plugins list stays fast.
 */
class WC_Order_Upsale_Updater {

	const CACHE_KEY = 'wc_store_enhancer_update_cache';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** The bootstrap instance, reused by status() so hooks are registered once. */
	private static ?self $instance = null;

	private string $file;      // Absolute path to the main plugin file.
	private string $basename;  // e.g. "wc-bump/wc-order-upsale.php".
	private string $slug;      // Plugin folder slug, e.g. "wc-bump".

	public function __construct( string $file ) {
		if ( null === self::$instance ) {
			self::$instance = $this;
		}

		$this->file     = $file;
		$this->basename = plugin_basename( $file );
		$slug           = dirname( $this->basename );
		$this->slug     = ( '.' === $slug || '' === $slug ) ? basename( $file, '.php' ) : $slug;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api',                           [ $this, 'plugins_api' ], 20, 3 );
		add_filter( 'upgrader_source_selection',             [ $this, 'fix_source_dir' ], 10, 4 );
		add_action( 'upgrader_process_complete',             [ $this, 'clear_cache' ] );

		// Manual "check now", offered from the licence tab.
		add_action( 'admin_post_wc_store_enhancer_check_update',  [ $this, 'manual_check' ] );
	}

	private function current_version(): string {
		return defined( 'WC_ORDER_UPSALE_VERSION' ) ? WC_ORDER_UPSALE_VERSION : '0';
	}

	/**
	 * Lightweight status summary for the dashboard.
	 *
	 * @return array{update_available:bool,new_version:string,current_version:string}
	 */
	public static function status(): array {
		// Reuse the bootstrap instance so its hooks are not registered twice.
		$instance = self::$instance ?? new self( defined( 'WC_ORDER_UPSALE_FILE' ) ? WC_ORDER_UPSALE_FILE : __FILE__ );
		$remote   = $instance->get_remote();
		$current  = $instance->current_version();

		return [
			'current_version'  => $current,
			'new_version'      => $remote->version ?? $current,
			'update_available' => $remote && version_compare( $remote->version, $current, '>' ) && ! empty( $remote->download_url ),
		];
	}

	/* ─────────────────────── Remote lookup ───────────────────────────── */

	/**
	 * Fetch and normalise remote release info, cached for CACHE_TTL.
	 *
	 * @return object|null Object with version, download_url, homepage, requires,
	 *                     requires_php, tested, changelog, last_updated.
	 */
	private function get_remote( bool $force = false ) {
		if ( ! $force ) {
			// Network-scoped to match the site transient (update_plugins) it feeds.
			$cached = get_site_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return is_object( $cached ) ? $cached : null;
			}
		}

		$info = $this->fetch_licensed_manifest();

		// Cache both hits and misses (as a sentinel) to avoid hammering the API.
		set_site_transient( self::CACHE_KEY, $info ?: 0, self::CACHE_TTL );

		return $info;
	}

	/**
	 * Ask the licence server for the build this site is entitled to.
	 *
	 * A shop without a valid licence is told there is no update rather than
	 * being handed a package it may not install — the licence gates updates and
	 * nothing else, so this is the only place enforcement happens.
	 */
	private function fetch_licensed_manifest() {
		if ( ! class_exists( 'WC_Order_Upsale_License' ) || ! WC_Order_Upsale_License::is_valid() ) {
			return null;
		}

		$result = WC_Order_Upsale_License::request( 'update', [ 'key' => WC_Order_Upsale_License::key() ] );
		if ( ! $result['ok'] ) {
			return null;
		}

		return $this->normalise_manifest( (object) $result['data'] );
	}

	/**
	 * Validate and normalise a manifest from any source.
	 *
	 * The package it names is installed as executable PHP, so an HTTPS package
	 * URL is non-negotiable regardless of which source produced it.
	 *
	 * @param mixed $data Decoded manifest.
	 */
	private function normalise_manifest( $data ) {
		// A licensed reply with no version is "nothing published yet", not a
		// failure — the caller shows no update either way, and neither case is
		// worth surfacing to a shop as an error.
		if ( ! is_object( $data ) || empty( $data->version ) || empty( $data->download_url ) ) {
			return null;
		}

		$download_url = esc_url_raw( (string) $data->download_url );
		if ( 0 !== stripos( $download_url, 'https://' ) ) {
			return null;
		}

		return (object) [
			'version'      => ltrim( (string) $data->version, 'vV' ),
			'download_url' => $download_url,
			'homepage'     => ! empty( $data->homepage ) ? esc_url_raw( (string) $data->homepage ) : '',
			'requires'     => ! empty( $data->requires ) ? (string) $data->requires : '6.4',
			'requires_php' => ! empty( $data->requires_php ) ? (string) $data->requires_php : '8.0',
			'tested'       => ! empty( $data->tested ) ? (string) $data->tested : '9.9',
			'changelog'    => ! empty( $data->changelog ) ? (string) $data->changelog : '',
			'last_updated' => ! empty( $data->last_updated ) ? (string) $data->last_updated : '',
		];
	}

	/* ─────────────────────── Update integration ──────────────────────── */

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$remote = $this->get_remote();
		if ( ! $remote || empty( $remote->version ) ) {
			return $transient;
		}

		$item = (object) [
			'id'           => $this->basename,
			'slug'         => $this->slug,
			'plugin'       => $this->basename,
			'new_version'  => $remote->version,
			'url'          => $remote->homepage,
			'package'      => $remote->download_url,
			'requires'     => $remote->requires,
			'requires_php' => $remote->requires_php,
			'tested'       => $remote->tested,
			'icons'        => [],
			'banners'      => [],
			'banners_rtl'  => [],
		];

		if ( version_compare( $remote->version, $this->current_version(), '>' ) && ! empty( $remote->download_url ) ) {
			$transient->response[ $this->basename ] = $item;
		} else {
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = [];
			}
			$transient->no_update[ $this->basename ] = $item;
		}

		return $transient;
	}

	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$remote = $this->get_remote();
		if ( ! $remote ) {
			return $result;
		}

		return (object) [
			'name'          => __( 'משפר חנויות ווקומרס', 'wc-order-upsale' ),
			'slug'          => $this->slug,
			'version'       => $remote->version,
			'author'        => '<a href="https://m-d.co.il">Multi Digital</a>',
			'homepage'      => $remote->homepage,
			'requires'      => $remote->requires,
			'requires_php'  => $remote->requires_php,
			'tested'        => $remote->tested,
			'last_updated'  => $remote->last_updated,
			'download_link' => $remote->download_url,
			'sections'      => [
				'changelog' => $remote->changelog ? wpautop( wp_kses_post( $remote->changelog ) ) : __( 'אין יומן שינויים.', 'wc-order-upsale' ),
			],
		];
	}

	/**
	 * Rename the freshly-extracted folder to the plugin's real slug so WordPress
	 * updates it in place. GitHub zipballs unpack to "owner-repo-<sha>/", which
	 * would otherwise install as a brand-new plugin folder.
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = null ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}
		if ( ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired ), true ) ) {
			return $desired;
		}

		return $source;
	}

	public function clear_cache(): void {
		delete_site_transient( self::CACHE_KEY );
	}

	/* ─────────────────────────── Settings tab ───────────────────────── */

	public function manual_check(): void {
		if ( ! check_admin_referer( 'wc_store_enhancer_check_update' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Unauthorized' );
		}

		$this->clear_cache();
		delete_site_transient( 'update_plugins' );
		$remote    = $this->get_remote( true );
		$available = $remote && version_compare( $remote->version, $this->current_version(), '>' );

		wp_safe_redirect( admin_url( 'admin.php?page=' . WC_Order_Upsale_Dashboard::SETTINGS_SLUG . '&tab=license&checked=' . ( $available ? '1' : '0' ) ) );
		exit;
	}

}