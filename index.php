<?php

/*
  Plugin Name: iPaymu Payment Gateway
  Plugin URI: http://ipaymu.com
  Description: iPaymu Payment Gateway
  Version: 1.1
  Author: iPaymu Development Team
  Author URI: http://ipaymu.com
 */

if ( ! defined( 'ABSPATH' ) ) exit; 

add_action('plugins_loaded', 'woocommerce_ipaymu_init', 0);
// require_once('wp-includes/template-loader.php');
// require('wp-blog-header.php');
function woocommerce_ipaymu_init() {

    if (!class_exists('WC_Payment_Gateway'))
        return;

    class WC_Gateway_iPaymu extends WC_Payment_Gateway {

        public function __construct() {
            
            //plugin id
            $this->id = 'ipaymu';
            //Payment Gateway title
            $this->method_title = 'iPaymu Payment Gateway';
            //true only in case of direct payment method, false in our case
            $this->has_fields = false;
            //payment gateway logo
            $this->icon = plugins_url('/ipaymu_badge.png', __FILE__);
            
            //redirect URL
            $this->redirect_url = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_Gateway_iPaymu', home_url( '/' ) ) );
            
            //Load settings
            $this->init_form_fields();
            $this->init_settings();
            // var_dump($this->settings);
            // exit;
            // Define user set variables
            $this->enabled      = $this->settings['enabled'] ?? '';
            $this->title        = "Ipaymu Payment";
            $this->description  = $this->settings['description'] ?? '';
            $this->apikey       = $this->settings['apikey'];
            $this->password     = $this->settings['password'] ?? '';
            $this->processor_id = $this->settings['processor_id'] ?? '';
            $this->salemethod   = $this->settings['salemethod'] ?? '';
            $this->gatewayurl   = $this->settings['gatewayurl'] ?? '';
            $this->order_prefix = $this->settings['order_prefix'] ?? '';
            $this->debugon      = $this->settings['debugon'] ?? '';
            $this->debugrecip   = $this->settings['debugrecip'] ?? '';
            $this->cvv          = $this->settings['cvv'] ?? '';
            
            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
            add_action('woocommerce_receipt_ipaymu', array(&$this, 'receipt_page'));
            
            // Payment listener/API hook
            add_action( 'woocommerce_api_wc_gateway_ipaymu', array( $this, 'check_ipaymu_response' ) );
        }

        function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                                'title' => __( 'Enable/Disable', 'woothemes' ), 
                                'label' => __( 'Enable iPaymu', 'woothemes' ), 
                                'type' => 'checkbox', 
                                'description' => '', 
                                'default' => 'no'
                            ), 
                'title' => array(
                                'title' => __( 'Title', 'woothemes' ), 
                                'type' => 'text', 
                                'description' => __( '', 'woothemes' ), 
                                'default' => __( 'Pembayaran iPaymu', 'woothemes' )
                            ), 
                'description' => array(
                                'title' => __( 'Description', 'woothemes' ), 
                                'type' => 'textarea', 
                                'description' => __( '', 'woothemes' ), 
                                'default' => 'Sistem pembayaran menggunakan iPaymu.'
                            ),  
                'apikey' => array(
                                'title' => __( 'API Key', 'woothemes' ), 
                                'type' => 'text', 
                                'description' => __( ' Dapatkan API Key <a href=https://my.ipaymu.com/ target=_blank>di sini</a></small>.', 'woothemes' ),
                                'default' => ''
                            ),
                /*'debugrecip' => array(
                                'title' => __( 'Debugging Email', 'woothemes' ), 
                                'type' => 'text', 
                                'description' => __( 'Who should receive the debugging emails.', 'woothemes' ), 
                                'default' =>  get_option('admin_email')
                            ),*/
            );
        }

        public function admin_options() {
            echo '<table class="form-table">';
            $this->generate_settings_html();
            echo '</table>';
        }

        function payment_fields() {
            if ($this->description)
                echo wpautop(wptexturize($this->description));
        }

        
        function receipt_page($order) {
            echo $this->generate_ipaymu_form($order);
        }

        
        public function generate_ipaymu_form($order_id) {

            global $woocommerce;
            
            $order = new WC_Order($order_id);
            // var_dump($order);
            // echo $order->get_total();
            // exit;
            // URL Payment IPAYMU Production
            $url = 'https://my.ipaymu.com/payment';

            // URL Payment IPAYMU Sandbox
            // $url = 'https://sandbox.ipaymu.com/payment';

            //for cod
            foreach ($order->get_items() as $item_key => $item_value){
                $width  = $item_value->get_product()->get_width();
                $height = $item_value->get_product()->get_height();
                $length = $item_value->get_product()->get_length();
                $weight = $item_value->get_product()->get_weight();
            }
            $buyer_name = $order->get_billing_first_name() . $order->get_billing_last_name();
            $buyer_email = $order->get_billing_email();
            $buyer_phone = $order->get_billing_phone();

            // Prepare Parameters
            $params = array(
                        'key'      => $this->apikey, // API Key Merchant / Penjual
                        'action'   => 'payment',
                        'product'  => 'Order : #' . $order_id,
                        // 'price'    => $order->order_total ?? $order->total, // Total Harga
                        'price'    => $order->get_total(),
                        'quantity' => 1,
                        'weight'   => $weight,
                        'dimensi'  => $length . ":" . $width . ":" . $height,
                        'reference_id' => $order_id,
                        'comments' => '', // Optional           
                        'ureturn'  => $this->redirect_url.'&id_order='.$order_id,
                        'unotify'  => $this->redirect_url.'&id_order='.$order_id.'&param=notify',
                        'ucancel'  => $this->redirect_url.'&id_order='.$order_id.'&param=cancel',
                        'buyer_name' => $buyer_name,
                        'buyer_phone' => $buyer_phone,
                        'buyer_email' => $buyer_email,
                        'format'   => 'json' // Format: xml / json. Default: xml 
                    );

            $params_string = http_build_query($params);

            //open connection
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, count($params));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

            //execute post
            $request = curl_exec($ch);

            if ( $request === false ) {
                echo 'Curl Error: ' . curl_error($ch);
            } else {
                
                $result = json_decode($request, true);

                if( isset($result['url']) )
                    wp_redirect($result['url']);
                else {
                    echo "Request Error ". $result['Status'] .": ". $result['Keterangan'];
                }
            }

            //close connection
            curl_close($ch);
        }

        
        function process_payment($order_id) {
            global $woocommerce;
            $order = new WC_Order($order_id);

			$order->reduce_order_stock();

			WC()->cart->empty_cart();

            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url( true ));
        }

  
        function check_ipaymu_response() {
            global $woocommerce;
            $order = new WC_Order($_REQUEST['id_order']);
            
            $order_received_url = wc_get_endpoint_url( 'order-received', $_REQUEST['id_order'], wc_get_page_permalink( 'checkout' ) );

            if ( 'yes' === get_option( 'woocommerce_force_ssl_checkout' ) || is_ssl() ) {
                $order_received_url = str_replace( 'http:', 'https:', $order_received_url );
            }
            if($_REQUEST['status'] == 'berhasil') {
            	$order->add_order_note( __( 'Pembayaran telah dilakukan melalui ipaymu dengan id transaksi '.$_REQUEST['trx_id'], 'woocommerce' ) );
            	$order->payment_complete();
            } else if($_REQUEST['status'] == 'pending'){
                $order->add_order_note( __( 'Menunggu pembayaran melalui iPaymu dengan id transaksi '.$_REQUEST['trx_id'], 'woocommerce' ) );
                $order->update_status( 'on-hold' );
                // $order->payment_complete();
            } else {
                $order->add_order_note( __( 'Menunggu pembayaran melalui iPaymu dengan id transaksi '.$_REQUEST['trx_id'], 'woocommerce' ) );
            }

            // $order_received_url = add_query_arg('key', $order->order_key, add_query_arg('order', $_REQUEST['id_order'], $order_received_url));
            // $order_received_url = add_query_arg( 'key', $order->order_key, $order_received_url );
            $order_received_url = add_query_arg( 'key', $order->get_order_key(), $order_received_url );
            $redirect =  apply_filters( 'woocommerce_get_checkout_order_received_url', $order_received_url, $this );
            wp_redirect($redirect);
            
        }

    }

    function add_ipaymu_gateway($methods) {
        $methods[] = 'WC_Gateway_iPaymu';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_ipaymu_gateway');
}
