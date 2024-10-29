<?php
/**
 * Plugin Name: Accept Bitcoin
 * Description: A payment gateway for WooCommerce that allows you to accept Bitcoin as a payment method. Easy to use. No registration, no middleman, no trusted third party.
 * Version: 0.6.1
 * Author: Bitonymous
 * License: GNU GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: accept-bitcoin
 */

function init_accept_bitcoin_class() {

    // Bail if WooCommerce is not active
    if( ! class_exists('WC_Payment_Gateway' ) ) {
        return;
    }

    class WC_Gateway_Accept_Bitcoin extends WC_Payment_Gateway {

        function __construct() {
            
            // Set default settings
            $this->default_settings = array(
                'enabled'       => 'no',
                'title'         => __('Bitcoin', 'accept-bitcoin'),
                'description'   => __('Pay using Bitcoin.', 'accpept-bitcoin'),
                'btc_address'   => '',
            );

            if( ! get_option('woocommerce_accept_bitcoin_settings' ) ) {
                update_option('woocommerce_accept_bitcoin_settings', $this->default_settings);
            }

            // Define payment gateway variables
            $this->id = 'accept_bitcoin'; // Unique ID for payment gateway
            $this->icon = apply_filters('accept_bitcoin_icon', plugin_dir_url(__FILE__) . 'img/bitcoin-symbol.png');
            $this->has_fields = false; // Don't add any fields to checkout
            $this->method_title = __('Accept Bitcoin', 'accept-bitcoin'); // Title of the payment method shown on the admin page.
            $this->method_description = __('Accept Bitcoin payments.', 'accept-bitcoin'); // Description of the payment method shown on the admin page.
            
            // Define user set variables.
            $this->title = get_option('woocommerce_accept_bitcoin_settings')['title'] !== '' ? get_option('woocommerce_accept_bitcoin_settings')['title'] : $this->default_settings['title'];
            $this->description  = get_option('woocommerce_accept_bitcoin_settings')['description'] !== '' ? get_option('woocommerce_accept_bitcoin_settings')['description'] : $this->default_settings['description'];

            // Load settings fields
            $this->init_form_fields();

            // Add a save hook for our settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options') );

            // Add payment instructions to order thank you page
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'render_thank_you_page_content') );

            // Modify "order received" message on thank you page
            add_filter('woocommerce_thankyou_order_received_text', array($this, 'modify_order_received_text'), 10, 2);

        }


        /**
         * Define payment gateway settings fields
         *
         * @return void
         */
        function init_form_fields() {

            $settings = get_option('woocommerce_accept_bitcoin_settings');

            $this->form_fields = array(

                'enabled' => array(
                    'title'         => __('Enable/Disable', 'woocommerce'),
                    'type'          => 'checkbox',
                    'label'         => __('Enable Accept Bitcoin', 'accept-bitcoin'),
                    'default'       => $settings['enabled'],
                ),
                'title' => array(
                    'title'         => __('Title', 'woocommerce'),
                    'type'          => 'text',
                    'description'   => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default'       => $settings['title'],
                    'desc_tip'      => true,
                    'placeholder'   => $this->default_settings['title'],
                ),
                'description'       => array(
                    'title'         => __('Description', 'woocommerce'),
                    'type'          => 'text',
                    'description'   => __('Payment method description that the customer will see on your checkout.', 'woocommerce'),
                    'desc_tip'      => true,
                    'default'       => $settings['description'],
                    'placeholder'   => $this->default_settings['description'],
                ),

                'bitcoin_settings'  => array(
                    'title'         => __('Bitcoin settings', 'accept-bitcoin'),
                    'type'          => 'title',
                ),
                'btc_address'       => array(
                    'title'         => __('Bitcoin Address', 'accept-bitcoin'),
                    'type'          => 'text',
                    'description'   => __('Enter your Bitcoin wallet address that you want your customers to send Bitcoin to. This field is required.', 'woocommerce'),
                    'desc_tip'      => false,
                    'default'       => $settings['btc_address'],
                    'placeholder'   => '',
                ),

            );
        }
        

        /**
         * Process the submitted settings form
         *
         * @return void
         */
        function process_admin_options() {

            $settings = get_option('woocommerce_accept_bitcoin_settings');

            if( isset( $_POST['woocommerce_accept_bitcoin_enabled'] ) ) {
                $settings['enabled'] = 'yes';
            } else {
                $settings['enabled'] = 'no';
            }

            if( isset( $_POST['woocommerce_accept_bitcoin_title'] ) ) {
                $settings['title'] = sanitize_text_field($_POST['woocommerce_accept_bitcoin_title'] );
            }

            if( isset( $_POST['woocommerce_accept_bitcoin_description'] ) ) {
                $settings['description'] = sanitize_text_field($_POST['woocommerce_accept_bitcoin_description'] );
            }

            if( isset( $_POST['woocommerce_accept_bitcoin_btc_address'] ) ) {
                $settings['btc_address'] = sanitize_text_field($_POST['woocommerce_accept_bitcoin_btc_address'] );
            }

            update_option('woocommerce_accept_bitcoin_settings', $settings);

        }


        /**
         * Process payment after the checkout form is submitted
         *
         * @param integer $order_id
         * @return array
         */
        function process_payment( $order_id ) {

            global $woocommerce;
            $order = new WC_Order( $order_id );

            // If order sum is 0 or less, mark the payment as complete
            if( $order->get_total() <= 0 ) {

                $order->payment_complete();

            } else {

                // Mark as on-hold (we're awaiting payment)
                $order->update_status('on-hold', __('Awaiting Bitcoin payment', 'accept-bitcoin'));

                // Convert to BTC
                $currency = $order->get_currency();
                $amount = $order->get_total();
                $btc_amount = $this->convert_to_btc($currency, $amount);
                $exchange_rate = $amount / $btc_amount;

                // Use BTC address provided by store owner
                $btc_address = get_option('woocommerce_accept_bitcoin_settings')['btc_address'];

                // Store information as post meta
                update_post_meta($order_id, '_accept_bitcoin_btc_address', $btc_address);
                update_post_meta($order_id, '_accept_bitcoin_btc_amount', $btc_amount);
                update_post_meta($order_id, '_accept_bitcoin_exchange_rate', $exchange_rate);

                // Add a order note with information
                $order->add_order_note( sprintf(
                    __('%s %s converted to %s BTC (exchange rate %s), which should be paid to %s.', 'accept-bitcoin'),
                    $amount,
                    $currency,
                    $btc_amount,
                    $exchange_rate,
                    $btc_address
                ), $is_customer_note = 1 );

            }
            
            // Empty the cart
            $woocommerce->cart->empty_cart();
        
            // Return thank you redirect
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url( $order )
            );

        }


        /**
         * Convert the order currency to Bitcoin (BTC)
         *
         * @param string $currency
         * @param mixed $amount
         * @return integer|float
         */
        function convert_to_btc( $currency, $amount ) {

            // We use blockchain.info's conversion API to convert fiat to BTC
            $url = 'https://blockchain.info/tobtc?currency=' . $currency . '&value=' . $amount;

            $response = wp_remote_get( esc_url_raw( $url ) );
            $response_body = wp_remote_retrieve_body( $response );

            $btc_amount = floatval( $response_body );
    
            return $btc_amount;

        }


        /**
         * Generates a QR code based on given wallet address and amount
         *
         * @param string $btc_address
         * @param mixed $btc_amount
         * @return string
         */
        function get_qr_code_url( $btc_address, $btc_amount ) {

            $qr_code_data = 'bitcoin:' . $btc_address . '?amount=' . $btc_amount;
            $size = '300x300';

            // We use Google's chart API to generate a QR code
            $qr_code_url = 'https://chart.googleapis.com/chart?cht=qr&chs=' . $size . '&chl=' . $qr_code_data;
            
            return $qr_code_url;

        }


        /**
         * Render content for the thank you page
         *
         * @param integer $order_id
         * @return void
         */
        function render_thank_you_page_content( $order_id ) {

            $btc_address = get_post_meta($order_id, '_accept_bitcoin_btc_address', true);
            $btc_amount = get_post_meta($order_id, '_accept_bitcoin_btc_amount', true);
            $exchange_rate = get_post_meta($order_id, '_accept_bitcoin_exchange_rate', true);

            ob_start();

            // Check for template file in theme folder, or use default template if it doesn't exist
            if( file_exists( get_stylesheet_directory() . '/accept-bitcoin/thankyou-content.php' ) ) {
                require( get_stylesheet_directory() . '/accept-bitcoin/thankyou-content.php' );
            } else {
                require( 'templates/thankyou-content.php' );
            }
            
            $html = ob_get_contents();

            ob_end_clean();

            echo $html;
            
        }


        /**
         * Checks required settings and returns true if met
         *
         * @return boolean
         */
        public static function has_required_settings() {

            $settings = get_option('woocommerce_accept_bitcoin_settings');

            if( $settings['btc_address'] === '' ) {
                return false;
            }

            return true;

        }

        
        /**
         * Modify the "order received" message on the thank you page
         *
         * @param [type] $var
         * @param object $order
         * @return string
         */
        function modify_order_received_text( $var, $order ) {

            if( $order->get_status() === 'on-hold' && $order->get_payment_method() == 'accept_bitcoin' ) {
                return __( 'Your order has been received. See payment instructions below to complete your purchase.', 'accept-bitcoin' );
            }

            return __( 'Thank you. Your order has been received.', 'woocommerce' );

        }

    }

}
add_action('plugins_loaded', 'init_accept_bitcoin_class');


/**
 * Tell WooCommerce that our payment method exists, only if required settings are set
 *
 * @param array $methods
 * @return array
 */
function add_accept_bitcoin_class( $methods ) {
    if( is_admin() || WC_Gateway_Accept_Bitcoin::has_required_settings() ) {
        $methods[] = 'WC_Gateway_Accept_Bitcoin';
    }
    return $methods;
}
add_filter('woocommerce_payment_gateways', 'add_accept_bitcoin_class');


/**
 * Check if WooCommerce is active when we activate our plugin, if not we bail
 *
 * @return void
 */
function accept_bitcoin_check_for_woocommerce() {

    if( ! class_exists('WC_Payment_Gateway' ) ) {
        wp_die( sprintf( __('Sorry, this plugin requires WooCommerce to be installed and active. <br><a href="%s">&laquo; Return to Plugins</a>', 'accept-bitcoin'), admin_url('plugins.php') ) );
    }

}
register_activation_hook( __FILE__, 'accept_bitcoin_check_for_woocommerce');


/**
 * Add a Settings link to the list of plugins
 *
 * @param array $links
 * @return array
 */
function accept_bitcoin_add_settings_link( $links ) {

    $settings_link = array(
        'settings' => '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=accept_bitcoin') . '">' . __('Settings', 'accept-bitcoin') . '</a>'
    );

    $links = array_merge( $settings_link, $links );
	
	return $links;

}
add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'accept_bitcoin_add_settings_link');


/**
 * Undocumented function
 *
 * @param array $links
 * @param string $file
 * @return array
 */
function accept_bitcoin_add_donate_link( $links, $file ) {

	if( strpos( $file, plugin_basename(__FILE__ ) ) !== false ) {

        $donate_link = array(
            'donate' => '<a href="bitcoin:bc1qacrp5lpw75y4z34suaxeqpzhsj742hcfpvjqff">' . __('Donate', 'accept-bitcoin') . '</a>'
        );
    
        $links = array_merge( $links, $donate_link );

	}
	
	return $links;
    
}
add_filter('plugin_row_meta', 'accept_bitcoin_add_donate_link', 10, 2 );