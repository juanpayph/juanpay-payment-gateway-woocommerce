<?php
/*
Plugin Name: WooCommerce JuanPay Payment Gateway
Plugin URI: http://www.juanpay.ph
Description: JuanPay Payment gateway for woocommerce
Version: 1.3
Author: Chris Ian Fiel
Author URI: http://www.juanpay.ph
*/
add_action('plugins_loaded', 'woocommerce_juanpay_init', 0);
function woocommerce_juanpay_init()
{
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_JuanPay extends WC_Payment_Gateway
    {
        public function __construct()
        {
            global $woocommerce;
            $this->id = 'juanpay';
            $this->icon         = apply_filters( 'woocommerce_juanpay_icon', $woocommerce->plugin_url() . '/assets/images/icons/juanpay.png' );
            $this->has_fields   = false;
            $this->liveurl      = 'https://www.juanpay.ph';
            $this->testurl      = 'https://sandbox.juanpay.ph';
            $this->method_title = __( 'JuanPay', 'woocommerce' );
            $this->notify_url   = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_Gateway_JuanPay', home_url( '/' ) ) );


            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->redirect_page_id = $this->settings['redirect_page_id'];
            $this->testmode			= $this->get_option( 'testmode' );
            $this->debug			= $this->get_option( 'debug' );
            $this->email 			= $this->get_option( 'email' );
            $this->api_key 			= $this->get_option( 'api_key' );

            $this->msg['message'] = "";
            $this->msg['class'] = "";

            // Logs
            if ( 'yes' == $this->debug )
                $this->log = $woocommerce->logger();

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }
            add_action('woocommerce_receipt_juanpay', array(&$this, 'receipt_page'));
            add_action( 'valid-juanpay-standard-ipn-request', array( $this, 'successful_request' ) );
            add_action( 'woocommerce_api_wc_juanpay', array( $this, 'check_ipn_response' ) );


        }

        function init_form_fields()
        {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable JuanPay Payment Module.', 'woocommerce'),
                    'default' => 'no'),
                'title' => array(
                    'title' => __('Title:', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default' => __('JuanPay', 'woocommerce')),
                'description' => array(
                    'title' => __('Description:', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Pay securely by Credit or Debit card or over the counter and mobile through JuanPay Secure Servers.', 'woocommerce')),
                'email' => array(
                    'title' => __('JuanPay Account Email', 'woocommerce'),
                    'type' => 'email',
                    'description' => __('Please enter your JuanPay account email address; this is needed in order to take payment.', 'woocommerce'),
                    'default' => '',
                    'desc_tip' => true,
                    'placeholder' => 'you@youremail.com',),
                'api_key' => array(
                    'title' => __('API Key', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('API key can be found in your JuanPay Account under Settings and API tab')),
                'redirect_page_id' => array(
                    'title' => __('Return Page'),
                    'type' => 'select',
                    'options' => $this->get_pages('Select Page'),
                    'description' => "URL of success page"),
                'testing' => array(
                    'title' => __('Gateway Testing', 'woocommerce'),
                    'type' => 'title',
                    'description' => '',),
                'testmode' => array(
                    'title' => __('JuanPay sandbox', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable JuanPay sandbox', 'woocommerce'),
                    'default' => 'yes',
                    'description' => sprintf(__('JuanPay sandbox can be used to test payments. Sign up for a developer account <a href="%s">here</a>.', 'woocommerce'), 'https://sandbox.juanpay.ph/'),),
                'debug' => array(
                    'title' => __('Debug Log', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable logging', 'woocommerce'),
                    'default' => 'no',
                    'description' => sprintf(__('Log JuanPay events, such as DPN requests, inside <code>woocommerce/logs/juanpay-%s.txt</code>', 'woocommerce'), sanitize_file_name(wp_hash('juanpay'))),
                )
            );
        }

        public function admin_options()
        {
            echo '<h3>' . __('JuanPay Payment Gateway', 'woocommerce') . '</h3>';
            echo '<p>' . __('JuanPay is the Paypal alternative for Pinoys') . '</p>';
            echo '<table class="form-table">';
            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';

        }

        /**
         *  There are no payment fields for juanpay, but we want to show the description if set.
         **/
        function payment_fields()
        {
            if ($this->description) echo wpautop(wptexturize($this->description));
        }

        /**
         * Receipt Page
         **/
        function receipt_page($order)
        {
            echo '<p>' . __('Thank you for your order, please click the button below to pay with JuanPay.', 'woocommerce') . '</p>';
            echo $this->generate_juanpay_form($order);
        }


        function juanpay_hash($params) {
            $API_Key = $this->api_key;
            $md5HashData = $API_Key;
            $hashedvalue = '';
            foreach($params as $key => $value) {
                if ($key<>'hash' && strlen($value) > 0) {
                    $md5HashData .= $value;
                }
            }
            if (strlen($API_Key) > 0) {
                $hashedvalue .= strtoupper(md5($md5HashData));
            }
            return $hashedvalue;
        }

        /**
         * Generate juanpay button link
         **/
        public function generate_juanpay_form($order_id)
        {

            global $woocommerce;
            $order = new WC_Order($order_id);

            if ( $this->testmode == 'yes' ):
                $juanpay_adr = $this->testurl . '/checkout';
            else :
                $juanpay_adr = $this->liveurl . '/checkout';
            endif;

            $redirect_url = ($this->redirect_page_id == "" || $this->redirect_page_id == 0) ? get_site_url() . "/" : get_permalink($this->redirect_page_id)."/?order=".$order_id."&key=".$order->order_key."&utm_nooverride=1";

            $juanpay_args = array(
                'email' => $this->email,
                'order_number' => $order_id.'_#&_'.date("ymds"),
                'confirm_form_option' => 'NONE',
                'buyer_first_name' => $order->billing_first_name,
                'buyer_last_name' => $order->billing_last_name,
                'buyer_email' => $order->billing_email,
                'buyer_cell_number' => $order->billing_phone,
                'return_url' => $redirect_url
            );

            // Cart Contents
            $item_loop = 0;
            if ( sizeof( $order->get_items() ) > 0 ) {
                foreach ( $order->get_items() as $item ) {
                    if ( $item['qty'] ) {

                        $item_loop++;

                        $product = $order->get_product_from_item( $item );

                        $item_name 	= $item['name'];

                        $item_meta = new WC_Order_Item_Meta( $item['item_meta'] );
                        if ( $meta = $item_meta->display( true, true ) )
                            $item_name .= ' ( ' . $meta . ' )';

                        $item_name = preg_replace('/\r|\n/m','',$item_name);
                        $juanpay_args[ 'item_name_' . $item_loop ] 	= html_entity_decode( $item_name, ENT_NOQUOTES, 'UTF-8' );
                        $juanpay_args[ 'qty_' . $item_loop ] 	= $item['qty'];
                        $juanpay_args[ 'price_' . $item_loop ] 		= $order->get_item_subtotal( $item, false );

                        if ( $product->get_sku() )
                            $juanpay_args[ 'item_number_' . $item_loop ] = $product->get_sku();
                    }
                }
            }

            ksort($juanpay_args);
            $hash = $this ->juanpay_hash($juanpay_args);
            $juanpay_args['hash'] = $hash;

            $juanpay_args_array = array();
            foreach ($juanpay_args as $key => $value) {
                $juanpay_args_array[] = "<input type='hidden' name='$key' value='$value'/>";
            }
            return '<form action="' . $juanpay_adr. '" method="post" id="juanpay_payment_form">
            ' . implode('', $juanpay_args_array) . '
            <input type="submit" class="button-alt" id="submit_juanpay_payment_form" value="' . __('Pay via JuanPay', 'woocommerce') . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Cancel order &amp; restore cart', 'woocommerce') . '</a>
            <script type="text/javascript">
jQuery(function(){
jQuery("body").block(
        {
            message: "'. __('Thank you for your order. We are now redirecting you to JuanPayment Gateway to make payment.', 'woocommerce') . '",
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
            cursor:         "wait",
            lineHeight:"32px"
    }
    });
    jQuery("#submit_juanpay_payment_form").click();});</script>
            </form>';


        }

        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);
            return array('result' => 'success', 'redirect' => add_query_arg('order',
                $order->id, add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
            );
        }


        function showMessage($content)
        {
            return '<div class="box ' . $this->msg['class'] . '-box">' . $this->msg['message'] . '</div>' . $content;
        }

        // get all pages
        function get_pages($title = false, $indent = true)
        {
            $wp_pages = get_pages('sort_column=menu_order');
            $page_list = array();
            if ($title) $page_list[] = $title;
            foreach ($wp_pages as $page) {
                $prefix = '';
                // show indented child pages?
                if ($indent) {
                    $has_parent = $page->post_parent;
                    while ($has_parent) {
                        $prefix .= ' - ';
                        $next_page = get_page($has_parent);
                        $has_parent = $next_page->post_parent;
                    }
                }
                // add to page list array array
                $page_list[$page->ID] = $prefix . $page->post_title;
            }
            return $page_list;
        }


        /**
         * Check JuanPay DPN validity
         **/
        function check_ipn_request_is_valid() {
            global $woocommerce;

            if ( 'yes' == $this->debug )
                $this->log->add( 'juanpay', 'Checking DPN response is valid...' );

            // Get recieved values from post data
            $received_values = array();
            $received_values += stripslashes_deep( $_POST );

            // Send back post vars to juanpay
            $params = array(
                'body' 			=> $received_values,
                'sslverify' 	=> false,
                'timeout' 		=> 60,
                'httpversion'   => '1.1',
                'headers'       => array( 'host' => 'www.juanpay.com' ),
                'user-agent'	=> 'WooCommerce/' . $woocommerce->version
            );

            if ( 'yes' == $this->debug )
                $this->log->add( 'juanpay', 'DPN Request: ' . print_r( $params, true ) );

            // Get url
            if ( $this->testmode == 'yes' )
                $juanpay_adr = $this->testurl;
            else
                $juanpay_adr = $this->liveurl;

            // Post back to get a response
            $response = wp_remote_post( $juanpay_adr."/dpn/validate", $params );

            if ( 'yes' == $this->debug )
                $this->log->add( 'juanpay', 'DPN Response: ' . print_r( $response, true ) );

            // check to see if the request was valid
            if ( ! is_wp_error( $response ) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 && ( strcmp( $response['body'], "VERIFIED" ) == 0 ) ) {
                if ( 'yes' == $this->debug )
                    $this->log->add( 'juanpay', 'Received valid response from JuanPay' );

                return true;
            }

            if ( 'yes' == $this->debug ) {
                $this->log->add( 'juanpay', 'Received invalid response from JuanPay' );
                if ( is_wp_error( $response ) )
                    $this->log->add( 'juanpay', 'Error response: ' . $response->get_error_message() );
            }

            return false;
        }


        /**
         * Check for JuanPay DPN Response
         *
         * @access public
         * @return void
         */
        function check_ipn_response() {

            @ob_clean();

            if ( ! empty( $_POST ) && $this->check_ipn_request_is_valid() ) {

                header( 'HTTP/1.1 200 OK' );

                do_action( "valid-juanpay-standard-ipn-request", $_POST );

            } else {
                $this->log->add( 'juanpay', 'JuanPay DPN Request Failure' );
                wp_die( "JuanPay DPN Request Failure" );
            }

        }

        function get_juanpay_order( $posted ) {
            $order_id = (int) substr( $posted['order_number'], 0, strpos($posted['order_number'], '_#&_'));
            if ( $this->debug=='yes' )
                $this->log->add( 'juanpay', 'Get JuanPay Order ID '.substr( $posted['order_number'], 0, strpos($posted['order_number'], '_#&_')));

            $order = new WC_Order( $order_id );

            if ( ! isset( $order->id ) ) {
                if ( $this->debug=='yes' )
                    $this->log->add( 'juanpay', 'Error: order id '.$order_id.' is not found' );
                exit;
            }

            return $order;
        }


        /**
         * Successful Payment!
         *
         * @access public
         * @param array $posted
         * @return void
         */
        function successful_request( $posted ) {
            global $woocommerce;

            $posted = stripslashes_deep( $posted );
            $order = $this->get_juanpay_order( $posted );
            if ( 'yes' == $this->debug )
                $this->log->add( 'juanpay', 'Successfull Request ' . print_r( $posted, true ) );

            if ($posted['status'] == 'Confirmed' || $posted['status'] == 'Underpaid') {
                // Check order not already completed
                if ( $order->status == 'completed') {
                    if ( 'yes' == $this->debug )
                        $this->log->add( 'juanpay', 'Aborting, Order #' . $order->id . ' is already complete.' );
                    return;
                } else {
                    $order->update_status( 'on-hold', sprintf( __( 'Order is Confirmed. Waiting for payment.', 'woocommerce' ), $posted['mc_gross'] ) );
                }
            }
            if ($posted['status'] == 'Paid' || $posted['status'] == 'Overpaid') {
                // Check order not already completed
                if ( $order->status == 'completed') {
                    if ( 'yes' == $this->debug )
                        $this->log->add( 'juanpay', 'Aborting, Order #' . $order->id . ' is already complete.' );
                    return;
                } else {
                    $order->update_status( 'processing', sprintf( __( 'Order is already paid. Please shipped Item(s)', 'woocommerce' ), $posted['mc_gross'] ) );
                    $order->payment_complete();
                }
            }

            if ($posted['status'] == 'Shipped') {
                $order->update_status( 'completed', sprintf( __( 'Order is already shipped. Marked Order to complete', 'woocommerce' ), $posted['mc_gross'] ) );
            }

            update_post_meta( $order->id, 'Status', $posted['status'] );
            update_post_meta( $order->id, 'Total', $posted['total'] );
            update_post_meta( $order->id, 'Ref. #', $posted['ref_number'] );
            update_post_meta( $order->id, 'Order Number', $posted['order_number'] );
            update_post_meta( $order->id, 'Message ID', $posted['message_id'] );
        }
    }

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_juanpay_gateway($methods)
    {
        $methods[] = 'WC_JuanPay';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_juanpay_gateway');
}
