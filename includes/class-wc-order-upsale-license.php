<?php

defined( 'ABSPATH' ) || exit;

/**
 * License client.
 *
 * The customer pastes one key. Everything else — which repository the build
 * comes from, which token reaches it — stops being their problem, which is what
 * makes this behave like an ordinary commercial plugin rather than a developer
 * checkout.
 *
 * The licence gates updates only. A shop that lapses keeps every feature it
 * already paid for and simply stops receiving new versions; nothing about the
 * storefront changes. That is deliberate — a plugin that switches a live shop's
 * behaviour off because an invoice is late does damage out of all proportion to
 * the debt.
 *
 * Validation is cached and re-checked once a day on cron, never on a page load,
 * so the licence server is never in the path of a request a shopper is waiting
 * on. If the server is unreachable the last known state stands.
 */
class WC_Order_Upsale_License {

	const OPTION    = 'wc_store_enhancer_license';
	const CRON_HOOK = 'wcse_license_check';
	/**
	 * Records that the one-time pre-licence decision has been taken. Deliberately
	 * separate from OPTION, which removing a licence deletes.
	 */
	const MIGRATED_OPT = 'wc_store_enhancer_license_migrated';

	/**
	 * Where licences are issued.
	 *
	 * A full base URL including the path, because the server need not be
	 * WordPress — the four endpoints hang directly off this. Override with
	 * WC_STORE_ENHANCER_LICENSE_API for staging or a self-hosted build.
	 */
	const DEFAULT_API = 'https://app.multidigital.co.il/api/license/v1';

	public function __construct() {
		add_action( self::CRON_HOOK, [ $this, 'scheduled_check' ] );
		add_action( 'init', [ $this, 'maybe_schedule' ] );
		// Runs once: an install that predates licensing keeps its modules.
		add_action( 'init', [ __CLASS__, 'grandfather' ], 1 );

		add_filter( 'wc_store_enhancer_settings_tabs',              [ $this, 'register_settings_tab' ], 88 );
		add_action( 'admin_post_wc_store_enhancer_license_save',    [ $this, 'handle_save' ] );
		add_action( 'admin_post_wc_store_enhancer_license_remove',  [ $this, 'handle_remove' ] );
		add_action( 'admin_post_wc_store_enhancer_license_refresh', [ $this, 'handle_refresh' ] );

		add_action( 'admin_notices', [ $this, 'admin_notice' ] );
	}

	/* ─────────────────────────── State ──────────────────────────────── */

	public static function get(): array {
		return wp_parse_args( (array) get_option( self::OPTION, [] ), [
			'key'         => '',
			'status'      => 'inactive', // inactive | valid | expired | invalid | limit | error
			'expires'     => '',
			'sites_limit' => 0,
			'sites_used'  => 0,
			'message'     => '',
			'checked_at'  => '',
			// Sticky: set the first time a key validates, and never cleared by a
			// later refusal. See has_been_licensed().
			'ever_valid'  => 0,
		] );
	}

	private static function put( array $data ): void {
		update_option( self::OPTION, array_merge( self::get(), $data ), false );
	}

	public static function key(): string {
		return (string) self::get()['key'];
	}

	/**
	 * Has this site ever held a working licence?
	 *
	 * The distinction that matters is not "is the licence valid now" but "was it
	 * ever". A shop that activated and later lapsed has paid; its modules keep
	 * running, and a server outage or an expiry never reaches the storefront. A
	 * copy that was never licensed at all has bought nothing, and gets nothing.
	 *
	 * Deliberately sticky: nothing the server can say later takes it away. Only
	 * the shop removing its own key does.
	 */
	public static function has_been_licensed(): bool {
		return ! empty( self::get()['ever_valid'] );
	}

	/** True when this site may receive updates. */
	public static function is_valid(): bool {
		$state = self::get();
		if ( '' === $state['key'] || 'valid' !== $state['status'] ) {
			return false;
		}
		if ( $state['expires'] && strtotime( $state['expires'] ) < time() ) {
			return false;
		}
		return true;
	}

	public static function api(): string {
		$api = defined( 'WC_STORE_ENHANCER_LICENSE_API' ) && WC_STORE_ENHANCER_LICENSE_API
			? (string) WC_STORE_ENHANCER_LICENSE_API
			: self::DEFAULT_API;

		/** Filter the licence API base, for staging or self-hosting. */
		return untrailingslashit( (string) apply_filters( 'wc_store_enhancer_license_api', $api ) );
	}

	/**
	 * The identity a licence is bound to.
	 *
	 * Sent exactly as WordPress reports it. The server normalises scheme, "www."
	 * and the trailing slash and matches case-insensitively, so a shop moving to
	 * HTTPS or restoring a backup is still the same site to it. Normalising here
	 * as well would put a second, divergent opinion in the loop.
	 */
	public static function site(): string {
		return home_url();
	}

	/* ─────────────────────────── Transport ──────────────────────────── */

	/**
	 * Ask the licence server something.
	 *
	 * HTTPS only: the answer decides whether a PHP package gets installed, so
	 * the transport is the integrity guarantee. A transport failure is reported
	 * as such and never silently downgraded to "invalid" — a shop must not lose
	 * its licence because its host had a bad minute.
	 *
	 * @return array{ok:bool,data:array,error:string}
	 */
	public static function request( string $endpoint, array $body = [] ): array {
		$url = self::api() . '/' . ltrim( $endpoint, '/' );

		if ( 0 !== stripos( $url, 'https://' ) ) {
			return [ 'ok' => false, 'data' => [], 'error' => __( 'כתובת שרת הרישיונות חייבת להיות HTTPS.', 'wc-order-upsale' ) ];
		}

		$body = array_merge(
			[
				'site'    => self::site(),
				'version' => defined( 'WC_ORDER_UPSALE_VERSION' ) ? WC_ORDER_UPSALE_VERSION : '',
			],
			$body
		);

		$response = wp_remote_post( $url, [
			'timeout' => 15,
			'headers' => [ 'Accept' => 'application/json' ],
			'body'    => $body,
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'data' => [], 'error' => $response->get_error_message() ];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return [ 'ok' => false, 'data' => [], 'error' => __( 'תשובה לא תקינה משרת הרישיונות.', 'wc-order-upsale' ) ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// 429 and 5xx say nothing about the licence — they say the server could
		// not answer right now. Naming that plainly keeps a shop from reading a
		// maintenance window as a revocation.
		if ( 429 === $code ) {
			return [ 'ok' => false, 'data' => $data, 'error' => __( 'יותר מדי בקשות לשרת הרישיונות. נסו שוב בעוד דקה.', 'wc-order-upsale' ) ];
		}
		if ( $code >= 500 ) {
			return [ 'ok' => false, 'data' => $data, 'error' => __( 'שרת הרישיונות אינו זמין כרגע. המצב הקודם נשמר.', 'wc-order-upsale' ) ];
		}
		if ( $code >= 400 ) {
			return [
				'ok'    => false,
				'data'  => $data,
				'error' => (string) ( $data['message'] ?? __( 'שרת הרישיונות דחה את הבקשה.', 'wc-order-upsale' ) ),
			];
		}

		return [ 'ok' => true, 'data' => $data, 'error' => '' ];
	}

	/** Store whatever the server said about this licence. */
	private static function absorb( array $data ): void {
		$status = (string) ( $data['status'] ?? 'invalid' );

		if ( 'valid' === $status ) {
			self::put( [ 'ever_valid' => 1 ] );
		}

		self::put( [
			'status'      => $status,
			'expires'     => (string) ( $data['expires'] ?? '' ),
			'sites_limit' => (int) ( $data['sites_limit'] ?? 0 ),
			'sites_used'  => (int) ( $data['sites_used'] ?? 0 ),
			'message'     => (string) ( $data['message'] ?? '' ),
			'checked_at'  => current_time( 'mysql' ),
		] );
	}

	/**
	 * Carry an install that predates licensing — once, and only once.
	 *
	 * A shop that has been running these modules for months must not have them
	 * switch off because it updated the plugin. The question is whether module
	 * settings already existed at the moment this code first ran, which is only
	 * true of an install that predates it: a fresh one has none yet, because the
	 * shop has not reached the dashboard.
	 *
	 * The decision is taken on the first request after the update and recorded
	 * permanently in its own option. Two things depend on that:
	 *
	 *  - The marker is written *before* the check, so this can never run twice.
	 *    Otherwise a fresh install would be grandfathered the moment the shop
	 *    saved the module toggles, which is one click, and the gate would mean
	 *    nothing.
	 *  - The marker lives outside the licence option, which removing a licence
	 *    deletes. Otherwise "remove licence" would hand the modules straight
	 *    back on the next page load.
	 */
	public static function grandfather(): void {
		if ( get_option( self::MIGRATED_OPT, false ) ) {
			return;
		}
		update_option( self::MIGRATED_OPT, 1, false );

		// No module settings yet means nobody has configured this install, so
		// there is nothing here that predates licensing.
		if ( false === get_option( 'wc_store_enhancer_modules', false ) ) {
			return;
		}

		update_option( self::OPTION, array_merge( self::get(), [
			'ever_valid' => 1,
			'status'     => 'inactive',
		] ), false );
	}

	/* ─────────────────────────── Operations ─────────────────────────── */

	public static function activate( string $key ): array {
		$key = trim( $key );
		if ( '' === $key ) {
			return [ 'ok' => false, 'error' => __( 'נא להזין מפתח רישיון.', 'wc-order-upsale' ) ];
		}

		$result = self::request( 'activate', [ 'key' => $key ] );
		if ( ! $result['ok'] ) {
			// Keep the key so the shop can retry without typing it again.
			self::put( [ 'key' => $key, 'status' => 'error', 'message' => $result['error'], 'checked_at' => current_time( 'mysql' ) ] );
			return [ 'ok' => false, 'error' => $result['error'] ];
		}

		self::put( [ 'key' => $key ] );
		self::absorb( $result['data'] );
		self::forget_update_cache();

		return [ 'ok' => 'valid' === ( $result['data']['status'] ?? '' ), 'error' => (string) ( $result['data']['message'] ?? '' ) ];
	}

	public static function deactivate(): void {
		$key = self::key();
		if ( '' !== $key ) {
			// Best effort: releasing the seat is courteous, but a shop must always
			// be able to remove a key locally even if the server is unreachable.
			self::request( 'deactivate', [ 'key' => $key ] );
		}
		delete_option( self::OPTION );
		self::forget_update_cache();
	}

	public static function check(): array {
		$key = self::key();
		if ( '' === $key ) {
			return [ 'ok' => false, 'error' => '' ];
		}

		$result = self::request( 'check', [ 'key' => $key ] );
		if ( ! $result['ok'] ) {
			// Transport trouble is not a verdict — leave the last known state.
			self::put( [ 'message' => $result['error'], 'checked_at' => current_time( 'mysql' ) ] );
			return [ 'ok' => false, 'error' => $result['error'] ];
		}

		self::absorb( $result['data'] );
		self::forget_update_cache();
		return [ 'ok' => true, 'error' => '' ];
	}

	private static function forget_update_cache(): void {
		if ( class_exists( 'WC_Order_Upsale_Updater' ) ) {
			delete_site_transient( WC_Order_Upsale_Updater::CACHE_KEY );
		}
	}

	/* ─────────────────────────── Schedule ───────────────────────────── */

	public function maybe_schedule(): void {
		if ( '' !== self::key() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public function scheduled_check(): void {
		self::check();
	}

	/* ─────────────────────────── Admin ──────────────────────────────── */

	public function admin_notice(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$state = self::get();
		if ( '' === $state['key'] || 'valid' === $state['status'] ) {
			return;
		}
		// One quiet line, only where it is actionable.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->id, [ 'plugins', 'dashboard' ], true ) && false === strpos( (string) $screen->id, 'wc-store-enhancer' ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'משפר חנויות ווקומרס: הרישיון אינו פעיל.', 'wc-order-upsale' ); ?></strong>
				<?php esc_html_e( 'התוסף ממשיך לעבוד כרגיל, אך לא יקבל עדכונים עד לחידוש.', 'wc-order-upsale' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WC_Order_Upsale_Dashboard::SETTINGS_SLUG . '&tab=license' ) ); ?>">
					<?php esc_html_e( 'לניהול הרישיון', 'wc-order-upsale' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	public function register_settings_tab( array $tabs ): array {
		if ( current_user_can( 'update_plugins' ) ) {
			$tabs[] = [
				'id'       => 'license',
				'label'    => __( 'רישיון', 'wc-order-upsale' ),
				'callback' => [ $this, 'render_settings_tab' ],
			];
		}
		return $tabs;
	}

	private function guard(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Unauthorized' );
		}
	}

	private function back( string $flag ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . WC_Order_Upsale_Dashboard::SETTINGS_SLUG . '&tab=license&' . $flag . '=1' ) );
		exit;
	}

	public function handle_save(): void {
		check_admin_referer( 'wcse_license_save' );
		$this->guard();

		$key    = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );
		$result = self::activate( $key );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		$this->back( $result['ok'] ? 'activated' : 'failed' );
	}

	public function handle_remove(): void {
		check_admin_referer( 'wcse_license_remove' );
		$this->guard();

		self::deactivate();
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$this->back( 'removed' );
	}

	public function handle_refresh(): void {
		check_admin_referer( 'wcse_license_refresh' );
		$this->guard();

		self::check();
		$this->back( 'refreshed' );
	}

	/** Human wording for each machine status. */
	private function status_label( string $status ): array {
		switch ( $status ) {
			case 'valid':
				return [ __( 'פעיל', 'wc-order-upsale' ), '#125c2b' ];
			case 'expired':
				return [ __( 'פג תוקף', 'wc-order-upsale' ), '#b32d2e' ];
			case 'limit':
				return [ __( 'חריגה ממספר האתרים', 'wc-order-upsale' ), '#b32d2e' ];
			case 'invalid':
				return [ __( 'מפתח לא מוכר', 'wc-order-upsale' ), '#b32d2e' ];
			case 'error':
				return [ __( 'לא ניתן לאמת כרגע', 'wc-order-upsale' ), '#7a5c00' ];
			default:
				return [ __( 'לא הופעל', 'wc-order-upsale' ), '#7a5c00' ];
		}
	}

	/** Show only the tail of a key, so a screenshot cannot leak it. */
	private function masked_key( string $key ): string {
		$len = strlen( $key );
		return $len <= 4 ? str_repeat( '•', $len ) : str_repeat( '•', max( 4, $len - 4 ) ) . substr( $key, -4 );
	}

	public function render_settings_tab(): void {
		$state              = self::get();
		list( $label, $col ) = $this->status_label( (string) $state['status'] );
		$has_key            = '' !== $state['key'];
		?>
		<div class="wcse-admin">
			<p class="description" style="max-width:720px">
				<?php esc_html_e( 'הזינו את מפתח הרישיון שקיבלתם. המפתח מפעיל את קבלת העדכונים האוטומטיים לאתר הזה.', 'wc-order-upsale' ); ?>
			</p>

			<?php if ( isset( $_GET['activated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'הרישיון הופעל בהצלחה.', 'wc-order-upsale' ); ?></p></div>
			<?php elseif ( isset( $_GET['failed'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'הפעלת הרישיון נכשלה. הפרטים מופיעים בטבלה למטה.', 'wc-order-upsale' ); ?></p></div>
			<?php elseif ( isset( $_GET['removed'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'הרישיון הוסר מהאתר הזה.', 'wc-order-upsale' ); ?></p></div>
			<?php elseif ( isset( $_GET['refreshed'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'מצב הרישיון נבדק מחדש.', 'wc-order-upsale' ); ?></p></div>
			<?php endif; ?>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'מצב', 'wc-order-upsale' ); ?></th>
					<td>
						<strong style="color:<?php echo esc_attr( $col ); ?>"><?php echo esc_html( $label ); ?></strong>
						<?php if ( ! empty( $state['message'] ) ) : ?>
							<p class="description"><?php echo esc_html( $state['message'] ); ?></p>
						<?php endif; ?>
						<?php if ( $has_key && 'valid' !== $state['status'] ) : ?>
							<p class="description"><?php esc_html_e( 'התוסף וכל המודולים ממשיכים לעבוד. רק העדכונים מושהים.', 'wc-order-upsale' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $has_key ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'מפתח', 'wc-order-upsale' ); ?></th>
						<td><code><?php echo esc_html( $this->masked_key( (string) $state['key'] ) ); ?></code></td>
					</tr>
					<?php if ( $state['expires'] ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'בתוקף עד', 'wc-order-upsale' ); ?></th>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $state['expires'] ) ) ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'אתרים', 'wc-order-upsale' ); ?></th>
						<td>
							<?php
							echo $state['sites_limit']
								? esc_html( sprintf( '%d / %d', (int) $state['sites_used'], (int) $state['sites_limit'] ) )
								: esc_html( sprintf(
									/* translators: %d: sites currently registered */
									__( '%d — ללא הגבלה', 'wc-order-upsale' ),
									(int) $state['sites_used']
								) );
							?>
						</td>
					</tr>
					<?php if ( $state['checked_at'] ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'נבדק לאחרונה', 'wc-order-upsale' ); ?></th>
							<td><?php echo esc_html( $state['checked_at'] ); ?></td>
						</tr>
					<?php endif; ?>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'האתר הזה', 'wc-order-upsale' ); ?></th>
					<td><code><?php echo esc_html( self::site() ); ?></code></td>
				</tr>
			</table>

			<?php
			$update = class_exists( 'WC_Order_Upsale_Updater' ) ? WC_Order_Upsale_Updater::status() : null;
			if ( $has_key ) :
				?>
				<h2 style="margin-top:24px"><?php esc_html_e( 'עדכונים', 'wc-order-upsale' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'גרסה מותקנת', 'wc-order-upsale' ); ?></th>
						<td><code><?php echo esc_html( WC_ORDER_UPSALE_VERSION ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'הגרסה האחרונה', 'wc-order-upsale' ); ?></th>
						<td>
							<?php if ( $update && ! empty( $update['remote_version'] ) ) : ?>
								<code><?php echo esc_html( $update['remote_version'] ); ?></code>
								<?php if ( ! empty( $update['update_available'] ) ) : ?>
									<a class="button button-primary button-small" style="margin-inline-start:8px" href="<?php echo esc_url( self_admin_url( 'plugins.php' ) ); ?>">
										<?php esc_html_e( 'עדכנו בעמוד התוספים', 'wc-order-upsale' ); ?>
									</a>
								<?php else : ?>
									<span style="color:#125c2b">— <?php esc_html_e( 'מעודכן', 'wc-order-upsale' ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<em><?php esc_html_e( 'לא ניתן לבדוק כרגע.', 'wc-order-upsale' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<p class="description" style="max-width:680px">
					<?php esc_html_e( 'עדכונים מגיעים כמו לכל תוסף אחר: כשיוצאת גרסה חדשה היא תופיע בעמוד התוספים ובעדכוני וורדפרס, ולחיצה על "עדכן" מתקינה אותה. הבדיקה מתבצעת אוטומטית ברקע.', 'wc-order-upsale' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:22px">
					<?php wp_nonce_field( 'wc_store_enhancer_check_update' ); ?>
					<input type="hidden" name="action" value="wc_store_enhancer_check_update">
					<button type="submit" class="button"><?php esc_html_e( 'בדוק עדכונים עכשיו', 'wc-order-upsale' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( ! $has_key ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wcse_license_save' ); ?>
					<input type="hidden" name="action" value="wc_store_enhancer_license_save">
					<p>
						<label for="wcse-license-key"><strong><?php esc_html_e( 'מפתח רישיון', 'wc-order-upsale' ); ?></strong></label><br>
						<input type="text" id="wcse-license-key" name="license_key" class="regular-text" autocomplete="off" spellcheck="false" placeholder="XXXX-XXXX-XXXX-XXXX">
					</p>
					<p><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'הפעלת רישיון', 'wc-order-upsale' ); ?></button></p>
				</form>
			<?php else : ?>
				<p style="display:flex;gap:10px;flex-wrap:wrap">
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wc_store_enhancer_license_refresh' ), 'wcse_license_refresh' ) ); ?>">
						<?php esc_html_e( 'בדיקה מחדש', 'wc-order-upsale' ); ?>
					</a>
					<a class="button" style="color:#b32d2e" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wc_store_enhancer_license_remove' ), 'wcse_license_remove' ) ); ?>"
						onclick="return confirm('<?php echo esc_js( __( 'להסיר את הרישיון מהאתר הזה? ניתן להפעיל אותו מחדש או באתר אחר.', 'wc-order-upsale' ) ); ?>');">
						<?php esc_html_e( 'הסרת הרישיון מהאתר', 'wc-order-upsale' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
