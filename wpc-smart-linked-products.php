<?php
/*
Plugin Name: WPC Smart Linked Products - Upsells & Cross-sells for WooCommerce
Plugin URI: https://wpclever.net/
Description: WPC Smart Linked Products plugin simplifies managing related, upsells, and cross-sells products in bulk with custom rules and mixed combinations.
Version: 1.3.3
Author: WPClever
Author URI: https://wpclever.net
Text Domain: wpc-smart-linked-products
Domain Path: /languages/
Requires Plugins: woocommerce
Requires at least: 4.0
Tested up to: 6.6
WC requires at least: 3.0
WC tested up to: 9.1
*/

defined( 'ABSPATH' ) || exit;

! defined( 'WPCSL_VERSION' ) && define( 'WPCSL_VERSION', '1.3.3' );
! defined( 'WPCSL_LITE' ) && define( 'WPCSL_LITE', __FILE__ );
! defined( 'WPCSL_FILE' ) && define( 'WPCSL_FILE', __FILE__ );
! defined( 'WPCSL_URI' ) && define( 'WPCSL_URI', plugin_dir_url( __FILE__ ) );
! defined( 'WPCSL_DIR' ) && define( 'WPCSL_DIR', plugin_dir_path( __FILE__ ) );
! defined( 'WPCSL_SUPPORT' ) && define( 'WPCSL_SUPPORT', 'https://wpclever.net/support?utm_source=support&utm_medium=wpcsl&utm_campaign=wporg' );
! defined( 'WPCSL_REVIEWS' ) && define( 'WPCSL_REVIEWS', 'https://wordpress.org/support/plugin/wpc-smart-linked-products/reviews/?filter=5' );
! defined( 'WPCSL_CHANGELOG' ) && define( 'WPCSL_CHANGELOG', 'https://wordpress.org/plugins/wpc-smart-linked-products/#developers' );
! defined( 'WPCSL_DISCUSSION' ) && define( 'WPCSL_DISCUSSION', 'https://wordpress.org/support/plugin/wpc-smart-linked-products' );
! defined( 'WPC_URI' ) && define( 'WPC_URI', WPCSL_URI );

include 'includes/dashboard/wpc-dashboard.php';
include 'includes/kit/wpc-kit.php';
include 'includes/hpos.php';

if ( ! function_exists( 'wpcsl_init' ) ) {
	add_action( 'plugins_loaded', 'wpcsl_init', 11 );

	function wpcsl_init() {
		// load text-domain
		load_plugin_textdomain( 'wpc-smart-linked-products', false, basename( __DIR__ ) . '/languages/' );

		if ( ! function_exists( 'WC' ) || ! version_compare( WC()->version, '3.0', '>=' ) ) {
			add_action( 'admin_notices', 'wpcsl_notice_wc' );

			return null;
		}

		if ( ! class_exists( 'WPCleverWpcsl' ) && class_exists( 'WC_Product' ) ) {
			class WPCleverWpcsl {
				protected static $settings = [];
				public static $us_rules = [];
				public static $cs_rules = [];
				protected static $instance = null;

				public static function instance() {
					if ( is_null( self::$instance ) ) {
						self::$instance = new self();
					}

					return self::$instance;
				}

				function __construct() {
					self::$settings = (array) get_option( 'wpcsl_settings', [] );
					self::$us_rules = (array) get_option( 'wpcsl_us', [] );
					self::$cs_rules = (array) get_option( 'wpcsl_cs', [] );

					// Settings
					add_action( 'admin_init', [ $this, 'register_settings' ] );
					add_action( 'admin_menu', [ $this, 'admin_menu' ] );

					// Enqueue backend scripts
					add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
					add_action( 'woocommerce_product_options_related', [ $this, 'product_options_related' ] );

					// Add settings link
					add_filter( 'plugin_action_links', [ $this, 'action_links' ], 10, 2 );
					add_filter( 'plugin_row_meta', [ $this, 'row_meta' ], 10, 2 );

					// AJAX
					add_action( 'wp_ajax_wpcsl_add_rule', [ $this, 'ajax_add_rule' ] );
					add_action( 'wp_ajax_wpcsl_search_term', [ $this, 'ajax_search_term' ] );
					add_action( 'wp_ajax_wpcsl_import_export', [ $this, 'ajax_import_export' ] );
					add_action( 'wp_ajax_wpcsl_import_export_save', [ $this, 'ajax_import_export_save' ] );

					// Upsells & Cross-sells
					add_filter( 'woocommerce_product_get_upsell_ids', [ $this, 'upsell_ids' ], 999, 2 );
					add_filter( 'woocommerce_product_variation_get_upsell_ids', [ $this, 'upsell_ids' ], 999, 2 );
					add_filter( 'woocommerce_product_get_cross_sell_ids', [ $this, 'cross_sell_ids' ], 999, 2 );
					add_filter( 'woocommerce_product_variation_get_cross_sell_ids', [
						$this,
						'cross_sell_ids'
					], 999, 2 );

					// Exclude Unpurchasable
					if ( self::get_setting( 'exclude_unpurchasable', 'no' ) === 'yes' ) {
						add_filter( 'wpcsl_get_products', [ $this, 'exclude_unpurchasable' ] );
					}
				}

				function upsell_ids( $ids, $product ) {
					if ( ! empty( $ids ) ) {
						return $ids;
					}

					if ( ! empty( self::$us_rules ) ) {
						foreach ( self::$us_rules as $rule ) {
							if ( self::check_apply( $product, $rule ) ) {
								return apply_filters( 'wpcsl_get_products', self::get_products( $rule, $product ), $rule, $product, 'upsell' );
							}
						}
					}

					return $ids;
				}

				function cross_sell_ids( $ids, $product ) {
					if ( ! empty( $ids ) ) {
						return $ids;
					}

					if ( is_a( $product, 'WC_Product_Variation' ) ) {
						$product = wc_get_product( $product->get_parent_id() );
					}

					if ( ! empty( self::$cs_rules ) ) {
						foreach ( self::$cs_rules as $rule ) {
							if ( self::check_apply( $product, $rule ) ) {
								return apply_filters( 'wpcsl_get_products', self::get_products( $rule, $product ), $rule, $product, 'cross_sell' );
							}
						}
					}

					return $ids;
				}

				function get_products( $rule, $product = null ) {
					if ( ! empty( $rule['get'] ) ) {
						if ( is_a( $product, 'WC_Product' ) ) {
							$product_id = $product->get_id();
						} elseif ( is_int( $product ) ) {
							$product_id = absint( $product );
						} else {
							$product_id = 0;
						}

						$limit   = absint( $rule['get_limit'] ?? 3 );
						$orderby = $rule['get_orderby'] ?? 'default';
						$order   = $rule['get_order'] ?? 'default';

						switch ( $rule['get'] ) {
							case 'all':
								return wc_get_products( [
									'status'  => 'publish',
									'limit'   => $limit,
									'orderby' => $orderby,
									'order'   => $order,
									'exclude' => [ $product_id ],
									'return'  => 'ids',
								] );
							case 'products':
								if ( ! empty( $rule['get_products'] ) && is_array( $rule['get_products'] ) ) {
									return array_diff( $rule['get_products'], [ $product_id ] );
								} else {
									return [];
								}
							case 'combination':
								if ( ! empty( $rule['get_combination'] ) && is_array( $rule['get_combination'] ) ) {
									$tax_query  = [];
									$meta_query = [];
									$terms_arr  = [];

									foreach ( $rule['get_combination'] as $combination ) {
										// term
										if ( ! empty( $combination['apply'] ) && ! empty( $combination['compare'] ) && ! empty( $combination['terms'] ) && is_array( $combination['terms'] ) ) {
											$tax_query[] = [
												'taxonomy' => $combination['apply'],
												'field'    => 'slug',
												'terms'    => $combination['terms'],
												'operator' => $combination['compare'] === 'is' ? 'IN' : 'NOT IN'
											];
										}

										// has same taxonomy
										if ( ! empty( $combination['apply'] ) && $combination['apply'] === 'same' && ! empty( $combination['same'] ) ) {
											$taxonomy = $combination['same'];

											if ( empty( $terms_arr[ $taxonomy ] ) ) {
												$terms = get_the_terms( $product_id, $taxonomy );

												if ( ! empty( $terms ) && is_array( $terms ) ) {
													foreach ( $terms as $term ) {
														$terms_arr[ $taxonomy ][] = $term->slug;
													}
												}
											}

											if ( ! empty( $terms_arr[ $taxonomy ] ) ) {
												$tax_query[] = [
													'taxonomy' => $taxonomy,
													'field'    => 'slug',
													'terms'    => $terms_arr[ $taxonomy ],
													'operator' => 'IN'
												];
											}
										}

										// price
										if ( ! empty( $combination['apply'] ) && $combination['apply'] === 'price' && ! empty( $combination['number_compare'] ) && isset( $combination['number_value'] ) && $combination['number_value'] !== '' ) {
											switch ( $combination['number_compare'] ) {
												case 'equal':
													$compare = '=';
													break;
												case 'not_equal':
													$compare = '!=';
													break;
												case 'greater':
													$compare = '>';
													break;
												case 'greater_equal':
													$compare = '>=';
													break;
												case 'less':
													$compare = '<';
													break;
												case 'less_equal':
													$compare = '<=';
													break;
												default:
													$compare = '=';
											}

											$meta_query[] = [
												'key'     => '_price',
												'value'   => (float) $combination['number_value'],
												'compare' => $compare,
												'type'    => 'NUMERIC'
											];
										}
									}

									$args = [
										'post_type'      => 'product',
										'post_status'    => 'publish',
										'posts_per_page' => $limit,
										'orderby'        => $orderby,
										'order'          => $order,
										'tax_query'      => $tax_query,
										'meta_query'     => $meta_query,
										'post__not_in'   => [ $product_id ],
										'fields'         => 'ids'
									];

									$ids = new WP_Query( $args );

									return $ids->posts;
								} else {
									return [];
								}
							default:
								if ( ! empty( $rule['get_terms'] ) && is_array( $rule['get_terms'] ) ) {
									$args = [
										'post_type'      => 'product',
										'post_status'    => 'publish',
										'posts_per_page' => $limit,
										'orderby'        => $orderby,
										'order'          => $order,
										'tax_query'      => [
											[
												'taxonomy' => $rule['get'],
												'field'    => 'slug',
												'terms'    => $rule['get_terms'],
											],
										],
										'post__not_in'   => [ $product_id ],
										'fields'         => 'ids'
									];

									$ids = new WP_Query( $args );

									return $ids->posts;
								} else {
									return [];
								}
						}
					}

					return [];
				}

				function exclude_unpurchasable( $ids ) {
					if ( is_array( $ids ) && ! empty( $ids ) ) {
						foreach ( $ids as $k => $id ) {
							$product = wc_get_product( $id );

							if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
								unset( $ids[ $k ] );
							}
						}
					}

					return $ids;
				}

				function register_settings() {
					// settings
					register_setting( 'wpcsl_settings', 'wpcsl_settings' );
					register_setting( 'wpcsl_cs', 'wpcsl_cs' );
					register_setting( 'wpcsl_us', 'wpcsl_us' );
					register_setting( 'wpcsl_rl', 'wpcsl_rl' );
				}

				function admin_enqueue_scripts() {
					wp_enqueue_style( 'hint', WPCSL_URI . 'assets/css/hint.css' );
					wp_enqueue_style( 'wpcsl-backend', WPCSL_URI . 'assets/css/backend.css', [ 'woocommerce_admin_styles' ], WPCSL_VERSION );
					wp_enqueue_script( 'wpcsl-backend', WPCSL_URI . 'assets/js/backend.js', [
						'jquery',
						'jquery-ui-sortable',
						'jquery-ui-dialog',
						'wc-enhanced-select',
						'selectWoo',
					], WPCSL_VERSION, true );
					wp_localize_script( 'wpcsl-backend', 'wpcsl_vars', [
						'wpcsl_nonce' => wp_create_nonce( 'wpcsl_nonce' )
					] );
				}

				function product_options_related() {
					echo '<p class="form-field"><label>Smart Linked Products</label><span>Click <a href="' . esc_url( admin_url( 'admin.php?page=wpclever-wpcsl-us' ) ) . '" target="_blank">here</a> to configure upsells/cross-sells products in bulk with many conditions.</span></p>';
				}

				function admin_menu() {
					add_submenu_page( 'wpclever', esc_html__( 'WPC Smart Linked Products', 'wpc-smart-linked-products' ), esc_html__( 'Smart Upsells', 'wpc-smart-linked-products' ), 'manage_options', 'wpclever-wpcsl-us', [
						$this,
						'admin_menu_content'
					] );
					add_submenu_page( 'wpclever', esc_html__( 'WPC Smart Linked Products', 'wpc-smart-linked-products' ), esc_html__( 'Smart Cross-sells', 'wpc-smart-linked-products' ), 'manage_options', 'wpclever-wpcsl-cs', [
						$this,
						'admin_menu_content'
					] );
				}

				function admin_menu_content() {
					$active_page = sanitize_key( $_GET['page'] ?? 'wpclever-wpcsl-us' );
					$active_tab  = sanitize_key( $_GET['tab'] ?? '' );
					?>
                    <div class="wpclever_settings_page wrap">
                        <h1 class="wpclever_settings_page_title"><?php echo esc_html__( 'WPC Smart Linked Products', 'wpc-smart-linked-products' ) . ' ' . esc_html( WPCSL_VERSION ) . ' ' . ( defined( 'WPCSL_PREMIUM' ) ? '<span class="premium" style="display: none">' . esc_html__( 'Premium', 'wpc-smart-linked-products' ) . '</span>' : '' ); ?></h1>
                        <div class="wpclever_settings_page_desc about-text">
                            <p>
								<?php printf( /* translators: stars */ esc_html__( 'Thank you for using our plugin! If you are satisfied, please reward it a full five-star %s rating.', 'wpc-smart-linked-products' ), '<span style="color:#ffb900">&#9733;&#9733;&#9733;&#9733;&#9733;</span>' ); ?>
                                <br/>
                                <a href="<?php echo esc_url( WPCSL_REVIEWS ); ?>" target="_blank"><?php esc_html_e( 'Reviews', 'wpc-smart-linked-products' ); ?></a> |
                                <a href="<?php echo esc_url( WPCSL_CHANGELOG ); ?>" target="_blank"><?php esc_html_e( 'Changelog', 'wpc-smart-linked-products' ); ?></a> |
                                <a href="<?php echo esc_url( WPCSL_DISCUSSION ); ?>" target="_blank"><?php esc_html_e( 'Discussion', 'wpc-smart-linked-products' ); ?></a>
                            </p>
                        </div>
						<?php if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) { ?>
                            <div class="notice notice-success is-dismissible">
                                <p><?php esc_html_e( 'Settings updated.', 'wpc-smart-linked-products' ); ?></p>
                            </div>
						<?php } ?>
                        <div class="wpclever_settings_page_nav">
                            <h2 class="nav-tab-wrapper">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-wpcsl-us&tab=settings' ) ); ?>" class="<?php echo esc_attr( $active_tab === 'settings' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>">
									<?php esc_html_e( 'Settings', 'wpc-smart-linked-products' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-wpcsl-us' ) ); ?>" class="<?php echo esc_attr( $active_page === 'wpclever-wpcsl-us' && $active_tab !== 'premium' && $active_tab !== 'settings' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>">
									<?php esc_html_e( 'Smart Upsells', 'wpc-smart-linked-products' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-wpcsl-cs' ) ); ?>" class="<?php echo esc_attr( $active_page === 'wpclever-wpcsl-cs' && $active_tab !== 'premium' && $active_tab !== 'settings' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>">
									<?php esc_html_e( 'Smart Cross-sells', 'wpc-smart-linked-products' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-wpcsl-us&tab=premium' ) ); ?>" class="<?php echo esc_attr( $active_tab === 'premium' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>" style="color: #c9356e">
									<?php esc_html_e( 'Premium Version', 'wpc-smart-linked-products' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-kit' ) ); ?>" class="nav-tab">
									<?php esc_html_e( 'Essential Kit', 'wpc-smart-linked-products' ); ?>
                                </a>
                            </h2>
                        </div>
                        <div class="wpclever_settings_page_content">
							<?php
							if ( $active_tab === 'settings' ) {
								$exclude_unpurchasable = self::get_setting( 'exclude_unpurchasable', 'no' );
								?>
                                <form method="post" action="options.php">
                                    <table class="form-table">
                                        <tr class="heading">
                                            <th colspan="2">
												<?php esc_html_e( 'General', 'wpc-smart-linked-products' ); ?>
                                            </th>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Exclude unpurchasable', 'wpc-smart-linked-products' ); ?></th>
                                            <td>
                                                <select name="wpcsl_settings[exclude_unpurchasable]">
                                                    <option value="yes" <?php selected( $exclude_unpurchasable, 'yes' ); ?>><?php esc_html_e( 'Yes', 'wpc-smart-linked-products' ); ?></option>
                                                    <option value="no" <?php selected( $exclude_unpurchasable, 'no' ); ?>><?php esc_html_e( 'No', 'wpc-smart-linked-products' ); ?></option>
                                                </select>
                                                <span class="description"><?php esc_html_e( 'Exclude unpurchasable products from upsells/cross-sells.', 'wpc-smart-linked-products' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr class="submit">
                                            <th colspan="2">
												<?php settings_fields( 'wpcsl_settings' ); ?><?php submit_button(); ?>
                                            </th>
                                        </tr>
                                    </table>
                                </form>
								<?php
							} else if ( $active_tab === 'premium' ) {
								?>
                                <div class="wpclever_settings_page_content_text">
                                    <p>Get the Premium Version just $29!
                                        <a href="https://wpclever.net/downloads/smart-linked-products?utm_source=pro&utm_medium=wpcsl&utm_campaign=wporg" target="_blank">https://wpclever.net/downloads/smart-linked-products</a>
                                    </p>
                                    <p><strong>Extra features for Premium Version:</strong></p>
                                    <ul style="margin-bottom: 0">
                                        <li>- Use combined conditions for Smart Upsells/Cross-sells.</li>
                                        <li>- Get the lifetime update & premium support.</li>
                                    </ul>
                                </div>
								<?php
							} else {
								if ( $active_page === 'wpclever-wpcsl-us' ) {
									self::rules( 'wpcsl_us', self::$us_rules );
								} elseif ( $active_page === 'wpclever-wpcsl-cs' ) {
									self::rules( 'wpcsl_cs', self::$cs_rules );
								}
							}
							?>
                        </div><!-- /.wpclever_settings_page_content -->
                        <div class="wpclever_settings_page_suggestion">
                            <div class="wpclever_settings_page_suggestion_label">
                                <span class="dashicons dashicons-yes-alt"></span> Suggestion
                            </div>
                            <div class="wpclever_settings_page_suggestion_content">
                                <div>
                                    To display custom engaging real-time messages on any wished positions, please install
                                    <a href="https://wordpress.org/plugins/wpc-smart-messages/" target="_blank">WPC Smart Messages</a> plugin. It's free!
                                </div>
                                <div>
                                    Wanna save your precious time working on variations? Try our brand-new free plugin
                                    <a href="https://wordpress.org/plugins/wpc-variation-bulk-editor/" target="_blank">WPC Variation Bulk Editor</a> and
                                    <a href="https://wordpress.org/plugins/wpc-variation-duplicator/" target="_blank">WPC Variation Duplicator</a>.
                                </div>
                            </div>
                        </div>
                    </div>
					<?php
				}

				function rules( $name = 'wpcsl_us', $rules = [] ) {
					?>
                    <form method="post" action="options.php">
                        <table class="form-table">
                            <tr>
                                <td>
									<?php esc_html_e( 'Our plugin checks rules from the top down the list. When there are products that satisfy more than 1 rule, the first rule on top will be prioritized. Please make sure you put the rules in the order of the most to the least prioritized.', 'wpc-smart-linked-products' ); ?>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="wpcsl_rules">
										<?php
										if ( is_array( $rules ) && ( count( $rules ) > 0 ) ) {
											foreach ( $rules as $key => $rule ) {
												self::rule( $key, $name, $rule, false );
											}
										} else {
											self::rule( '', $name, [], true );
										}
										?>
                                    </div>
                                    <div class="wpcsl_add_rule">
                                        <div>
                                            <a href="#" class="wpcsl_new_rule button" data-name="<?php echo esc_attr( $name ); ?>">
												<?php esc_html_e( '+ Add rule', 'wpc-smart-linked-products' ); ?>
                                            </a> <a href="#" class="wpcsl_expand_all">
												<?php esc_html_e( 'Expand All', 'wpc-smart-linked-products' ); ?>
                                            </a> <a href="#" class="wpcsl_collapse_all">
												<?php esc_html_e( 'Collapse All', 'wpc-smart-linked-products' ); ?>
                                            </a>
                                        </div>
                                        <div>
                                            <a href="#" class="wpcsl_import_export hint--left" aria-label="<?php esc_attr_e( 'Remember to save current rules before exporting to get the latest version.', 'wpc-smart-linked-products' ); ?>" data-name="<?php echo esc_attr( $name ); ?>" style="color: #999999"><?php esc_html_e( 'Import/Export', 'wpc-smart-linked-products' ); ?></a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr class="submit">
                                <th colspan="2">
									<?php settings_fields( $name ); ?><?php submit_button(); ?>
                                </th>
                            </tr>
                        </table>
                    </form>
					<?php
				}

				function ajax_import_export() {
					if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wpcsl_nonce' ) ) {
						die( 'Permissions check failed!' );
					}

					$rules = [];
					$name  = ! empty( $_POST['name'] ) ? sanitize_key( $_POST['name'] ) : 'wpcsl_us';

					if ( $name === 'wpcsl_us' ) {
						$rules = self::$us_rules;
					}

					if ( $name === 'wpcsl_cs' ) {
						$rules = self::$cs_rules;
					}

					echo '<textarea class="wpcsl_import_export_data" style="width: 100%; height: 200px">' . ( ! empty( $rules ) ? json_encode( $rules ) : '' ) . '</textarea>';
					echo '<div style="display: flex; align-items: center; margin-top: 10px;"><button class="button button-primary wpcsl_import_export_save" data-name="' . esc_attr( $name ) . '">' . esc_html__( 'Update', 'wpc-smart-linked-products' ) . '</button>';
					echo '<span style="color: #ff4f3b; font-size: 10px; margin-left: 10px;">' . esc_html__( '* All current rules will be replaced after pressing Update!', 'wpc-smart-linked-products' ) . '</span>';
					echo '</div>';

					wp_die();
				}

				function ajax_import_export_save() {
					if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wpcsl_nonce' ) ) {
						die( 'Permissions check failed!' );
					}

					$rules = sanitize_textarea_field( trim( $_POST['rules'] ) );
					$name  = ! empty( $_POST['name'] ) ? sanitize_key( $_POST['name'] ) : 'wpcsl_us';

					if ( ! empty( $rules ) ) {
						$rules = json_decode( stripcslashes( $rules ), true );

						if ( $rules !== null ) {
							update_option( $name, $rules );
						}

						echo 'Done!';
					}

					wp_die();
				}

				function rule( $key = '', $name = 'wpcsl_us', $rule = [], $active = false ) {
					$rule = array_merge( [
						'apply'             => 'all',
						'apply_products'    => [],
						'apply_terms'       => [],
						'apply_combination' => [],
						'get'               => 'all',
						'get_products'      => [],
						'get_terms'         => [],
						'get_combination'   => [],
						'get_limit'         => 3,
						'get_orderby'       => 'default',
						'get_order'         => 'default',
						'name'              => '',
					], (array) $rule );

					if ( empty( $key ) || is_numeric( $key ) ) {
						$key = self::generate_key();
					}

					$apply             = $rule['apply'] ?? 'all';
					$apply_products    = (array) ( $rule['apply_products'] ?? [] );
					$apply_terms       = (array) ( $rule['apply_terms'] ?? [] );
					$apply_combination = (array) ( $rule['apply_combination'] ?? [] );
					$get               = $rule['get'] ?? 'all';
					$get_products      = (array) ( $rule['get_products'] ?? [] );
					$get_terms         = (array) ( $rule['get_terms'] ?? [] );
					$get_combination   = (array) ( $rule['get_combination'] ?? [] );
					$get_limit         = absint( $rule['get_limit'] ?? 3 );
					$get_orderby       = $rule['get_orderby'] ?? 'default';
					$get_order         = $rule['get_order'] ?? 'default';
					$rule_name         = $rule['name'] ?? '';
					?>
                    <div class="<?php echo esc_attr( $active ? 'wpcsl_rule active' : 'wpcsl_rule' ); ?>" data-key="<?php echo esc_attr( $key ); ?>">
                        <div class="wpcsl_rule_heading">
                            <span class="wpcsl_rule_move"></span>
                            <span class="wpcsl_rule_label"><span class="wpcsl_rule_name"><?php echo esc_html( $rule_name ); ?></span> <span class="wpcsl_rule_apply_get"><?php echo esc_html( $apply . ' | ' . $get ); ?></span></span>
                            <a href="#" class="wpcsl_rule_duplicate" data-name="<?php echo esc_attr( $name ); ?>"><?php esc_html_e( 'duplicate', 'wpc-smart-linked-products' ); ?></a>
                            <a href="#" class="wpcsl_rule_remove"><?php esc_html_e( 'remove', 'wpc-smart-linked-products' ); ?></a>
                        </div>
                        <div class="wpcsl_rule_content">
                            <div class="wpcsl_tr">
                                <div class="wpcsl_th">
									<?php esc_html_e( 'Name', 'wpc-smart-linked-products' ); ?>
                                </div>
                                <div class="wpcsl_td">
                                    <input type="text" class="regular-text wpcsl_rule_name_val" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>][name]" value="<?php echo esc_attr( $rule_name ); ?>"/>
                                    <span class="description"><?php esc_html_e( 'For management only.', 'wpc-smart-linked-products' ); ?></span>
                                </div>
                            </div>
                            <div class="wpcsl_tr">
                                <div class="wpcsl_th wpcsl_th_full">
									<?php esc_html_e( 'Add linked products to which?', 'wpc-smart-linked-products' ); ?>
                                </div>
                            </div>
							<?php self::source( $name, $key, $apply, $apply_products, $apply_terms, $apply_combination, 'apply' ); ?>
                            <div class="wpcsl_tr">
                                <div class="wpcsl_th wpcsl_th_full">
									<?php esc_html_e( 'Define applicable linked products:', 'wpc-smart-linked-products' ); ?>
                                </div>
                            </div>
							<?php self::source( $name, $key, $get, $get_products, $get_terms, $get_combination, 'get', $get_limit, $get_orderby, $get_order ); ?>
                        </div>
                    </div>
					<?php
				}

				function source( $name = 'wpcsl_us', $key = '', $apply = 'all', $products = [], $terms = [], $combination = [], $type = 'apply', $get_limit = null, $get_orderby = null, $get_order = null ) {
					?>
                    <div class="wpcsl_tr">
                        <div class="wpcsl_th"><?php esc_html_e( 'Source', 'wpc-smart-linked-products' ); ?></div>
                        <div class="wpcsl_td wpcsl_rule_td">
                            <select class="wpcsl_source_selector wpcsl_source_selector_<?php echo esc_attr( $type ); ?>" data-type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $type ); ?>]">
                                <option value="all"><?php esc_html_e( 'All products', 'wpc-smart-linked-products' ); ?></option>
                                <option value="products" <?php selected( $apply, 'products' ); ?>><?php esc_html_e( 'Products', 'wpc-smart-linked-products' ); ?></option>
                                <option value="combination" <?php selected( $apply, 'combination' ); ?> disabled><?php esc_html_e( 'Combined (Premium)', 'wpc-smart-linked-products' ); ?></option>
								<?php
								$taxonomies = get_object_taxonomies( 'product', 'objects' );

								foreach ( $taxonomies as $taxonomy ) {
									echo '<option value="' . esc_attr( $taxonomy->name ) . '" ' . ( $apply === $taxonomy->name ? 'selected' : '' ) . '>' . esc_html( $taxonomy->label ) . '</option>';
								}
								?>
                            </select>
							<?php if ( $type === 'get' ) { ?>
                                <span class="show_get hide_if_get_products">
										<span><?php esc_html_e( 'Limit', 'wpc-smart-linked-products' ); ?> <input type="number" min="1" max="50" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>][get_limit]" value="<?php echo esc_attr( $get_limit ); ?>"/></span>
										<span>
										<?php esc_html_e( 'Order by', 'wpc-smart-linked-products' ); ?> <select name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>][get_orderby]">
                                                        <option value="default" <?php selected( $get_orderby, 'default' ); ?>><?php esc_html_e( 'Default', 'wpc-smart-linked-products' ); ?></option>
                                                        <option value="none" <?php selected( $get_orderby, 'none' ); ?>><?php esc_html_e( 'None', 'wpc-smart-linked-products' ); ?></option>
                                                        <option value="ID" <?php selected( $get_orderby, 'ID' ); ?>><?php esc_html_e( 'ID', 'wpc-smart-linked-products' ); ?></option>
                                                        <option value="name" <?php selected( $get_orderby, 'name' ); ?>><?php esc_html_e( 'Name', 'wpc-smart-linked-products' ); ?></option>
                                                        <option value="type" <?php selected( $get_orderby, 'type' ); ?>><?php esc_html_e( 'Type', 'wpc-smart-linked-products' ); ?></option>
                                                        <option value="rand" <?php selected( $get_orderby, 'rand' ); ?>><?php esc_html_e( 'Rand', 'wpc-smart-linked-products' ); ?></option>
                                                        <option value="date" <?php selected( $get_orderby, 'date' ); ?>><?php esc_html_e( 'Date', 'wpc-smart-linked-products' ); ?></option>
                                                        <option value="price" <?php selected( $get_orderby, 'price' ); ?>><?php esc_html_e( 'Price', 'wpc-smart-linked-products' ); ?></option>
                                                        <option value="modified" <?php selected( $get_orderby, 'modified' ); ?>><?php esc_html_e( 'Modified', 'wpc-smart-linked-products' ); ?></option>
                                                    </select>
									</span>
										<span><?php esc_html_e( 'Order', 'wpc-smart-linked-products' ); ?> <select name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>][get_order]">
                                                        <option value="default" <?php selected( $get_order, 'default' ); ?>><?php esc_html_e( 'Default', 'wpc-smart-linked-products' ); ?></option>
                                                        <option value="DESC" <?php selected( $get_order, 'DESC' ); ?>><?php esc_html_e( 'DESC', 'wpc-smart-linked-products' ); ?></option>
                                                        <option value="ASC" <?php selected( $get_order, 'ASC' ); ?>><?php esc_html_e( 'ASC', 'wpc-smart-linked-products' ); ?></option>
                                                        </select></span>
									</span>
							<?php } ?>
                        </div>
                    </div>
                    <div class="wpcsl_tr hide_<?php echo esc_attr( $type ); ?> show_if_<?php echo esc_attr( $type ); ?>_products">
                        <div class="wpcsl_th"><?php esc_html_e( 'Products', 'wpc-smart-linked-products' ); ?></div>
                        <div class="wpcsl_td wpcsl_rule_td">
                            <select class="wc-product-search wpcsl-product-search" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $type . '_products' ); ?>][]" multiple="multiple" data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'wpc-smart-linked-products' ); ?>" data-action="woocommerce_json_search_products_and_variations">
								<?php
								if ( ! empty( $products ) ) {
									foreach ( $products as $_product_id ) {
										if ( $_product = wc_get_product( $_product_id ) ) {
											echo '<option value="' . esc_attr( $_product_id ) . '" selected>' . wp_kses_post( $_product->get_formatted_name() ) . '</option>';
										}
									}
								}
								?>
                            </select>
                        </div>
                    </div>
                    <div class="wpcsl_tr show_<?php echo esc_attr( $type ); ?> hide_if_<?php echo esc_attr( $type ); ?>_all hide_if_<?php echo esc_attr( $type ); ?>_products hide_if_<?php echo esc_attr( $type ); ?>_combination">
                        <div class="wpcsl_th wpcsl_<?php echo esc_attr( $type ); ?>_text"><?php esc_html_e( 'Terms', 'wpc-smart-linked-products' ); ?></div>
                        <div class="wpcsl_td wpcsl_rule_td">
                            <select class="wpcsl_terms" data-type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $type . '_terms' ); ?>][]" multiple="multiple" data-<?php echo esc_attr( $apply ); ?>="<?php echo esc_attr( implode( ',', $terms ) ); ?>">
								<?php
								if ( ! empty( $terms ) ) {
									foreach ( $terms as $at ) {
										if ( $term = get_term_by( 'slug', $at, $apply ) ) {
											echo '<option value="' . esc_attr( $at ) . '" selected>' . esc_html( $term->name ) . '</option>';
										}
									}
								}
								?>
                            </select>
                        </div>
                    </div>
					<?php
				}

				function ajax_add_rule() {
					if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'wpcsl_nonce' ) ) {
						die( 'Permissions check failed!' );
					}

					$rule      = [];
					$name      = sanitize_key( $_POST['name'] ?? 'wpcsl_us' );
					$rule_data = $_POST['rule_data'] ?? '';

					if ( ! empty( $rule_data ) ) {
						$form_rule = [];
						parse_str( $rule_data, $form_rule );

						if ( isset( $form_rule[ $name ] ) && is_array( $form_rule[ $name ] ) ) {
							$rule = reset( $form_rule[ $name ] );
						}
					}

					self::rule( '', $name, $rule, true );
					wp_die();
				}

				function ajax_search_term() {
					$return = [];

					$args = [
						'taxonomy'   => sanitize_text_field( $_REQUEST['taxonomy'] ),
						'orderby'    => 'id',
						'order'      => 'ASC',
						'hide_empty' => false,
						'fields'     => 'all',
						'name__like' => sanitize_text_field( $_REQUEST['q'] ),
					];

					$terms = get_terms( $args );

					if ( count( $terms ) ) {
						foreach ( $terms as $term ) {
							$return[] = [ $term->slug, $term->name ];
						}
					}

					wp_send_json( $return );
				}

				public static function check_apply( $product, $rule ) {
					if ( is_a( $product, 'WC_Product' ) ) {
						$product_id = $product->get_id();
					} elseif ( is_int( $product ) ) {
						$product_id = $product;
					} else {
						$product_id = 0;
					}

					if ( ! $product_id || empty( $rule['apply'] ) ) {
						return false;
					}

					switch ( $rule['apply'] ) {
						case 'all':
							return true;
						case 'products':
							if ( ! empty( $rule['apply_products'] ) && is_array( $rule['apply_products'] ) ) {
								if ( in_array( $product_id, $rule['apply_products'] ) ) {
									return true;
								}
							}

							return false;
						case 'combination':
							if ( ! empty( $rule['apply_combination'] ) && is_array( $rule['apply_combination'] ) ) {
								$match_all = true;

								foreach ( $rule['apply_combination'] as $combination ) {
									$match = true;

									if ( ! empty( $combination['apply'] ) && ! empty( $combination['compare'] ) && ! empty( $combination['terms'] ) && is_array( $combination['terms'] ) ) {
										if ( ( $combination['apply'] === 'product_cat' || $combination['apply'] === 'product_tag' ) && ( $_product = wc_get_product( $product_id ) ) && is_a( $_product, 'WC_Product_Variation' ) ) {
											$parent_id = $_product->get_parent_id();

											if ( ( $combination['compare'] === 'is' ) && ! has_term( $combination['terms'], $combination['apply'], $parent_id ) ) {
												$match = false;
											}

											if ( ( $combination['compare'] === 'is_not' ) && has_term( $combination['terms'], $combination['apply'], $parent_id ) ) {
												$match = false;
											}
										} else {
											if ( ( $combination['compare'] === 'is' ) && ! has_term( $combination['terms'], $combination['apply'], $product_id ) ) {
												$match = false;
											}

											if ( ( $combination['compare'] === 'is_not' ) && has_term( $combination['terms'], $combination['apply'], $product_id ) ) {
												$match = false;
											}
										}
									}

									$match_all &= $match;
								}

								return $match_all;
							}

							return false;
						default:
							if ( ! empty( $rule['apply_terms'] ) && is_array( $rule['apply_terms'] ) ) {
								if ( ( $rule['apply'] === 'product_cat' || $rule['apply'] === 'product_tag' ) && ( $_product = wc_get_product( $product_id ) ) && is_a( $_product, 'WC_Product_Variation' ) ) {
									$product_id = $_product->get_parent_id();
								}

								if ( has_term( $rule['apply_terms'], $rule['apply'], $product_id ) ) {
									return true;
								}
							}

							return false;
					}
				}

				function action_links( $links, $file ) {
					static $plugin;

					if ( ! isset( $plugin ) ) {
						$plugin = plugin_basename( __FILE__ );
					}

					if ( $plugin === $file ) {
						$st                   = '<a href="' . esc_url( admin_url( 'admin.php?page=wpclever-wpcsl-us&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'wpc-smart-linked-products' ) . '</a>';
						$us                   = '<a href="' . esc_url( admin_url( 'admin.php?page=wpclever-wpcsl-us' ) ) . '">' . esc_html__( 'Smart Upsells', 'wpc-smart-linked-products' ) . '</a>';
						$cs                   = '<a href="' . esc_url( admin_url( 'admin.php?page=wpclever-wpcsl-cs' ) ) . '">' . esc_html__( 'Smart Cross-sells', 'wpc-smart-linked-products' ) . '</a>';
						$links['wpc-premium'] = '<a href="' . esc_url( admin_url( 'admin.php?page=wpclever-wpcsl-us&tab=premium' ) ) . '">' . esc_html__( 'Premium Version', 'wpc-smart-linked-products' ) . '</a>';
						array_unshift( $links, $st, $us, $cs );
					}

					return (array) $links;
				}

				function row_meta( $links, $file ) {
					static $plugin;

					if ( ! isset( $plugin ) ) {
						$plugin = plugin_basename( __FILE__ );
					}

					if ( $plugin === $file ) {
						$row_meta = [
							'support' => '<a href="' . esc_url( WPCSL_DISCUSSION ) . '" target="_blank">' . esc_html__( 'Community support', 'wpc-smart-linked-products' ) . '</a>',
						];

						return array_merge( $links, $row_meta );
					}

					return (array) $links;
				}

				public static function get_settings() {
					return apply_filters( 'wpcsl_get_settings', self::$settings );
				}

				public static function get_setting( $name, $default = false ) {
					if ( ! empty( self::$settings ) && isset( self::$settings[ $name ] ) ) {
						$setting = self::$settings[ $name ];
					} else {
						$setting = get_option( 'wpcsl_' . $name, $default );
					}

					return apply_filters( 'wpcsl_get_setting', $setting, $name, $default );
				}

				public static function generate_key() {
					$key         = '';
					$key_str     = apply_filters( 'wpcsl_key_characters', 'abcdefghijklmnopqrstuvwxyz0123456789' );
					$key_str_len = strlen( $key_str );

					for ( $i = 0; $i < apply_filters( 'wpcsl_key_length', 4 ); $i ++ ) {
						$key .= $key_str[ random_int( 0, $key_str_len - 1 ) ];
					}

					if ( is_numeric( $key ) ) {
						$key = self::generate_key();
					}

					return apply_filters( 'wpcsl_generate_key', $key );
				}
			}

			return WPCleverWpcsl::instance();
		}

		return null;
	}
}

if ( ! function_exists( 'wpcsl_notice_wc' ) ) {
	function wpcsl_notice_wc() {
		?>
        <div class="error">
            <p><strong>WPC Smart Linked Products</strong> require WooCommerce version 3.0 or greater.</p>
        </div>
		<?php
	}
}
