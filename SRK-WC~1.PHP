<?php
/**
 * Plugin Name: SRK WC Live to Dev Importer
 * Description: Reset dev WooCommerce data, then import products, categories, tags, images, attributes, variations, and meta from a live WooCommerce site with AJAX batch processing and a modern admin UI.
 * Version: 3.5.0
 * Author: Sumon Rahman Kabbo
 * Author URI: https://sumonrahmankabbo.com/
 * Text Domain: srk-wc-live-to-dev-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SRK_WC_Live_To_Dev_Importer_V3' ) ) {

	class SRK_WC_Live_To_Dev_Importer_V3 {

		const OPTION_KEY    = 'srk_wc_ltdi_settings_v3';
		const MENU_SLUG     = 'srk-wc-live-to-dev-importer';
		const NONCE_KEY     = 'srk_wc_ltdi_nonce';
		const TRANSIENT_KEY = 'srk_wc_ltdi_progress_';
		const LOG_LIMIT     = 1000;

		public function __construct() {
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_post_srk_wc_ltdi_test_connection', array( $this, 'handle_test_connection' ) );
			add_action( 'wp_ajax_srk_wc_ltdi_start_import', array( $this, 'ajax_start_import' ) );
			add_action( 'wp_ajax_srk_wc_ltdi_run_batch', array( $this, 'ajax_run_batch' ) );
			add_action( 'wp_ajax_srk_wc_ltdi_get_progress', array( $this, 'ajax_get_progress' ) );
			add_action( 'wp_ajax_srk_wc_ltdi_reset_dev_data', array( $this, 'ajax_reset_dev_data' ) );
			add_action( 'wp_ajax_srk_wc_ltdi_clear_logs', array( $this, 'ajax_clear_logs' ) );
		}

		public function admin_menu() {
			add_submenu_page(
				'woocommerce',
				'Live to Dev Importer',
				'Live to Dev Importer',
				'manage_woocommerce',
				self::MENU_SLUG,
				array( $this, 'render_admin_page' )
			);
		}

		public function register_settings() {
			register_setting( 'srk_wc_ltdi_group', self::OPTION_KEY, array( $this, 'sanitize_settings' ) );
		}

		public function sanitize_settings( $input ) {
			$old = $this->get_settings();

			return array(
				'source_url'             => isset( $input['source_url'] ) ? esc_url_raw( trim( $input['source_url'] ) ) : '',
				'consumer_key'           => isset( $input['consumer_key'] ) ? sanitize_text_field( trim( $input['consumer_key'] ) ) : '',
				'consumer_secret'        => isset( $input['consumer_secret'] ) ? sanitize_text_field( trim( $input['consumer_secret'] ) ) : '',
				'per_page'               => isset( $input['per_page'] ) ? max( 1, min( 100, absint( $input['per_page'] ) ) ) : 20,
				'start_page'             => isset( $input['start_page'] ) ? max( 1, absint( $input['start_page'] ) ) : 1,
				'max_pages'              => isset( $input['max_pages'] ) ? max( 1, absint( $input['max_pages'] ) ) : 1,
				'auto_all_pages'         => ! empty( $input['auto_all_pages'] ) ? 1 : 0,
				'resume_stopped_import' => ! empty( $input['resume_stopped_import'] ) ? 1 : 0,
				'selected_category_ids'  => isset( $input['selected_category_ids'] ) ? sanitize_text_field( trim( $input['selected_category_ids'] ) ) : '',
				'import_categories'      => ! empty( $input['import_categories'] ) ? 1 : 0,
				'import_tags'            => ! empty( $input['import_tags'] ) ? 1 : 0,
				'import_images'          => ! empty( $input['import_images'] ) ? 1 : 0,
				'import_variations'      => ! empty( $input['import_variations'] ) ? 1 : 0,
				'copy_meta_data'         => ! empty( $input['copy_meta_data'] ) ? 1 : 0,
				'update_existing'        => ! empty( $input['update_existing'] ) ? 1 : 0,
				'gallery_resync_mode'    => ! empty( $input['gallery_resync_mode'] ) ? 1 : 0,
				'remote_category_images' => ! empty( $input['remote_category_images'] ) ? 1 : 0,
				'reset_before_import'    => ! empty( $input['reset_before_import'] ) ? 1 : 0,
				'match_by'               => isset( $input['match_by'] ) && in_array( $input['match_by'], array( 'smart', 'remote_id', 'sku', 'slug' ), true ) ? $input['match_by'] : 'smart',
				'import_status'          => isset( $input['import_status'] ) && in_array( $input['import_status'], array( 'draft', 'publish', 'private' ), true ) ? $input['import_status'] : 'draft',
				'last_summary'           => isset( $old['last_summary'] ) ? $old['last_summary'] : array(),
			);
		}

		private function get_settings() {
			$defaults = array(
				'source_url'             => '',
				'consumer_key'           => '',
				'consumer_secret'        => '',
				'per_page'               => 20,
				'start_page'             => 1,
				'max_pages'              => 1,
				'auto_all_pages'         => 1,
				'resume_stopped_import' => 1,
				'selected_category_ids'  => '',
				'import_categories'      => 1,
				'import_tags'            => 1,
				'import_images'          => 1,
				'import_variations'      => 1,
				'copy_meta_data'         => 1,
				'update_existing'        => 1,
				'gallery_resync_mode'    => 0,
				'remote_category_images' => 1,
				'reset_before_import'    => 0,
				'match_by'               => 'smart',
				'import_status'          => 'draft',
				'last_summary'           => array(),
			);
			return wp_parse_args( get_option( self::OPTION_KEY, array() ), $defaults );
		}

		private function save_settings( $settings ) {
			update_option( self::OPTION_KEY, $settings );
		}

		private function get_progress_key() {
			return self::TRANSIENT_KEY . get_current_user_id();
		}

		private function get_resume_state_key() {
			return 'srk_wc_ltdi_resume_state_' . get_current_user_id();
		}

		private function save_resume_state( $state ) {
			update_option( $this->get_resume_state_key(), $state, false );
		}

		private function get_resume_state() {
			$state = get_option( $this->get_resume_state_key(), array() );
			return is_array( $state ) ? $state : array();
		}

		private function clear_resume_state() {
			delete_option( $this->get_resume_state_key() );
		}

		private function get_progress() {
			$progress = get_transient( $this->get_progress_key() );
			return is_array( $progress ) ? $progress : array();
		}

		private function save_progress( $progress ) {
			set_transient( $this->get_progress_key(), $progress, 12 * HOUR_IN_SECONDS );
		}

		private function add_log( $message, $label = 'INFO' ) {
			$progress = $this->get_progress();
			if ( empty( $progress['logs'] ) || ! is_array( $progress['logs'] ) ) {
				$progress['logs'] = array();
			}
			$progress['logs'][] = '[' . current_time( 'mysql' ) . '] [' . strtoupper( $label ) . '] ' . wp_strip_all_tags( $message );
			$progress['logs']   = array_slice( $progress['logs'], - self::LOG_LIMIT );
			$this->save_progress( $progress );
		}


		private function get_connection_status_key() {
			return 'srk_wc_ltdi_connection_status_' . get_current_user_id();
		}

		private function save_connection_status( $is_connected, $message = '' ) {
			set_transient(
				$this->get_connection_status_key(),
				array(
					'is_connected' => ! empty( $is_connected ),
					'message'      => sanitize_text_field( $message ),
					'updated_at'   => current_time( 'mysql' ),
				),
				7 * DAY_IN_SECONDS
			);
		}

		private function get_connection_status() {
			$status = get_transient( $this->get_connection_status_key() );
			if ( ! is_array( $status ) ) {
				$status = array(
					'is_connected' => false,
					'message'      => 'Not connected',
					'updated_at'   => '',
				);
			}
			return $status;
		}


		private function is_progress_resumable( $progress ) {
			if ( ! empty( $progress ) && is_array( $progress ) ) {
				$status = isset( $progress['status'] ) ? (string) $progress['status'] : '';
				if ( in_array( $status, array( 'starting', 'running', 'error', 'paused' ), true ) && ! empty( $progress['current_page'] ) ) {
					return true;
				}
			}

			$resume = $this->get_resume_state();
			return ! empty( $resume['current_page'] );
		}

		private function verify_request() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Permission denied.', 'srk-wc-live-to-dev-importer' ) );
			}
			check_admin_referer( self::NONCE_KEY );
		}

		private function verify_ajax() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
			}
			check_ajax_referer( self::NONCE_KEY, 'nonce' );
		}

		public function render_admin_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$settings = $this->get_settings();
			$progress = $this->get_progress();
			$connection_status = $this->get_connection_status();
			$nonce    = wp_create_nonce( self::NONCE_KEY );
			?>
			<div class="wrap srk-ltdi-wrap">
				<style>
					.srk-ltdi-wrap{margin:20px 20px 0 0}
					.srk-ltdi-hero{background:linear-gradient(135deg,#111827,#1f2937 45%,#2563eb);color:#fff;padding:26px 28px;border-radius:18px;margin:16px 0 20px;box-shadow:0 10px 30px rgba(0,0,0,.12)}
					.srk-ltdi-hero h1{color:#fff;margin:0 0 8px;font-size:28px;font-weight:700}
					.srk-ltdi-hero p{margin:0;color:rgba(255,255,255,.88);font-size:14px}
					.srk-ltdi-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:18px;max-width:1280px}
					.srk-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:20px;box-shadow:0 8px 24px rgba(15,23,42,.05)}
					.srk-card h2{margin:0 0 14px;font-size:18px}
					.srk-two-col{display:grid;grid-template-columns:1fr 1fr;gap:14px}
					.srk-field label{display:block;font-weight:600;margin:0 0 7px}
					.srk-field input[type=text],.srk-field input[type=url],.srk-field input[type=number],.srk-field select{width:100%;min-height:42px;border:1px solid #d1d5db;border-radius:12px;padding:0 12px}
					.srk-check{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1px solid #eef2f7;border-radius:12px;background:#fafcff}
					.srk-check input{margin-top:3px}
					.srk-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
					.srk-btn{border:none;border-radius:12px;padding:12px 16px;font-weight:600;cursor:pointer}
					.srk-btn-primary{background:#2563eb;color:#fff}
					.srk-btn-dark{background:#111827;color:#fff}
					.srk-btn-danger{background:#dc2626;color:#fff}
					.srk-btn-soft{background:#e5eefc;color:#1e40af}
					.srk-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:14px}
					.srk-metric{background:#f8fafc;border:1px solid #e5e7eb;border-radius:14px;padding:14px}
					.srk-metric .label{display:block;font-size:12px;color:#6b7280;margin-bottom:4px}
					.srk-metric .value{font-size:24px;font-weight:700;color:#111827}
					.srk-status-pill{display:inline-flex;padding:7px 10px;border-radius:999px;font-size:12px;font-weight:700;background:#e5eefc;color:#1d4ed8;text-transform:uppercase}
					.srk-log{background:#0b1220;color:#d1fae5;border-radius:14px;padding:14px;min-height:280px;max-height:560px;overflow:auto;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;line-height:1.6}
					.srk-note{font-size:12px;color:#6b7280;margin-top:6px}
					.srk-summary-table{width:100%;border-collapse:collapse}
					.srk-summary-table td{padding:10px;border-bottom:1px solid #eef2f7;vertical-align:top}
					.srk-connection-row{display:flex;align-items:center;gap:10px;margin:0 0 16px}
					.srk-conn-dot{width:12px;height:12px;border-radius:50%;display:inline-block;box-shadow:0 0 0 4px rgba(0,0,0,.05)}
					.srk-conn-dot.green{background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.14)}
					.srk-conn-dot.red{background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.12)}
					.srk-conn-text{font-weight:700;color:#111827}
					.srk-conn-sub{font-size:12px;color:#6b7280}
					@media(max-width:1100px){.srk-ltdi-grid,.srk-two-col,.srk-metrics{grid-template-columns:1fr}}
				</style>

				<div class="srk-ltdi-hero">
					<h1>SRK WC Live to Dev Importer v3</h1>
					<p>Reset the dev store first, then run a cleaner import with live AJAX progress, full automatic page-by-page importing, category filtering, image sync, variation support, and a refreshed admin UI.</p>
				</div>

				<div class="srk-ltdi-grid">
					<div class="srk-card">
						<h2>Connection and Import Settings</h2>
						<div class="srk-connection-row">
							<span class="srk-conn-dot <?php echo ! empty( $connection_status['is_connected'] ) ? 'green' : 'red'; ?>" id="srk-conn-dot"></span>
							<div>
								<div class="srk-conn-text" id="srk-conn-text"><?php echo ! empty( $connection_status['is_connected'] ) ? 'Connected' : 'Not Connected'; ?></div>
								<div class="srk-conn-sub" id="srk-conn-sub"><?php echo ! empty( $connection_status['message'] ) ? esc_html( $connection_status['message'] ) : 'Run Test Connection to verify the live store API.'; ?></div>
							</div>
						</div>
						<form method="post" action="options.php">
							<?php settings_fields( 'srk_wc_ltdi_group' ); ?>
							<div class="srk-two-col">
								<div class="srk-field">
									<label for="source_url">Live Site URL</label>
									<input type="url" id="source_url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[source_url]" value="<?php echo esc_attr( $settings['source_url'] ); ?>" placeholder="https://example.com">
								</div>
								<div class="srk-field">
									<label for="selected_category_ids">Only Selected Category IDs</label>
									<input type="text" id="selected_category_ids" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[selected_category_ids]" value="<?php echo esc_attr( $settings['selected_category_ids'] ); ?>" placeholder="12,15,28">
									<div class="srk-note">Use remote live-site WooCommerce category IDs. Leave blank to import all.</div>
								</div>
								<div class="srk-field">
									<label for="consumer_key">Consumer Key</label>
									<input type="text" id="consumer_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[consumer_key]" value="<?php echo esc_attr( $settings['consumer_key'] ); ?>">
								</div>
								<div class="srk-field">
									<label for="consumer_secret">Consumer Secret</label>
									<input type="text" id="consumer_secret" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[consumer_secret]" value="<?php echo esc_attr( $settings['consumer_secret'] ); ?>">
								</div>
								<div class="srk-field">
									<label for="per_page">Products Per Page</label>
									<input type="number" id="per_page" min="1" max="100" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[per_page]" value="<?php echo esc_attr( $settings['per_page'] ); ?>">
								</div>
								<div class="srk-field">
									<label for="start_page">Start Page</label>
									<input type="number" id="start_page" min="1" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[start_page]" value="<?php echo esc_attr( $settings['start_page'] ); ?>">
								</div>
								<div class="srk-field">
									<label for="max_pages">Max Pages</label>
									<input type="number" id="max_pages" min="1" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_pages]" value="<?php echo esc_attr( $settings['max_pages'] ); ?>">
									<div class="srk-note">Used only when Auto Import All Pages is off.</div>
								</div>
								<div class="srk-field">
									<label for="match_by">Duplicate Match Mode</label>
									<select id="match_by" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[match_by]">
										<option value="smart" <?php selected( $settings['match_by'], 'smart' ); ?>>Smart, remote ID → SKU → slug</option>
										<option value="remote_id" <?php selected( $settings['match_by'], 'remote_id' ); ?>>Remote ID only</option>
										<option value="sku" <?php selected( $settings['match_by'], 'sku' ); ?>>SKU only</option>
										<option value="slug" <?php selected( $settings['match_by'], 'slug' ); ?>>Slug only</option>
									</select>
								</div>
								<div class="srk-field">
									<label for="import_status">Imported Product Status</label>
									<select id="import_status" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[import_status]">
										<option value="draft" <?php selected( $settings['import_status'], 'draft' ); ?>>Draft</option>
										<option value="publish" <?php selected( $settings['import_status'], 'publish' ); ?>>Publish</option>
										<option value="private" <?php selected( $settings['import_status'], 'private' ); ?>>Private</option>
									</select>
								</div>
							</div>

							<div class="srk-two-col" style="margin-top:14px">
								<label class="srk-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[import_categories]" value="1" <?php checked( $settings['import_categories'], 1 ); ?>><span><strong>Import categories</strong><br><span class="srk-note">Create/update product categories before product import.</span></span></label>
								<label class="srk-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[remote_category_images]" value="1" <?php checked( $settings['remote_category_images'], 1 ); ?>><span><strong>Import category images</strong><br><span class="srk-note">Sync remote category thumbnails into dev.</span></span></label>
								<label class="srk-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[import_tags]" value="1" <?php checked( $settings['import_tags'], 1 ); ?>><span><strong>Import tags</strong><br><span class="srk-note">Assign product tags from the live site.</span></span></label>
								<label class="srk-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[import_images]" value="1" <?php checked( $settings['import_images'], 1 ); ?>><span><strong>Import product images</strong><br><span class="srk-note">Featured image, gallery, and variation images.</span></span></label>
								<label class="srk-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[gallery_resync_mode]" value="1" <?php checked( $settings['gallery_resync_mode'], 1 ); ?>><span><strong>Gallery re-sync mode</strong><br><span class="srk-note">Clear old gallery on update and rebuild from remote.</span></span></label>
								<label class="srk-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[import_variations]" value="1" <?php checked( $settings['import_variations'], 1 ); ?>><span><strong>Import variations</strong><br><span class="srk-note">Handles variable products and variation data.</span></span></label>
								<label class="srk-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[copy_meta_data]" value="1" <?php checked( $settings['copy_meta_data'], 1 ); ?>><span><strong>Copy meta_data</strong><br><span class="srk-note">Useful when Product Add-Ons data is exposed in the API. Known add-on keys are copied when the live API returns them.</span></span></label>
								<label class="srk-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[update_existing]" value="1" <?php checked( $settings['update_existing'], 1 ); ?>><span><strong>Update existing products</strong><br><span class="srk-note">Works best with smart match mode and/or reset first.</span></span></label>
								<label class="srk-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[reset_before_import]" value="1" <?php checked( $settings['reset_before_import'], 1 ); ?>><span><strong>Reset dev data before import</strong><br><span class="srk-note">Deletes dev products, categories, and tags before import starts.</span></span></label>
								<label class="srk-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_all_pages]" value="1" <?php checked( $settings['auto_all_pages'], 1 ); ?>><span><strong>Auto import all pages</strong><br><span class="srk-note">When enabled, one click keeps importing page by page until no more remote products are found.</span></span></label>
								<label class="srk-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[resume_stopped_import]" value="1" <?php checked( $settings['resume_stopped_import'], 1 ); ?>><span><strong>Resume stopped import</strong><br><span class="srk-note">If the browser or AJAX stops, clicking Run Auto Import again resumes from the last saved page.</span></span></label>
							</div>

							<div class="srk-actions">
								<?php submit_button( 'Save Settings', 'primary', 'submit', false, array( 'class' => 'srk-btn srk-btn-primary' ) ); ?>
							</div>
						</form>

						<div class="srk-actions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( self::NONCE_KEY ); ?>
								<input type="hidden" name="action" value="srk_wc_ltdi_test_connection">
								<button class="srk-btn srk-btn-soft" type="submit">Test Connection</button>
							</form>
							<button type="button" class="srk-btn srk-btn-primary" id="srk-wc-start-import">Run Auto Import</button>
							<button type="button" class="srk-btn srk-btn-dark" id="srk-wc-refresh-progress">Refresh Progress</button>
							<button type="button" class="srk-btn srk-btn-soft" id="srk-wc-clear-logs">Clear Logs</button>
							<button type="button" class="srk-btn srk-btn-danger" id="srk-wc-reset-dev-data">Delete Dev Products, Categories, Tags</button>
						</div>
						<div class="srk-note">Run Test Connection first. The status light turns green when the live WooCommerce API connection is verified successfully.</div>
						<div class="srk-note">With Auto import all pages enabled, one click on Run Auto Import keeps going automatically until all remote products are finished. If AJAX stops midway, running it again can resume from the last saved page.</div>
						<div class="srk-note">Delete removes dev WooCommerce products, product categories, and product tags. Default “Uncategorized” category is kept.</div>
						<div class="srk-note">Product Add-Ons can only be imported when the live WooCommerce API returns the add-on meta keys. This version logs copied add-on related meta keys so you can verify what came across.</div>
						<div class="srk-note">Resume now uses a saved checkpoint. If AJAX breaks, Run Auto Import should continue from the last saved next page instead of starting from page 1.</div>
					</div>

					<div class="srk-card">
						<h2>Live Progress</h2>
						<div style="margin-bottom:12px"><span class="srk-status-pill" id="srk-wc-status"><?php echo ! empty( $progress['status'] ) ? esc_html( $progress['status'] ) : 'idle'; ?></span></div>
						<div class="srk-metrics">
							<div class="srk-metric"><span class="label">Current Page</span><span class="value" id="srk-wc-page"><?php echo ! empty( $progress['current_page'] ) ? esc_html( $progress['current_page'] ) : '0'; ?></span></div>
							<div class="srk-metric"><span class="label">Imported</span><span class="value" id="srk-wc-imported"><?php echo ! empty( $progress['summary']['products_imported'] ) ? esc_html( $progress['summary']['products_imported'] ) : '0'; ?></span></div>
							<div class="srk-metric"><span class="label">Updated</span><span class="value" id="srk-wc-updated"><?php echo ! empty( $progress['summary']['products_updated'] ) ? esc_html( $progress['summary']['products_updated'] ) : '0'; ?></span></div>
							<div class="srk-metric"><span class="label">Skipped</span><span class="value" id="srk-wc-skipped"><?php echo ! empty( $progress['summary']['products_skipped'] ) ? esc_html( $progress['summary']['products_skipped'] ) : '0'; ?></span></div>
							<div class="srk-metric"><span class="label">Processed</span><span class="value" id="srk-wc-processed"><?php echo ! empty( $progress['summary']['products_processed'] ) ? esc_html( $progress['summary']['products_processed'] ) : '0'; ?></span></div>
							<div class="srk-metric"><span class="label">Errors</span><span class="value" id="srk-wc-errors"><?php echo ! empty( $progress['summary']['errors'] ) ? esc_html( $progress['summary']['errors'] ) : '0'; ?></span></div>
						</div>
						<div class="srk-log" id="srk-wc-log-box">
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

				<?php if ( ! empty( $settings['last_summary'] ) ) : ?>
					<div class="srk-card" style="max-width:1280px;margin-top:18px">
						<h2>Last Finished Summary</h2>
						<table class="srk-summary-table">
							<tbody>
							<?php foreach ( $settings['last_summary'] as $k => $v ) : ?>
								<tr>
									<td style="width:280px"><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $k ) ) ); ?></strong></td>
									<td><?php echo esc_html( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<script>
				(function($){
					let running = false;
					let failCount = 0;
					const maxRetries = 5;
					const nonce = '<?php echo esc_js( $nonce ); ?>';

					function renderProgress(data){
						if(!data){return;}
						$('#srk-wc-status').text(data.status || 'idle');
						$('#srk-wc-page').text(data.current_page || 0);
						$('#srk-wc-imported').text(data.summary?.products_imported || 0);
						$('#srk-wc-updated').text(data.summary?.products_updated || 0);
						$('#srk-wc-skipped').text(data.summary?.products_skipped || 0);
						$('#srk-wc-processed').text(data.summary?.products_processed || 0);
						$('#srk-wc-errors').text(data.summary?.errors || 0);

						const box = $('#srk-wc-log-box');
						box.empty();
						if(data.logs && data.logs.length){
							[...data.logs].reverse().forEach(function(line){
								box.append($('<div/>').text(line));
							});
						}else{
							box.html('<div>No logs yet.</div>');
						}
					}

					function pollProgress(){
						$.post(ajaxurl,{action:'srk_wc_ltdi_get_progress',nonce:nonce},function(resp){
							if(resp.success){ renderProgress(resp.data); }
						});
					}

					function runNextBatch(){
						$.post(ajaxurl,{action:'srk_wc_ltdi_run_batch',nonce:nonce},function(resp){
							if(resp.success){
								failCount = 0;
								renderProgress(resp.data.progress);
								if(resp.data.done){
									running = false;
									alert('Import completed.');
								}else{
									setTimeout(runNextBatch, 500);
								}
							}else{
								failCount++;
								if(failCount <= maxRetries){
									setTimeout(runNextBatch, 1500);
								}else{
									running = false;
									alert((resp.data && resp.data.message ? resp.data.message : 'Batch failed.') + ' You can click Run Auto Import again to resume from the saved page.');
								}
							}
						}).fail(function(){
							failCount++;
							if(failCount <= maxRetries){
								setTimeout(runNextBatch, 1500);
							}else{
								running = false;
								alert('AJAX batch request failed repeatedly. Click Run Auto Import again to resume from the saved page.');
							}
						});
					}

					$('#srk-wc-start-import').on('click', function(){
						if(running){ return; }
						running = true;
						$.post(ajaxurl,{action:'srk_wc_ltdi_start_import',nonce:nonce},function(resp){
							if(resp.success){
								renderProgress(resp.data.progress);
								runNextBatch();
							}else{
								running = false;
								alert(resp.data && resp.data.message ? resp.data.message : 'Could not start import.');
							}
						}).fail(function(){
							running = false;
							alert('Could not start import.');
						});
					});

					$('#srk-wc-refresh-progress').on('click', pollProgress);

					$('#srk-wc-clear-logs').on('click', function(){
						$.post(ajaxurl,{action:'srk_wc_ltdi_clear_logs',nonce:nonce},function(resp){
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

					$('#srk-wc-reset-dev-data').on('click', function(){
						if(!confirm('This will delete dev products, product categories, and product tags. Continue?')){
							return;
						}
						$.post(ajaxurl,{action:'srk_wc_ltdi_reset_dev_data',nonce:nonce},function(resp){
							if(resp.success){
								renderProgress(resp.data.progress);
								alert(resp.data.message || 'Delete completed.');
							}else{
								alert(resp.data && resp.data.message ? resp.data.message : 'Delete failed.');
							}
						}).fail(function(){
							alert('Delete request failed.');
						});
					});

					setInterval(pollProgress, 4000);
				})(jQuery);
				</script>
			</div>
			<?php
		}

		public function handle_test_connection() {
			$this->verify_request();
			$settings = $this->get_settings();
			$response = $this->api_get( 'products', array( 'per_page' => 1, 'status' => 'any' ), $settings );
			if ( is_wp_error( $response ) ) {
				$this->save_connection_status( false, $response->get_error_message() );
				$this->redirect_with_error( $response->get_error_message() );
			}
			$this->save_connection_status( true, 'Connection successful.' );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => self::MENU_SLUG,
						'message' => rawurlencode( 'Connection successful.' ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}


		public function ajax_clear_logs() {
			$this->verify_ajax();

			$progress = $this->get_progress();
			if ( empty( $progress ) || ! is_array( $progress ) ) {
				$progress = array(
					'status'       => 'idle',
					'current_page' => 0,
					'summary'      => array(
						'products_processed'  => 0,
						'products_imported'   => 0,
						'products_updated'    => 0,
						'products_skipped'    => 0,
						'categories_imported' => 0,
						'variations_imported' => 0,
						'variations_updated'  => 0,
						'images_imported'     => 0,
						'errors'              => 0,
						'reset_products'      => 0,
						'reset_categories'    => 0,
						'reset_tags'          => 0,
					),
				);
			}

			$progress['logs'] = array();
			$this->save_progress( $progress );

			wp_send_json_success(
				array(
					'message'  => 'Logs cleared successfully.',
					'progress' => $this->get_progress(),
				)
			);
		}

		public function ajax_reset_dev_data() {
			$this->verify_ajax();

			$progress = array(
				'status'       => 'deleting',
				'current_page' => 0,
				'summary'      => array(
					'products_processed'  => 0,
					'products_imported'   => 0,
					'products_updated'    => 0,
					'products_skipped'    => 0,
					'categories_imported' => 0,
					'variations_imported' => 0,
					'variations_updated'  => 0,
					'images_imported'     => 0,
					'errors'              => 0,
					'reset_products'      => 0,
					'reset_categories'    => 0,
					'reset_tags'          => 0,
				),
				'logs'         => array(),
			);
			$this->save_progress( $progress );
			$this->clear_resume_state();
			$this->add_log( 'Dev delete started.', 'INFO' );

			$deleted_products   = 0;
			$deleted_categories = 0;
			$deleted_tags       = 0;

			$product_ids = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $product_ids ) ) {
				$this->add_log( 'Found ' . count( $product_ids ) . ' dev products and variations to delete.', 'INFO' );
				foreach ( $product_ids as $pid ) {
					$title = get_the_title( $pid );
					$type  = get_post_type( $pid );
					wp_delete_post( $pid, true );
					$deleted_products++;
					$this->add_log( 'Deleted ' . $type . ' #' . $pid . ( $title ? ' - ' . $title : '' ), 'OK' );
				}
			} else {
				$this->add_log( 'No dev products or variations found to delete.', 'INFO' );
			}

			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				)
			);

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$this->add_log( 'Scanning dev product categories for deletion.', 'INFO' );
				foreach ( $terms as $term ) {
					if ( 'uncategorized' === $term->slug || 'Uncategorized' === $term->name ) {
						$this->add_log( 'Kept default category: ' . $term->name . ' (#' . $term->term_id . ')', 'INFO' );
						continue;
					}
					$term_name = $term->name;
					$term_id   = $term->term_id;
					wp_delete_term( $term_id, 'product_cat' );
					$deleted_categories++;
					$this->add_log( 'Deleted category #' . $term_id . ' - ' . $term_name, 'OK' );
				}
			} else {
				$this->add_log( 'No product categories found to delete.', 'INFO' );
			}

			$tags = get_terms(
				array(
					'taxonomy'   => 'product_tag',
					'hide_empty' => false,
				)
			);

			if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
				$this->add_log( 'Found ' . count( $tags ) . ' dev product tags to delete.', 'INFO' );
				foreach ( $tags as $tag ) {
					$tag_name = $tag->name;
					$tag_id   = $tag->term_id;
					wp_delete_term( $tag_id, 'product_tag' );
					$deleted_tags++;
					$this->add_log( 'Deleted tag #' . $tag_id . ' - ' . $tag_name, 'OK' );
				}
			} else {
				$this->add_log( 'No product tags found to delete.', 'INFO' );
			}

			$progress = $this->get_progress();
			$progress['status'] = 'delete-complete';
			$progress['summary']['reset_products']   = $deleted_products;
			$progress['summary']['reset_categories'] = $deleted_categories;
			$progress['summary']['reset_tags']       = $deleted_tags;
			$this->save_progress( $progress );

			$this->add_log( 'Delete summary, products and variations: ' . $deleted_products, 'OK' );
			$this->add_log( 'Delete summary, categories: ' . $deleted_categories, 'OK' );
			$this->add_log( 'Delete summary, tags: ' . $deleted_tags, 'OK' );

			wp_send_json_success(
				array(
					'message'  => 'Dev delete completed successfully.',
					'progress' => $this->get_progress(),
				)
			);
		}

		public function ajax_start_import() {
			$this->verify_ajax();

			if ( ! class_exists( 'WooCommerce' ) ) {
				wp_send_json_error( array( 'message' => 'WooCommerce must be active on the dev site.' ) );
			}

			$settings          = $this->get_settings();
			$existing_progress = $this->get_progress();
			$resume_state      = $this->get_resume_state();

			if ( ! empty( $settings['resume_stopped_import'] ) ) {
				if ( ! empty( $resume_state['current_page'] ) ) {
					$progress = array(
						'status'            => 'starting',
						'started_at'        => isset( $resume_state['started_at'] ) ? $resume_state['started_at'] : current_time( 'mysql' ),
						'current_page'      => absint( $resume_state['current_page'] ),
						'end_page'          => isset( $resume_state['end_page'] ) ? absint( $resume_state['end_page'] ) : 0,
						'auto_all_pages'    => ! empty( $resume_state['auto_all_pages'] ),
						'categories_done'   => ! empty( $resume_state['categories_done'] ),
						'reset_done'        => ! empty( $resume_state['reset_done'] ),
						'settings_snapshot' => $settings,
						'summary'           => ! empty( $resume_state['summary'] ) && is_array( $resume_state['summary'] ) ? $resume_state['summary'] : array(),
						'logs'              => ! empty( $resume_state['logs'] ) && is_array( $resume_state['logs'] ) ? $resume_state['logs'] : array(),
					);
					$this->save_progress( $progress );
					$this->add_log( 'Resuming import from saved page ' . absint( $progress['current_page'] ) . '.', 'INFO' );
					wp_send_json_success( array( 'progress' => $this->get_progress(), 'resumed' => true ) );
				}

				if ( $this->is_progress_resumable( $existing_progress ) ) {
					$existing_progress['status']            = 'starting';
					$existing_progress['settings_snapshot'] = $settings;
					$this->save_progress( $existing_progress );
					$this->add_log( 'Resuming import from saved page ' . absint( $existing_progress['current_page'] ) . '.', 'INFO' );
					wp_send_json_success( array( 'progress' => $this->get_progress(), 'resumed' => true ) );
				}
			}

			$auto_all_pages = ! empty( $settings['auto_all_pages'] );
			$progress       = array(
				'status'            => 'starting',
				'started_at'        => current_time( 'mysql' ),
				'current_page'      => max( 1, absint( $settings['start_page'] ) ),
				'end_page'          => $auto_all_pages ? 0 : ( max( 1, absint( $settings['start_page'] ) ) + max( 1, absint( $settings['max_pages'] ) ) - 1 ),
				'auto_all_pages'    => $auto_all_pages,
				'categories_done'   => false,
				'reset_done'        => false,
				'settings_snapshot' => $settings,
				'summary'           => array(
					'categories_imported' => 0,
					'products_fetched'    => 0,
					'products_processed'  => 0,
					'products_imported'   => 0,
					'products_updated'    => 0,
					'products_skipped'    => 0,
					'variations_imported' => 0,
					'variations_updated'  => 0,
					'images_imported'     => 0,
					'errors'              => 0,
					'reset_products'      => 0,
					'reset_categories'    => 0,
					'reset_tags'          => 0,
				),
				'logs' => array(),
			);
			$this->save_progress( $progress );
			$this->save_resume_state(
				array(
					'current_page'    => $progress['current_page'],
					'end_page'        => $progress['end_page'],
					'auto_all_pages'  => $progress['auto_all_pages'],
					'categories_done' => false,
					'reset_done'      => false,
					'started_at'      => $progress['started_at'],
					'summary'         => $progress['summary'],
					'logs'            => array(),
				)
			);
			$this->add_log( 'Import session initialized.', 'INFO' );

			wp_send_json_success( array( 'progress' => $this->get_progress(), 'resumed' => false ) );
		}

		public function ajax_run_batch() {
			$this->verify_ajax();

			$progress = $this->get_progress();
			if ( empty( $progress ) ) {
				wp_send_json_error( array( 'message' => 'No active import session found.' ) );
			}

			$settings = ! empty( $progress['settings_snapshot'] ) ? $progress['settings_snapshot'] : $this->get_settings();

			if ( empty( $progress['reset_done'] ) && ! empty( $settings['reset_before_import'] ) ) {
				$this->perform_silent_reset_for_import();
				$progress = $this->get_progress();
				$progress['reset_done'] = true;
				$this->save_progress( $progress );
			}

			if ( empty( $progress['categories_done'] ) && ! empty( $settings['import_categories'] ) ) {
				$result = $this->import_all_categories( $settings );
				$progress = $this->get_progress();
				$progress['categories_done'] = true;
				$this->save_progress( $progress );
				$this->save_resume_state( array(
					'current_page'    => isset( $progress['current_page'] ) ? absint( $progress['current_page'] ) : 1,
					'end_page'        => isset( $progress['end_page'] ) ? absint( $progress['end_page'] ) : 0,
					'auto_all_pages'  => ! empty( $progress['auto_all_pages'] ),
					'categories_done' => true,
					'reset_done'      => ! empty( $progress['reset_done'] ),
					'started_at'      => isset( $progress['started_at'] ) ? $progress['started_at'] : current_time( 'mysql' ),
					'summary'         => isset( $progress['summary'] ) ? $progress['summary'] : array(),
					'logs'            => isset( $progress['logs'] ) ? $progress['logs'] : array(),
				) );

				if ( is_wp_error( $result ) ) {
					$progress['status'] = 'error';
					$progress['summary']['errors']++;
					$this->save_progress( $progress );
					$this->add_log( 'Category import failed: ' . $result->get_error_message(), 'ERROR' );
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				}
			}

			$current_page = isset( $progress['current_page'] ) ? absint( $progress['current_page'] ) : 1;
			$end_page     = isset( $progress['end_page'] ) ? absint( $progress['end_page'] ) : 0;
			$auto_all_pages = ! empty( $progress['auto_all_pages'] );

			if ( ! $auto_all_pages && $current_page > $end_page ) {
				$progress['status']      = 'completed';
				$progress['finished_at'] = current_time( 'mysql' );
				$this->save_progress( $progress );
				$this->clear_resume_state();

				$saved = $this->get_settings();
				$saved['last_summary'] = $progress['summary'];
				$this->save_settings( $saved );

				wp_send_json_success( array( 'done' => true, 'progress' => $progress ) );
			}

			$progress['status'] = 'running';
			$this->save_progress( $progress );
			$this->add_log( 'Fetching remote products for page ' . $current_page . ( ! empty( $progress['auto_all_pages'] ) ? ' in auto-all mode.' : '.' ), 'INFO' );

			$query = array(
				'page'     => $current_page,
				'per_page' => max( 1, min( 100, absint( $settings['per_page'] ) ) ),
				'status'   => 'any',
			);

			if ( ! empty( $settings['selected_category_ids'] ) ) {
				$query['category'] = $this->normalize_category_ids_for_api( $settings['selected_category_ids'] );
			}

			$response = $this->api_get( 'products', $query, $settings );
			if ( is_wp_error( $response ) ) {
				$progress = $this->get_progress();
				$progress['status'] = 'error';
				$progress['summary']['errors']++;
				$this->save_progress( $progress );
				$this->add_log( 'Product page ' . $current_page . ' failed: ' . $response->get_error_message(), 'ERROR' );
				wp_send_json_error( array( 'message' => $response->get_error_message() ) );
			}

			$products = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $products ) || ! is_array( $products ) ) {
				$progress = $this->get_progress();
				$progress['status']      = 'completed';
				$progress['finished_at'] = current_time( 'mysql' );
				$this->save_progress( $progress );
				$this->clear_resume_state();
				$this->add_log( 'No products returned for page ' . $current_page . ', import finished automatically.', 'INFO' );

				$saved = $this->get_settings();
				$saved['last_summary'] = $progress['summary'];
				$this->save_settings( $saved );

				wp_send_json_success( array( 'done' => true, 'progress' => $progress ) );
			}

			$progress = $this->get_progress();
			$progress['summary']['products_fetched'] += count( $products );
			$this->save_progress( $progress );

			foreach ( $products as $remote_product ) {
				$result = $this->import_single_product( $remote_product, $settings );
				$progress = $this->get_progress();
				$progress['summary']['products_processed']++;
				if ( is_wp_error( $result ) ) {
					$progress['summary']['errors']++;
					$this->save_progress( $progress );
					$this->add_log( $result->get_error_message(), 'ERROR' );
				} else {
					$this->save_progress( $progress );
				}
			}

			$progress = $this->get_progress();
			$progress['current_page'] = $current_page + 1;
			$this->save_progress( $progress );
			$this->save_resume_state( array(
				'current_page'    => $progress['current_page'],
				'end_page'        => isset( $progress['end_page'] ) ? absint( $progress['end_page'] ) : 0,
				'auto_all_pages'  => ! empty( $progress['auto_all_pages'] ),
				'categories_done' => ! empty( $progress['categories_done'] ),
				'reset_done'      => ! empty( $progress['reset_done'] ),
				'started_at'      => isset( $progress['started_at'] ) ? $progress['started_at'] : current_time( 'mysql' ),
				'summary'         => isset( $progress['summary'] ) ? $progress['summary'] : array(),
				'logs'            => isset( $progress['logs'] ) ? $progress['logs'] : array(),
			) );
			$this->add_log( 'Finished page ' . $current_page . '. Next saved resume page is ' . $progress['current_page'] . '.', 'OK' );

			wp_send_json_success( array( 'done' => false, 'progress' => $this->get_progress() ) );
		}

		public function ajax_get_progress() {
			$this->verify_ajax();
			wp_send_json_success( $this->get_progress() );
		}

		private function perform_silent_reset_for_import() {
			$this->add_log( 'Reset before import enabled, cleaning dev store first.', 'INFO' );

			$deleted_products = 0;
			$product_ids = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			if ( ! empty( $product_ids ) ) {
				foreach ( $product_ids as $pid ) {
					wp_delete_post( $pid, true );
					$deleted_products++;
				}
			}

			$deleted_categories = 0;
			$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( 'uncategorized' === $term->slug || 'Uncategorized' === $term->name ) {
						continue;
					}
					wp_delete_term( $term->term_id, 'product_cat' );
					$deleted_categories++;
				}
			}

			$deleted_tags = 0;
			$tags = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );
			if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
				foreach ( $tags as $tag ) {
					wp_delete_term( $tag->term_id, 'product_tag' );
					$deleted_tags++;
				}
			}

			$progress = $this->get_progress();
			$progress['summary']['reset_products']   = $deleted_products;
			$progress['summary']['reset_categories'] = $deleted_categories;
			$progress['summary']['reset_tags']       = $deleted_tags;
			$this->save_progress( $progress );

			$this->add_log( 'Reset complete, deleted products and variations: ' . $deleted_products, 'OK' );
			$this->add_log( 'Reset complete, deleted categories: ' . $deleted_categories, 'OK' );
			$this->add_log( 'Reset complete, deleted tags: ' . $deleted_tags, 'OK' );
		}

		private function normalize_category_ids_for_api( $csv ) {
			$ids = array_filter( array_map( 'absint', array_map( 'trim', explode( ',', (string) $csv ) ) ) );
			return implode( ',', $ids );
		}

		private function import_all_categories( $settings ) {
			$page = 1;
			$all_categories = array();
			$filter_ids = array_filter( array_map( 'absint', array_map( 'trim', explode( ',', (string) $settings['selected_category_ids'] ) ) ) );

			while ( true ) {
				$response = $this->api_get( 'products/categories', array( 'page' => $page, 'per_page' => 100 ), $settings );
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				$cats = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( empty( $cats ) || ! is_array( $cats ) ) {
					break;
				}
				$all_categories = array_merge( $all_categories, $cats );
				if ( count( $cats ) < 100 ) {
					break;
				}
				$page++;
			}

			if ( empty( $all_categories ) ) {
				$this->add_log( 'No categories found on source site.', 'INFO' );
				return true;
			}

			usort(
				$all_categories,
				function( $a, $b ) {
					return (int) $a['parent'] - (int) $b['parent'];
				}
			);

			$remote_to_local = array();

			foreach ( $all_categories as $cat ) {
				$remote_id = ! empty( $cat['id'] ) ? absint( $cat['id'] ) : 0;
				if ( ! empty( $filter_ids ) && ! in_array( $remote_id, $filter_ids, true ) ) {
					continue;
				}

				$parent_id = 0;
				if ( ! empty( $cat['parent'] ) && ! empty( $remote_to_local[ absint( $cat['parent'] ) ] ) ) {
					$parent_id = absint( $remote_to_local[ absint( $cat['parent'] ) ] );
				}

				$term_id = 0;
				$existing_by_meta = get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'hide_empty' => false,
						'meta_query' => array(
							array(
								'key'   => '_srk_remote_cat_id',
								'value' => $remote_id,
							),
						),
						'number' => 1,
					)
				);
				if ( ! empty( $existing_by_meta ) && ! is_wp_error( $existing_by_meta ) ) {
					$term_id = (int) $existing_by_meta[0]->term_id;
				}
				if ( ! $term_id && ! empty( $cat['slug'] ) ) {
					$existing_by_slug = get_term_by( 'slug', sanitize_title( $cat['slug'] ), 'product_cat' );
					if ( $existing_by_slug && ! is_wp_error( $existing_by_slug ) ) {
						$term_id = (int) $existing_by_slug->term_id;
					}
				}

				$args = array(
					'description' => isset( $cat['description'] ) ? wp_kses_post( $cat['description'] ) : '',
					'slug'        => sanitize_title( $cat['slug'] ),
					'parent'      => $parent_id,
				);

				if ( $term_id ) {
					wp_update_term( $term_id, 'product_cat', $args );
				} else {
					$created = wp_insert_term( wp_strip_all_tags( $cat['name'] ), 'product_cat', $args );
					if ( is_wp_error( $created ) ) {
						$this->add_log( 'Category failed: ' . $cat['name'] . ' - ' . $created->get_error_message(), 'ERROR' );
						continue;
					}
					$term_id = (int) $created['term_id'];
				}

				update_term_meta( $term_id, '_srk_remote_cat_id', $remote_id );
				$remote_to_local[ $remote_id ] = $term_id;

				if ( ! empty( $settings['remote_category_images'] ) && ! empty( $cat['image']['src'] ) ) {
					$image_id = $this->import_image_url( $cat['image']['src'], 0, true );
					if ( $image_id ) {
						update_term_meta( $term_id, 'thumbnail_id', $image_id );
					}
				}

				$progress = $this->get_progress();
				$progress['summary']['categories_imported']++;
				$this->save_progress( $progress );
				$this->add_log( 'Category synced: ' . $cat['name'], 'OK' );
			}

			return true;
		}

		private function import_single_product( $remote_product, $settings ) {
			$name      = ! empty( $remote_product['name'] ) ? $remote_product['name'] : 'Untitled';
			$remote_id = ! empty( $remote_product['id'] ) ? absint( $remote_product['id'] ) : 0;
			$sku       = isset( $remote_product['sku'] ) ? (string) $remote_product['sku'] : '';
			$slug      = ! empty( $remote_product['slug'] ) ? sanitize_title( $remote_product['slug'] ) : sanitize_title( $name );
			$type      = ! empty( $remote_product['type'] ) ? $remote_product['type'] : 'simple';

			$existing_id = $this->find_existing_product_id( $remote_product, $settings );

			if ( $existing_id && empty( $settings['update_existing'] ) ) {
				$progress = $this->get_progress();
				$progress['summary']['products_skipped']++;
				$this->save_progress( $progress );
				$this->add_log( $name . ' skipped, existing match found.', 'SKIP' );
				return true;
			}

			$post_id = $existing_id ? $existing_id : wp_insert_post(
				array(
					'post_type'    => 'product',
					'post_status'  => $settings['import_status'],
					'post_title'   => $name,
					'post_name'    => $slug,
					'post_content' => isset( $remote_product['description'] ) ? wp_kses_post( $remote_product['description'] ) : '',
					'post_excerpt' => isset( $remote_product['short_description'] ) ? wp_kses_post( $remote_product['short_description'] ) : '',
				),
				true
			);

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				return new WP_Error( 'product_create_failed', $name . ' could not be created.' );
			}

			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_title'   => $name,
					'post_name'    => $slug,
					'post_status'  => $settings['import_status'],
					'post_content' => isset( $remote_product['description'] ) ? wp_kses_post( $remote_product['description'] ) : '',
					'post_excerpt' => isset( $remote_product['short_description'] ) ? wp_kses_post( $remote_product['short_description'] ) : '',
				)
			);

			update_post_meta( $post_id, '_srk_remote_product_id', $remote_id );
			wp_set_object_terms( $post_id, $type, 'product_type' );

			update_post_meta( $post_id, '_sku', $sku );
			update_post_meta( $post_id, '_regular_price', isset( $remote_product['regular_price'] ) ? wc_clean( $remote_product['regular_price'] ) : '' );
			update_post_meta( $post_id, '_sale_price', isset( $remote_product['sale_price'] ) ? wc_clean( $remote_product['sale_price'] ) : '' );
			update_post_meta( $post_id, '_price', isset( $remote_product['price'] ) ? wc_clean( $remote_product['price'] ) : '' );
			update_post_meta( $post_id, '_stock_status', ! empty( $remote_product['stock_status'] ) ? wc_clean( $remote_product['stock_status'] ) : 'instock' );
			update_post_meta( $post_id, '_manage_stock', ! empty( $remote_product['manage_stock'] ) ? 'yes' : 'no' );
			update_post_meta( $post_id, '_stock', isset( $remote_product['stock_quantity'] ) && '' !== $remote_product['stock_quantity'] ? wc_stock_amount( $remote_product['stock_quantity'] ) : '' );
			update_post_meta( $post_id, '_virtual', ! empty( $remote_product['virtual'] ) ? 'yes' : 'no' );
			update_post_meta( $post_id, '_downloadable', ! empty( $remote_product['downloadable'] ) ? 'yes' : 'no' );
			update_post_meta( $post_id, '_sold_individually', ! empty( $remote_product['sold_individually'] ) ? 'yes' : 'no' );
			update_post_meta( $post_id, '_featured', ! empty( $remote_product['featured'] ) ? 'yes' : 'no' );
			update_post_meta( $post_id, '_weight', isset( $remote_product['weight'] ) ? wc_clean( $remote_product['weight'] ) : '' );
			update_post_meta( $post_id, '_length', isset( $remote_product['dimensions']['length'] ) ? wc_clean( $remote_product['dimensions']['length'] ) : '' );
			update_post_meta( $post_id, '_width', isset( $remote_product['dimensions']['width'] ) ? wc_clean( $remote_product['dimensions']['width'] ) : '' );
			update_post_meta( $post_id, '_height', isset( $remote_product['dimensions']['height'] ) ? wc_clean( $remote_product['dimensions']['height'] ) : '' );

			$this->import_product_attributes( $post_id, $remote_product );

			if ( ! empty( $settings['import_categories'] ) && ! empty( $remote_product['categories'] ) && is_array( $remote_product['categories'] ) ) {
				$cat_ids = array();
				foreach ( $remote_product['categories'] as $category ) {
					$term = get_terms(
						array(
							'taxonomy'   => 'product_cat',
							'hide_empty' => false,
							'meta_query' => array(
								array(
									'key'   => '_srk_remote_cat_id',
									'value' => absint( $category['id'] ),
								),
							),
							'number' => 1,
						)
					);
					if ( ! empty( $term ) && ! is_wp_error( $term ) ) {
						$cat_ids[] = (int) $term[0]->term_id;
					} else {
						$by_slug = get_term_by( 'slug', sanitize_title( $category['slug'] ), 'product_cat' );
						if ( $by_slug && ! is_wp_error( $by_slug ) ) {
							$cat_ids[] = (int) $by_slug->term_id;
						}
					}
				}
				if ( ! empty( $cat_ids ) ) {
					wp_set_object_terms( $post_id, array_unique( $cat_ids ), 'product_cat' );
				}
			}

			if ( ! empty( $settings['import_tags'] ) && ! empty( $remote_product['tags'] ) && is_array( $remote_product['tags'] ) ) {
				$tag_names = array();
				foreach ( $remote_product['tags'] as $tag ) {
					if ( ! empty( $tag['name'] ) ) {
						$tag_names[] = $tag['name'];
					}
				}
				if ( ! empty( $tag_names ) ) {
					wp_set_object_terms( $post_id, $tag_names, 'product_tag' );
				}
			}

			if ( ! empty( $settings['copy_meta_data'] ) && ! empty( $remote_product['meta_data'] ) && is_array( $remote_product['meta_data'] ) ) {
				$this->copy_meta_data_to_post( $post_id, $remote_product['meta_data'] );
			}

			if ( ! empty( $settings['import_images'] ) && ! empty( $remote_product['images'] ) && is_array( $remote_product['images'] ) ) {
				$count = $this->import_product_images( $post_id, $remote_product['images'], ! empty( $settings['gallery_resync_mode'] ) );
				$progress = $this->get_progress();
				$progress['summary']['images_imported'] += (int) $count;
				$this->save_progress( $progress );
			}

			if ( 'variable' === $type && ! empty( $settings['import_variations'] ) ) {
				$result = $this->import_variations_for_product( $post_id, $remote_product, $settings );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			$progress = $this->get_progress();
			if ( $existing_id ) {
				$progress['summary']['products_updated']++;
				$this->save_progress( $progress );
				$this->add_log( $name . ' updated.', 'UPDATE' );
			} else {
				$progress['summary']['products_imported']++;
				$this->save_progress( $progress );
				$this->add_log( $name . ' imported.', 'NEW' );
			}

			return true;
		}

		private function import_product_attributes( $post_id, $remote_product ) {
			if ( empty( $remote_product['attributes'] ) || ! is_array( $remote_product['attributes'] ) ) {
				return;
			}
			$product_attributes = array();

			foreach ( $remote_product['attributes'] as $attribute ) {
				if ( empty( $attribute['name'] ) ) {
					continue;
				}

				$attr_name   = sanitize_text_field( $attribute['name'] );
				$is_taxonomy = ! empty( $attribute['id'] ) || ! empty( $attribute['variation'] );
				$taxonomy    = wc_attribute_taxonomy_name( sanitize_title( $attr_name ) );

				if ( $is_taxonomy ) {
					$this->maybe_create_global_attribute_taxonomy( $attr_name );

					if ( ! taxonomy_exists( $taxonomy ) ) {
						register_taxonomy(
							$taxonomy,
							array( 'product' ),
							array(
								'hierarchical' => false,
								'label'        => $attr_name,
								'query_var'    => true,
								'rewrite'      => false,
							)
						);
					}

					$term_ids = array();
					if ( ! empty( $attribute['options'] ) && is_array( $attribute['options'] ) ) {
						foreach ( $attribute['options'] as $option ) {
							$term = term_exists( $option, $taxonomy );
							if ( ! $term ) {
								$term = wp_insert_term( $option, $taxonomy );
							}
							if ( ! is_wp_error( $term ) ) {
								$term_ids[] = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
							}
						}
					}
					if ( ! empty( $term_ids ) ) {
						wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
					}

					$product_attributes[ $taxonomy ] = array(
						'name'         => $taxonomy,
						'value'        => '',
						'position'     => isset( $attribute['position'] ) ? (int) $attribute['position'] : 0,
						'is_visible'   => ! empty( $attribute['visible'] ) ? 1 : 0,
						'is_variation' => ! empty( $attribute['variation'] ) ? 1 : 0,
						'is_taxonomy'  => 1,
					);
				} else {
					$values = ! empty( $attribute['options'] ) && is_array( $attribute['options'] ) ? implode( ' | ', array_map( 'wc_clean', $attribute['options'] ) ) : '';
					$product_attributes[ sanitize_title( $attr_name ) ] = array(
						'name'         => $attr_name,
						'value'        => $values,
						'position'     => isset( $attribute['position'] ) ? (int) $attribute['position'] : 0,
						'is_visible'   => ! empty( $attribute['visible'] ) ? 1 : 0,
						'is_variation' => ! empty( $attribute['variation'] ) ? 1 : 0,
						'is_taxonomy'  => 0,
					);
				}
			}

			if ( ! empty( $product_attributes ) ) {
				update_post_meta( $post_id, '_product_attributes', $product_attributes );
			}
		}

		private function maybe_create_global_attribute_taxonomy( $label ) {
			global $wpdb;
			if ( ! function_exists( 'wc_create_attribute' ) ) {
				return;
			}
			$slug   = wc_sanitize_taxonomy_name( $label );
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s", $slug ) );
			if ( $exists ) {
				return;
			}
			$result = wc_create_attribute(
				array(
					'name'         => $label,
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			);
			if ( is_wp_error( $result ) ) {
				$this->add_log( 'Attribute create failed for ' . $label . ': ' . $result->get_error_message(), 'ERROR' );
			}
		}

		private function import_variations_for_product( $parent_id, $remote_product, $settings ) {
			$remote_id = isset( $remote_product['id'] ) ? absint( $remote_product['id'] ) : 0;
			if ( ! $remote_id ) {
				return new WP_Error( 'missing_remote_id', 'Remote product ID missing for variable product.' );
			}

			$response = $this->api_get( 'products/' . $remote_id . '/variations', array( 'page' => 1, 'per_page' => 100 ), $settings );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$variations = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $variations ) || ! is_array( $variations ) ) {
				$this->add_log( 'No variations returned for remote product ID ' . $remote_id, 'INFO' );
				return true;
			}

			foreach ( $variations as $remote_variation ) {
				$variation_id = $this->find_existing_variation_id( $parent_id, $remote_variation );
				$is_new = false;

				if ( ! $variation_id ) {
					$variation_id = wp_insert_post(
						array(
							'post_type'   => 'product_variation',
							'post_parent' => $parent_id,
							'post_status' => 'publish',
							'post_title'  => 'Variation for product#' . $parent_id,
							'menu_order'  => isset( $remote_variation['menu_order'] ) ? absint( $remote_variation['menu_order'] ) : 0,
						),
						true
					);
					if ( is_wp_error( $variation_id ) ) {
						$this->add_log( 'Variation create failed for parent ' . $parent_id, 'ERROR' );
						continue;
					}
					$is_new = true;
				}

				$variation = new WC_Product_Variation( $variation_id );
				$variation->set_parent_id( $parent_id );
				$variation->set_status( 'publish' );
				if ( isset( $remote_variation['sku'] ) ) {
					$variation->set_sku( (string) $remote_variation['sku'] );
				}
				if ( isset( $remote_variation['regular_price'] ) ) {
					$variation->set_regular_price( (string) $remote_variation['regular_price'] );
				}
				if ( isset( $remote_variation['sale_price'] ) ) {
					$variation->set_sale_price( (string) $remote_variation['sale_price'] );
				}
				if ( isset( $remote_variation['price'] ) ) {
					$variation->set_price( (string) $remote_variation['price'] );
				}
				$variation->set_manage_stock( ! empty( $remote_variation['manage_stock'] ) );
				$variation->set_stock_status( ! empty( $remote_variation['stock_status'] ) ? $remote_variation['stock_status'] : 'instock' );

				if ( isset( $remote_variation['stock_quantity'] ) && '' !== $remote_variation['stock_quantity'] ) {
					$variation->set_stock_quantity( wc_stock_amount( $remote_variation['stock_quantity'] ) );
				}
				if ( isset( $remote_variation['weight'] ) ) {
					$variation->set_weight( (string) $remote_variation['weight'] );
				}
				if ( ! empty( $remote_variation['dimensions'] ) && is_array( $remote_variation['dimensions'] ) ) {
					$variation->set_length( isset( $remote_variation['dimensions']['length'] ) ? (string) $remote_variation['dimensions']['length'] : '' );
					$variation->set_width( isset( $remote_variation['dimensions']['width'] ) ? (string) $remote_variation['dimensions']['width'] : '' );
					$variation->set_height( isset( $remote_variation['dimensions']['height'] ) ? (string) $remote_variation['dimensions']['height'] : '' );
				}

				$attributes = array();
				if ( ! empty( $remote_variation['attributes'] ) && is_array( $remote_variation['attributes'] ) ) {
					foreach ( $remote_variation['attributes'] as $attr ) {
						if ( empty( $attr['name'] ) ) {
							continue;
						}
						$taxonomy = wc_attribute_taxonomy_name( sanitize_title( $attr['name'] ) );
						$attributes[ $taxonomy ] = sanitize_title( $attr['option'] );
					}
				}
				$variation->set_attributes( $attributes );
				$variation->save();

				update_post_meta( $variation_id, '_srk_remote_variation_id', isset( $remote_variation['id'] ) ? absint( $remote_variation['id'] ) : 0 );

				if ( ! empty( $settings['copy_meta_data'] ) && ! empty( $remote_variation['meta_data'] ) && is_array( $remote_variation['meta_data'] ) ) {
					$this->copy_meta_data_to_post( $variation_id, $remote_variation['meta_data'] );
				}

				if ( ! empty( $settings['import_images'] ) && ! empty( $remote_variation['image']['src'] ) ) {
					$image_id = $this->import_image_url( $remote_variation['image']['src'], $variation_id, true );
					if ( $image_id ) {
						update_post_meta( $variation_id, '_thumbnail_id', $image_id );
						$progress = $this->get_progress();
						$progress['summary']['images_imported']++;
						$this->save_progress( $progress );
					}
				}

				$progress = $this->get_progress();
				if ( $is_new ) {
					$progress['summary']['variations_imported']++;
				} else {
					$progress['summary']['variations_updated']++;
				}
				$this->save_progress( $progress );
			}

			WC_Product_Variable::sync( $parent_id );
			wc_delete_product_transients( $parent_id );
			return true;
		}

		private function import_product_images( $post_id, $images, $resync = false ) {
			$count = 0;
			$gallery_ids = array();

			if ( $resync ) {
				delete_post_thumbnail( $post_id );
				delete_post_meta( $post_id, '_product_image_gallery' );
			}

			foreach ( $images as $index => $image ) {
				if ( empty( $image['src'] ) ) {
					continue;
				}
				$attachment_id = $this->import_image_url( $image['src'], $post_id, true );
				if ( ! $attachment_id ) {
					continue;
				}
				if ( 0 === (int) $index ) {
					set_post_thumbnail( $post_id, $attachment_id );
				} else {
					$gallery_ids[] = $attachment_id;
				}
				$count++;
			}

			if ( ! empty( $gallery_ids ) ) {
				update_post_meta( $post_id, '_product_image_gallery', implode( ',', array_unique( $gallery_ids ) ) );
			}

			return $count;
		}

		private function import_image_url( $url, $parent_post_id = 0, $dedupe_by_source = true ) {
			if ( empty( $url ) ) {
				return 0;
			}

			if ( $dedupe_by_source ) {
				$existing = get_posts(
					array(
						'post_type'      => 'attachment',
						'posts_per_page' => 1,
						'post_status'    => 'inherit',
						'meta_key'       => '_srk_remote_image_url',
						'meta_value'     => esc_url_raw( $url ),
						'fields'         => 'ids',
					)
				);
				if ( ! empty( $existing[0] ) ) {
					return (int) $existing[0];
				}
			}

			if ( ! function_exists( 'media_handle_sideload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			$tmp = download_url( $url, 45 );
			if ( is_wp_error( $tmp ) ) {
				$this->add_log( 'Image download failed: ' . $url, 'ERROR' );
				return 0;
			}

			$file_array = array(
				'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
				'tmp_name' => $tmp,
			);

			$attachment_id = media_handle_sideload( $file_array, $parent_post_id );
			if ( is_wp_error( $attachment_id ) ) {
				@unlink( $tmp );
				$this->add_log( 'Image sideload failed: ' . $url, 'ERROR' );
				return 0;
			}

			update_post_meta( $attachment_id, '_srk_remote_image_url', esc_url_raw( $url ) );
			return (int) $attachment_id;
		}

		private function copy_meta_data_to_post( $post_id, $meta_data ) {
			$known_addon_keys = array(
				'_product_addons',
				'product_addons',
				'_wc_product_addons',
				'_wcj_product_addons',
				'_ywapo_product_addons'
			);

			foreach ( $meta_data as $meta_item ) {
				if ( ! is_array( $meta_item ) || empty( $meta_item['key'] ) ) {
					continue;
				}
				$key = (string) $meta_item['key'];
				$val = isset( $meta_item['value'] ) ? $meta_item['value'] : '';

				if ( in_array( $key, array( '_edit_lock', '_edit_last' ), true ) ) {
					continue;
				}

				if ( is_array( $val ) || is_object( $val ) ) {
					$val = maybe_serialize( $val );
				}

				update_post_meta( $post_id, $key, $val );

				if ( in_array( $key, $known_addon_keys, true ) ) {
					$this->add_log( 'Copied product add-on meta key ' . $key . ' to product #' . $post_id . '.', 'INFO' );
				}

				if ( false !== strpos( $key, 'addon' ) || false !== strpos( $key, 'add_on' ) ) {
					$this->add_log( 'Copied possible add-on meta key ' . $key . ' to product #' . $post_id . '.', 'INFO' );
				}
			}
		}

		private function find_existing_product_id( $remote_product, $settings ) {
			$mode      = ! empty( $settings['match_by'] ) ? $settings['match_by'] : 'smart';
			$remote_id = ! empty( $remote_product['id'] ) ? absint( $remote_product['id'] ) : 0;
			$sku       = ! empty( $remote_product['sku'] ) ? (string) $remote_product['sku'] : '';
			$slug      = ! empty( $remote_product['slug'] ) ? sanitize_title( $remote_product['slug'] ) : '';

			if ( in_array( $mode, array( 'smart', 'remote_id' ), true ) && $remote_id ) {
				$posts = get_posts(
					array(
						'post_type'      => 'product',
						'posts_per_page' => 1,
						'post_status'    => 'any',
						'meta_key'       => '_srk_remote_product_id',
						'meta_value'     => $remote_id,
						'fields'         => 'ids',
					)
				);
				if ( ! empty( $posts[0] ) ) {
					return (int) $posts[0];
				}
				if ( 'remote_id' === $mode ) {
					return 0;
				}
			}

			if ( in_array( $mode, array( 'smart', 'sku' ), true ) && $sku ) {
				$id = wc_get_product_id_by_sku( $sku );
				if ( $id ) {
					return (int) $id;
				}
				if ( 'sku' === $mode ) {
					return 0;
				}
			}

			if ( in_array( $mode, array( 'smart', 'slug' ), true ) && $slug ) {
				$post = get_page_by_path( $slug, OBJECT, 'product' );
				if ( $post ) {
					return (int) $post->ID;
				}
				if ( 'slug' === $mode ) {
					return 0;
				}
			}

			return 0;
		}

		private function find_existing_variation_id( $parent_id, $remote_variation ) {
			$remote_id = ! empty( $remote_variation['id'] ) ? absint( $remote_variation['id'] ) : 0;
			if ( $remote_id ) {
				$posts = get_posts(
					array(
						'post_type'      => 'product_variation',
						'posts_per_page' => 1,
						'post_parent'    => $parent_id,
						'post_status'    => 'any',
						'meta_key'       => '_srk_remote_variation_id',
						'meta_value'     => $remote_id,
						'fields'         => 'ids',
					)
				);
				if ( ! empty( $posts[0] ) ) {
					return (int) $posts[0];
				}
			}
			if ( ! empty( $remote_variation['sku'] ) ) {
				$id = wc_get_product_id_by_sku( (string) $remote_variation['sku'] );
				if ( $id ) {
					return (int) $id;
				}
			}
			return 0;
		}

		private function api_get( $endpoint, $query_args, $settings ) {
			$source_url = isset( $settings['source_url'] ) ? untrailingslashit( $settings['source_url'] ) : '';
			$key        = isset( $settings['consumer_key'] ) ? trim( $settings['consumer_key'] ) : '';
			$secret     = isset( $settings['consumer_secret'] ) ? trim( $settings['consumer_secret'] ) : '';

			if ( empty( $source_url ) || empty( $key ) || empty( $secret ) ) {
				return new WP_Error( 'missing_credentials', 'Please enter source URL, consumer key, and consumer secret.' );
			}

			$url = add_query_arg(
				array_merge(
					$query_args,
					array(
						'consumer_key'    => $key,
						'consumer_secret' => $secret,
					)
				),
				$source_url . '/wp-json/wc/v3/' . ltrim( $endpoint, '/' )
			);

			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 60,
					'headers' => array( 'Accept' => 'application/json' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'api_error', 'API request failed with status ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
			}

			return $response;
		}

		private function redirect_with_error( $message ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'          => self::MENU_SLUG,
						'error_message' => rawurlencode( $message ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	new SRK_WC_Live_To_Dev_Importer_V3();
}
