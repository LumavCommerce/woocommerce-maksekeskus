<?php
/*
  Plugin Name: Woocommerce Maksekeskus
  Text Domain: wc_maksekeskus_domain
*/

global $mkDbVersion, $mkDbTable;
$mkDbVersion = '1.0';
$mkDbTable = 'mk_banklinks';

function mk_install() {
	global $wpdb;
	global $mkDbVersion, $mkDbTable;

	$tableName = $wpdb->prefix . $mkDbTable;
	
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $tableName (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		country char(2) NOT NULL,
		method varchar(25) NOT NULL,
		url varchar(250) NOT NULL,
		UNIQUE KEY id (id)
	) $charset;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );

	add_option( 'mk_db_version', $mkDbVersion );
}

register_activation_hook( __FILE__, 'mk_install' );

function woocommerce_payment_maksekeskus_init() {
	
	if (!class_exists('Maksekeskus_Api')) {
		require_once('includes/Api.php');
	}
	
	class woocommerce_maksekeskus extends WC_Payment_Gateway {
		
		const MK_CANCELLED = 'CANCELLED';
		const MK_COMPLETED = 'COMPLETED';
		
		const MK_PART_REFUNDED = 'PART_REFUNDED';
        const MK_REFUNDED = 'REFUNDED';
		
		public $id = 'maksekeskus';
		public $version = '0.1';
		
		protected $_shop_id;
        protected $_api_key_secret;
        protected $_api_key_public;
        
        protected $_banklinks;
        protected $_banklinks_grouped;
		protected $_cards;
		
		protected $_api;
		
		public function __construct() {
			
			// Load the form fields.
			$this->init_form_fields();
			
			// Load the settings.
            $this->init_settings();
            
            $this->initBanklinks();
            $this->initCards();
            
            $this->title = $this->settings['ui_widget_title'];
            $this->description = $this->settings['description'];
            
            if($this->settings['api_type'] == 'live') {
            	
            	$this->_shop_id = $this->settings['shop_id'];
				$this->_api_key_secret = $this->settings['api_key_secret'];
				$this->_api_key_public = $this->settings['api_key_public'];
            	$this->_api = New Maksekeskus_Api($this->_shop_id, $this->_api_key_public, $this->_api_key_secret, false);
            	
            } elseif($this->settings['api_type'] == 'test') {
            	
            	$this->_shop_id = $this->settings['test_shop_id'];
				$this->_api_key_secret = $this->settings['test_key_secret'];
				$this->_api_key_public = $this->settings['test_key_public'];
            	$this->_api = New Maksekeskus_Api($this->_shop_id, $this->_api_key_public, $this->_api_key_secret, TRUE);
            }
            
            $this->supports = array(
				'products',
				'refunds',
			);
            
			add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
			
			add_filter('query_vars', array(&$this, 'maksekeskus_return_trigger'));
			add_action('template_redirect', array(&$this, 'maksekeskus_return_trigger_check'));
			
			add_action('woocommerce_receipt_' . $this->id, array(&$this, 'receipt_page'));
			
			add_action('woocommerce_admin_order_data_after_order_details', array(&$this, 'admin_order_page'), 10, 1);
			
			wp_enqueue_style('maksekeskus', plugins_url('/css/maksekeskus.css', __FILE__));
			
			if(is_admin()) {
				add_action( 'wp_ajax_mk_reload', array(&$this, 'mk_reload') );
			}
			
		}
		
		/**
		 * Initialise Gateway Settings Form Fields
		 */
		function init_form_fields() {

			$this->form_fields = array(
				'enabled' => array(
					'title' => __('Enable/Disable', 'wc_maksekeskus_domain'),
					'type' => 'checkbox',
					'label' => __('Enable Maksekeskus payment module', 'wc_maksekeskus_domain'),
					'default' => 'no'
				),
				'description' => array(
					'title' => __('Description', $this->_plugin_text_domain),
					'type' => 'textarea',
					'description' => __('This controls the description which the user sees during checkout.', $this->_plugin_text_domain),
					'default' => __("", $this->_plugin_text_domain)
				),
				'api_type' => array(
					'title' => __('Live', 'wc_maksekeskus_domain'),
					'type' => 'select',
					'default' => 'live',
					'description' => __('We have separate Test and Live environments. The Test Environment is a safe sandbox where you can explore and learn how our systems function without worrying about messing up your real account at MakeCommerce / Maksekeskus', 'wc_maksekeskus_domain'),
					'options' => array(
						'live' => __('Live', 'wc_maksekeskus_domain'),
						'test' => __('Test', 'wc_maksekeskus_domain'),
					)
				),
				'shop_id' => array(
					'title' => __('Shop ID', 'wc_maksekeskus_domain'),
					'type' => 'text',
					'description' => __('You get the ShopID and API keys from the Merchant Portal, see the link above', 'wc_maksekeskus_domain'),
					'default' => ''
				),
				'api_key_secret' => array(
					'title' => __('Shop Secret Key', 'wc_maksekeskus_domain'),
					'type' => 'text',
					'description' => sprintf(__('Maksekeskus provides you with %s.', 'wc_maksekeskus_domain'), __('API secret', 'wc_maksekeskus_domain')),
					'default' => ''
				),
				'api_key_public' => array(
					'title' => __('Shop Public Key', 'wc_maksekeskus_domain'),
					'type' => 'text',
					'description' => sprintf(__('Maksekeskus provides you with %s.', 'wc_maksekeskus_domain'), __('API public', 'wc_maksekeskus_domain')),
					'default' => ''
				),
				'test_shop_id' => array(
					'title' => __('Test Shop ID', 'wc_maksekeskus_domain'),
					'type' => 'text',
					'description' => __('You get the ShopID and API keys from the Merchant Portal, see the link above', 'wc_maksekeskus_domain'),
					'default' => ''
				),
				'test_key_secret' => array(
					'title' => __('Test Shop Secret Key', 'wc_maksekeskus_domain'),
					'type' => 'text',
					'description' => sprintf(__('Maksekeskus provides you with %s.', 'wc_maksekeskus_domain'), __('API secret', 'wc_maksekeskus_domain')),
					'default' => ''
				),
				'test_key_public' => array(
					'title' => __('Test Shop Public Key', 'wc_maksekeskus_domain'),
					'type' => 'text',
					'description' => sprintf(__('Maksekeskus provides you with %s.', 'wc_maksekeskus_domain'), __('API public', 'wc_maksekeskus_domain')),
					'default' => ''
				),
				'currency' => array(
					'title' => __('Accepted currency by this gateway', 'wc_maksekeskus_domain'),
					'type' => 'select',
					'description' => __('Other currencies will be converted to accepted currency', 'wc_maksekeskus_domain'),
					'options' => get_woocommerce_currencies(),
					'default' => 'EUR'
				),
				'disable_other_currency' => array(
					'title' => __('Disable this method, when order currency is not EUR', 'wc_maksekeskus_domain'),
					'type' => 'checkbox',
					'label' => __('Conversion will be attempted when this method is not disabled', 'wc_maksekeskus_domain'),
					'default' => 'yes'
				),
				'locale' => array(
					'title' => __('Preferred locale', 'wc_maksekeskus_domain'),
					'type' => 'text',
					'description' => __('RFC-2616 format locale. Like et,en,ru', 'wc_maksekeskus_domain'),
					'default' => 'et'
				),
				'cc_pass_cust_data' => array(
					'title' => __('Prefill Credit Card form with customer data', 'wc_maksekeskus_domain'),
					'type' => 'checkbox',
					'label' => __('It will pass user Name and e-mail address to the Credit Card dialog to make the form filling easier ', 'wc_maksekeskus_domain'),
					'default' => 'yes'
				),
				'cc_shop_name' => array(
					'title' => __('Shop name on credit card payment', 'wc_maksekeskus_domain'),
					'type' => 'text',
					'default' => ''
				),
				'shop_description' => array(
					'title' => __('Order description displayed under shop name', 'wc_maksekeskus_domain'),
					'description' => __('%s will be replaced with order increment id or with quote reserved order id or with quote id', 'wc_maksekeskus_domain'),
					'type' => 'text',
					'default' => '%s'
				),
				'return' => array(
					'title' => __('Return URL', 'wc_maksekeskus_domain'),
					'type' => 'maksekeskusreturn',
					'description' => __('Enter this URL to Maksekeskus database', 'wc_maksekeskus_domain'),
					'default' => plugins_url('/return.php', __FILE__),
					'custom_attributes' => array(
						'readonly' => 'readonly',
						'onfocus' => 'jQuery(this).select();'
					),
				),
				'ui_mode' => array(
					'title' => __('Display MK payment channels as', 'wc_maksekeskus_domain'),
					'type' => 'select',
					'default' => 'inline',
					'options' => array(
						'inline' => __('List', 'wc_maksekeskus_domain'),
						'widget' => __('Grouped to widget', 'wc_maksekeskus_domain'),
					)
				),
				'ui_inline_uselogo' => array(
					'title' => __('MK payment channels display style', 'wc_maksekeskus_domain'),
					'type' => 'select',
					'default' => 'logo',
					'options' => array(
						'logo' => __('Logo', 'wc_maksekeskus_domain'),
						'text_logo' => __('Text & logo', 'wc_maksekeskus_domain'),
						'text' => __('Text', 'wc_maksekeskus_domain'),
					)
				),
				'ui_chorder' => array(
					'title' => __('Define custom order of channels', 'wc_maksekeskus_domain'),
					'type' => 'text',
					'description' => 'If you want to change default order, put here comma separated list of channels. i,e, - seb,lhv,swedbank. see more on the module home page (link above)',
				),
				'ui_widget_title' => array(
					'title' => __('Title', 'wc_maksekeskus_domain'),
					'type' => 'text',
					'description' => __('This controls the title which the user sees during checkout.', 'wc_maksekeskus_domain'),
					'default' => __('Pay with bank-links or credit card', 'wc_maksekeskus_domain')
				),
				'ui_widget_groupcountries' => array(
					'title' => __('Group bank-links by countries', 'wc_maksekeskus_domain'),
					'type' => 'checkbox',
					'default' => 'no',
				),
				'ui_widget_groupcc' => array(
					'title' => __('Group credit card into separate widget', 'wc_maksekeskus_domain'),
					'type' => 'checkbox',
					'default' => 'no',
				),
				'ui_widget_groupcc_title' => array(
					'title' => __('Credit Card payments widget title', 'wc_maksekeskus_domain'),
					'type' => 'text',
					'default' => __('Pay with credit card', 'wc_maksekeskus_domain'),
				),
				'ui_widget_logosize' => array(
					'title' => __('Size of channel logos', 'wc_maksekeskus_domain'),
					'type' => 'select',
					'description' => __('Large logo is the original size, medium logo is 120px wide, small logo is 80px wide', 'wc_maksekeskus_domain'),
					'default' => 'medium',
					'class' => 'availability',
					'options' => array(
						'small' => __('Small', 'wc_maksekeskus_domain'),
						'medium' => __('Medium', 'wc_maksekeskus_domain'),
						'large' => __('Large', 'wc_maksekeskus_domain')
					)
				),
				'availability' => array(
					'title' => __('Method availability', 'wc_maksekeskus_domain'),
					'type' => 'select',
					'default' => 'specific',
					'class' => 'availability',
					'options' => array(
						'all' => __('All allowed countries', 'wc_maksekeskus_domain'),
						'specific' => __('Specific Countries', 'wc_maksekeskus_domain')
					)
				),
				'countries' => array(
					'title' => __('Specific Countries', 'wc_maksekeskus_domain'),
					'type' => 'multiselect',
					'class' => 'chosen_select',
					'css' => 'width: 450px;',
					'default' => array('EE', 'LV'),
					'options' => $this->_getWooCommerce()->countries->countries
				),
				'enable_log' => array(
					'title' => __('Log API requests', 'wc_maksekeskus_domain'),
					'type' => 'checkbox',
					'label' => __('Log API requests', 'wc_maksekeskus_domain'),
					'default' => 'no'
				),
				'reload_links' => array(
					'type' => 'mk_reload',
					'title' => __('Update payment methods', 'wc_maksekeskus_domain'),
					'description' => __('Update', 'wc_maksekeskus_domain'),
				),
			);
		}
		
		public function generate_mk_reload_html( $key, $data ) {
		
			$field    = $this->get_field_key( $key );
			$defaults = array(
				'title'             => '',
				'disabled'          => false,
				'class'             => '',
				'css'               => '',
				'placeholder'       => '',
				'type'              => 'text',
				'desc_tip'          => false,
				'description'       => '',
				'custom_attributes' => array()
			);
		
			$data = wp_parse_args( $data, $defaults );
		
			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label for="<?php echo esc_attr( $field ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
					<?php echo $this->get_tooltip_html( $data ); ?>
				</th>
				<td class="forminp">
					<fieldset>
						<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
						<input id="mk_reload" class="button <?php echo esc_attr( $data['class'] ); ?>" type="button" name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" value="<?php echo esc_attr( $data['description'] ); ?>" placeholder="<?php echo esc_attr( $data['placeholder'] ); ?>" <?php disabled( $data['disabled'], true ); ?> <?php echo $this->get_custom_attribute_html( $data ); ?> />
						<script type="text/javascript">
						jQuery('input#mk_reload').on('click', function() {
								init_mk_loading();
								jQuery.ajax({
									url: '<?php echo get_site_url(); ?>/wp-admin/admin-ajax.php',
									type: 'POST',
									data: 'action=mk_reload',
									success: function (output) {
										alert(output.data);
									},
									complete: function() { stop_mk_loading(); }
								});
						});
						
						function init_mk_loading() {
							jQuery('input#mk_reload').attr('disabled', 'disabled');
						}
						
						function stop_mk_loading() {
							jQuery('input#mk_reload').removeAttr('disabled');
						}
						</script>
					</fieldset>
				</td>
			</tr>
			<?php
		
			return ob_get_clean();
		}
		
		public function mk_reload() {
			if (defined('DOING_AJAX') && DOING_AJAX) {
				
				global $wpdb;
				global $mkDbTable;
				global $wp_version;
				
				$tableName = $wpdb->prefix . $mkDbTable;
				$wpdb->query('TRUNCATE TABLE '.$tableName);
				
				$request_params = array(
					'environment' => json_encode(array(
						'platform' => 'wordpress '.$wp_version,
						'module' => $this->id.' '.$this->version,
					)),
				);
				
				$methods = $this->_api->getShopConfig($request_params)->paymentMethods;
				if(isset($methods->banklinks)) {
					foreach($methods->banklinks as $method) {
						$wpdb->insert($tableName, array('type' => 'banklink', 'country' => $method->country, 'name' => $method->name, 'url' => $method->url));
					}
					$updated = true;
				}
				
				if(isset($methods->cards)) {
					foreach($methods->cards as $method) {
						$wpdb->insert($tableName, array('type' => 'card', 'name' => $method->name));
					}
					$updated = true;
				}
				
				if($updated) {
					wp_send_json(array('success' => 1, 'data' => __('Update successfully completed!', 'wc_maksekeskus_domain')));
					exit; 
				}
				
				wp_send_json(array('success' => 0, 'data' => __('There was an error with your update.\nPlease try again', 'wc_maksekeskus_domain')));
				exit; 
			}
			die();
		}
		
		/*public function mk_reload2() { //vana gateway
			if (defined('DOING_AJAX') && DOING_AJAX) {
				
				global $wpdb;
				global $mkDbTable;
				
				$tableName = $wpdb->prefix . $mkDbTable;
				$wpdb->query('TRUNCATE TABLE '.$tableName);
				
				$request_params = array(
					'currency' => 'EUR',
					//'country' => 'ee',
				);
				
				$methods = $this->_api->getPaymentMethods($request_params);
				if(isset($methods->banklinks)) {
					foreach($methods->banklinks as $method) {
						$wpdb->insert($tableName, array('country' => $method->country, 'name' => $method->name, 'url' => $method->url));
					}
					wp_send_json(array('success' => 1, 'data' => __('Update successfully completed!', 'wc_maksekeskus_domain')));
					exit; 
				}
				wp_send_json(array('success' => 0, 'data' => __('There was an error with your update.\nPlease try again', 'wc_maksekeskus_domain')));
				exit; 
			}
			die();
		}*/
		
		
		protected function _getWooCommerce() {
			global $woocommerce;
			return $woocommerce;
		}
		
		public function process_admin_options() {
			$this->validate_settings_fields();

			if (count($this->errors) > 0) {
				$this->display_errors();
				return false;
			} else {
				update_option($this->plugin_id . $this->id . '_settings', $this->sanitized_fields);
				return true;
			}
		}
		
		function is_valid_for_use() {
			return true;
		}
		
		public function is_available() {
			if ($this->settings['enabled'] == "yes") {
				return true;
			}
		}
		
		function payment_fields() {
			
			if($this->settings['ui_mode'] == 'inline') {
				
				?>
				<ul class="maksekeskus-picker">
				<?php foreach($this->_banklinks as $method): ?>
					<li class="maksekeskus-picker-method">
						<input type="radio" id="maksekeskus_method_picker_<?php echo $method->country.'_'.$method->name; ?>" name="PRESELECTED_METHOD_<?php echo $this->id; ?>" value="<?php echo $method->country.'_'.$method->name; ?>"/>
						<label for="maksekeskus_method_picker_<?php echo $method->country.'_'.$method->name; ?>">
							<span class="maksekeskus-method-title"><?php echo $this->getCountryName($method->country); if(in_array($this->settings['ui_inline_uselogo'], array('text', 'text_logo'))) { echo ' - '.ucfirst($method->name); } ?></span>
							<?php if(in_array($this->settings['ui_inline_uselogo'], array('logo', 'text_logo'))) : ?>
								<div><img class="size-<?php echo $this->settings['ui_widget_logosize']; ?>" src="<?php echo $this->getImageUrl($method->name); ?>" title="<?php echo ucfirst($method->name); ?>" /></div>
							<?php endif; ?>
						</label>
					</li>
				<?php endforeach; ?>
				<?php foreach($this->_cards as $method): ?>
					<li class="maksekeskus-picker-method">
						<input type="radio" id="maksekeskus_method_picker_<?php echo 'card_'.$method->name; ?>" name="PRESELECTED_METHOD_<?php echo $this->id; ?>" value="<?php echo 'card_'.$method->name; ?>"/>
						<label for="maksekeskus_method_picker_<?php echo 'card_'.$method->name; ?>">
							<span class="maksekeskus-method-title"><?php if(in_array($this->settings['ui_inline_uselogo'], array('text', 'text_logo'))) { echo ucfirst($method->name); } ?></span>
							<?php if(in_array($this->settings['ui_inline_uselogo'], array('logo', 'text_logo'))) : ?>
								<div><img class="size-<?php echo $this->settings['ui_widget_logosize']; ?>" src="<?php echo $this->getImageUrl($method->name); ?>" title="<?php echo ucfirst($method->name); ?>" /></div>
							<?php endif; ?>
						</label>
					</li>
				<?php endforeach; ?>
				</ul>
				<?php
				
			} else {
				?>
				<select id="<?php echo $this->id; ?>" name="PRESELECTED_METHOD_<?php echo $this->id; ?>">
					<option value=""></option>
				<?php foreach($this->_banklinks as $method): ?>
					<option value="<?php echo $method->country.'_'.$method->name; ?>"><?php echo strtoupper($method->country).' - '.ucfirst($method->name); ?></option>
				<?php endforeach; ?>
				<?php foreach($this->_cards as $method): ?>
					<option value="card_<?php echo $method->name; ?>"><?php echo ucfirst($method->name); ?></option>
				<?php endforeach; ?>
				</select>
				<ul class="maksekeskus-picker">
				<?php
				
				if($this->_banklinks) {
					$defaultCountry = $this->getDefaultCountry();
					?>
					<?php if($this->settings['ui_widget_groupcountries'] == 'no') : ?>
						<div class="maksekeskus_country_picker_countries">
							<?php foreach(array_keys($this->_banklinks_grouped) as $country): ?>
								<input style="display: none;" type="radio" id="maksekeskus_country_picker_<?php echo $country; ?>" name="maksekeskus_country_picker" value="<?php echo $country; ?>" <?php if($defaultCountry == $country) echo 'checked="checked" '; ?>/><label for="maksekeskus_country_picker_<?php echo $country; ?>" class="maksekeskus_country_picker_label" style="background-image: url(<?php echo plugins_url('/images/'.$country.'32.png', __FILE__); ?>);"></label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<?php foreach($this->_banklinks_grouped as $country => $methods): ?>
						<li class="maksekeskus-picker-country">
						<?php if($this->settings['ui_widget_groupcountries'] == 'yes') : ?>
							<input type="radio" id="maksekeskus_country_picker_<?php echo $country; ?>" name="maksekeskus_country_picker" value="<?php echo $country; ?>" <?php if($defaultCountry == $country) echo 'checked="checked" '; ?>/><label for="maksekeskus_country_picker_<?php echo $country; ?>"><img src="<?php echo plugins_url('/images/'.$country.'32.png', __FILE__); ?>" /></label>
						<?php endif; ?>
							<div class="maksekeskus_country_picker_methods" id="maksekeskus_country_picker_methods_<?php echo $country; ?>">
								<?php foreach($methods as $method): ?>
									<div class="maksekeskus-banklink-picker" banklink_id="<?php echo $method->country.'_'.$method->name; ?>">
										<img class="size-<?php echo $this->settings['ui_widget_logosize']; ?>" src="<?php echo $this->getImageUrl($method->name); ?>" title="<?php echo ucfirst($method->name); ?>" />
									</div>
								<?php endforeach; ?>
								<?php if($this->_cards && $this->settings['ui_widget_groupcc'] == 'no') : ?>
									<?php foreach($this->_cards as $method): ?>
										<div class="maksekeskus-banklink-picker" banklink_id="card_<?php echo $method->name; ?>">
											<img class="size-<?php echo $this->settings['ui_widget_logosize']; ?>" src="<?php echo $this->getImageUrl($method->name); ?>" title="<?php echo ucfirst($method->name); ?>" />
										</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
					<?php if($this->_cards && $this->settings['ui_widget_groupcc'] == 'yes') : ?>
						<li class="maksekeskus-picker-country">
							<input type="radio" id="maksekeskus_country_picker_card" name="maksekeskus_country_picker" value="card"/><label for="maksekeskus_country_picker_card"><?php echo $this->settings['ui_widget_groupcc_title']; ?></label>
							<div class="maksekeskus_country_picker_methods" id="maksekeskus_country_picker_methods_card">
								<?php foreach($this->_cards as $method): ?>
									<div class="maksekeskus-banklink-picker" banklink_id="card_<?php echo $method->name; ?>">
										<img class="size-<?php echo $this->settings['ui_widget_logosize']; ?>" src="<?php echo $this->getImageUrl($method->name); ?>" title="<?php echo ucfirst($method->name); ?>" />
									</div>
								<?php endforeach; ?>
							</div>
						</li>
					<?php endif; ?>
				<?php
				}
				?>
					</ul>
					<script type="text/javascript">
					var maksekeskusId = '<?php echo $this->id; ?>';
					
					maksekeskusPick();
					
					jQuery('body').on('change', 'input[name=maksekeskus_country_picker]', maksekeskusPick);
					
					function maksekeskusPick() {
						jQuery('select#'+maksekeskusId).val('');
						jQuery('div.maksekeskus-banklink-picker').removeClass('selected');
						jQuery('label.maksekeskus_country_picker_label').removeClass('selected');
						
						var selected = jQuery('input[name=maksekeskus_country_picker]:checked').val();
						jQuery('div.maksekeskus_country_picker_methods').hide();
						jQuery('div#maksekeskus_country_picker_methods_' + selected).show();
						jQuery('label[for=maksekeskus_country_picker_' + selected).addClass('selected');
					}
					
					jQuery('div.maksekeskus-banklink-picker').on('click', function() {
							var banklink_id = jQuery(this).attr('banklink_id');
							jQuery('select#'+maksekeskusId).val(banklink_id);
							
							jQuery('div.maksekeskus-banklink-picker').removeClass('selected');
							jQuery(this).addClass('selected');
					});
					</script>
				<?php
			}
		}
		
		private function getDefaultCountry() {
			if ($this->_getWooCommerce()->customer) {
				$customerCountry = strtolower($this->_getWooCommerce()->customer->get_shipping_country());
				if(array_key_exists($customerCountry, $this->_banklinks_grouped)) {
					return $customerCountry;
				}
			}
			
			$localeToCountry = array(
				'et' => 'ee',
				'lv' => 'lv',
				'lt' => 'lt',
				'fi' => 'fi',
			);
			if(array_key_exists(get_locale(), $localeToCountry)) {
				return $localeToCountry[get_locale()];
			}
			
			return key($this->_banklinks_grouped);
		}
		
		private function initBanklinks() {
			global $wpdb;
			global $mkDbTable;
			
			$tableName = $wpdb->prefix . $mkDbTable;
			$methods = $wpdb->get_results('SELECT * FROM '.$tableName.' WHERE type = "banklink"');
			
			if(count($methods)) {
				$banklinks = array();
				$banklinks_grouped = array();
				foreach($methods as $method) {
					$banklinks[] = $banklinks_grouped[$method->country][] = $method;
				}
			}
			
			$this->_banklinks = $banklinks;
			$this->_banklinks_grouped = $banklinks_grouped;
			
			return $banklinks;
		}
		
		private function initCards() {
			global $wpdb;
			global $mkDbTable;
			
			$tableName = $wpdb->prefix . $mkDbTable;
			$this->_cards = $wpdb->get_results('SELECT * FROM '.$tableName.' WHERE type = "card"');
			
			return $this->_cards;
		}
		
		protected function getImageUrl($methodName) {
			$imageUrlPath = 'https://static.maksekeskus.ee/img/channel/lnd/';
			
			return $imageUrlPath.$methodName.'.png';
		}
		
		public function validate_fields() {
			$selected = isset($_POST['PRESELECTED_METHOD_' . $this->id]) ? sanitize_text_field($_POST['PRESELECTED_METHOD_' . $this->id]) : false;

			if (!$selected) {
				wc_add_notice(__('Please select suitable payment option!', $this->_plugin_text_domain), 'error');
			} else {
				$this->_getWooCommerce()->session->maksekeskus_preselected_method = $selected;
			}

			return true;
		}
		
		function process_payment($order_id) {

			$order = new WC_Order($order_id);

			$selected = isset($_POST['PRESELECTED_METHOD_' . $this->id]) ? sanitize_text_field($_POST['PRESELECTED_METHOD_' . $this->id]) : false;
			
			if(!empty($selected)) {
				
				update_post_meta($order_id, '_maksekeskus_preselected_method', $selected);
				
				if(substr($selected, 0, 5) == 'card_') {
					
					$request_body = array(
						'transaction' => array(
							'amount' => round($order->order_total, 2),
							'currency' => $order->get_order_currency(),
							'reference' => $order->id,
							),
						'customer' => array(
							'ip' => $_SERVER['REMOTE_ADDR'],
							'country' => strtolower($order->billing_country),
							'locale' => strtolower(substr(get_locale(), 0, 2)),
							),
					);
					$transaction = $this->_api->createTransaction($request_body);
					
					if(isset($transaction->id)) {
						update_post_meta($orderId, '_maksekeskus_cc_transaction_id', $transaction->id);
						return array(
							'result' => 'success',
							'redirect' => $this->_getOrderConfirmationUrl($order),
						);
					}
					
					wc_add_notice(__('An error occured when trying to process payment!', $this->_plugin_text_domain), 'error');
					return array(
						'result' => 'failure',
					);
					
				} else {
				
					$redirectUrl = $this->_getRedirectUrl($selected);
					
					$request_body = array(
						'transaction' => array(
							'amount' => round($order->order_total, 2),
							'currency' => $order->get_order_currency(),
							'reference' => $order->id,
							),
						'customer' => array(
							'ip' => $_SERVER['REMOTE_ADDR'],
							'country' => strtolower($order->billing_country),
							'locale' => strtolower(substr(get_locale(), 0, 2)),
							),
					);
					$transaction = $this->_api->createTransaction($request_body);
					
					if($redirectUrl && isset($transaction->id)) {
						return array(
							'result' => 'success',
							'redirect' => $redirectUrl.$transaction->id,
						);
					}
					
					wc_add_notice(__('An error occured when trying to process payment!', $this->_plugin_text_domain), 'error');
					return array(
						'result' => 'failure',
					);
					
				}
				
			}
			
			wc_add_notice(__('An error occured when trying to process payment!', $this->_plugin_text_domain), 'error');
			return array(
				'result' => 'failure',
			);
		}
		
		protected function _getRedirectUrl($selected) {
			foreach($this->_banklinks as $method) {
				if($selected == $method->country.'_'.$method->name)
					return $method->url;
			}
			
			return false;
		}
		
		protected function _getOrderConfirmationUrl($order) {
			$url = add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))));
			return $url;
		}
		
		function receipt_page($orderId) {
			echo '<p>' . __('Thank you for the order, please click on the button to start the payment.', $this->_plugin_text_domain) . '</p>';
			
			if(substr(get_post_meta($orderId, '_maksekeskus_preselected_method', true), 0, 5) == 'card_') {
				echo $this->generateCardForm($orderId);
			}
        }
        
        function generateCardForm($orderId) {
        	$order = new WC_Order($orderId);
        	$scriptSrc = htmlspecialchars($this->get_option('checkout_js_url'));
        	$jsParams = array(
				'key' => $this->get_option('api_key_public'),
				'transaction' => get_post_meta($order->id, '_maksekeskus_cc_transaction_id', true),
				'selector' => '#submit_banklinkmaksekeskus_payment_form',
				'completed' => 'maksekeskus_cc_datacompleted',
				'amount' => round($order->order_total, 2),
				'locale' => strtolower(substr(get_locale(), 0, 2)),
				'open-on-load' => 'true',
				'client-name' => (string) ($order->billing_first_name . ' ' . $order->billing_last_name),
				'email' => (string) $order->billing_email,
				'completed' => 'maksekeskus_cc_complete',
			);
			?>
			<script type="text/javascript">
			function maksekeskus_cc_complete(data) {
				
			}
			</script>
			<form>
				<input type="submit" class="button-alt" id="submit_banklinkmaksekeskus_payment_form" value="<?php echo __('Pay', $this->_plugin_text_domain); ?>" />
				<a class="button cancel" href="<?php echo esc_url($order->get_cancel_order_url()); ?>"><?php echo __('Cancel order &amp; restore cart', $this->_plugin_text_domain); ?></a>
				<script type="text/javascript" src="<?php echo $scriptSrc; ?>" <?php echo $this->_toHtmlAttributes($jsParams); ?>></script>
			</form>
			<?php
        }
        
        protected function _toHtmlAttributes($input) {
			$result = array();
			foreach ($input as $key => $value) {
				$result[] = 'data-' . htmlspecialchars($key) . '=' . '"' . htmlspecialchars($value) . '"';
			}
			return implode(' ', $result);
		}        
        
		function maksekeskus_return_trigger($vars) {
			$vars[] = 'maksekeskus_return';
			return $vars;
		}
		
		function maksekeskus_return_trigger_check() {
			if(intval(get_query_var('maksekeskus_return')) == 1) {
				
				$returnUrl = home_url();
				
				$request = stripslashes_deep($_POST);
				if($this->_api->verifySignature($request)) {
					$data = $this->_api->extractRequestData($request);
					$order = new WC_Order($data['reference']);
					
					switch($data['status']) {
						case self::MK_CANCELLED:
							$order->update_status( 'cancelled' );
							wc_add_notice(__('Payment transaction cancelled', $this->_plugin_text_domain), 'error');
							$returnUrl = $this->_getWooCommerce()->cart->get_cart_url();
							break;
						case self::MK_COMPLETED:
							if($this->validate_completed_payment($order, $data)) {
								$orderNote = array();
								$orderNote[] = __('Transaction ID', $this->_plugin_text_domain) . ': ' . $data['transaction'];
								$orderNote[] = __('Payment option', $this->_plugin_text_domain) . ': ' . get_post_meta($order->id, '_maksekeskus_preselected_method', true);
	
								$order->add_order_note(implode("\r\n", $orderNote));
								
								$order->payment_complete($data['transaction']);
								$order->update_status( 'completed' );
								
								$order->reduce_order_stock();
								
								try {
									@ob_start();
									$this->receipt_page($order->id);
									@ob_end_clean();
									
									$returnUrl = $this->get_return_url($order);
								} catch (Exception $ex) {
									@ob_end_clean();									
								}
							} else {
								$returnUrl = $this->_getWooCommerce()->cart->get_cart_url();
							}
							break;
					}
					
					//exit;
				}
				wp_redirect($returnUrl);
				exit;
			}
		}
		
		private function validate_completed_payment($order, $data) {
			
			if(empty($data['transaction'])) {
				$order->add_order_note(__('Payment error, missing transaction id', $this->_plugin_text_domain));
				wc_add_notice(__('Error verifying transaction', $this->_plugin_text_domain), 'error');
				return false;
			}
			if($data['amount'] != round($order->order_total, 2)) {
				$order->add_order_note(sprintf(__('Payment error, incorrect amount captured: %s', $this->_plugin_text_domain), $data['amount'].' '.$data['currency']));
				wc_add_notice(__('Error verifying transaction', $this->_plugin_text_domain).', '.__('Incorrect amount captured', $this->_plugin_text_domain), 'error');
				return false;
			}
			if($data['currency'] != $order->get_order_currency()) {
				$order->add_order_note(sprintf(__('Payment error, incorrect currency captured: %s', $this->_plugin_text_domain), $data['currency']));
				wc_add_notice(__('Error verifying transaction', $this->_plugin_text_domain).', '.__('Incorrect currency captured', $this->_plugin_text_domain), 'error');
				return false;
			}
			
			return true;
		}
		
		function admin_order_page($order) {
			//
		}
		
		public function process_refund($order_id, $amount = null, $comment = '') {
			if ($this->_api) {
				try {
					$order = new WC_Order($order_id);
					$transactionId = $order->get_transaction_id();
					
					if($response = $this->_api->createRefund($transactionId, array('amount' => $amount, 'comment' => ($comment ? : 'refund')))) {
						if($status = (string)$response->transaction->status) {
							switch($status) {
								case self::MK_REFUNDED: 
									$order->add_order_note(sprintf(__('Refund completed for amount %s', $this->_plugin_text_domain), $amount));
									return true;
									break;
								case self::MK_PART_REFUNDED: 
									$order->add_order_note(sprintf(__('Partial refund completed for amount %s', $this->_plugin_text_domain), $amount));
									return true;
									break;
							}
						}
					}
					return false;
					
				} catch (Exception $e) {
					return new WP_Error('maksekeskus_refund_error', $e->getMessage());
				}

				return false;
			}
			return false;
		}
		
		protected function getCountryName($slug) {
			switch($slug) {
				case 'ee': return __('Estonia', $this->_plugin_text_domain); break;
				case 'lv': return __('Latvia', $this->_plugin_text_domain); break;
				case 'lt': return __('Lithuania', $this->_plugin_text_domain); break;
				case 'fi': return __('Finland', $this->_plugin_text_domain); break;
			}
			return $slug;
		}
		
	}
	
}

function woocommerce_payment_maksekeskus_add($methods) {
	$methods[] = 'woocommerce_maksekeskus';
	return $methods;
}

add_action('plugins_loaded', 'woocommerce_payment_maksekeskus_init');
add_action('woocommerce_payment_gateways', 'woocommerce_payment_maksekeskus_add');