<?php
/*
 * Plugin Name: Mispay WooCommerce
 * Plugin URI: https://mispay.co
 * Description: MISPay API Integration
 * Author: MisPay
 * Author URI: https://mispay.co
 * Version: 0.0.1
 * Text Domain: mispay-woocommerce
 * Domain Path: /languages
 */

require_once('MispayController.php');


add_action('plugins_loaded', 'mispay_init_gateway_class', 0);
function mispay_init_gateway_class()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    /*
    * Only Require our main class after WC_Payment_Gateway is initialized by WooCommerce
    */
    require_once('MispayPaymentGateway.php');
}

// Register MisPay as a Payment Gateway in WooCommerce 
function mispay_gateway_class($methods)
{
    $methods[] = 'WC_MisPay';
    return $methods;
}
add_filter('woocommerce_payment_gateways', 'mispay_gateway_class');

// Load translation files
add_action('plugins_loaded', 'load_mispay_translation');
function load_mispay_translation()
{
    load_plugin_textdomain('mispay-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

add_action('wp_head', 'add_mispay_sdk_script');
function add_mispay_sdk_script() {
    $options = get_option('woocommerce_mispay_settings');
    if (!empty($options['access_key']) && $options['enable_widget'] === 'yes') {
        $testmode = !empty($options['testmode']) && $options['testmode'] === 'yes';
        $widget_url = $testmode ? 'https://widget-sandbox.mispay.co/v1/sdk.js?authorize=' : 'https://widget.mispay.co/v1/sdk.js?authorize=';
        echo '<script defer src="' . esc_url($widget_url . $options['access_key']) . '"></script>';
    }
}

add_action('woocommerce_single_product_summary', 'add_mispay_widget_to_product_detail_page', 25);
function add_mispay_widget_to_product_detail_page() {
    global $product;

    $price = $product->get_price();

    $lang = 'en';
    if (strpos(get_locale(), 'ar') === 0) {
        $lang = 'ar';
    }

    echo '<mispay-widget amount="' . esc_attr($price) . '" lang="' . esc_attr($lang) . '"></mispay-widget>';
}


add_action('wc_payment_gateways_initialized','add_mispay_widget_to_cart_detail_page', 25);
function add_mispay_widget_to_cart_detail_page() {
    if (is_cart()) {
        $cart_total = WC()->cart->total;

        $lang = 'en';
        if (strpos(get_locale(), 'ar') === 0) {
            $lang = 'ar';
        }

        echo '<mispay-widget amount="' . esc_attr($cart_total) . '" lang="' . esc_attr($lang) . '"></mispay-widget><br>';
    }
}
