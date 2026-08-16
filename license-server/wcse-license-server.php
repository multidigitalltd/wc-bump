<?php
/**
 * Plugin Name: משפר חנויות ווקומרס — שרת רישיונות
 * Description: מנפיק ומאמת מפתחות רישיון עבור התוסף "משפר חנויות ווקומרס", ומגיש לו עדכונים. מותקן על אתר הניהול בלבד — לא על אתרי הלקוחות.
 * Version: 1.0.0
 * Author: Multi Digital
 * Author URI: https://multidigital.co.il
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Text Domain: wcse-license-server
 */

defined( 'ABSPATH' ) || exit;

/**
 * Licence server.
 *
 * Issues keys, binds them to sites, and hands the plugin its update manifest.
 *
 * Two design points worth stating, because they are what keep this safe:
 *
 *  - The key never appears in a download URL that could be shared. A download
 *    link is a short-lived HMAC over the licence, the site and an expiry, so a
 *    copied link stops working within the hour and cannot be reused elsewhere.
 *  - Keys are compared with hash_equals and looked up by hash, so neither
 *    timing nor a leaked database row hands anybody a working key.
 */
final class WCSE_License_Server {

	const VERSION      = '1.0.0';
	const NS           = 'wcse-license/v1';
	const OPTION       = 'wcse_ls_settings';
	const DB_VERSION   = '1.0.0';
	const DB_VER_OPT   = 'wcse_ls_db_version';
	/** How long a download link stays usable. */
	const LINK_TTL     = HOUR_IN_SECONDS;

	public function __construct() {
		add_action( 'init', [ $this, 'maybe_create_tables' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_wcse_ls_save_release', [ $this, 'save_release' ] );
		add_action( 'admin_post_wcse_ls_add_license', [ $this, 'add_license' ] );
		add_action( 'admin_post_wcse_ls_update_license', [ $this, 'update_license' ] );
	}

	/* ─────────────────────────── Storage ────────────────────────────── */

	public static function licenses_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wcse_licenses';
	}

	public static function sites_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wcse_license_sites';
	}

	public function maybe_create_tables(): void {
		if ( get_option( self::DB_VER_OPT ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$licenses = self::licenses_table();
		$sites    = self::sites_table();

		dbDelta( "CREATE TABLE {$licenses} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			license_key VARCHAR(64) NOT NULL,
			key_hash CHAR(64) NOT NULL,
			customer VARCHAR(190) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			sites_limit SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			expires_at DATE NULL DEFAULT NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY key_hash (key_hash),
			KEY status (status),
			KEY email (email)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$sites} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			license_id BIGINT UNSIGNED NOT NULL,
			site_url VARCHAR(190) NOT NULL,
			last_seen DATETIME NOT NULL,
			plugin_version VARCHAR(20) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY license_site (license_id, site_url),
			KEY license_id (license_id)
		) {$charset};" );

		update_option( self::DB_VER_OPT, self::DB_VERSION );
	}

	/* ─────────────────────────── Settings ───────────────────────────── */

	public static function settings(): array {
		return wp_parse_args( (array) get_option( self::OPTION, [] ), [
			'version'      => '',
			'zip_url'      => '',
			'requires'     => '6.4',
			'requires_php' => '8.0',
			'tested'       => '9.9',
			'changelog'    => '',
			'homepage'     => 'https://multidigital.co.il',
		] );
	}

	/* ─────────────────────────── Keys ───────────────────────────────── */

	/** A key the customer can retype without ambiguity: no O/0/I/1. */
	public static function generate_key(): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$groups   = [];
		for ( $g = 0; $g < 4; $g++ ) {
			$chunk = '';
			for ( $i = 0; $i < 4; $i++ ) {
				$chunk .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}
			$groups[] = $chunk;
		}
		return implode( '-', $groups );
	}

	private static function hash_key( string $key ): string {
		return hash_hmac( 'sha256', strtoupper( trim( $key ) ), wp_salt( 'auth' ) );
	}

	private static function find( string $key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::licenses_table() . ' WHERE key_hash = %s LIMIT 1', self::hash_key( $key ) ),
			ARRAY_A
		);
		return $row ?: null;
	}

	private static function site_count( int $license_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::sites_table() . ' WHERE license_id = %d', $license_id ) );
	}

	private static function site_registered( int $license_id, string $site ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::sites_table() . ' WHERE license_id = %d AND site_url = %s LIMIT 1',
			$license_id,
			$site
		) );
	}

	/* ─────────────────────────── REST ───────────────────────────────── */

	public function register_routes(): void {
		$args = [
			'key'     => [ 'required' => true, 'type' => 'string' ],
			'site'    => [ 'required' => true, 'type' => 'string' ],
			'version' => [ 'required' => false, 'type' => 'string' ],
		];

		foreach ( [ 'activate', 'deactivate', 'check', 'update' ] as $route ) {
			register_rest_route( self::NS, '/' . $route, [
				'methods'             => [ 'POST', 'GET' ],
				'callback'            => [ $this, 'handle_' . $route ],
				'permission_callback' => '__return_true', // The key is the credential.
				'args'                => $args,
			] );
		}

		register_rest_route( self::NS, '/download', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_download' ],
			'permission_callback' => '__return_true',
		] );
	}

	/** Normalise the two fields every route needs. */
	private function inputs( WP_REST_Request $request ): array {
		return [
			strtoupper( trim( (string) $request->get_param( 'key' ) ) ),
			untrailingslashit( esc_url_raw( (string) $request->get_param( 'site' ) ) ),
			substr( sanitize_text_field( (string) $request->get_param( 'version' ) ), 0, 20 ),
		];
	}

	/**
	 * Resolve a key to a verdict.
	 *
	 * @return array{row:?array,status:string,message:string}
	 */
	private function verdict( string $key, string $site ): array {
		if ( '' === $key || '' === $site ) {
			return [ 'row' => null, 'status' => 'invalid', 'message' => __( 'חסרים פרטים בבקשה.', 'wcse-license-server' ) ];
		}

		$row = self::find( $key );
		if ( ! $row || ! hash_equals( (string) $row['key_hash'], self::hash_key( $key ) ) ) {
			return [ 'row' => null, 'status' => 'invalid', 'message' => __( 'מפתח הרישיון אינו מוכר.', 'wcse-license-server' ) ];
		}
		if ( 'active' !== $row['status'] ) {
			return [ 'row' => $row, 'status' => 'invalid', 'message' => __( 'הרישיון בוטל. פנו אלינו לפרטים.', 'wcse-license-server' ) ];
		}
		if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] . ' 23:59:59' ) < time() ) {
			return [ 'row' => $row, 'status' => 'expired', 'message' => __( 'תוקף הרישיון פג. חידוש יחזיר את העדכונים.', 'wcse-license-server' ) ];
		}

		return [ 'row' => $row, 'status' => 'valid', 'message' => '' ];
	}

	/** The shape the plugin's absorb() expects. */
	private function state( array $row, string $status, string $message ): array {
		return [
			'status'      => $status,
			'expires'     => (string) ( $row['expires_at'] ?? '' ),
			'sites_limit' => (int) ( $row['sites_limit'] ?? 0 ),
			'sites_used'  => isset( $row['id'] ) ? self::site_count( (int) $row['id'] ) : 0,
			'message'     => $message,
		];
	}

	public function handle_activate( WP_REST_Request $request ) {
		list( $key, $site, $version ) = $this->inputs( $request );
		$verdict = $this->verdict( $key, $site );

		if ( ! $verdict['row'] ) {
			return new WP_REST_Response( [ 'status' => 'invalid', 'message' => $verdict['message'] ], 200 );
		}

		$row = $verdict['row'];
		$id  = (int) $row['id'];

		if ( 'valid' !== $verdict['status'] ) {
			return new WP_REST_Response( $this->state( $row, $verdict['status'], $verdict['message'] ), 200 );
		}

		// A site already on the licence re-activating is not a new seat.
		if ( ! self::site_registered( $id, $site ) && self::site_count( $id ) >= (int) $row['sites_limit'] ) {
			return new WP_REST_Response(
				$this->state( $row, 'limit', sprintf(
					/* translators: %d: number of sites allowed */
					__( 'הרישיון מוגבל ל-%d אתרים. הסירו אותו מאתר אחר או שדרגו.', 'wcse-license-server' ),
					(int) $row['sites_limit']
				) ),
				200
			);
		}

		global $wpdb;
		$wpdb->replace(
			self::sites_table(),
			[
				'license_id'     => $id,
				'site_url'       => $site,
				'last_seen'      => current_time( 'mysql' ),
				'plugin_version' => $version,
			],
			[ '%d', '%s', '%s', '%s' ]
		);

		return new WP_REST_Response( $this->state( $row, 'valid', '' ), 200 );
	}

	public function handle_deactivate( WP_REST_Request $request ) {
		list( $key, $site ) = $this->inputs( $request );
		$verdict = $this->verdict( $key, $site );

		if ( $verdict['row'] ) {
			global $wpdb;
			$wpdb->delete( self::sites_table(), [ 'license_id' => (int) $verdict['row']['id'], 'site_url' => $site ], [ '%d', '%s' ] );
		}

		return new WP_REST_Response( [ 'status' => 'inactive', 'message' => '' ], 200 );
	}

	public function handle_check( WP_REST_Request $request ) {
		list( $key, $site, $version ) = $this->inputs( $request );
		$verdict = $this->verdict( $key, $site );

		if ( ! $verdict['row'] ) {
			return new WP_REST_Response( [ 'status' => 'invalid', 'message' => $verdict['message'] ], 200 );
		}

		$row = $verdict['row'];
		$id  = (int) $row['id'];

		// A licence that is valid but whose site was never registered has not
		// really been activated here.
		if ( 'valid' === $verdict['status'] && ! self::site_registered( $id, $site ) ) {
			return new WP_REST_Response(
				$this->state( $row, 'invalid', __( 'הרישיון אינו מופעל עבור האתר הזה.', 'wcse-license-server' ) ),
				200
			);
		}

		if ( 'valid' === $verdict['status'] ) {
			global $wpdb;
			$wpdb->update(
				self::sites_table(),
				[ 'last_seen' => current_time( 'mysql' ), 'plugin_version' => $version ],
				[ 'license_id' => $id, 'site_url' => $site ],
				[ '%s', '%s' ],
				[ '%d', '%s' ]
			);
		}

		return new WP_REST_Response( $this->state( $row, $verdict['status'], $verdict['message'] ), 200 );
	}

	public function handle_update( WP_REST_Request $request ) {
		list( $key, $site ) = $this->inputs( $request );
		$verdict = $this->verdict( $key, $site );

		if ( ! $verdict['row'] || 'valid' !== $verdict['status'] ) {
			return new WP_REST_Response( [ 'status' => $verdict['status'], 'message' => $verdict['message'] ], 403 );
		}

		$id = (int) $verdict['row']['id'];
		if ( ! self::site_registered( $id, $site ) ) {
			return new WP_REST_Response(
				[ 'status' => 'invalid', 'message' => __( 'הרישיון אינו מופעל עבור האתר הזה.', 'wcse-license-server' ) ],
				403
			);
		}

		$settings = self::settings();
		if ( '' === $settings['version'] || '' === $settings['zip_url'] ) {
			return new WP_REST_Response( [ 'message' => __( 'לא הוגדרה גרסה להפצה.', 'wcse-license-server' ) ], 404 );
		}

		return new WP_REST_Response( [
			'version'      => $settings['version'],
			'download_url' => $this->signed_download_url( $key, $site ),
			'homepage'     => $settings['homepage'],
			'requires'     => $settings['requires'],
			'requires_php' => $settings['requires_php'],
			'tested'       => $settings['tested'],
			'changelog'    => $settings['changelog'],
			'last_updated' => get_option( 'wcse_ls_release_date', '' ),
		], 200 );
	}

	/* ─────────────────────────── Download ───────────────────────────── */

	private function signature( string $key, string $site, int $expires ): string {
		return hash_hmac( 'sha256', self::hash_key( $key ) . '|' . $site . '|' . $expires, wp_salt( 'secure_auth' ) );
	}

	private function signed_download_url( string $key, string $site ): string {
		$expires = time() + self::LINK_TTL;

		return add_query_arg(
			[
				'site' => rawurlencode( $site ),
				'exp'  => $expires,
				'sig'  => $this->signature( $key, $site, $expires ),
				'k'    => rawurlencode( self::hash_key( $key ) ),
			],
			rest_url( self::NS . '/download' )
		);
	}

	/**
	 * Serve the package to a link this server signed.
	 *
	 * The licence key itself is never in the URL — only its hash — so a link
	 * captured in a proxy log or a support ticket cannot be turned back into a
	 * working key, and it stops opening within the hour regardless.
	 */
	public function handle_download( WP_REST_Request $request ) {
		$site    = untrailingslashit( esc_url_raw( (string) $request->get_param( 'site' ) ) );
		$expires = (int) $request->get_param( 'exp' );
		$sig     = (string) $request->get_param( 'sig' );
		$hash    = (string) $request->get_param( 'k' );

		if ( $expires < time() ) {
			return new WP_REST_Response( [ 'message' => 'Link expired' ], 410 );
		}

		$expected = hash_hmac( 'sha256', $hash . '|' . $site . '|' . $expires, wp_salt( 'secure_auth' ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			return new WP_REST_Response( [ 'message' => 'Bad signature' ], 403 );
		}

		global $wpdb;
		$license_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::licenses_table() . ' WHERE key_hash = %s LIMIT 1', $hash ) );
		if ( ! $license_id || ! self::site_registered( $license_id, $site ) ) {
			return new WP_REST_Response( [ 'message' => 'Not licensed' ], 403 );
		}

		$zip = self::settings()['zip_url'];
		if ( '' === $zip ) {
			return new WP_REST_Response( [ 'message' => 'No package configured' ], 404 );
		}

		// The package lives wherever the operator put it; hand the client
		// straight to it rather than proxying bytes through PHP.
		wp_redirect( $zip, 302 );
		exit;
	}

	/* ─────────────────────────── Admin ──────────────────────────────── */

	public function menu(): void {
		add_menu_page(
			__( 'רישיונות', 'wcse-license-server' ),
			__( 'רישיונות', 'wcse-license-server' ),
			'manage_options',
			'wcse-licenses',
			[ $this, 'render' ],
			'dashicons-admin-network',
			58
		);
	}

	public function add_license(): void {
		check_admin_referer( 'wcse_ls_add' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$key = self::generate_key();

		global $wpdb;
		$wpdb->insert(
			self::licenses_table(),
			[
				'license_key' => $key,
				'key_hash'    => self::hash_key( $key ),
				'customer'    => sanitize_text_field( wp_unslash( $_POST['customer'] ?? '' ) ),
				'email'       => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
				'status'      => 'active',
				'sites_limit' => max( 1, absint( $_POST['sites_limit'] ?? 1 ) ),
				'expires_at'  => $this->clean_date( wp_unslash( $_POST['expires_at'] ?? '' ) ),
				'note'        => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
				'created_at'  => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);

		wp_safe_redirect( admin_url( 'admin.php?page=wcse-licenses&created=' . rawurlencode( $key ) ) );
		exit;
	}

	public function update_license(): void {
		check_admin_referer( 'wcse_ls_update' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( $id ) {
			global $wpdb;
			$wpdb->update(
				self::licenses_table(),
				[
					'status'      => in_array( $_POST['status'] ?? '', [ 'active', 'revoked' ], true ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'active',
					'sites_limit' => max( 1, absint( $_POST['sites_limit'] ?? 1 ) ),
					'expires_at'  => $this->clean_date( wp_unslash( $_POST['expires_at'] ?? '' ) ),
				],
				[ 'id' => $id ],
				[ '%s', '%d', '%s' ],
				[ '%d' ]
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wcse-licenses&saved=1' ) );
		exit;
	}

	private function clean_date( $value ): ?string {
		$value = trim( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}

	public function save_release(): void {
		check_admin_referer( 'wcse_ls_release' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		update_option( self::OPTION, [
			'version'      => sanitize_text_field( wp_unslash( $_POST['version'] ?? '' ) ),
			'zip_url'      => esc_url_raw( wp_unslash( $_POST['zip_url'] ?? '' ) ),
			'requires'     => sanitize_text_field( wp_unslash( $_POST['requires'] ?? '6.4' ) ),
			'requires_php' => sanitize_text_field( wp_unslash( $_POST['requires_php'] ?? '8.0' ) ),
			'tested'       => sanitize_text_field( wp_unslash( $_POST['tested'] ?? '9.9' ) ),
			'changelog'    => wp_kses_post( wp_unslash( $_POST['changelog'] ?? '' ) ),
			'homepage'     => esc_url_raw( wp_unslash( $_POST['homepage'] ?? '' ) ),
		] );
		update_option( 'wcse_ls_release_date', current_time( 'mysql' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=wcse-licenses&released=1' ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$settings = self::settings();
		$licenses = $wpdb->get_results( 'SELECT * FROM ' . self::licenses_table() . ' ORDER BY created_at DESC LIMIT 500', ARRAY_A );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'רישיונות — משפר חנויות ווקומרס', 'wcse-license-server' ); ?></h1>

			<?php if ( ! empty( $_GET['created'] ) ) : ?>
				<div class="notice notice-success">
					<p>
						<?php esc_html_e( 'נוצר רישיון חדש. זה המפתח שיש למסור ללקוח:', 'wcse-license-server' ); ?>
						<code style="font-size:15px"><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['created'] ) ) ); ?></code>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'הרישיון עודכן.', 'wcse-license-server' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['released'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'פרטי הגרסה נשמרו. אתרים עם רישיון פעיל יראו את העדכון תוך כמה שעות.', 'wcse-license-server' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'הגרסה המופצת', 'wcse-license-server' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wcse_ls_release' ); ?>
				<input type="hidden" name="action" value="wcse_ls_save_release">
				<table class="form-table">
					<tr>
						<th scope="row"><label for="ls-version"><?php esc_html_e( 'מספר גרסה', 'wcse-license-server' ); ?></label></th>
						<td><input type="text" id="ls-version" name="version" value="<?php echo esc_attr( $settings['version'] ); ?>" class="regular-text" placeholder="1.21.0"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ls-zip"><?php esc_html_e( 'כתובת קובץ ה-ZIP', 'wcse-license-server' ); ?></label></th>
						<td>
							<input type="url" id="ls-zip" name="zip_url" value="<?php echo esc_attr( $settings['zip_url'] ); ?>" class="large-text" placeholder="https://multidigital.co.il/releases/wc-bump-1.21.0.zip">
							<p class="description"><?php esc_html_e( 'העלו את ה-ZIP למדיה או לתיקייה בשרת והדביקו כאן את הכתובת. הלקוח לעולם לא מקבל אותה ישירות — הוא מקבל קישור חתום שפג תוך שעה.', 'wcse-license-server' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ls-changelog"><?php esc_html_e( 'מה חדש', 'wcse-license-server' ); ?></label></th>
						<td><textarea id="ls-changelog" name="changelog" rows="5" class="large-text"><?php echo esc_textarea( $settings['changelog'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'תאימות', 'wcse-license-server' ); ?></th>
						<td>
							<label>WP <input type="text" name="requires" value="<?php echo esc_attr( $settings['requires'] ); ?>" style="width:80px"></label>
							<label style="margin-inline-start:12px">PHP <input type="text" name="requires_php" value="<?php echo esc_attr( $settings['requires_php'] ); ?>" style="width:80px"></label>
							<label style="margin-inline-start:12px">WC <input type="text" name="tested" value="<?php echo esc_attr( $settings['tested'] ); ?>" style="width:80px"></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ls-home"><?php esc_html_e( 'עמוד המוצר', 'wcse-license-server' ); ?></label></th>
						<td><input type="url" id="ls-home" name="homepage" value="<?php echo esc_attr( $settings['homepage'] ); ?>" class="regular-text"></td>
					</tr>
				</table>
				<p class="submit"><button class="button button-primary"><?php esc_html_e( 'שמור גרסה', 'wcse-license-server' ); ?></button></p>
			</form>

			<hr>

			<h2><?php esc_html_e( 'הנפקת רישיון', 'wcse-license-server' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wcse_ls_add' ); ?>
				<input type="hidden" name="action" value="wcse_ls_add_license">
				<table class="form-table">
					<tr>
						<th scope="row"><label for="ls-customer"><?php esc_html_e( 'לקוח', 'wcse-license-server' ); ?></label></th>
						<td><input type="text" id="ls-customer" name="customer" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ls-email"><?php esc_html_e( 'אימייל', 'wcse-license-server' ); ?></label></th>
						<td><input type="email" id="ls-email" name="email" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ls-limit"><?php esc_html_e( 'מספר אתרים', 'wcse-license-server' ); ?></label></th>
						<td><input type="number" id="ls-limit" name="sites_limit" value="1" min="1" style="width:80px"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ls-exp"><?php esc_html_e( 'בתוקף עד', 'wcse-license-server' ); ?></label></th>
						<td>
							<input type="date" id="ls-exp" name="expires_at">
							<p class="description"><?php esc_html_e( 'ריק = ללא תפוגה.', 'wcse-license-server' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ls-note"><?php esc_html_e( 'הערה', 'wcse-license-server' ); ?></label></th>
						<td><textarea id="ls-note" name="note" rows="2" class="large-text"></textarea></td>
					</tr>
				</table>
				<p class="submit"><button class="button button-primary"><?php esc_html_e( 'הנפק מפתח', 'wcse-license-server' ); ?></button></p>
			</form>

			<hr>

			<h2><?php esc_html_e( 'רישיונות קיימים', 'wcse-license-server' ); ?></h2>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'מפתח', 'wcse-license-server' ); ?></th>
						<th><?php esc_html_e( 'לקוח', 'wcse-license-server' ); ?></th>
						<th><?php esc_html_e( 'אתרים', 'wcse-license-server' ); ?></th>
						<th><?php esc_html_e( 'תוקף', 'wcse-license-server' ); ?></th>
						<th><?php esc_html_e( 'מצב', 'wcse-license-server' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $licenses ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'עדיין לא הונפקו רישיונות.', 'wcse-license-server' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $licenses as $row ) : ?>
						<?php
						$used  = self::site_count( (int) $row['id'] );
						$sites = $wpdb->get_col( $wpdb->prepare( 'SELECT site_url FROM ' . self::sites_table() . ' WHERE license_id = %d', (int) $row['id'] ) );
						?>
						<tr>
							<td><code><?php echo esc_html( $row['license_key'] ); ?></code></td>
							<td>
								<?php echo esc_html( $row['customer'] ); ?>
								<?php if ( $row['email'] ) : ?><br><span class="description"><?php echo esc_html( $row['email'] ); ?></span><?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( $used . ' / ' . (int) $row['sites_limit'] ); ?>
								<?php if ( $sites ) : ?>
									<br><span class="description"><?php echo esc_html( implode( ', ', $sites ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['expires_at'] ?: __( 'ללא הגבלה', 'wcse-license-server' ) ); ?></td>
							<td>
								<?php echo 'active' === $row['status']
									? '<span style="color:#125c2b">' . esc_html__( 'פעיל', 'wcse-license-server' ) . '</span>'
									: '<span style="color:#b32d2e">' . esc_html__( 'מבוטל', 'wcse-license-server' ) . '</span>'; ?>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
									<?php wp_nonce_field( 'wcse_ls_update' ); ?>
									<input type="hidden" name="action" value="wcse_ls_update_license">
									<input type="hidden" name="id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
									<select name="status">
										<option value="active" <?php selected( $row['status'], 'active' ); ?>><?php esc_html_e( 'פעיל', 'wcse-license-server' ); ?></option>
										<option value="revoked" <?php selected( $row['status'], 'revoked' ); ?>><?php esc_html_e( 'מבוטל', 'wcse-license-server' ); ?></option>
									</select>
									<input type="number" name="sites_limit" value="<?php echo esc_attr( (string) $row['sites_limit'] ); ?>" min="1" style="width:64px">
									<input type="date" name="expires_at" value="<?php echo esc_attr( (string) $row['expires_at'] ); ?>">
									<button class="button button-small"><?php esc_html_e( 'שמור', 'wcse-license-server' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

new WCSE_License_Server();
