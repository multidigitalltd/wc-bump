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

		// Admin: settings + manual "check now".
		add_action( 'admin_menu',                                 [ $this, 'add_menu' ] );
		add_action( 'admin_post_wc_store_enhancer_save_update',   [ $this, 'save_settings' ] );
		add_action( 'admin_post_wc_store_enhancer_check_update',  [ $this, 'manual_check' ] );
	}

	/* ─────────────────────────── Settings ───────────────────────────── */

	public static function get_settings(): array {
		$settings = wp_parse_args( (array) get_option( self::OPTION, [] ), [
			'source' => 'github',
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
		$info     = 'url' === $settings['source']
			? $this->fetch_custom_manifest( $settings['url'] )
			: $this->fetch_github_release( $settings['repo'], $settings['token'] );

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
		if ( ! empty( $body->assets ) && is_array( $body->assets ) ) {
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

	private function fetch_custom_manifest( string $url ) {
		$url = esc_url_raw( trim( $url ) );
		if ( '' === $url ) {
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
		if ( ! is_object( $data ) || empty( $data->version ) || empty( $data->download_url ) ) {
			return null;
		}

		return (object) [
			'version'      => ltrim( (string) $data->version, 'vV' ),
			'download_url' => esc_url_raw( (string) $data->download_url ),
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
		if ( 'github' !== $settings['source'] || '' === $settings['token'] ) {
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

	/* ─────────────────────────── Admin page ──────────────────────────── */

	public function add_menu(): void {
		// Changing where plugin code is pulled from is a plugin-management
		// action, so it requires update_plugins (administrators) — never merely
		// manage_woocommerce (shop managers). Fall back to a parent that always
		// exists when the WooCommerce-gated dashboard is absent.
		$parent = class_exists( 'WC_Order_Upsale_Dashboard' )
			? WC_Order_Upsale_Dashboard::MENU_SLUG
			: 'options-general.php';

		add_submenu_page(
			$parent,
			__( 'עדכונים', 'wc-order-upsale' ),
			__( 'עדכונים', 'wc-order-upsale' ),
			'update_plugins',
			'wc-store-enhancer-updates',
			[ $this, 'render_page' ]
		);
	}

	public function save_settings(): void {
		if ( ! check_admin_referer( 'wc_store_enhancer_update_save', 'wc_store_enhancer_update_nonce' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Unauthorized' );
		}

		// Read the raw stored option (not get_settings(), which may substitute a
		// constant-defined token) so we never persist the constant into the DB.
		$stored         = (array) get_option( self::OPTION, [] );
		$existing_token = isset( $stored['token'] ) ? (string) $stored['token'] : '';

		// The token field is rendered blank (never echoing the secret); an empty
		// submission keeps the stored token instead of wiping it. When the token is
		// provided via constant, the UI field is disabled and never overrides it.
		$token_input = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$token       = ( '' === $token_input || $this->token_is_constant() ) ? $existing_token : $token_input;

		update_option( self::OPTION, [
			'source' => in_array( $_POST['source'] ?? '', [ 'github', 'url' ], true ) ? $_POST['source'] : 'github',
			'repo'   => sanitize_text_field( wp_unslash( $_POST['repo'] ?? '' ) ),
			'token'  => $token,
			'url'    => esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) ),
		] );

		$this->clear_cache();
		wp_safe_redirect( admin_url( 'admin.php?page=wc-store-enhancer-updates&saved=1' ) );
		exit;
	}

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

		wp_safe_redirect( admin_url( 'admin.php?page=wc-store-enhancer-updates&checked=' . ( $available ? '1' : '0' ) ) );
		exit;
	}

	public function render_page(): void {
		$settings = self::get_settings();
		$remote   = $this->get_remote();
		$current  = $this->current_version();
		?>
		<div class="wrap wcse-updates">
			<h1><?php esc_html_e( 'עדכוני תוסף', 'wc-order-upsale' ); ?></h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'ההגדרות נשמרו.', 'wc-order-upsale' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['checked'] ) ) : ?>
				<div class="notice notice-info is-dismissible">
					<p>
						<?php
						echo '1' === $_GET['checked']
							? esc_html__( 'נמצא עדכון חדש! עברו לעמוד התוספים כדי לעדכן.', 'wc-order-upsale' )
							: esc_html__( 'התוסף מעודכן לגרסה האחרונה.', 'wc-order-upsale' );
						?>
					</p>
				</div>
			<?php endif; ?>

			<table class="widefat" style="max-width:640px;margin:12px 0">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'גרסה מותקנת', 'wc-order-upsale' ); ?></td>
						<td><strong><?php echo esc_html( $current ); ?></strong></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'הגרסה האחרונה במקור', 'wc-order-upsale' ); ?></td>
						<td>
							<?php if ( $remote ) : ?>
								<strong><?php echo esc_html( $remote->version ); ?></strong>
								<?php if ( version_compare( $remote->version, $current, '>' ) ) : ?>
									<span style="color:#7a5c00">— <?php esc_html_e( 'עדכון זמין', 'wc-order-upsale' ); ?></span>
								<?php else : ?>
									<span style="color:#125c2b">— <?php esc_html_e( 'מעודכן', 'wc-order-upsale' ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<em><?php esc_html_e( 'לא ניתן לבדוק כרגע (בדקו את הגדרות המקור).', 'wc-order-upsale' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'wc_store_enhancer_check_update' ); ?>
				<input type="hidden" name="action" value="wc_store_enhancer_check_update">
				<button type="submit" class="button"><?php esc_html_e( 'בדוק עדכונים עכשיו', 'wc-order-upsale' ); ?></button>
			</form>
			<a class="button button-primary" href="<?php echo esc_url( self_admin_url( 'plugins.php' ) ); ?>" style="margin-inline-start:6px">
				<?php esc_html_e( 'עבור לעמוד התוספים לעדכון', 'wc-order-upsale' ); ?>
			</a>

			<h2 style="margin-top:26px"><?php esc_html_e( 'מקור העדכונים', 'wc-order-upsale' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wc_store_enhancer_update_save', 'wc_store_enhancer_update_nonce' ); ?>
				<input type="hidden" name="action" value="wc_store_enhancer_save_update">

				<table class="form-table">
					<tr>
						<th scope="row"><label for="wcse-source"><?php esc_html_e( 'סוג מקור', 'wc-order-upsale' ); ?></label></th>
						<td>
							<select id="wcse-source" name="source">
								<option value="github" <?php selected( $settings['source'], 'github' ); ?>><?php esc_html_e( 'GitHub Releases', 'wc-order-upsale' ); ?></option>
								<option value="url"    <?php selected( $settings['source'], 'url' ); ?>><?php esc_html_e( 'כתובת JSON מותאמת', 'wc-order-upsale' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-repo"><?php esc_html_e( 'מאגר GitHub (owner/repo)', 'wc-order-upsale' ); ?></label></th>
						<td>
							<input type="text" id="wcse-repo" name="repo" value="<?php echo esc_attr( $settings['repo'] ); ?>" class="regular-text" placeholder="multidigitalltd/wc-bump">
							<p class="description"><?php esc_html_e( 'העדכון יימשך מה-Release האחרון של המאגר. עדיף לצרף קובץ zip כ-asset ל-Release.', 'wc-order-upsale' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-token"><?php esc_html_e( 'GitHub Token (למאגר פרטי)', 'wc-order-upsale' ); ?></label></th>
						<td>
							<?php $token_const = $this->token_is_constant(); ?>
							<input type="password" id="wcse-token" name="token" value="" class="regular-text" autocomplete="off"
								<?php disabled( $token_const ); ?>
								placeholder="<?php echo '' !== $settings['token'] ? '••••••••••••' : ''; ?>">
							<p class="description">
								<?php if ( $token_const ) : ?>
									<?php esc_html_e( 'ה-Token מוגדר דרך הקבוע WC_STORE_ENHANCER_GITHUB_TOKEN ב-wp-config.php (מומלץ) ולכן לא ניתן לעריכה כאן.', 'wc-order-upsale' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'אופציונלי. נדרש רק אם המאגר פרטי. הרשאת "Contents: read" מספיקה.', 'wc-order-upsale' ); ?>
									<?php if ( '' !== $settings['token'] ) : ?>
										<br><?php esc_html_e( 'Token שמור כבר קיים. השאירו ריק כדי לשמור אותו, או הזינו חדש כדי להחליף.', 'wc-order-upsale' ); ?>
									<?php endif; ?>
									<br><?php esc_html_e( 'לאבטחה מיטבית ניתן להגדיר במקום זאת: define(\'WC_STORE_ENHANCER_GITHUB_TOKEN\', \'...\'); בקובץ wp-config.php.', 'wc-order-upsale' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-url"><?php esc_html_e( 'כתובת JSON מותאמת', 'wc-order-upsale' ); ?></label></th>
						<td>
							<input type="url" id="wcse-url" name="url" value="<?php echo esc_attr( $settings['url'] ); ?>" class="regular-text" placeholder="https://m-d.co.il/updates/wc-store-enhancer.json">
							<p class="description"><?php esc_html_e( 'בשימוש רק כאשר סוג המקור הוא "כתובת JSON מותאמת".', 'wc-order-upsale' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'שמור מקור עדכונים', 'wc-order-upsale' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
