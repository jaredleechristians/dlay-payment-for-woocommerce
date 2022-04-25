<?php 
/**
* Plugin Name: DLAY Payments for woocommerce
* Plugin URI: https://dlay.co.za
* Author Name: Jared Christians
* Author URI: https://www.linkedin.com/in/jaredchristians/
* Description: Allows for DLAY payment system
* Version: 0.1.1
 */

if( ! in_array('woocommerce/woocommerce.php', apply_filters('active_plugins',get_option('active_plugins')))) return;

add_action('plugins_loaded', 'dlay_payment_init', 11);

function dlay_payment_init(){
     if(class_exists('WC_Payment_Gateway')){
         class WC_Dlay_Gateway extends WC_Payment_Gateway{
            public function __construct(){
                $this->id = 'dlay';
                $this->method_title = __('DLAY', 'dlay-pay-woo');
                $this->method_description = __('DLAY payment system', 'dlay-pay-woo');
                $this->init_form_fields();
                $this->init_settings();
                $this->has_fields = false;
                $this->icon = WP_PLUGIN_URL . '/dlay-payment-for-woocommerce-main/assets/images/icon.png';
                $this->title = $this->get_option( 'title' );
                $this->description = $this->get_option( 'description' );
                $this->available_countries  = array( 'ZA' );
                $this->available_currencies = (array)apply_filters('woocommerce_gateway_dlay_available_currencies', array( 'ZAR' ) );
                $this->supports = array(
                    'products',
                );

                $this->url = 'https://pay.dlay.co.za'; // payment processor
				//$this->url = 'https://dlay-sandbox.robotweb.co.za'; // robotweb sandbox payment processor
                $this->response_url = add_query_arg( 'wc-api', 'Dlay_Handler', home_url( '/' ) );

                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
                add_action( 'woocommerce_receipt_dlay', array( $this, 'receipt_page' ) );
              
              	// Setup merchant data.
				$this->merchant_code      = $this->get_option( 'merchant_code' );
				$this->merchant_name      = $this->get_option( 'merchant_name' );
				if($this->get_option( 'sandbox' ) == "yes"){
					$this->api = "https://app-uat.dlay.co.za";
				}else{
					$this->api = "https://app.dlay.co.za";
				}
				
				add_action( 'woocommerce_api_dlay_handler', array( $this, 'handler' ) );

            }
			 
			public function handler(){
				header( 'HTTP/1.1 200 OK');
				$content = file_get_contents("php://input");
				$order_key = isset($_REQUEST['key']) ? $_REQUEST['key'] : null;
				$order_id = wc_get_order_id_by_order_key( $order_key );
				$order = wc_get_order($order_id);
				if (is_null($order_id)) return;
				$json = json_decode($content);
				$msg = $content;
				$status = $json->setup_status;
				$status_message = $json->setup_message;

				if($status == "OK"){				
					if($status_message == "Vetting Approved"){
						$order->update_status('wc-approved');
					}elseif($status_message == "Vetting Approved (Cheaper Deal)"){
						$order->update_status('wc-approved-cheaper-deal');
					}elseif($status_message == "Vetting Declined"){
						$order->update_status('wc-declined');
					}else{
						$order->update_status('processing');
						$order->payment_complete();
						wc_reduce_stock_levels($order_id);
					}
				}else{
					$order->update_status('failed');
				}
				$order->add_order_note( $content );
				$order->update_meta_data( 'serial', "" );
				$order->update_meta_data( 'ammacom_id', $json->ammacom_id );
				$order->update_meta_data( 'transaction_id', $json->transaction_id );
				$order->update_meta_data( 'setup_status', $json->setup_status );
				$order->update_meta_data( 'setup_message', $json->setup_message );
				$order->update_meta_data( 'merchant_code', $this->get_option( 'merchant_code' ) );
				$order->update_meta_data( 'api', $this->api );
				$order->save();
				
			}

            public function init_form_fields(){
                $this->form_fields = apply_filters(
                    'woo_dlay_pay_fields', array(
                        'enabled' => array(
                            'title'       => __( 'Enable/Disable', 'dlay-pay-woo' ),
                            'label'       => __( 'Enable DLAY', 'dlay-pay-woo' ),
                            'type'        => 'checkbox',
                            'description' => __( 'This controls whether or not this gateway is enabled within WooCommerce.', 'dlay-pay-woo' ),
                            'default'     => '',
                        ),
						'sandbox' => array(
						'title'       => __( 'Enable Sandbox', 'dlay-pay-woo' ),
						'type'        => 'checkbox',
						'description' => __( 'Place the payment gateway in development mode.', 'dlay-pay-woo' ),
						'default'     => 'true',
                        ),'rental' => array(
						'title'       => __( 'Enable Rental', 'dlay-pay-woo' ),
						'type'        => 'checkbox',
						'description' => __( 'Place the payment gateway in rental mode.', 'dlay-pay-woo' ),
						'default'     => 'false',
                        ),
                        'title' => array(
                            'title'       => __( 'Title', 'dlay-pay-woo' ),
                            'type'        => 'text',
                            'description' => __( 'This controls the title which the user sees during checkout.', 'dlay-pay-woo' ),
                            'default'     => __( 'DLAY', 'dlay-pay-woo' ),
                            'desc_tip'    => true,
                        ),
                        'description' => array(
                            'title'       => __( 'Description', 'dlay-pay-woo' ),
                            'type'        => 'text',
                            'description' => __( 'This controls the description which the user sees during checkout.', 'dlay-pay-woo' ),
                            'default'     => '',
                            'desc_tip'    => true,
                        ),
						'merchant_name' => array(
                            'title'       => __( 'Merchant Name', 'dlay-pay-woo' ),
                            'type'        => 'text',
                            'description' => __( 'This is the merchant name, shown on DLAY payment platform.', 'dlay-pay-woo' ),
                            'default'     => '',
                        ),
                        'merchant_code' => array(
                            'title'       => __( 'Merchant Code', 'dlay-pay-woo' ),
                            'type'        => 'text',
                            'description' => __( 'This is the merchant code, received from DLAY.', 'dlay-pay-woo' ),
                            'default'     => '',
                        )
                    )
                );
            }

            public function process_payment( $order_id ) {
                $order = wc_get_order( $order_id );
                return array(
                    'result' 	 => 'success',
                    'redirect'	 => $order->get_checkout_payment_url( true ),
                );
            }

            public function receipt_page( $order ) {
                echo '<p>' . __( 'Thank you for your order, please click the button below to pay with dlay.', 'dlay-pay-woo' ) . '</p>';
                echo $this->generate_dlay_form( $order );
            }

            public function generate_dlay_form( $order_id ) {
                $order = wc_get_order( $order_id );
				$items = $order->get_items();
				$products = array();
				$longest_period = 1;
				$product_name = "";
				$product_url = "";
				$monthly_fee = 0;
				
					
				foreach ( $items as $item ) {
					$object = new stdClass();
					$product = $item->get_product();
					$object->product_name = $item->get_name();
					$object->product_image = wp_get_attachment_url( $product->get_image_id() );
					$object->product_code = $product->get_sku();
					$fee = intval($product->get_attribute( 'monthly_fee' ));
					$object->monthly_fee = $fee;
					
					$monthly_fee += $fee;
					
					array_push($products,$object);
					
					//check if this is a variation
					if ( 'variation' === $product->get_type() ) {
						$variation_id = $item->get_variation_id();
						$variation    = new WC_Product_Variation( $variation_id );
						$attributes   = $variation->get_attributes();
						foreach ( $attributes as $key => $value ) {
							if ( 'period' === $key ) {
								if(intval($value) > $longest_period){
									$longest_period = intval($value);
								}
							}
						}
					}else{
						$value = $product->get_attribute( 'period' );
						if(intval($value) > $longest_period){
							$longest_period = intval($value);
						}
					}
				}
					
                // Construct variables for post
                $this->data_to_send = array(
                    // Merchant details
                  	'first_name'		=> $order->get_billing_first_name(),
                  	'last_name'			=> $order->get_billing_last_name(),
                  	'mobile'			=> $order->get_billing_phone(),
                  	'email'				=> $order->get_billing_email(),
                  	'merchant_code'	   	=> $this->merchant_code,
					'merchant_name'	   	=> $this->merchant_name,
                    'amount'			=> $order->get_total(),
					'period'			=> $longest_period,
                  	'transaction_id'	=> get_bloginfo( 'name' ) . " order #" . $order_id,
                    'return_url'       	=> $this->get_return_url( $order ),
                    'cancel_url'       	=> $order->get_cancel_order_url(),
                    'notify_url'       	=> $this->response_url . "&key=".$order->get_order_key(),
					'sandbox'			=> $this->get_option( 'sandbox' ),
					'rental'			=> $this->get_option( 'rental' ),
					'api'				=> $this->api,
					'products'			=> json_encode($products),
					'monthly_fee'		=> $monthly_fee
                );
				
				if($this->get_option( 'rental' ) == "yes"){
					$this->url = $this->url . "/rent/";
				}
                        
                $dlay_args_array = array();
                $sign_strings = array();
                foreach ( $this->data_to_send as $key => $value ) {
                    if ($key !== 'source') {
                        $sign_strings[] = esc_attr( $key ) . '=' . urlencode(str_replace('&amp;', '&', trim( $value )));
                    }
                    $dlay_args_array[] = '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
                }

                return '<form action="' . esc_url( $this->url ) . '" method="post" id="payment_form">
                        ' . implode( '', $dlay_args_array ) . '
                        <input type="submit" class="button-alt" id="submit_payment_form" value="' . __( 'Pay via Dlay', 'dlay-pay-woo' ) . '" /> 
                        <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __( 'Cancel order &amp; restore cart', 'dlay-pay-woo' ) . '</a>
                        <script type="text/javascript">
                            
                            jQuery(function(){
                                jQuery("body").block(
                                    {
                                        message: "' . __( 'Thank you for your order. We are now redirecting you to Dlay to make payment.', 'woocommerce-gateway-dlay' ) . '",
                                        overlayCSS:
                                        {
                                            background: "#fff",
                                            opacity: 0.6
                                        },
                                        css: {
                                            padding:        20,
                                            textAlign:      "center",
                                            color:          "#555",
                                            border:         "3px solid #aaa",
                                            backgroundColor:"#fff",
                                            cursor:         "wait"
                                        }
                                    });
                                jQuery( "#submit_payment_form" ).click();
                            });
                            
                        </script>
                    </form>';
                    
                    
            }
         }
     }
 }

 add_filter('woocommerce_payment_gateways','add_to_woo_dlay_payment');

 function add_to_woo_dlay_payment($gateways){
    $gateways[] = 'WC_Dlay_Gateway';
    return $gateways;
 }

function woocommerce_dlay_plugin_links( $links ) {
	$settings_url = add_query_arg(
		array(
			'page' => 'wc-settings',
			'tab' => 'checkout',
			'section' => 'dlay',
		),
		admin_url( 'admin.php' )
	);

	$plugin_links = array(
		'<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'woocommerce-gateway-dlay' ) . '</a>',
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'woocommerce_dlay_plugin_links' );

// define the woocommerce_order_status_changed callback 
function action_woocommerce_order_status_changed($order_id, $old_status, $new_status) {
	if ( $new_status == "completed" ) {
		
		$order = wc_get_order( $order_id );
		$transaction_id = $order->get_meta("transaction_id");
		$ammacom_id = $order->get_meta("ammacom_id");
		$merchant_code = $order->get_meta("merchant_code");
		$serial = $order->get_meta("serial");
		$api = $order->get_meta("api") . "/server/api/conc-sub-setup";
		$status = "COMPLETE";
		$json = json_encode(array("transaction_id"=>$transaction_id,
					  "ammacom_id"=>$ammacom_id,
					  "serial" => $serial,
					  "merchant_code"=>$merchant_code,
					  "status"=>$status,"api"=>$api));
		
		$url = "https://pay.dlay.co.za/conclude/"; # payment conclude
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $json );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json','Accept: application/json'));
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$result = curl_exec($ch);
		curl_close($ch);
		$order->add_order_note( $result );
    }
}; 
add_action( 'woocommerce_order_status_changed', 'action_woocommerce_order_status_changed', 10, 4 );

add_action( 'init', 'register_my_new_order_statuses' );

function register_my_new_order_statuses() {
    register_post_status( 'wc-approved', array(
        'label'                     => _x( 'Approved', 'Order status', 'woocommerce' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Approved <span class="count">(%s)</span>', 'Approved<span class="count">(%s)</span>', 'woocommerce' )
    ) );
	
	register_post_status( 'wc-approved-cheaper-deal', array(
        'label'                     => _x( 'Approved (Cheaper Deal)', 'Order status', 'woocommerce' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Approved (Cheaper Deal)<span class="count">(%s)</span>', 'Approved (Cheaper Deal)<span class="count">(%s)</span>', 'woocommerce' )
    ) );
	
	register_post_status( 'wc-declined', array(
        'label'                     => _x( 'Declined', 'Order status', 'woocommerce' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Declined<span class="count">(%s)</span>', 'Declined<span class="count">(%s)</span>', 'woocommerce' )
    ) );
}

add_filter( 'wc_order_statuses', 'my_new_wc_order_statuses' );

// Register in wc_order_statuses.
function my_new_wc_order_statuses( $order_statuses ) {
    $order_statuses['wc-approved'] = _x( 'Approved', 'Order status', 'woocommerce' );
	$order_statuses['wc-approved-cheaper-deal'] = _x( 'Approved (Cheaper Deal)', 'Order status', 'woocommerce' );
	$order_statuses['wc-declined'] = _x( 'Declined', 'Order status', 'woocommerce' );

    return $order_statuses;
}
add_filter('acf/settings/remove_wp_meta_box', '__return_false');

 
