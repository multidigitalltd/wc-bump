<?php

defined( 'ABSPATH' ) || exit;

/**
 * Beautiful Variations — turns WooCommerce variable-product attribute dropdowns
 * into clickable pill / color swatches on the single product page.
 *
 * The native <select> is preserved so WooCommerce's own variation JS — price
 * updates, availability, add-to-cart validation — keeps working untouched. Once
 * the JS takes over, the swatch group becomes an accessible ARIA radiogroup and
 * the <select> is hidden from both the layout and the accessibility tree; with
 * JS off, the swatches stay hidden and the native <select> remains the control.
 */
class WC_Order_Upsale_Variation_Swatches {

	const OPTION = 'wc_store_enhancer_swatches';

	/** Hook suffix of the settings page, captured so asset loading is parent-agnostic. */
	private string $hook_suffix = '';

	public function __construct() {
		// Frontend — append swatches after each variation dropdown.
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', [ $this, 'render_swatches' ], 20, 2 );
		add_action( 'wp_enqueue_scripts',                                    [ $this, 'enqueue_assets' ] );

		// Shortcode: show only a product's colour swatches (e.g. on shop/archive cards).
		add_shortcode( 'wc_color_swatches', [ $this, 'color_swatches_shortcode' ] );

		// Admin settings page.
		add_action( 'admin_menu',                                    [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts',                         [ $this, 'admin_assets' ] );
		add_action( 'admin_post_save_wc_store_enhancer_swatches',    [ $this, 'save_settings' ] );
	}

	/* ─────────────────────────── Settings ───────────────────────────── */

	public static function get_settings(): array {
		return wp_parse_args( (array) get_option( self::OPTION, [] ), [
			'shape' => 'circle', // circle | rounded | square
			'size'  => 44,
		] );
	}

	/** Master on/off comes from the dashboard module registry. */
	private function is_enabled(): bool {
		return ! class_exists( 'WC_Order_Upsale_Modules' )
			|| WC_Order_Upsale_Modules::is_enabled( 'variation_swatches' );
	}

	/* ─────────────────────────── Frontend ───────────────────────────── */

	public function enqueue_assets(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Register (not enqueue) so both the single-product page and the archive
		// shortcode can pull the assets in on demand — nothing is output until a
		// handle is actually enqueued.
		wp_register_style(
			'wc-order-upsale-swatches',
			WC_ORDER_UPSALE_URL . 'assets/css/variation-swatches.css',
			[],
			WC_ORDER_UPSALE_VERSION
		);
		wp_register_script(
			'wc-order-upsale-swatches',
			WC_ORDER_UPSALE_URL . 'assets/js/variation-swatches.js',
			[ 'jquery' ],
			WC_ORDER_UPSALE_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		$size = max( 24, min( 120, (int) self::get_settings()['size'] ) );
		wp_add_inline_style( 'wc-order-upsale-swatches', '.wcse-swatches{--wcse-size:' . $size . 'px}' );

		// Full interactive swatches load only on variable-product pages.
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product && $product->is_type( 'variable' ) ) {
				wp_enqueue_style( 'wc-order-upsale-swatches' );
				wp_enqueue_script( 'wc-order-upsale-swatches' );
			}
		}

		// Product archives are the home of the [wc_color_swatches] shortcode, and
		// that shortcode renders after wp_head(). Enqueue the stylesheet here so
		// it is reliably printed in the <head> rather than depending on late
		// enqueue when the shortcode runs inside the loop.
		if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) ) {
			wp_enqueue_style( 'wc-order-upsale-swatches' );
		}
	}

	/**
	 * Filter callback: append swatch markup after the native dropdown HTML.
	 *
	 * @param string $html Original <select> HTML built by WooCommerce.
	 * @param array  $args Dropdown args (product, attribute, options, selected…).
	 */
	public function render_swatches( string $html, array $args ): string {
		if ( ! $this->is_enabled() ) {
			return $html;
		}

		$product   = $args['product']   ?? null;
		$attribute = $args['attribute'] ?? '';
		if ( ! $product instanceof WC_Product || '' === $attribute ) {
			return $html;
		}

		$options = $args['options'] ?? [];
		if ( empty( $options ) ) {
			$variation_attributes = $product->get_variation_attributes();
			$options              = $variation_attributes[ $attribute ] ?? [];
		}
		if ( empty( $options ) ) {
			return $html;
		}

		$selected    = (string) ( $args['selected'] ?? '' );
		$is_taxonomy = taxonomy_exists( $attribute );
		$label       = wc_attribute_label( $attribute, $product );
		$is_color    = $this->is_color_attribute( $attribute, $label );

		$buttons = '';

		if ( $is_taxonomy ) {
			$terms = wc_get_product_terms( $product->get_id(), $attribute, [ 'fields' => 'all' ] );
			foreach ( $terms as $term ) {
				if ( ! in_array( $term->slug, $options, true ) ) {
					continue;
				}
				$name  = apply_filters( 'woocommerce_variation_option_name', $term->name, $term, $attribute, $product );
				$color = $is_color ? $this->resolve_color( $term->name, $term->slug, $term->term_id ) : '';
				$buttons .= $this->button( $term->slug, (string) $name, $color, sanitize_title( $selected ) === $term->slug );
			}
		} else {
			foreach ( $options as $option ) {
				$name   = apply_filters( 'woocommerce_variation_option_name', $option, null, $attribute, $product );
				$color  = $is_color ? $this->resolve_color( (string) $option, sanitize_title( $option ), 0 ) : '';
				$is_sel = sanitize_title( $selected ) === $selected
					? $selected === sanitize_title( $option )
					: $selected === $option;
				$buttons .= $this->button( (string) $option, (string) $name, $color, $is_sel );
			}
		}

		if ( '' === $buttons ) {
			return $html;
		}

		$shape   = self::get_settings()['shape'];
		$classes = 'wcse-swatches wcse-shape-' . sanitize_html_class( $shape ) . ( $is_color ? ' wcse-is-color' : ' wcse-is-label' );

		$wrap = '<div class="' . esc_attr( $classes ) . '" role="radiogroup"'
			. ' aria-label="' . esc_attr( $label ) . '"'
			. ' data-attribute="attribute_' . esc_attr( sanitize_title( $attribute ) ) . '">'
			. $buttons
			. '</div>';

		return $html . $wrap;
	}

	/** Build a single swatch button. */
	private function button( string $value, string $label, string $color, bool $selected ): string {
		// The colour-circle vs text-pill styling is driven by a per-button
		// modifier class, so an unresolved colour inside a colour attribute still
		// renders as a proper text pill instead of an overflowing circle.
		if ( '' !== $color ) {
			$modifier = ' wcse-swatch--color';
			$inner    = '<span class="wcse-swatch-color" aria-hidden="true"></span>';
			$style    = ' style="--wcse-color:' . esc_attr( $color ) . '"';
		} else {
			$modifier = ' wcse-swatch--label';
			$inner    = '<span class="wcse-swatch-text">' . esc_html( $label ) . '</span>';
			$style    = '';
		}

		return sprintf(
			'<button type="button" class="wcse-swatch%1$s%2$s" role="radio" aria-checked="%3$s" data-value="%4$s" title="%5$s" aria-label="%5$s"%6$s>%7$s</button>',
			$modifier,
			$selected ? ' is-selected' : '',
			$selected ? 'true' : 'false',
			esc_attr( $value ),
			esc_attr( $label ),
			$style,
			$inner
		);
	}

	/* ─────────────────────────── Archive shortcode ──────────────────── */

	/**
	 * Shortcode [wc_color_swatches] — renders only a product's colour swatches,
	 * for shop/category archive cards. Read-only; each swatch links to the
	 * product with that colour preselected.
	 *
	 * Attributes:
	 *   id   — product ID (defaults to the current loop product)
	 *   size — swatch size in px (defaults to a compact archive size)
	 *   link — "yes" (default) links each colour to the product, "no" for plain display
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function color_swatches_shortcode( $atts ): string {
		if ( ! $this->is_enabled() ) {
			return '';
		}

		$atts = shortcode_atts( [
			'id'   => 0,
			'size' => 0,
			'link' => 'yes',
		], $atts, 'wc_color_swatches' );

		$product = absint( $atts['id'] )
			? wc_get_product( absint( $atts['id'] ) )
			: ( $GLOBALS['product'] ?? null );
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
			return '';
		}

		// Find the first colour attribute among the product's variation attributes.
		$attribute = '';
		$options   = [];
		foreach ( $product->get_variation_attributes() as $name => $opts ) {
			if ( $this->is_color_attribute( $name, wc_attribute_label( $name, $product ) ) ) {
				$attribute = $name;
				$options   = $opts;
				break;
			}
		}
		if ( '' === $attribute || empty( $options ) ) {
			return '';
		}

		$do_link   = 'no' !== strtolower( (string) $atts['link'] );
		$permalink = (string) get_permalink( $product->get_id() );
		$query_key = 'attribute_' . sanitize_title( $attribute );

		$items  = '';
		$labels = [];

		if ( taxonomy_exists( $attribute ) ) {
			$terms = wc_get_product_terms( $product->get_id(), $attribute, [ 'fields' => 'all' ] );
			foreach ( $terms as $term ) {
				if ( ! in_array( $term->slug, $options, true ) ) {
					continue;
				}
				$name     = (string) apply_filters( 'woocommerce_variation_option_name', $term->name, $term, $attribute, $product );
				$color    = $this->resolve_color( $term->name, $term->slug, $term->term_id );
				$items   .= $this->archive_swatch( $term->slug, $name, $color, $do_link, $permalink, $query_key );
				$labels[] = $name;
			}
		} else {
			foreach ( $options as $option ) {
				$name     = (string) apply_filters( 'woocommerce_variation_option_name', $option, null, $attribute, $product );
				$color    = $this->resolve_color( (string) $option, sanitize_title( $option ), 0 );
				$items   .= $this->archive_swatch( (string) $option, $name, $color, $do_link, $permalink, $query_key );
				$labels[] = $name;
			}
		}

		if ( '' === $items ) {
			return '';
		}

		wp_enqueue_style( 'wc-order-upsale-swatches' );

		$size  = absint( $atts['size'] );
		$style = $size ? ' style="--wcse-size:' . esc_attr( (string) max( 14, min( 80, $size ) ) ) . 'px"' : '';

		/* translators: %s: comma-separated colour names */
		$group_label = sprintf( __( 'צבעים זמינים: %s', 'wc-order-upsale' ), implode( ', ', $labels ) );

		return '<div class="wcse-swatches wcse-ready wcse-is-color wcse-archive" role="group" aria-label="'
			. esc_attr( $group_label ) . '"' . $style . '>' . $items . '</div>';
	}

	/** One read-only archive colour swatch (link when enabled, otherwise a span). */
	private function archive_swatch( string $value, string $label, string $color, bool $link, string $permalink, string $query_key ): string {
		if ( '' !== $color ) {
			$modifier = ' wcse-swatch--color';
			$inner    = '<span class="wcse-swatch-color" aria-hidden="true"></span>';
			$style    = ' style="--wcse-color:' . esc_attr( $color ) . '"';
		} else {
			$modifier = ' wcse-swatch--label';
			$inner    = '<span class="wcse-swatch-text">' . esc_html( $label ) . '</span>';
			$style    = '';
		}

		if ( $link && '' !== $permalink ) {
			$href = esc_url( add_query_arg( [ $query_key => $value ], $permalink ) );
			return '<a class="wcse-swatch' . $modifier . '" href="' . $href . '"'
				. ' aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '"' . $style . '>' . $inner . '</a>';
		}

		return '<span class="wcse-swatch' . $modifier . '" role="img"'
			. ' aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '"' . $style . '>' . $inner . '</span>';
	}

	/* ─────────────────────────── Colour helpers ─────────────────────── */

	/** Whether an attribute should render as colour swatches (by name). */
	private function is_color_attribute( string $attribute, string $label ): bool {
		$attr = strtolower( $attribute );
		if ( false !== strpos( $attr, 'color' ) || false !== strpos( $attr, 'colour' ) ) {
			return true;
		}
		if ( false !== strpos( $attribute, 'צבע' ) || false !== strpos( $label, 'צבע' ) ) {
			return true;
		}

		/**
		 * Force an attribute to be treated (or not) as a colour swatch.
		 *
		 * @param bool   $is_color
		 * @param string $attribute
		 * @param string $label
		 */
		return (bool) apply_filters( 'wc_store_enhancer_is_color_attribute', false, $attribute, $label );
	}

	/** Resolve a CSS colour for an option, or '' to fall back to a text pill. */
	private function resolve_color( string $name, string $slug, int $term_id ): string {
		// 1. Colour stored as term meta by common swatch plugins.
		if ( $term_id > 0 ) {
			foreach ( [ 'product_attribute_color', 'wc_swatches_color', 'swatch_color', 'color', 'colour' ] as $key ) {
				$meta = get_term_meta( $term_id, $key, true );
				if ( is_string( $meta ) && $this->is_hex( $meta ) ) {
					return $meta;
				}
			}
		}

		// 2. The option itself is a hex value.
		if ( $this->is_hex( $name ) ) {
			return $name;
		}

		// 3. Known colour name (Hebrew or English).
		$map        = $this->color_map();
		$lower_name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
		$candidates = [ trim( $lower_name ), str_replace( '-', ' ', strtolower( $slug ) ) ];
		foreach ( $candidates as $candidate ) {
			if ( isset( $map[ $candidate ] ) ) {
				return $map[ $candidate ];
			}
		}

		/**
		 * Supply a colour for an option name the built-in map does not know.
		 *
		 * @param string $color Empty string by default.
		 * @param string $name  Option display name.
		 * @param string $slug  Option slug.
		 */
		$filtered = apply_filters( 'wc_store_enhancer_option_color', '', $name, $slug );
		return $this->is_hex( (string) $filtered ) ? (string) $filtered : '';
	}

	private function is_hex( string $value ): bool {
		return (bool) preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value );
	}

	/** Common colour-name → hex map (Hebrew + English). */
	private function color_map(): array {
		return [
			// English
			'black' => '#111111', 'white' => '#ffffff', 'red' => '#e02424',
			'green' => '#16a34a', 'blue' => '#2563eb', 'navy' => '#1e2a5a',
			'yellow' => '#facc15', 'orange' => '#f97316', 'purple' => '#7c3aed',
			'pink' => '#ec4899', 'gray' => '#9ca3af', 'grey' => '#9ca3af',
			'brown' => '#92400e', 'beige' => '#e8d9b5', 'gold' => '#d4af37',
			'silver' => '#c0c0c0', 'turquoise' => '#14b8a6', 'teal' => '#14b8a6',
			'cyan' => '#06b6d4', 'magenta' => '#d946ef', 'maroon' => '#7f1d1d',
			'olive' => '#808000', 'lime' => '#84cc16', 'cream' => '#f5f0e1',
			'ivory' => '#fffff0', 'khaki' => '#b5a642', 'burgundy' => '#7b1e3a',
			// Hebrew
			'שחור' => '#111111', 'לבן' => '#ffffff', 'אדום' => '#e02424',
			'ירוק' => '#16a34a', 'כחול' => '#2563eb', 'נייבי' => '#1e2a5a',
			'כחול נייבי' => '#1e2a5a', 'צהוב' => '#facc15', 'כתום' => '#f97316',
			'סגול' => '#7c3aed', 'ורוד' => '#ec4899', 'אפור' => '#9ca3af',
			'חום' => '#92400e', "בז'" => '#e8d9b5', 'בז' => '#e8d9b5',
			'זהב' => '#d4af37', 'כסף' => '#c0c0c0', 'טורקיז' => '#14b8a6',
			'תכלת' => '#7dd3fc', 'ורוד בהיר' => '#f9a8d4', 'בורדו' => '#7b1e3a',
			'קרם' => '#f5f0e1', 'זית' => '#808000', 'אפור בהיר' => '#d1d5db',
			'אפור כהה' => '#4b5563',
		];
	}

	/* ─────────────────────────── Admin page ─────────────────────────── */

	public function add_menu(): void {
		$parent = class_exists( 'WC_Order_Upsale_Dashboard' )
			? WC_Order_Upsale_Dashboard::MENU_SLUG
			: 'woocommerce';

		$this->hook_suffix = (string) add_submenu_page(
			$parent,
			__( 'וריאציות יפות', 'wc-order-upsale' ),
			__( 'וריאציות יפות', 'wc-order-upsale' ),
			'manage_woocommerce',
			'wc-store-enhancer-swatches',
			[ $this, 'render_page' ]
		);
	}

	public function admin_assets( string $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'wc-order-upsale-swatches',
			WC_ORDER_UPSALE_URL . 'assets/css/variation-swatches.css',
			[],
			WC_ORDER_UPSALE_VERSION
		);
	}

	public function save_settings(): void {
		if ( ! check_admin_referer( 'wc_store_enhancer_swatches_save', 'wc_store_enhancer_swatches_nonce' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		$shape = in_array( $_POST['shape'] ?? '', [ 'circle', 'rounded', 'square' ], true )
			? sanitize_key( wp_unslash( $_POST['shape'] ) ) : 'circle';

		update_option( self::OPTION, [
			'shape' => $shape,
			'size'  => max( 24, min( 120, absint( $_POST['size'] ?? 44 ) ) ),
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=wc-store-enhancer-swatches&saved=1' ) );
		exit;
	}

	public function render_page(): void {
		$settings = self::get_settings();
		$size     = max( 24, min( 120, (int) $settings['size'] ) );
		?>
		<div class="wrap wcse-admin">
			<h1><?php esc_html_e( 'וריאציות יפות', 'wc-order-upsale' ); ?></h1>
			<p class="description" style="max-width:720px">
				<?php esc_html_e( 'הפיצ\'ר ממיר אוטומטית את תפריטי הבחירה של וריאציות המוצר (מידה, אורך, צבע וכו\') לכפתורים עגולים ויפים בעמוד המוצר. תכונות "צבע" מזוהות אוטומטית ומוצגות כעיגולי צבע.', 'wc-order-upsale' ); ?>
			</p>

				<div class="notice notice-info inline" style="max-width:720px">
					<p>
						<strong><?php esc_html_e( 'שורטקוד לארכיון:', 'wc-order-upsale' ); ?></strong>
						<code>[wc_color_swatches]</code>
						<?php esc_html_e( '— מציג רק את עיגולי הצבע של המוצר (לשימוש בכרטיס מוצר בארכיון/קטגוריה). כל צבע מקשר למוצר עם הצבע נבחר מראש.', 'wc-order-upsale' ); ?>
						<br>
						<?php esc_html_e( 'אפשרויות: ', 'wc-order-upsale' ); ?>
						<code>[wc_color_swatches id="123" size="22" link="no"]</code>
					</p>
				</div>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'ההגדרות נשמרו בהצלחה.', 'wc-order-upsale' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wc_store_enhancer_swatches_save', 'wc_store_enhancer_swatches_nonce' ); ?>
				<input type="hidden" name="action" value="save_wc_store_enhancer_swatches">

				<?php if ( ! $this->is_enabled() ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php esc_html_e( 'המודול כבוי כרגע. הפעילו אותו מלוח הבקרה כדי שהכפתורים יופיעו בחזית.', 'wc-order-upsale' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WC_Order_Upsale_Dashboard::MENU_SLUG ) ); ?>"><?php esc_html_e( 'לוח הבקרה', 'wc-order-upsale' ); ?></a>
						</p>
					</div>
				<?php endif; ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="wcse-shape"><?php esc_html_e( 'צורת הכפתור', 'wc-order-upsale' ); ?></label></th>
						<td>
							<select id="wcse-shape" name="shape">
								<option value="circle"  <?php selected( $settings['shape'], 'circle' ); ?>><?php esc_html_e( 'עגול', 'wc-order-upsale' ); ?></option>
								<option value="rounded" <?php selected( $settings['shape'], 'rounded' ); ?>><?php esc_html_e( 'פינות מעוגלות', 'wc-order-upsale' ); ?></option>
								<option value="square"  <?php selected( $settings['shape'], 'square' ); ?>><?php esc_html_e( 'מרובע', 'wc-order-upsale' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-size"><?php esc_html_e( 'גודל (px)', 'wc-order-upsale' ); ?></label></th>
						<td>
							<input type="number" id="wcse-size" name="size" value="<?php echo esc_attr( $size ); ?>" min="24" max="120" step="1" style="width:90px">
							<p class="description"><?php esc_html_e( 'גובה/רוחב הכפתורים. ברירת מחדל: 44.', 'wc-order-upsale' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'תצוגה מקדימה', 'wc-order-upsale' ); ?></h2>
				<div class="wcse-preview" style="--wcse-size:<?php echo esc_attr( $size ); ?>px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;max-width:520px" dir="rtl">
					<p style="margin:0 0 6px;font-weight:600"><?php esc_html_e( 'אורך', 'wc-order-upsale' ); ?></p>
					<div class="wcse-swatches wcse-ready wcse-shape-<?php echo esc_attr( $settings['shape'] ); ?> wcse-is-label">
						<?php foreach ( [ '90cm', '86cm', '83cm', '80cm', '78cm', '74cm', '70cm' ] as $i => $v ) : ?>
							<button type="button" class="wcse-swatch wcse-swatch--label<?php echo 0 === $i ? ' is-selected' : ''; ?>"><span class="wcse-swatch-text"><?php echo esc_html( $v ); ?></span></button>
						<?php endforeach; ?>
					</div>
					<p style="margin:16px 0 6px;font-weight:600"><?php esc_html_e( 'מידה', 'wc-order-upsale' ); ?></p>
					<div class="wcse-swatches wcse-ready wcse-shape-<?php echo esc_attr( $settings['shape'] ); ?> wcse-is-label">
						<?php foreach ( [ '44', '42', '40', '38', '36', '34' ] as $i => $v ) : ?>
							<button type="button" class="wcse-swatch wcse-swatch--label<?php echo 2 === $i ? ' is-selected' : ''; ?>"><span class="wcse-swatch-text"><?php echo esc_html( $v ); ?></span></button>
						<?php endforeach; ?>
					</div>
					<p style="margin:16px 0 6px;font-weight:600"><?php esc_html_e( 'צבע', 'wc-order-upsale' ); ?></p>
					<div class="wcse-swatches wcse-ready wcse-shape-<?php echo esc_attr( $settings['shape'] ); ?> wcse-is-color">
						<?php foreach ( [ '#111111', '#1e2a5a' ] as $i => $c ) : ?>
							<button type="button" class="wcse-swatch wcse-swatch--color<?php echo 0 === $i ? ' is-selected' : ''; ?>" style="--wcse-color:<?php echo esc_attr( $c ); ?>"><span class="wcse-swatch-color"></span></button>
						<?php endforeach; ?>
					</div>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary button-large">
						<?php esc_html_e( 'שמור הגדרות', 'wc-order-upsale' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}
}
