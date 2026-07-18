<?php

defined( 'ABSPATH' ) || exit;

/**
 * "משפר חנויות ווקומרס" control panel.
 *
 * Registers the top-level admin menu that every feature module hangs off, and
 * renders a dashboard listing each module as a card with an enable toggle, a
 * link to its settings, and the current plugin version + update status.
 */
class WC_Order_Upsale_Dashboard {

	const MENU_SLUG = 'wc-store-enhancer';

	public function __construct() {
		// Priority 9 so the parent menu exists before modules add their submenus (priority 10).
		add_action( 'admin_menu',                                     [ $this, 'add_menu' ], 9 );
		add_action( 'admin_post_wc_store_enhancer_save_modules',      [ $this, 'save_modules' ] );
		add_action( 'admin_enqueue_scripts',                          [ $this, 'assets' ] );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'משפר חנויות ווקומרס', 'wc-order-upsale' ),
			__( 'משפר חנויות', 'wc-order-upsale' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render' ],
			'dashicons-store',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'לוח בקרה', 'wc-order-upsale' ),
			__( 'לוח בקרה', 'wc-order-upsale' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render' ]
		);
	}

	public function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		// Inline-only stylesheet: register a src-less handle and attach CSS to it.
		wp_register_style( 'wcse-dashboard', false, [], WC_ORDER_UPSALE_VERSION );
		wp_enqueue_style( 'wcse-dashboard' );
		wp_add_inline_style( 'wcse-dashboard', $this->inline_css() );
	}

	public function save_modules(): void {
		if ( ! check_admin_referer( 'wc_store_enhancer_modules_save', 'wc_store_enhancer_modules_nonce' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		$enabled = array_map( 'sanitize_key', (array) ( $_POST['modules'] ?? [] ) );
		WC_Order_Upsale_Modules::save( $enabled );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&saved=1' ) );
		exit;
	}

	public function render(): void {
		$states  = WC_Order_Upsale_Modules::get_states();
		$modules = WC_Order_Upsale_Modules::definitions();
		$update  = class_exists( 'WC_Order_Upsale_Updater' ) ? WC_Order_Upsale_Updater::status() : null;
		?>
		<div class="wrap wcse-dashboard">
			<h1><?php esc_html_e( 'משפר חנויות ווקומרס', 'wc-order-upsale' ); ?></h1>
			<p class="description" style="max-width:760px">
				<?php esc_html_e( 'לוח הבקרה של התוסף. כאן מפעילים או מכבים כל מודול ומגיעים להגדרות שלו. מודולים נוספים יתווספו בהמשך.', 'wc-order-upsale' ); ?>
			</p>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'ההגדרות נשמרו בהצלחה.', 'wc-order-upsale' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="wcse-version-bar">
				<span class="wcse-version-label">
					<?php
					printf(
						/* translators: %s: version number */
						esc_html__( 'גרסה מותקנת: %s', 'wc-order-upsale' ),
						'<strong>' . esc_html( WC_ORDER_UPSALE_VERSION ) . '</strong>'
					);
					?>
				</span>
				<?php if ( $update && ! empty( $update['update_available'] ) ) : ?>
					<span class="wcse-update-pill wcse-update-available">
						<?php
						printf(
							/* translators: %s: version number */
							esc_html__( 'עדכון זמין: %s', 'wc-order-upsale' ),
							esc_html( $update['new_version'] )
						);
						?>
						<a class="button button-primary button-small" href="<?php echo esc_url( self_admin_url( 'plugins.php' ) ); ?>">
							<?php esc_html_e( 'עדכן עכשיו', 'wc-order-upsale' ); ?>
						</a>
					</span>
				<?php elseif ( $update ) : ?>
					<span class="wcse-update-pill wcse-update-current"><?php esc_html_e( 'מעודכן לגרסה האחרונה', 'wc-order-upsale' ); ?></span>
				<?php endif; ?>
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-store-enhancer-updates' ) ); ?>">
					<?php esc_html_e( 'ניהול עדכונים', 'wc-order-upsale' ); ?>
				</a>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wc_store_enhancer_modules_save', 'wc_store_enhancer_modules_nonce' ); ?>
				<input type="hidden" name="action" value="wc_store_enhancer_save_modules">

				<div class="wcse-modules-grid">
					<?php foreach ( $modules as $id => $module ) : ?>
						<?php $enabled = ! empty( $states[ $id ] ); ?>
						<div class="wcse-module-card <?php echo $enabled ? 'is-enabled' : 'is-disabled'; ?>">
							<div class="wcse-module-icon"><span class="dashicons dashicons-<?php echo esc_attr( $module['icon'] ); ?>"></span></div>
							<div class="wcse-module-main">
								<h2><?php echo esc_html( $module['name'] ); ?></h2>
								<p><?php echo esc_html( $module['description'] ); ?></p>
								<div class="wcse-module-actions">
									<label class="wcse-switch">
										<input type="checkbox" name="modules[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $enabled ); ?>>
										<span class="wcse-switch-track" aria-hidden="true"></span>
										<span class="wcse-switch-text"><?php echo $enabled ? esc_html__( 'פעיל', 'wc-order-upsale' ) : esc_html__( 'כבוי', 'wc-order-upsale' ); ?></span>
									</label>
									<a class="button" href="<?php echo esc_url( WC_Order_Upsale_Modules::settings_url( $id ) ); ?>">
										<?php esc_html_e( 'הגדרות', 'wc-order-upsale' ); ?>
									</a>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary button-large">
						<?php esc_html_e( 'שמור מצב מודולים', 'wc-order-upsale' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	private function inline_css(): string {
		return '
		.wcse-version-bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:16px 0 8px;padding:12px 16px;background:#fff;border:1px solid #dcdcde;border-radius:8px}
		.wcse-update-pill{display:inline-flex;align-items:center;gap:8px;padding:2px 10px;border-radius:999px;font-size:13px}
		.wcse-update-available{background:#fcf3d7;color:#7a5c00}
		.wcse-update-current{background:#e7f6ec;color:#125c2b}
		.wcse-modules-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-top:16px}
		.wcse-module-card{display:flex;gap:14px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;transition:box-shadow .15s,border-color .15s}
		.wcse-module-card.is-enabled{border-color:#2271b1}
		.wcse-module-card:hover{box-shadow:0 3px 14px rgba(0,0,0,.06)}
		.wcse-module-icon .dashicons{font-size:34px;width:34px;height:34px;color:#2271b1}
		.wcse-module-card.is-disabled .wcse-module-icon .dashicons{color:#a7aaad}
		.wcse-module-main{flex:1}
		.wcse-module-main h2{margin:0 0 6px;font-size:16px}
		.wcse-module-main p{margin:0 0 14px;color:#50575e}
		.wcse-module-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
		.wcse-switch{display:inline-flex;align-items:center;gap:8px;cursor:pointer;user-select:none}
		.wcse-switch input{position:absolute;opacity:0;width:0;height:0}
		.wcse-switch-track{position:relative;width:44px;height:24px;background:#c3c4c7;border-radius:999px;transition:background .15s}
		.wcse-switch-track::after{content:"";position:absolute;top:3px;inset-inline-start:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:inset-inline-start .15s}
		.wcse-switch input:checked+.wcse-switch-track{background:#2271b1}
		.wcse-switch input:checked+.wcse-switch-track::after{inset-inline-start:23px}
		.wcse-switch input:focus-visible+.wcse-switch-track{outline:2px solid #2271b1;outline-offset:2px}
		.wcse-switch-text{font-weight:600;font-size:13px}
		';
	}
}
