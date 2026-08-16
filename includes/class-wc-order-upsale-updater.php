<?php

defined( 'ABSPATH' ) || exit;

/**
 * Self-hosted update checker.
 *
 * Lets the site pull new plugin versions straight from wp-admin (Plugins →
 * "There is a new version…" and Dashboard → Updates) without the wordpress.org
 * repository. Two sources are supported:
 *
 *   - github: the latest GitHub Release of a repo (public, or private with a
 *             personal-access token). A ".zip" release asset is preferred, else
 *             the auto-generated source zipball is used.
 *   - url:    a custom JSON manifest hosted anywhere, e.g.
 *             { "version":"1.6.0", "download_url":"https://…/plugin.zip",
 *               "requires":"6.4", "requires_php":"8.0", "tested":"9.9",
 *               "homepage":"…", "changelog":"…", "last_updated":"2026-07-18" }
 *
 * The remote lookup is cached in a transient so the plugins list stays fast.
 */
class WC_Order_Upsale_Updater {

	const OPTION    = 'wc_store_enhancer_update';
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
		add_filter( 'http_request_args',                     [ $this, 'authorize_download' ], 10, 2 );
		add_action( 'upgrader_process_complete',             [ $this, 'clear_cache' ] );

		// Admin: settings tab on the shared page (kept last) + manual "check now".
		add_action( 'admin_post_wc_store_enhancer_check_update',  [ $this, 'manual_check' ] );
	}

	/**
	 * Register the "עדכונים" tab — only for users who can actually manage plugin
	 * updates, so it never shows to shop managers.
	 */

	/* ─────────────────────────── Settings ───────────────────────────── */

	/**
	 * Update source.
	 *
	 * Always the licence server for a customer install — there is no setting for
	 * it any more, and an install that predates the licence must not keep pulling
	 * from a repository it should never have known about. A constant leaves a way
	 * in for our own development builds.
	 */
	public static function source(): string {
		$source = defined( 'WC_STORE_ENHANCER_UPDATE_SOURCE' ) ? (string) WC_STORE_ENHANCER_UPDATE_SOURCE : 'license';
		return in_array( $source, [ 'license', 'github', 'url' ], true ) ? $source : 'license';
	}

	public static function get_settings(): array {
		$settings = wp_parse_args( (array) get_option( self::OPTION, [] ), [
			'source' => 'license',
			'repo'   => 'multidigitalltd/wc-bump',
			'token'  => '',
			'url'    => '',
		] );

		// Prefer a token defined outside the database (e.g. in wp-config.php):
		// keeping the secret out of the options table is the recommended practice.
		if ( defined( 'WC_STORE_ENHANCER_GITHUB_TOKEN' ) && '' !== (string) WC_STORE_ENHANCER_GITHUB_TOKEN ) {
			$settings['token'] = (string) WC_STORE_ENHANCER_GITHUB_TOKEN;
		}

		return $settings;
	}

	/** Whether the token is supplied via constant (and therefore not editable in the UI). */
	private function token_is_constant(): bool {
		return defined( 'WC_STORE_ENHANCER_GITHUB_TOKEN' ) && '' !== (string) WC_STORE_ENHANCER_GITHUB_TOKEN;
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

		$settings = self::get_settings();

		switch ( self::source() ) {
			case 'license':
				$info = $this->fetch_licensed_manifest();
				break;
			case 'url':
				$info = $this->fetch_custom_manifest( $settings['url'] );
				break;
			default:
				$info = $this->fetch_github_release( $settings['repo'], $settings['token'] );
		}

		// Cache both hits and misses (as a sentinel) to avoid hammering the API.
		set_site_transient( self::CACHE_KEY, $info ?: 0, self::CACHE_TTL );

		return $info;
	}

	private function fetch_github_release( string $repo, string $token ) {
		$repo = trim( $repo, " /\t\n\r" );
		if ( ! preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) ) {
			return null;
		}

		$headers = [
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'wc-store-enhancer-updater',
		];
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_safe_remote_get( "https://api.github.com/repos/{$repo}/releases/latest", [
			'timeout' => 15,
			'headers' => $headers,
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! is_object( $body ) || empty( $body->tag_name ) ) {
			return null;
		}

		$download = '';

		// For a private repo (token set) the source zipball is the reliable
		// download: it accepts the Authorization header and 302-redirects to an
		// already-authenticated codeload URL. Release *assets* on github.com do
		// not, so only prefer a .zip asset for public (token-less) repos.
		if ( '' === $token && ! empty( $body->assets ) && is_array( $body->assets ) ) {
			foreach ( $body->assets as $asset ) {
				if ( ! empty( $asset->browser_download_url ) && '.zip' === strtolower( substr( (string) $asset->name, -4 ) ) ) {
					$download = $asset->browser_download_url;
					break;
				}
			}
		}
		if ( '' === $download && ! empty( $body->zipball_url ) ) {
			$download = $body->zipball_url;
		}

		return (object) [
			'version'      => ltrim( (string) $body->tag_name, 'vV' ),
			'download_url' => $download,
			'homepage'     => ! empty( $body->html_url ) ? $body->html_url : "https://github.com/{$repo}",
			'requires'     => '6.4',
			'requires_php' => '8.0',
			'tested'       => '9.9',
			'changelog'    => ! empty( $body->body ) ? (string) $body->body : '',
			'last_updated' => ! empty( $body->published_at ) ? (string) $body->published_at : '',
		];
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

	private function fetch_custom_manifest( string $url ) {
		$url = esc_url_raw( trim( $url ) );
		// Require HTTPS: the fetched manifest ultimately determines the package
		// that gets installed as PHP, so TLS is the integrity guarantee.
		if ( '' === $url || 0 !== stripos( $url, 'https://' ) ) {
			return null;
		}

		// wp_safe_remote_get() applies reject_unsafe_urls, blocking loopback,
		// link-local and private-range hosts (SSRF hardening) for this
		// operator-supplied URL.
		$response = wp_safe_remote_get( $url, [ 'timeout' => 15 ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );

		return $this->normalise_manifest( $data );
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

	/**
	 * Attach the configured GitHub token when WordPress downloads the update
	 * package, so private-repo zipballs can be fetched.
	 *
	 * Scoped to the exact package URL this plugin resolved and only for the
	 * GitHub API/codeload hosts — so the token is never carried onto unrelated
	 * requests or leaked onto the signed CDN (objects.githubusercontent.com)
	 * that release-asset URLs redirect to.
	 */
	public function authorize_download( $args, $url ) {
		$settings = self::get_settings();
		if ( 'github' !== self::source() || '' === $settings['token'] ) {
			return $args;
		}

		$remote = $this->get_remote();
		if ( ! $remote || empty( $remote->download_url ) || (string) $url !== (string) $remote->download_url ) {
			return $args;
		}

		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( in_array( $host, [ 'api.github.com', 'codeload.github.com' ], true ) ) {
			if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
				$args['headers'] = [];
			}
			$args['headers']['Authorization'] = 'Bearer ' . $settings['token'];
		}
		return $args;
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

	/** Tab body rendered inside the shared settings page (no outer .wrap/h1). */
}
