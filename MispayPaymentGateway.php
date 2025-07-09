<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

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
        $plugin_name = 'MISPay';
        $this->id = 'mispay';
        $this->has_fields = true;
        $this->method_title = 'MISPay';
        $this->method_description = __('MISPay API integration for WooCommerce', 'mispay-woocommerce');

        // Add support for more WooCommerce features
        $this->supports = array(
            'products',
            'refunds',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'multiple_subscriptions'
        );

        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }

        $isArabic = (get_locale() === 'ar' || get_locale() === 'ar_SA');

        $this->title = $isArabic ? $this->get_option('titleAR') : $this->get_option('titleEN');
        $this->description = $isArabic ? $this->get_option('descriptionAR') : $this->get_option('descriptionEN');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');

        if($this->get_option('showIcon') === 'yes') {
            $this->icon = plugin_dir_url(__FILE__) . 'assets/logo.svg';
        }

        $this->app_secret = $this->get_option('app_secret');
        $this->app_id = $this->get_option('app_id');

        if (empty($this->app_id) || empty($this->app_secret)) {
            error_log('MISPay Error: App ID or App Secret is empty.');
        }

        $this->MisPayController = new MisPayController($this->testmode, $this->app_id, $this->app_secret);

        add_action('init', array($this, 'register_mispay_callback_endpoint'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
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
        $response = $_GET['_'];

        if (empty($response)) {
            wc_add_notice(__('Invalid callback response.', 'mispay-woocommerce'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        $decodeResponse = $this->MisPayController->decrypt(base64_decode($response));
        $order = wc_get_order($decodeResponse['orderId']);

        if (!$order) {
            wc_add_notice(__('Invalid order ID.', 'mispay-woocommerce'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        // Log the callback received
        $this->log_callback_response($order, $decodeResponse);

        // Only use the decoded response to determine success/failure
        if (isset($decodeResponse['code']) && $decodeResponse['code'] === 'MP00') {
            // Update order status to 'Processing' for successful MISPay payments
            $order->update_status('processing', __('MISPay payment completed successfully - order moved to processing.', 'mispay-woocommerce'));
            wp_redirect($this->get_return_url($order));
            exit;
        } else {
            $order->update_status('cancelled', __('Canceled', 'woocommerce'));
            wc_add_notice(__('Payment failed or was canceled.', 'mispay-woocommerce'), 'error');
            wp_redirect($order->get_cancel_order_url());
            exit;
        }
    }

    /**
     * Log callback response to order meta
     */
    private function log_callback_response($order, $callback_data)
    {
        $log_entry = array(
            'timestamp' => current_time('Y-m-d H:i:s'),
            'status' => 'callback_received',
            'message' => 'Callback received from MISPay',
            'details' => array(
                'code' => $callback_data['code'] ?? 'unknown',
                'order_id' => $callback_data['orderId'] ?? 'unknown',
                'checkout_id' => $callback_data['checkoutId'] ?? 'unknown',
                'raw_data' => $callback_data
            )
        );
        
        // Get existing logs
        $existing_logs = $order->get_meta('mispay_transaction_logs');
        $logs = is_array($existing_logs) ? $existing_logs : array();
        
        // Add new log entry
        $logs[] = $log_entry;
        
        // Keep only last 20 entries
        if (count($logs) > 20) {
            $logs = array_slice($logs, -20);
        }
        
        $order->update_meta_data('mispay_transaction_logs', $logs);
        $order->save();
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'api_credentials' => array(
                'title' => __('API Credentials', 'mispay-woocommerce'),
                'type' => 'title',
                'description' => __('Enter your API credentials to connect with MISPay services. App ID and App Secret will be provide by MISPay Team', 'mispay-woocommerce'),
                'id' => 'api_credentials',
            ),
            'app_id' => array(
                'title' => 'App ID',
                'type' => 'text',
                'description' => __('Enter your MISPay application ID. This is required to authenticate your store with MISPay.', 'mispay-woocommerce'),
                'desc_tip' => true,
            ),
            'app_secret' => array(
                'title' => 'App Secret',
                'type' => 'password',
                'description' => __('Enter your MISPay application secret. This is required to authenticate your store with MISPay securely.', 'mispay-woocommerce'),
                'desc_tip' => true,
            ),
            'api_credentials_space' => array(
                'type' => 'title',
                'description' => '<br>',
                'id' => 'api_credentials_space',
            ),

            'general_settings' => array(
                'title' => __('General Settings', 'mispay-woocommerce'),
                'type' => 'title',
                'description' => __('Configure the general settings for MISPay.', 'mispay-woocommerce'),
                'id' => 'general_settings'
            ),
            'enabled' => array(
                'title' => __('Enable/Disable', 'mispay-woocommerce'),
                'label' => __('Enable MISPay', 'mispay-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Check this box to enable or disable the MISPay payment gateway.', 'mispay-woocommerce'),
                'default' => 'no',
                'desc_tip' => true,
            ),
            'testmode' => array(
                'title' => __('Test mode', 'mispay-woocommerce'),
                'label' => __('Enable Test Mode', 'mispay-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in test mode using test API keys.', 'mispay-woocommerce'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'showIcon' => array(
                'title' => __('MISPay Logo', 'mispay-woocommerce'),
                'label' => __('Show/Hide', 'mispay-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Toggle to display or hide the MISPay logo at checkout.'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'general_settings_space' => array(
                'type' => 'title',
                'description' => '<br>',
                'id' => 'general_settings_space',
            ),

            'widget_settings' => array(
                'title' => __('Widget Settings', 'mispay-woocommerce'),
                'type' => 'title',
                'description' => __('Configure the settings for the MISPay widget.', 'mispay-woocommerce'),
                'id' => 'widget_settings'
            ),
            'enable_widget' => array(
                'title' => __('Enable/Disable Widget', 'mispay-woocommerce'),
                'label' => __('Enable MISPay Widget', 'mispay-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Check this box to enable or disable the MISPay widget on your site.', 'mispay-woocommerce'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'access_key' => array(
                'title' => 'Access Key',
                'type' => 'text',
                'description' => 'Access key for MISPay widget authorization.',
                'default' => '',
                'desc_tip' => true,
            ),
            'widget_settings_space' => array(
                'type' => 'title',
                'description' => '<br>',
                'id' => 'widget_settings_space',
            ),

            'title_description_settings' => array(
                'title' => __('Title and Description Settings', 'mispay-woocommerce'),
                'type' => 'title',
                'description' => __('Customize the titles and descriptions for MISPay displayed on the checkout page.', 'mispay-woocommerce'),
                'id' => 'title_description_settings'
            ),
            'titleEN' => array(
                'title' => __('MISPay Title(English)', 'mispay-woocommerce'),
                'label' => __('Title', 'mispay-woocommerce'),
                'type' => 'textarea',
                'description' => "Payment method title that the customer will see on your checkout.",
                'default' => 'Buy now then pay it later with MISpay',
                'desc_tip' => true,
            ),
            'titleAR' => array(
                'title' => __('MISPay Title(Arabic)', 'mispay-woocommerce'),
                'label' => __('Title', 'mispay-woocommerce'),
                'type' => 'textarea',
                'description' => "Payment method title that the customer will see on your checkout.",
                'default' =>  'اشتر الان وقسطها لاحقا مع MISpay',
                'desc_tip' => true,
            ),
            'descriptionEN' => array(
                'title' => 'MISPay Description(English)',
                'type' => 'textarea',
                'description' => 'Payment method description that the customer will see on your checkout.',
                'default' => 'Split your purchase into 3 interest-free payments, No late fees. sharia-compliant',
                'desc_tip' => true,
            ),
            'descriptionAR' => array(
                'title' => 'MISPay Description(Arabic)',
                'type' => 'textarea',
                'description' => 'Payment method description that the customer will see on your checkout.',
                'default' => 'قسم مشترياتك إلى 3 دفعات بدون فوائد، بدون رسوم تأخير متوافقة مع أحكام الشريعة الإسلامية',
                'desc_tip' => true,
            ),
        );
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            error_log('MISPay Error: Invalid order ID in process_payment: ' . $order_id);
            wc_add_notice(__('An error occurred while processing your payment.', 'mispay-woocommerce'), 'error');
            return array(
                'result' => 'failure',
                'redirect' => wc_get_checkout_url()
            );
        }

        $order->update_status('pending', __('Pending payment', 'woocommerce'));
        $order->update_meta_data('Payment_method', 'MISPay');
        $order->save_meta_data();
        
        $startCheckout = $this->MisPayController->start_checkout($order->get_id());
        if ($startCheckout) {
            return array(
                'result' => 'success',
                'redirect' => $startCheckout,
            );
        } else {
            $order->update_status('failed', __('Failed to start checkout process', 'mispay-woocommerce'));

            // Display a generic error message on the checkout page.
            wc_add_notice(__('There was an error while processing your request. Please check any charges in your payment method and review your order history before placing an order again.', 'mispay-woocommerce'), 'error');
            
            return array(
                'result' => 'failure',
                'redirect' => wc_get_checkout_url()
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

        $order = wc_get_order($decodeResponse['orderId']);

        if (!$order) {
            error_log('MISPay Error: Invalid order ID in handle_callback: ' . $decodeResponse['orderId']);
            return array(
                'result' => 'failed',
                'orderId' => null,
                'message' => __('Invalid order ID', 'mispay-woocommerce')
            );
        }

        if (!isset($decodeResponse['code']) || $decodeResponse['code'] !== 'MP00') {
            $order->update_status('cancelled', __('Canceled', 'woocommerce'));
            return array(
                'result' => 'failed',
                'orderId' => $order->get_id(),
                'message' => $decodeResponse['code'] === 'MP02' ? __('Payment was canceled', 'mispay-woocommerce') : __('Timeout while processing payment.', 'mispay-woocommerce')
            );
        } else {
            // Update order status to 'Processing' for successful MISPay payments
            $order->update_status('processing', __('MISPay payment completed successfully - order moved to processing.', 'mispay-woocommerce'));
            return array(
                'result' => 'success',
                'orderId' => $order->get_id(),
                'checkoutId' => $decodeResponse['checkoutId']
            );
        }
    }
}
