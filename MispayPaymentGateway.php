<?php

include_once('MispayController.php');


class WC_MisPay extends WC_Payment_Gateway
{
    private $MisPayController;

    // Payment Admin Fields
    public $id, $icon, $has_fields, $method_title, $method_description, $supports;

    // MisPay Required Fields
    public $title, $description, $enabled,  $testmode, $app_secret, $app_id;

    public function __construct()
    {

        $this->id = 'mispay';
        $this->icon = 'https://cdn.mispay.co/widget/assets/logo.svg';
        $this->has_fields = true;
        $this->method_title = 'MISPay';
        $this->method_description = __('MISPay API integration for WooCommerce', 'mispay-woocommerce');


        $this->supports = array(
            'products'
        );


        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');

        $this->app_secret = $this->get_option('app_secret');
        $this->app_id = $this->get_option('app_id');

        $this->MisPayController = new MisPayController($this->testmode, $this->app_id, $this->app_secret);

        add_action('init', array($this, 'register_mispay_callback_endpoint'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_checkout_process', array($this, 'process_payment'));
        add_action('woocommerce_api_mispay-callback', array($this, 'handle_mispay_callback'));
    }


    /*
	* Registers our callbackURL to Wordpress as domain/wc-api/mispay-callback
	*/
    function register_mispay_callback_endpoint()
    {
        add_rewrite_endpoint('mispay-callback', EP_ROOT | EP_PAGES);
    }

    /*
	* Registers our callbackURL to Wordpress as domain/wc-api/mispay-callback
	*/
    function handle_mispay_callback()
    {
        $WCMisPay = new WC_MisPay();
        $paymentStatus = $WCMisPay->handle_callback();
        $checkout_url = wc_get_checkout_url();

        if ($paymentStatus['result'] === 'success') {
            $this->MisPayController->end_checkout($paymentStatus['checkoutId']);
            return wp_safe_redirect($checkout_url . 'order-received/' . $paymentStatus['orderId']);
        } else {
            wc_add_notice($paymentStatus['message'], 'error');
            return wp_safe_redirect($checkout_url);
        }
    }

    public function init_form_fields()
    {

        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'mispay-woocommerce'),
                'label' => __('Enable MISPay', 'mispay-woocommerce'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no'
            ),
            'testmode' => array(
                'title' => __('Test mode', 'mispay-woocommerce'),
                'label' => __('Enable Test Mode', 'mispay-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in test mode using test API keys.', 'mispay-woocommerce'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'app_id' => array(
                'title' => 'App ID',
                'type' => 'text'
            ),
            'app_secret' => array(
                'title' => 'App Secret',
                'type' => 'password'
            )
        );
    }

    public function process_payment($order_id)
    {

        echo $this->app_id;
        $order = new WC_Order($order_id);

        $order->update_status('on-hold', __('Awaiting cheque payment', 'woocommerce'));
        $startCheckout = $this->MisPayController->start_checkout($order->get_id(), $order->total);
        if ($startCheckout) {
            return array(
                'result' => 'success',
                'redirect' => $startCheckout,
            );
        }
    }

    public function handle_callback()
    {

        $response = $_GET['_'];

        if (empty($response)) {
            return array(
                'result' => 'failed',
                'orderId' => null
            );
        }

        $decodeResponse = $this->MisPayController->decrypt(base64_decode($response));

        $order = new WC_Order($decodeResponse['orderId']);

        if (!isset($decodeResponse['code']) || $decodeResponse['code'] !== 'MP00') {
            $order->update_status('cancelled', __('Canceled', 'woocommerce'));
            return array(
                'result' => 'failed',
                'orderId' => $order->get_id(),
                'message' => $decodeResponse['code'] === 'MP02' ? __('Payment was canceled', 'mispay-woocommerce') : __('Timeout while processing payment.', 'mispay-woocommerce')
            );
        } else {
            $order->payment_complete();
            return array(
                'result' => 'success',
                'orderId' => $order->get_id(),
                'checkoutId' => $decodeResponse['checkoutId']
            );
        }
    }
}
