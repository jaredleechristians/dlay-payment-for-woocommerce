<?php 
/**
* Plugin Name: Dlay Payments for woocommerce
* Plugin URI:
* Author Name: Jared Christians
* Author URI: 
* Description: Allows for dlay payment system
* Version: 0.1.0
 */

if( ! in_array('woocommerce/woocommerce.php', apply_filters('active_plugins',get_option('active_plugins')))) return;

add_action('plugins_loaded', 'dlay_payment_init', 11);

function dlay_payment_init(){
     if(class_exists('WC_Payment_Gateway')){
         class WC_Dlay_Gateway extends WC_Payment_Gateway{
            public function __construct(){
                $this->id = 'dlay';
                $this->method_title = __('Dlay', 'dlay-pay-woo');
                $this->method_description = __('dlay payment system', 'dlay-pay-woo');
                $this->init_form_fields();
                $this->init_settings();
                $this->has_fields = false;
                $this->icon = WP_PLUGIN_URL . '/dlay-payment-for-woocommerce/assets/images/icon.png';
                $this->title = $this->get_option( 'title' );
                $this->description = $this->get_option( 'description' );
                $this->available_countries  = array( 'ZA' );
                $this->available_currencies = (array)apply_filters('woocommerce_gateway_dlay_available_currencies', array( 'ZAR' ) );
                $this->supports = array(
                    'products',
                );

                $this->url = 'https://dlay-sandbox.robotweb.co.za/';
                $this->response_url = add_query_arg( 'wc-api', 'Dlay_Handler', home_url( '/' ) );

                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
                add_action( 'woocommerce_receipt_dlay', array( $this, 'receipt_page' ) );
              
              	// Setup default merchant data.
				$this->merchant_code      = $this->get_option( 'merchant_code' );
              
              	// Setup the test data, if in test mode.
                if ( 'yes' === $this->get_option( 'testmode' ) ) {
                    $this->url          = 'https://dlay-sandbox.robotweb.co.za/';
                }
				
				add_action( 'woocommerce_api_dlay_handler', array( $this, 'handler' ) );

            }
			 
			public function handler(){
				header( 'HTTP/1.1 200 OK');
				$content = file_get_contents("php://input");
				$order_id = isset($_REQUEST['order_id']) ? $_REQUEST['order_id'] : null;
				$order = wc_get_order($order_id);
				if (is_null($order_id)) return;
				$json = json_decode($content);
				$msg = $content;
				$status = $json->setup_status;
				$note = str_replace("{","",$content);
				$note = str_replace("}","",$note);
				$note = str_replace('"',"",$note);
				if($status == "OK"){
					$order->payment_complete();
					wc_reduce_stock_levels($order_id);
					$order->add_order_note($note);
					$order->save();
				}else{
					$order->update_status('failed');
					$order->add_order_note( $note );
					$order->save();
				}

			}

            public function init_form_fields(){
                $this->form_fields = apply_filters(
                    'woo_dlay_pay_fields', array(
                        'enabled' => array(
                            'title'       => __( 'Enable/Disable', 'dlay-pay-woo' ),
                            'label'       => __( 'Enable dlay', 'dlay-pay-woo' ),
                            'type'        => 'checkbox',
                            'description' => __( 'This controls whether or not this gateway is enabled within WooCommerce.', 'dlay-pay-woo' ),
                            'default'     => '',
                        ),
                        'title' => array(
                            'title'       => __( 'Title', 'dlay-pay-woo' ),
                            'type'        => 'text',
                            'description' => __( 'This controls the title which the user sees during checkout.', 'dlay-pay-woo' ),
                            'default'     => __( 'Dlay', 'dlay-pay-woo' ),
                            'desc_tip'    => true,
                        ),
                        'description' => array(
                            'title'       => __( 'Description', 'dlay-pay-woo' ),
                            'type'        => 'text',
                            'description' => __( 'This controls the description which the user sees during checkout.', 'dlay-pay-woo' ),
                            'default'     => '',
                            'desc_tip'    => true,
                        ),
                        'testmode' => array(
                            'title'       => __( 'Sandbox', 'dlay-pay-woo' ),
                            'type'        => 'checkbox',
                            'description' => __( 'Place the payment gateway in development mode.', 'dlay-pay-woo' ),
                            'default'     => 'true',
                        ),
                        'merchant_code' => array(
                            'title'       => __( 'Merchant Code', 'dlay-pay-woo' ),
                            'type'        => 'text',
                            'description' => __( 'This is the merchant code, received from dlay.', 'dlay-pay-woo' ),
                            'default'     => '',
                        ),

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
				foreach ( $items as $item ) {
					$product = $item->get_product();
					array_push($products,$product);
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
                    'amount'			=> $order->get_total(),
					'period'			=> $longest_period,
					//'products'			=> $products,
                  	'transaction_id'	=> get_bloginfo( 'name' ) . " order #" . $order_id,
                    'return_url'       	=> $this->get_return_url( $order ),
                    'cancel_url'       	=> $order->get_cancel_order_url(),
                    'notify_url'       	=> $this->response_url . "&order_id=".$order_id,
                    
                );
                        
                $dlay_args_array = array();
                $sign_strings = array();
                foreach ( $this->data_to_send as $key => $value ) {
                    if ($key !== 'source') {
                        $sign_strings[] = esc_attr( $key ) . '=' . urlencode(str_replace('&amp;', '&', trim( $value )));
                    }
                    $dlay_args_array[] = '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
                }
                //print_r($order);
                //print_r($this->data_to_send);
				//echo json_encode($dlay_args_array);
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

 