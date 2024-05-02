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
