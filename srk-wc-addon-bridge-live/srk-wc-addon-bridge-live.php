<?php
/**
 * Plugin Name: SRK WC Add-On Bridge Live
 * Description: Exposes product add-on and attribute meta through a protected custom REST endpoint for dev-site syncing.
 * Version: 1.1.0
 * Author: Sumon Rahman Kabbo
 * Author URI: https://sumonrahmankabbo.com/
 * Text Domain: srk-wc-addon-bridge-live
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SRK_WC_Addon_Bridge_Live' ) ) {

	class SRK_WC_Addon_Bridge_Live {

		const OPTION_KEY = 'srk_wc_addon_bridge_live_settings';
		const MENU_SLUG  = 'srk-wc-addon-bridge-live';

		public function __construct() {
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		public function admin_menu() {
			add_submenu_page(
				'woocommerce',
				'SRK Add-On Bridge',
				'SRK Add-On Bridge',
				'manage_woocommerce',
				self::MENU_SLUG,
				array( $this, 'render_page' )
			);
		}

		public function register_settings() {
			register_setting( 'srk_wc_addon_bridge_live_group', self::OPTION_KEY, array( $this, 'sanitize_settings' ) );
		}

		public function sanitize_settings( $input ) {
			$secret = isset( $input['secret_key'] ) ? sanitize_text_field( trim( $input['secret_key'] ) ) : '';
			if ( empty( $secret ) ) {
				$secret = wp_generate_password( 24, false, false );
			}
			return array( 'secret_key' => $secret );
		}

		private function get_settings() {
			return wp_parse_args( get_option( self::OPTION_KEY, array() ), array( 'secret_key' => '' ) );
		}

		public function render_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			$settings = $this->get_settings();
			?>
			<div class="wrap">
				<h1>SRK WC Add-On Bridge Live</h1>
				<p>Install this plugin on the live site. It exposes a protected endpoint for syncing product add-on meta and attribute meta to the dev site.</p>
				<form method="post" action="options.php" style="background:#fff;border:1px solid #ddd;padding:20px;max-width:760px;">
					<?php settings_fields( 'srk_wc_addon_bridge_live_group' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="secret_key">Bridge Secret Key</label></th>
							<td>
								<input type="text" class="regular-text" id="secret_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[secret_key]" value="<?php echo esc_attr( $settings['secret_key'] ); ?>" />
								<p class="description">Use the same secret on the dev sync plugin.</p>
							</td>
						</tr>
					</table>
					<?php submit_button( 'Save Secret' ); ?>
				</form>
				<div style="background:#fff;border:1px solid #ddd;padding:20px;max-width:760px;margin-top:18px;">
					<h2 style="margin-top:0;">Endpoint</h2>
					<p><code><?php echo esc_html( home_url( '/wp-json/srk-addon-bridge/v1/product' ) ); ?></code></p>
					<p>Accepted query params: <code>secret</code>, <code>sku</code> or <code>slug</code></p>
				</div>
			</div>
			<?php
		}

		public function register_routes() {
			register_rest_route(
				'srk-addon-bridge/v1',
				'/product',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_get_product_meta' ),
					'permission_callback' => '__return_true',
				)
			);
		}

		private function get_product_id_from_request( $request ) {
			$sku  = sanitize_text_field( (string) $request->get_param( 'sku' ) );
			$slug = sanitize_title( (string) $request->get_param( 'slug' ) );

			if ( ! empty( $sku ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
				$product_id = wc_get_product_id_by_sku( $sku );
				if ( $product_id ) {
					return absint( $product_id );
				}
			}
			if ( ! empty( $slug ) ) {
				$post = get_page_by_path( $slug, OBJECT, 'product' );
				if ( $post ) {
					return absint( $post->ID );
				}
			}
			return 0;
		}

		private function build_meta_payload( $product_id ) {
			$all_meta = get_post_meta( $product_id );
			$payload  = array();
			$explicit_keys = array(
				'_product_attributes',
				'_default_attributes',
				'_product_addons',
				'_product_addons_exclude_global',
				'_product_addons_virtual',
				'_wc_product_addons',
				'product_addons',
				'_ywapo_product_addons',
			);

			foreach ( $all_meta as $key => $values ) {
				$include = false;
				if ( in_array( $key, $explicit_keys, true ) ) {
					$include = true;
				}
				if ( false !== stripos( $key, 'addon' ) || false !== stripos( $key, 'add_on' ) ) {
					$include = true;
				}
				if ( false !== stripos( $key, 'attribute' ) ) {
					$include = true;
				}
				if ( ! $include ) {
					continue;
				}
				$prepared = array();
				foreach ( (array) $values as $value ) {
					$prepared[] = maybe_unserialize( $value );
				}
				$payload[ $key ] = 1 === count( $prepared ) ? $prepared[0] : $prepared;
			}
			return $payload;
		}

		public function rest_get_product_meta( $request ) {
			$settings = $this->get_settings();
			$secret   = sanitize_text_field( (string) $request->get_param( 'secret' ) );

			if ( empty( $settings['secret_key'] ) || empty( $secret ) || $secret !== $settings['secret_key'] ) {
				return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid secret.' ), 403 );
			}

			$product_id = $this->get_product_id_from_request( $request );
			if ( ! $product_id ) {
				return new WP_REST_Response( array( 'success' => false, 'message' => 'Product not found.' ), 404 );
			}

			$product = wc_get_product( $product_id );

			return new WP_REST_Response(
				array(
					'success'    => true,
					'product_id' => $product_id,
					'sku'        => $product ? $product->get_sku() : '',
					'slug'       => get_post_field( 'post_name', $product_id ),
					'meta'       => $this->build_meta_payload( $product_id ),
				),
				200
			);
		}
	}

	new SRK_WC_Addon_Bridge_Live();
}
