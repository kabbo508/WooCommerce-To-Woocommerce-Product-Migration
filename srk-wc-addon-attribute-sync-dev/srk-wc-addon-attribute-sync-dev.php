<?php
/**
 * Plugin Name: SRK WC Add-On Attribute Sync Dev
 * Description: Syncs product add-on meta and attribute meta from the live site into already imported dev products, with live AJAX logs and auto batch running.
 * Version: 1.1.0
 * Author: Sumon Rahman Kabbo
 * Author URI: https://sumonrahmankabbo.com/
 * Text Domain: srk-wc-addon-attribute-sync-dev
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SRK_WC_Addon_Attribute_Sync_Dev' ) ) {

	class SRK_WC_Addon_Attribute_Sync_Dev {

		const OPTION_KEY    = 'srk_wc_addon_attribute_sync_dev_settings';
		const MENU_SLUG     = 'srk-wc-addon-attribute-sync-dev';
		const NONCE_KEY     = 'srk_wc_aasd_nonce';
		const TRANSIENT_KEY = 'srk_wc_aasd_progress_';

		public function __construct() {
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'wp_ajax_srk_wc_aasd_test_connection', array( $this, 'ajax_test_connection' ) );
			add_action( 'wp_ajax_srk_wc_aasd_start_sync', array( $this, 'ajax_start_sync' ) );
			add_action( 'wp_ajax_srk_wc_aasd_run_batch', array( $this, 'ajax_run_batch' ) );
			add_action( 'wp_ajax_srk_wc_aasd_get_progress', array( $this, 'ajax_get_progress' ) );
			add_action( 'wp_ajax_srk_wc_aasd_clear_logs', array( $this, 'ajax_clear_logs' ) );
		}

		public function admin_menu() {
			add_submenu_page(
				'woocommerce',
				'SRK Add-On Attribute Sync',
				'SRK Add-On Attribute Sync',
				'manage_woocommerce',
				self::MENU_SLUG,
				array( $this, 'render_page' )
			);
		}

		public function register_settings() {
			register_setting( 'srk_wc_aasd_group', self::OPTION_KEY, array( $this, 'sanitize_settings' ) );
		}

		public function sanitize_settings( $input ) {
			$old = $this->get_settings();
			return array(
				'live_url'         => isset( $input['live_url'] ) ? esc_url_raw( trim( $input['live_url'] ) ) : '',
				'bridge_secret'    => isset( $input['bridge_secret'] ) ? sanitize_text_field( trim( $input['bridge_secret'] ) ) : '',
				'batch_size'       => isset( $input['batch_size'] ) ? max( 1, absint( $input['batch_size'] ) ) : 25,
				'start_offset'     => isset( $input['start_offset'] ) ? max( 0, absint( $input['start_offset'] ) ) : 0,
				'connection_state' => isset( $old['connection_state'] ) && is_array( $old['connection_state'] ) ? $old['connection_state'] : array(),
			);
		}

		private function get_settings() {
			$defaults = array(
				'live_url'         => '',
				'bridge_secret'    => '',
				'batch_size'       => 25,
				'start_offset'     => 0,
				'connection_state' => array(
					'is_connected' => false,
					'message'      => 'Not tested yet.',
					'updated_at'   => '',
				),
			);
			return wp_parse_args( get_option( self::OPTION_KEY, array() ), $defaults );
		}

		private function save_settings( $settings ) {
			update_option( self::OPTION_KEY, $settings );
		}

		private function get_progress_key() {
			return self::TRANSIENT_KEY . get_current_user_id();
		}

		private function get_progress() {
			$progress = get_transient( $this->get_progress_key() );
			return is_array( $progress ) ? $progress : array();
		}

		private function save_progress( $progress ) {
			set_transient( $this->get_progress_key(), $progress, 12 * HOUR_IN_SECONDS );
		}

		private function verify_ajax() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
			}
			check_ajax_referer( self::NONCE_KEY, 'nonce' );
		}

		private function add_log( $message, $label = 'INFO' ) {
			$progress = $this->get_progress();
			if ( empty( $progress['logs'] ) || ! is_array( $progress['logs'] ) ) {
				$progress['logs'] = array();
			}
			$progress['logs'][] = '[' . current_time( 'mysql' ) . '] [' . strtoupper( $label ) . '] ' . wp_strip_all_tags( $message );
			$progress['logs']   = array_slice( $progress['logs'], -800 );
			$this->save_progress( $progress );
		}

		private function get_products_total() {
			$count = wp_count_posts( 'product' );
			$total = 0;
			if ( $count && is_object( $count ) ) {
				foreach ( get_object_vars( $count ) as $value ) {
					$total += (int) $value;
				}
			}
			return $total;
		}

		private function fetch_live_meta( $settings, $sku, $slug ) {
			$base = untrailingslashit( $settings['live_url'] ) . '/wp-json/srk-addon-bridge/v1/product';
			$args = array( 'secret' => $settings['bridge_secret'] );
			if ( ! empty( $sku ) ) {
				$args['sku'] = $sku;
			} else {
				$args['slug'] = $slug;
			}

			$url      = add_query_arg( $args, $base );
			$response = wp_remote_get( $url, array( 'timeout' => 60 ) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code < 200 || $code >= 300 || empty( $body['success'] ) ) {
				return new WP_Error( 'bridge_error', ! empty( $body['message'] ) ? $body['message'] : 'Live bridge request failed.' );
			}

			return $body;
		}

		private function save_meta_to_product( $product_id, $meta ) {
			foreach ( (array) $meta as $key => $value ) {
				update_post_meta( $product_id, $key, $value );
			}
		}

		public function ajax_test_connection() {
			$this->verify_ajax();

			$settings = $this->get_settings();
			$test     = $this->fetch_live_meta( $settings, '__not_real__', '__not_real__' );

			if ( is_wp_error( $test ) ) {
				$message = $test->get_error_message();
				$is_connected = false;

				if ( false !== stripos( $message, 'Product not found' ) ) {
					$is_connected = true;
					$message = 'Connection successful.';
				}

				$settings['connection_state'] = array(
					'is_connected' => $is_connected,
					'message'      => $message,
					'updated_at'   => current_time( 'mysql' ),
				);
				$this->save_settings( $settings );

				if ( $is_connected ) {
					wp_send_json_success( $settings['connection_state'] );
				}
				wp_send_json_error( array( 'message' => $message ) );
			}

			$settings['connection_state'] = array(
				'is_connected' => true,
				'message'      => 'Connection successful.',
				'updated_at'   => current_time( 'mysql' ),
			);
			$this->save_settings( $settings );
			wp_send_json_success( $settings['connection_state'] );
		}

		public function ajax_start_sync() {
			$this->verify_ajax();

			$settings = $this->get_settings();
			$progress = array(
				'status'     => 'starting',
				'offset'     => max( 0, absint( $settings['start_offset'] ) ),
				'batch_size' => max( 1, absint( $settings['batch_size'] ) ),
				'total'      => $this->get_products_total(),
				'summary'    => array(
					'processed'      => 0,
					'synced'         => 0,
					'not_found_live' => 0,
					'errors'         => 0,
				),
				'logs' => array(),
			);
			$this->save_progress( $progress );
			$this->add_log( 'Add-on attribute sync initialized.', 'INFO' );

			wp_send_json_success( array( 'progress' => $this->get_progress() ) );
		}

		public function ajax_run_batch() {
			$this->verify_ajax();

			$settings = $this->get_settings();
			$progress = $this->get_progress();

			if ( empty( $progress ) ) {
				wp_send_json_error( array( 'message' => 'No active sync session found.' ) );
			}

			$offset     = isset( $progress['offset'] ) ? absint( $progress['offset'] ) : 0;
			$batch_size = isset( $progress['batch_size'] ) ? absint( $progress['batch_size'] ) : max( 1, absint( $settings['batch_size'] ) );

			$products = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'posts_per_page' => $batch_size,
					'offset'         => $offset,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
				)
			);

			if ( empty( $products ) ) {
				$progress['status'] = 'completed';
				$this->save_progress( $progress );
				$this->add_log( 'No more dev products found. Sync completed.', 'OK' );
				wp_send_json_success( array( 'done' => true, 'progress' => $this->get_progress() ) );
			}

			$progress['status'] = 'running';
			$this->save_progress( $progress );
			$this->add_log( 'Running sync batch from offset ' . $offset . ' with ' . count( $products ) . ' products.', 'INFO' );

			foreach ( $products as $product_id ) {
				$product  = wc_get_product( $product_id );
				$sku      = $product ? (string) $product->get_sku() : '';
				$slug     = get_post_field( 'post_name', $product_id );
				$title    = get_the_title( $product_id );

				$progress = $this->get_progress();
				$progress['summary']['processed']++;
				$this->save_progress( $progress );
				$this->add_log( 'Checking dev product #' . $product_id . ' - ' . $title, 'INFO' );

				$remote = $this->fetch_live_meta( $settings, $sku, $slug );
				if ( is_wp_error( $remote ) ) {
					$message  = $remote->get_error_message();
					$progress = $this->get_progress();

					if ( false !== stripos( $message, 'Product not found' ) ) {
						$progress['summary']['not_found_live']++;
						$this->save_progress( $progress );
						$this->add_log( 'Live product not found for #' . $product_id . ' using ' . ( $sku ? 'SKU ' . $sku : 'slug ' . $slug ) . '.', 'SKIP' );
						continue;
					}

					$progress['summary']['errors']++;
					$this->save_progress( $progress );
					$this->add_log( 'Error syncing #' . $product_id . ': ' . $message, 'ERROR' );
					continue;
				}

				$meta = ! empty( $remote['meta'] ) && is_array( $remote['meta'] ) ? $remote['meta'] : array();
				if ( empty( $meta ) ) {
					$this->add_log( 'No add-on/attribute meta returned for #' . $product_id . '.', 'SKIP' );
					continue;
				}

				$this->save_meta_to_product( $product_id, $meta );
				$progress = $this->get_progress();
				$progress['summary']['synced']++;
				$this->save_progress( $progress );

				$keys = implode( ', ', array_keys( $meta ) );
				$this->add_log( 'Synced #' . $product_id . ' meta keys: ' . $keys, 'OK' );
			}

			$progress = $this->get_progress();
			$progress['offset'] = $offset + count( $products );
			$this->save_progress( $progress );
			$this->add_log( 'Batch finished. Next offset is ' . $progress['offset'] . '.', 'OK' );

			wp_send_json_success( array( 'done' => false, 'progress' => $this->get_progress() ) );
		}

		public function ajax_get_progress() {
			$this->verify_ajax();
			wp_send_json_success( $this->get_progress() );
		}

		public function ajax_clear_logs() {
			$this->verify_ajax();

			$progress = $this->get_progress();
			if ( empty( $progress ) || ! is_array( $progress ) ) {
				$progress = array(
					'status'     => 'idle',
					'offset'     => 0,
					'batch_size' => 25,
					'total'      => $this->get_products_total(),
					'summary'    => array(
						'processed'      => 0,
						'synced'         => 0,
						'not_found_live' => 0,
						'errors'         => 0,
					),
				);
			}
			$progress['logs'] = array();
			$this->save_progress( $progress );

			wp_send_json_success( array( 'message' => 'Logs cleared successfully.', 'progress' => $this->get_progress() ) );
		}

		public function render_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$settings = $this->get_settings();
			$progress = $this->get_progress();
			$nonce    = wp_create_nonce( self::NONCE_KEY );
			$state    = ! empty( $settings['connection_state'] ) && is_array( $settings['connection_state'] ) ? $settings['connection_state'] : array(
				'is_connected' => false,
				'message'      => 'Not tested yet.',
				'updated_at'   => '',
			);
			?>
			<div class="wrap srk-aasd-wrap">
				<style>
					.srk-aasd-wrap{margin:20px 20px 0 0}
					.srk-aasd-hero{background:linear-gradient(135deg,#111827,#1f2937 45%,#2563eb);color:#fff;padding:24px 28px;border-radius:18px;margin:16px 0 20px;box-shadow:0 10px 30px rgba(0,0,0,.12)}
					.srk-aasd-hero h1{color:#fff;margin:0 0 8px;font-size:28px;font-weight:700}
					.srk-aasd-hero p{margin:0;color:rgba(255,255,255,.88);font-size:14px}
					.srk-aasd-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:18px;max-width:1280px}
					.srk-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:20px;box-shadow:0 8px 24px rgba(15,23,42,.05)}
					.srk-card h2{margin:0 0 14px;font-size:18px}
					.srk-two-col{display:grid;grid-template-columns:1fr 1fr;gap:14px}
					.srk-field label{display:block;font-weight:600;margin:0 0 7px}
					.srk-field input[type=text],.srk-field input[type=url],.srk-field input[type=number]{width:100%;min-height:42px;border:1px solid #d1d5db;border-radius:12px;padding:0 12px}
					.srk-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
					.srk-btn{border:none;border-radius:12px;padding:12px 16px;font-weight:600;cursor:pointer}
					.srk-btn-primary{background:#2563eb;color:#fff}
					.srk-btn-dark{background:#111827;color:#fff}
					.srk-btn-soft{background:#e5eefc;color:#1e40af}
					.srk-metrics{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:14px}
					.srk-metric{background:#f8fafc;border:1px solid #e5e7eb;border-radius:14px;padding:14px}
					.srk-metric .label{display:block;font-size:12px;color:#6b7280;margin-bottom:4px}
					.srk-metric .value{font-size:24px;font-weight:700;color:#111827}
					.srk-status-pill{display:inline-flex;padding:7px 10px;border-radius:999px;font-size:12px;font-weight:700;background:#e5eefc;color:#1d4ed8;text-transform:uppercase}
					.srk-log{background:#0b1220;color:#d1fae5;border-radius:14px;padding:14px;min-height:280px;max-height:560px;overflow:auto;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;line-height:1.6}
					.srk-note{font-size:12px;color:#6b7280;margin-top:6px}
					.srk-connection-row{display:flex;align-items:center;gap:10px;margin:0 0 16px}
					.srk-conn-dot{width:12px;height:12px;border-radius:50%;display:inline-block;box-shadow:0 0 0 4px rgba(0,0,0,.05)}
					.srk-conn-dot.green{background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.14)}
					.srk-conn-dot.red{background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.12)}
					.srk-conn-text{font-weight:700;color:#111827}
					.srk-conn-sub{font-size:12px;color:#6b7280}
					@media(max-width:1100px){.srk-aasd-grid,.srk-two-col,.srk-metrics{grid-template-columns:1fr}}
				</style>

				<div class="srk-aasd-hero">
					<h1>SRK WC Add-On Attribute Sync Dev</h1>
					<p>Connect to the live bridge, then run automatic AJAX batch syncing with live logs for product add-on and attribute meta.</p>
				</div>

				<div class="srk-aasd-grid">
					<div class="srk-card">
						<h2>Connection and Sync Settings</h2>

						<div class="srk-connection-row">
							<span class="srk-conn-dot <?php echo ! empty( $state['is_connected'] ) ? 'green' : 'red'; ?>" id="srk-conn-dot"></span>
							<div>
								<div class="srk-conn-text" id="srk-conn-text"><?php echo ! empty( $state['is_connected'] ) ? 'Connected' : 'Not Connected'; ?></div>
								<div class="srk-conn-sub" id="srk-conn-sub"><?php echo ! empty( $state['message'] ) ? esc_html( $state['message'] ) : 'Run Test Connection.'; ?></div>
							</div>
						</div>

						<form method="post" action="options.php">
							<?php settings_fields( 'srk_wc_aasd_group' ); ?>
							<div class="srk-two-col">
								<div class="srk-field">
									<label for="live_url">Live Site URL</label>
									<input type="url" id="live_url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[live_url]" value="<?php echo esc_attr( $settings['live_url'] ); ?>">
								</div>
								<div class="srk-field">
									<label for="bridge_secret">Bridge Secret</label>
									<input type="text" id="bridge_secret" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bridge_secret]" value="<?php echo esc_attr( $settings['bridge_secret'] ); ?>">
								</div>
								<div class="srk-field">
									<label for="batch_size">Products Per Batch</label>
									<input type="number" min="1" id="batch_size" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[batch_size]" value="<?php echo esc_attr( $settings['batch_size'] ); ?>">
								</div>
								<div class="srk-field">
									<label for="start_offset">Start Offset</label>
									<input type="number" min="0" id="start_offset" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[start_offset]" value="<?php echo esc_attr( $settings['start_offset'] ); ?>">
									<div class="srk-note">Set 0 for a fresh run, or another offset to continue from a custom point.</div>
								</div>
							</div>
							<div class="srk-actions">
								<?php submit_button( 'Save Settings', 'primary', 'submit', false, array( 'class' => 'srk-btn srk-btn-primary' ) ); ?>
							</div>
						</form>

						<div class="srk-actions">
							<button type="button" class="srk-btn srk-btn-soft" id="srk-aasd-test-connection">Test Connection</button>
							<button type="button" class="srk-btn srk-btn-primary" id="srk-aasd-start-sync">Run Auto Sync</button>
							<button type="button" class="srk-btn srk-btn-dark" id="srk-aasd-refresh-progress">Refresh Progress</button>
							<button type="button" class="srk-btn srk-btn-soft" id="srk-aasd-clear-logs">Clear Logs</button>
						</div>

						<div class="srk-note">This plugin auto-runs one batch after another until all dev products in the selected range are processed.</div>
					</div>

					<div class="srk-card">
						<h2>Live Progress</h2>
						<div style="margin-bottom:12px"><span class="srk-status-pill" id="srk-aasd-status"><?php echo ! empty( $progress['status'] ) ? esc_html( $progress['status'] ) : 'idle'; ?></span></div>
						<div class="srk-metrics">
							<div class="srk-metric"><span class="label">Offset</span><span class="value" id="srk-aasd-offset"><?php echo ! empty( $progress['offset'] ) ? esc_html( $progress['offset'] ) : '0'; ?></span></div>
							<div class="srk-metric"><span class="label">Total Products</span><span class="value" id="srk-aasd-total"><?php echo ! empty( $progress['total'] ) ? esc_html( $progress['total'] ) : '0'; ?></span></div>
							<div class="srk-metric"><span class="label">Processed</span><span class="value" id="srk-aasd-processed"><?php echo ! empty( $progress['summary']['processed'] ) ? esc_html( $progress['summary']['processed'] ) : '0'; ?></span></div>
							<div class="srk-metric"><span class="label">Synced</span><span class="value" id="srk-aasd-synced"><?php echo ! empty( $progress['summary']['synced'] ) ? esc_html( $progress['summary']['synced'] ) : '0'; ?></span></div>
							<div class="srk-metric"><span class="label">Not Found Live</span><span class="value" id="srk-aasd-notfound"><?php echo ! empty( $progress['summary']['not_found_live'] ) ? esc_html( $progress['summary']['not_found_live'] ) : '0'; ?></span></div>
							<div class="srk-metric"><span class="label">Errors</span><span class="value" id="srk-aasd-errors"><?php echo ! empty( $progress['summary']['errors'] ) ? esc_html( $progress['summary']['errors'] ) : '0'; ?></span></div>
						</div>

						<div class="srk-log" id="srk-aasd-log-box">
							<?php
							if ( ! empty( $progress['logs'] ) && is_array( $progress['logs'] ) ) {
								foreach ( array_reverse( $progress['logs'] ) as $line ) {
									echo '<div>' . esc_html( $line ) . '</div>';
								}
							} else {
								echo '<div>No logs yet.</div>';
							}
							?>
						</div>
					</div>
				</div>

				<script>
				(function($){
					let running = false;
					const nonce = '<?php echo esc_js( $nonce ); ?>';

					function renderConnection(data){
						const dot = $('#srk-conn-dot');
						const text = $('#srk-conn-text');
						const sub = $('#srk-conn-sub');
						if(data.is_connected){
							dot.removeClass('red').addClass('green');
							text.text('Connected');
						}else{
							dot.removeClass('green').addClass('red');
							text.text('Not Connected');
						}
						sub.text(data.message || 'No connection message.');
					}

					function renderProgress(data){
						if(!data){ return; }
						$('#srk-aasd-status').text(data.status || 'idle');
						$('#srk-aasd-offset').text(data.offset || 0);
						$('#srk-aasd-total').text(data.total || 0);
						$('#srk-aasd-processed').text(data.summary?.processed || 0);
						$('#srk-aasd-synced').text(data.summary?.synced || 0);
						$('#srk-aasd-notfound').text(data.summary?.not_found_live || 0);
						$('#srk-aasd-errors').text(data.summary?.errors || 0);

						const box = $('#srk-aasd-log-box');
						box.empty();
						if(data.logs && data.logs.length){
							const reversed = [...data.logs].reverse();
							reversed.forEach(function(line){
								box.append($('<div/>').text(line));
							});
						}else{
							box.html('<div>No logs yet.</div>');
						}
					}

					function pollProgress(){
						$.post(ajaxurl,{action:'srk_wc_aasd_get_progress',nonce:nonce},function(resp){
							if(resp.success){
								renderProgress(resp.data);
							}
						});
					}

					function runNextBatch(){
						$.post(ajaxurl,{action:'srk_wc_aasd_run_batch',nonce:nonce},function(resp){
							if(resp.success){
								renderProgress(resp.data.progress);
								if(resp.data.done){
									running = false;
									alert('Add-on attribute sync completed.');
								}else{
									setTimeout(runNextBatch, 400);
								}
							}else{
								running = false;
								alert(resp.data && resp.data.message ? resp.data.message : 'Batch failed.');
							}
						}).fail(function(){
							running = false;
							alert('AJAX batch request failed.');
						});
					}

					$('#srk-aasd-test-connection').on('click', function(){
						$.post(ajaxurl,{action:'srk_wc_aasd_test_connection',nonce:nonce},function(resp){
							if(resp.success){
								renderConnection(resp.data);
								alert('Connection successful.');
							}else{
								renderConnection({is_connected:false,message:(resp.data && resp.data.message ? resp.data.message : 'Connection failed.')});
								alert(resp.data && resp.data.message ? resp.data.message : 'Connection failed.');
							}
						}).fail(function(){
							renderConnection({is_connected:false,message:'Connection request failed.'});
							alert('Connection request failed.');
						});
					});

					$('#srk-aasd-start-sync').on('click', function(){
						if(running){ return; }
						running = true;
						$.post(ajaxurl,{action:'srk_wc_aasd_start_sync',nonce:nonce},function(resp){
							if(resp.success){
								renderProgress(resp.data.progress);
								runNextBatch();
							}else{
								running = false;
								alert(resp.data && resp.data.message ? resp.data.message : 'Could not start sync.');
							}
						}).fail(function(){
							running = false;
							alert('Could not start sync.');
						});
					});

					$('#srk-aasd-refresh-progress').on('click', pollProgress);

					$('#srk-aasd-clear-logs').on('click', function(){
						$.post(ajaxurl,{action:'srk_wc_aasd_clear_logs',nonce:nonce},function(resp){
							if(resp.success){
								renderProgress(resp.data.progress);
								alert(resp.data.message || 'Logs cleared.');
							}else{
								alert(resp.data && resp.data.message ? resp.data.message : 'Could not clear logs.');
							}
						}).fail(function(){
							alert('Could not clear logs.');
						});
					});

					setInterval(pollProgress, 4000);
				})(jQuery);
				</script>
			</div>
			<?php
		}
	}

	new SRK_WC_Addon_Attribute_Sync_Dev();
}
