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

	const MENU_SLUG     = 'wc-store-enhancer';
	const SETTINGS_SLUG = 'wc-store-enhancer-settings';

	/** Hook suffix of the shared settings page, captured for asset loading. */
	private string $settings_hook = '';

	public function __construct() {
		// Priority 9 so the parent menu exists before modules add their submenus (priority 10).
		add_action( 'admin_menu',                                     [ $this, 'add_menu' ], 9 );
		// Priority 100 so the shared "הגדרות" page is registered last in the submenu order.
		add_action( 'admin_menu',                                     [ $this, 'add_settings_menu' ], 100 );
		add_action( 'admin_post_wc_store_enhancer_save_modules',      [ $this, 'save_modules' ] );
		add_action( 'admin_enqueue_scripts',                          [ $this, 'assets' ] );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'משפר המרות ווקומרס', 'wc-order-upsale' ),
			__( 'משפר המרות', 'wc-order-upsale' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render' ],
			'dashicons-chart-line',
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

	/** The shared, tabbed "הגדרות" page — registered last so it sits at the bottom. */
	public function add_settings_menu(): void {
		$this->settings_hook = (string) add_submenu_page(
			self::MENU_SLUG,
			__( 'הגדרות', 'wc-order-upsale' ),
			__( 'הגדרות', 'wc-order-upsale' ),
			'manage_woocommerce',
			self::SETTINGS_SLUG,
			[ $this, 'render_settings' ]
		);
	}

	public function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG === $hook ) {
			// Inline-only stylesheet: register a src-less handle and attach CSS to it.
			wp_register_style( 'wcse-dashboard', false, [], WC_ORDER_UPSALE_VERSION );
			wp_enqueue_style( 'wcse-dashboard' );
			wp_add_inline_style( 'wcse-dashboard', $this->inline_css() );
			return;
		}

		// Settings page needs the swatch stylesheet for the live preview.
		if ( '' !== $this->settings_hook && $hook === $this->settings_hook ) {
			wp_enqueue_style(
				'wc-order-upsale-swatches',
				WC_ORDER_UPSALE_URL . 'assets/css/variation-swatches.css',
				[],
				WC_ORDER_UPSALE_VERSION
			);
		}
	}

	/** Render the shared settings page with a tab per registered module. */
	public function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		/**
		 * Modules register their settings tabs here.
		 *
		 * @param array<int,array{id:string,label:string,callback:callable}> $tabs
		 */
		$tabs = (array) apply_filters( 'wc_store_enhancer_settings_tabs', [] );
		$tabs = array_values( array_filter( $tabs, static fn( $t ) => ! empty( $t['id'] ) && is_callable( $t['callback'] ?? null ) ) );

		if ( empty( $tabs ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'הגדרות', 'wc-order-upsale' ) . '</h1><p>'
				. esc_html__( 'אין הגדרות זמינות.', 'wc-order-upsale' ) . '</p></div>';
			return;
		}

		$ids       = wp_list_pluck( $tabs, 'id' );
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active    = in_array( $requested, $ids, true ) ? $requested : $ids[0];
		?>
		<div class="wrap wcse-settings">
			<h1><?php esc_html_e( 'הגדרות — משפר המרות', 'wc-order-upsale' ); ?></h1>

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'לשוניות הגדרות', 'wc-order-upsale' ); ?>">
				<?php foreach ( $tabs as $tab ) : ?>
					<a class="nav-tab <?php echo $tab['id'] === $active ? 'nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&tab=' . $tab['id'] ) ); ?>">
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="wcse-settings-tab" style="margin-top:16px">
				<?php
				foreach ( $tabs as $tab ) {
					if ( $tab['id'] === $active ) {
						call_user_func( $tab['callback'] );
						break;
					}
				}
				?>
			</div>
		</div>
		<?php
	}

	public function save_modules(): void {
		if ( ! check_admin_referer( 'wc_store_enhancer_modules_save', 'wc_store_enhancer_modules_nonce' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		$enabled = array_map( 'sanitize_key', (array) wp_unslash( $_POST['modules'] ?? [] ) );
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
			<h1><?php esc_html_e( 'משפר המרות ווקומרס', 'wc-order-upsale' ); ?></h1>
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
				<?php $can_update = current_user_can( 'update_plugins' ); ?>
				<?php if ( $update && ! empty( $update['update_available'] ) ) : ?>
					<span class="wcse-update-pill wcse-update-available">
						<?php
						printf(
							/* translators: %s: version number */
							esc_html__( 'עדכון זמין: %s', 'wc-order-upsale' ),
							esc_html( $update['new_version'] )
						);
						?>
						<?php if ( $can_update ) : ?>
							<a class="button button-primary button-small" href="<?php echo esc_url( self_admin_url( 'plugins.php' ) ); ?>">
								<?php esc_html_e( 'עדכן עכשיו', 'wc-order-upsale' ); ?>
							</a>
						<?php endif; ?>
					</span>
				<?php elseif ( $update ) : ?>
					<span class="wcse-update-pill wcse-update-current"><?php esc_html_e( 'מעודכן לגרסה האחרונה', 'wc-order-upsale' ); ?></span>
				<?php endif; ?>
				<?php if ( $can_update ) : ?>
					<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&tab=license' ) ); ?>">
						<?php esc_html_e( 'רישיון', 'wc-order-upsale' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( class_exists( 'WC_Order_Upsale_License' ) && ! WC_Order_Upsale_License::has_been_licensed() ) : ?>
				<div class="notice notice-warning inline" style="max-width:820px">
					<p>
						<strong><?php esc_html_e( 'המודולים אינם פעילים עדיין — נדרש מפתח רישיון.', 'wc-order-upsale' ); ?></strong><br>
						<?php esc_html_e( 'אפשר להגדיר הכל כאן מראש; ההגדרות נשמרות, והמודולים שסימנתם ידלקו ברגע שהמפתח יוזן. לאחר ההפעלה הם ימשיכו לעבוד גם אם הרישיון יפוג — אז רק העדכונים נעצרים.', 'wc-order-upsale' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&tab=license' ) ); ?>">
							<?php esc_html_e( 'הזנת מפתח רישיון', 'wc-order-upsale' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<?php $this->render_impact(); ?>

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
								<?php if ( ! empty( $module['benefit'] ) ) : ?>
									<p class="wcse-module-benefit">
										<span class="wcse-benefit-icon" aria-hidden="true">📊</span>
										<span><?php echo esc_html( $module['benefit'] ); ?></span>
										<?php if ( ! empty( $module['benefit_source'] ) ) : ?>
											<a href="<?php echo esc_url( $module['benefit_source'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'מקור', 'wc-order-upsale' ); ?></a>
										<?php endif; ?>
									</p>
								<?php endif; ?>
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

			<?php $email = self::service_email(); ?>
			<div class="wcse-cta">
				<div class="wcse-cta-icon"><span class="dashicons dashicons-hammer"></span></div>
				<div class="wcse-cta-body">
					<h2><?php esc_html_e( 'צריכים פיתוח מותאם לחנות?', 'wc-order-upsale' ); ?></h2>
					<p><?php esc_html_e( 'צוות Multi Digital מפתח פיצ׳רים, אינטגרציות ואוטומציות ל-WooCommerce לפי הצורך שלכם. נשמח להצעת מחיר.', 'wc-order-upsale' ); ?></p>
					<p class="wcse-cta-actions">
						<a class="button button-primary" href="<?php echo esc_url( 'mailto:' . $email . '?subject=' . rawurlencode( __( 'הזמנת פיתוח — משפר המרות ווקומרס', 'wc-order-upsale' ) ) ); ?>">
							<?php esc_html_e( 'להזמנת פיתוח במייל', 'wc-order-upsale' ); ?>
						</a>
						<a class="button" href="https://m-d.co.il" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'לאתר שלנו', 'wc-order-upsale' ); ?></a>
						<span class="wcse-cta-email"><?php echo esc_html( $email ); ?></span>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/** Service e-mail shown in the "order development" box (filterable). */
	/**
	 * Impact panel: what the modules actually did, from real orders.
	 *
	 * Everything here is already stored — three aggregate queries behind a short
	 * transient. Nothing is measured on the storefront to produce it.
	 */
	private function render_impact(): void {
		if ( ! class_exists( 'WC_Order_Upsale_Impact' ) ) {
			return;
		}

		$totals   = WC_Order_Upsale_Impact::totals();
		$headline = WC_Order_Upsale_Impact::headline();
		$defs     = WC_Order_Upsale_Modules::definitions();

		// Only modules that can be credited to an order appear here; the synthetic
		// total row is the headline, not a module.
		$tracked = [ 'order_upsale', 'abandoned_cart', 'back_in_stock', 'buy_now', 'sticky_atc' ];
		$rows    = [];
		foreach ( $tracked as $id ) {
			$data = $totals[ $id ] ?? [];
			if ( empty( $data['orders'] ) && empty( $data['revenue'] ) ) {
				continue;
			}
			$rows[ $id ] = $data;
		}
		?>
		<div class="wcse-impact">
			<h2><?php esc_html_e( 'מה המודולים עשו בפועל', 'wc-order-upsale' ); ?></h2>

			<?php if ( empty( $rows ) ) : ?>
				<p class="description">
					<?php esc_html_e( 'עדיין אין נתונים. המספרים מתחילים להצטבר מרגע שלקוחות משתמשים במודולים ומשלימים הזמנות.', 'wc-order-upsale' ); ?>
				</p>
			<?php else : ?>
				<div class="wcse-impact-headline">
					<div>
						<span class="wcse-impact-num"><?php echo esc_html( number_format_i18n( $headline['orders'] ) ); ?></span>
						<span class="wcse-impact-cap"><?php esc_html_e( 'הזמנות בהשתתפות המודולים', 'wc-order-upsale' ); ?></span>
					</div>
					<div>
						<span class="wcse-impact-num"><?php echo wp_kses_post( wc_price( $headline['revenue'] ) ); ?></span>
						<span class="wcse-impact-cap"><?php esc_html_e( 'שווי ההזמנות האלה', 'wc-order-upsale' ); ?></span>
					</div>
				</div>

				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'מודול', 'wc-order-upsale' ); ?></th>
							<th><?php esc_html_e( 'הזמנות', 'wc-order-upsale' ); ?></th>
							<th><?php esc_html_e( 'שווי ההזמנות', 'wc-order-upsale' ); ?></th>
							<th><?php esc_html_e( 'פירוט', 'wc-order-upsale' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $id => $data ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $defs[ $id ]['name'] ?? $id ); ?></strong></td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $data['orders'] ?? 0 ) ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( (float) ( $data['revenue'] ?? 0 ) ) ); ?></td>
							<td class="description">
								<?php
								if ( 'order_upsale' === $id && ! empty( $data['impressions'] ) ) {
									printf(
										/* translators: 1: impressions, 2: add to cart, 3: conversion rate, 4: revenue from the upsale items themselves */
										esc_html__( '%1$s הצגות · %2$s הוספות לסל · %3$s המרה · %4$s מהאפסייל עצמו', 'wc-order-upsale' ),
										esc_html( number_format_i18n( (int) $data['impressions'] ) ),
										esc_html( number_format_i18n( (int) ( $data['add_to_cart'] ?? 0 ) ) ),
										esc_html( WC_Order_Upsale_Analytics::format_rate( (int) ( $data['orders'] ?? 0 ), (int) $data['impressions'] ) ),
										wp_kses_post( wc_price( (float) ( $data['direct_revenue'] ?? 0 ) ) )
									);
								} elseif ( 'abandoned_cart' === $id && ! empty( $data['sent'] ) ) {
									printf(
										/* translators: 1: mails sent, 2: recovery rate */
										esc_html__( '%1$s מיילים נשלחו · %2$s שוחזרו', 'wc-order-upsale' ),
										esc_html( number_format_i18n( (int) $data['sent'] ) ),
										esc_html( WC_Order_Upsale_Analytics::format_rate( (int) ( $data['orders'] ?? 0 ), (int) $data['sent'] ) )
									);
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p class="description wcse-impact-note">
				<?php esc_html_e( 'המספרים סופרים הזמנות שהלקוח באמת השתמש במודול בדרך אליהן — לא הערכה של כמה היה נמכר בלעדיו. המספרים למעלה סופרים כל הזמנה פעם אחת בלבד. בטבלה, הזמנה שעברה דרך שני מודולים מופיעה אצל שניהם, ולכן חיבור השורות ייתן יותר מהסכום האמיתי.', 'wc-order-upsale' ); ?>
				<?php esc_html_e( 'נתונים שנצברו לפני העדכון לגרסה 1.24 נספרים בכותרת כאומדן זהיר כלפי מטה, כי החפיפה בין המודולים לא נרשמה אז.', 'wc-order-upsale' ); ?>
			</p>
		</div>
		<?php
	}

	private static function service_email(): string {
		return sanitize_email( apply_filters( 'wc_store_enhancer_service_email', 'service@multidigital.co.il' ) );
	}

	private function inline_css(): string {
		return '
		.wcse-impact{margin:16px 0;padding:16px 18px;background:#fff;border:1px solid #dcdcde;border-radius:8px}
		.wcse-impact h2{margin:0 0 12px;font-size:15px}
		.wcse-impact-headline{display:flex;gap:34px;flex-wrap:wrap;margin:0 0 16px}
		.wcse-impact-num{display:block;font-size:26px;font-weight:700;line-height:1.2}
		.wcse-impact-cap{display:block;font-size:12px;opacity:.7}
		.wcse-impact-note{margin-top:12px;max-width:760px}
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
		.wcse-module-benefit{display:flex;gap:8px;align-items:flex-start;margin:0 0 14px !important;padding:10px 12px;background:#eef4fb;border:1px solid #cfe0f3;border-radius:8px;color:#1d3c63 !important;font-size:13px;line-height:1.5}
		.wcse-benefit-icon{flex:0 0 auto}
		.wcse-module-benefit a{white-space:nowrap}
		.wcse-module-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
		.wcse-switch{display:inline-flex;align-items:center;gap:8px;cursor:pointer;user-select:none}
		.wcse-switch input{position:absolute;opacity:0;width:0;height:0}
		.wcse-switch-track{position:relative;width:44px;height:24px;background:#c3c4c7;border-radius:999px;transition:background .15s}
		.wcse-switch-track::after{content:"";position:absolute;top:3px;inset-inline-start:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:inset-inline-start .15s}
		.wcse-switch input:checked+.wcse-switch-track{background:#2271b1}
		.wcse-switch input:checked+.wcse-switch-track::after{inset-inline-start:23px}
		.wcse-switch input:focus-visible+.wcse-switch-track{outline:2px solid #2271b1;outline-offset:2px}
		.wcse-switch-text{font-weight:600;font-size:13px}
		.wcse-cta{display:flex;gap:16px;align-items:flex-start;margin-top:26px;padding:20px 22px;background:linear-gradient(135deg,#1e2a5a,#2b3f8c);border-radius:12px;color:#fff}
		.wcse-cta-icon .dashicons{font-size:30px;width:30px;height:30px;color:#cdd6ff}
		.wcse-cta-body h2{margin:0 0 6px;color:#fff;font-size:18px}
		.wcse-cta-body p{margin:0 0 12px;color:#dfe4fb;max-width:640px}
		.wcse-cta-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
		.wcse-cta-email{font-family:monospace;font-size:13px;color:#cdd6ff}
		';
	}
}
