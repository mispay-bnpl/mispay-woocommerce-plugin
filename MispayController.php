<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class MisPayController
{
    private $SANDBOX_API_URL = 'https://api-sandbox.mispay.dev/v1/api/';
    private $PROD_API_URL = 'https://api.mispay.co/v1/api/';
    private $API_URL;
    private $APP_ID;
    private $APP_SECRET;
    private $ACCESS_TOKEN;
    private $TOKEN_EXPIRY;

    public function __construct($isSandbox, $appId, $appSecret)
    {
        $this->API_URL = $isSandbox ? $this->SANDBOX_API_URL : $this->PROD_API_URL;
        $this->APP_ID = $appId;
        $this->APP_SECRET = $appSecret;
        $this->ACCESS_TOKEN = null;
        $this->TOKEN_EXPIRY = null;
    }

    /**
     * Get a fresh access token, ensuring it's valid and not expired
     */
    function get_access_token()
    {
        // Check if we have a valid token that hasn't expired
        if ($this->is_token_valid()) {
            return $this->ACCESS_TOKEN;
        }

        // Clear any existing token
        $this->ACCESS_TOKEN = null;
        $this->TOKEN_EXPIRY = null;

        $api_url = $this->API_URL . 'token';

        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'x-app-id' => $this->APP_ID,
                'x-app-secret' => $this->APP_SECRET,
                'accept' => 'application/json',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            error_log('MISPay Error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('MISPay Error: Invalid response code ' . $response_code);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['status']) || $data['status'] !== 'success') {
            error_log('MISPay Error: Invalid response data');
            return false;
        }

        $encryptedAccessToken = $data['result']['token'];
        $decryptedToken = $this->decrypt($encryptedAccessToken);

        if (!empty($decryptedToken['token'])) {
            $this->ACCESS_TOKEN = $decryptedToken['token'];
            // Set token expiry to 1 hour from now (assuming tokens expire in 1 hour)
            $this->TOKEN_EXPIRY = time() + (60 * 60);
            return $decryptedToken['token'];
        } else {
            error_log('MISPay Error: Failed to decrypt token');
            return false;
        }
    }

    /**
     * Check if the current token is valid and not expired
     */
    private function is_token_valid()
    {
        if (empty($this->ACCESS_TOKEN) || empty($this->TOKEN_EXPIRY)) {
            return false;
        }

        // Check if token has expired (with 5 minute buffer)
        if (time() >= ($this->TOKEN_EXPIRY - 300)) {
            return false;
        }

        return true;
    }

    /**
     * Ensure we have a valid token before making API requests
     */
    private function ensure_valid_token()
    {
        $token = $this->get_access_token();
        if (!$token) {
            // Try one more time with fresh token request
            $this->ACCESS_TOKEN = null;
            $this->TOKEN_EXPIRY = null;
            $token = $this->get_access_token();
            
            if (!$token) {
                throw new Exception('Failed to obtain valid access token after retry');
            }
        }
        return $token;
    }

    /**
     * Refresh token
     */
    private function refresh_token()
    {
        error_log('MISPay: Attempting to refresh token');
            
            $token = $this->get_access_token();
            if ($token) {
                error_log('MISPay: Token refreshed successfully');
                return $token;
            }
            
        error_log('MISPay Error: Failed to refresh token');
        return false;
    }

    /**
     * Handle API response and check for token-related errors
     */
    private function handle_api_response($response, $operation = 'API request')
    {
        if (is_wp_error($response)) {
            error_log('MISPay Error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        
        // Handle token-related errors
        if ($response_code === 401) {
            error_log('MISPay Error: Token expired or invalid, attempting to refresh token');
            // Clear current token and try to get a new one
            $this->ACCESS_TOKEN = null;
            $this->TOKEN_EXPIRY = null;
            
            // Try to refresh token
            $new_token = $this->refresh_token();
            if (!$new_token) {
                error_log('MISPay Error: Failed to refresh token for ' . $operation);
                return false;
            }
            
            // Return false to indicate the calling method should retry the request
            return false;
        }
        
        // Define success codes. 201 is valid for start-checkout.
        $success_codes = [200];
        if ($operation === 'start-checkout') {
            $success_codes[] = 201;
        }
        
        if (!in_array($response_code, $success_codes)) {
            error_log('MISPay Error: Invalid response code ' . $response_code . ' for ' . $operation);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['status']) || $data['status'] !== 'success') {
            error_log('MISPay Error: Invalid response data for ' . $operation);
            return false;
        }

        return $data;
    }

    function start_checkout($orderId)
    {
            try {
                // Ensure we have a fresh token
                $this->ensure_valid_token();
            } catch (Exception $e) {
                error_log('MISPay Error: ' . $e->getMessage());
                    return false;
            }
            
            // Get WooCommerce order object
            $order = wc_get_order($orderId);
            if (!$order) {
                error_log('MISPay Error: Invalid order ID ' . $orderId);
                return false;
            }

            $api_url = $this->API_URL . 'start-checkout';

            // Get order items
            $items = array();
            foreach ($order->get_items() as $item) {
                $items[] = array(
                'quantity' => (string) $item->get_quantity(),
                'unitPrice' => (string) $order->get_item_subtotal($item, false),
                    'nameEnglish' => $item->get_name()
                );
            }

            // Calculate components for purchaseAmount
            $totalPrice = (float) $order->get_subtotal();
            $shippingAmount = (float) $order->get_shipping_total();
            $vat = (float) $order->get_total_tax();
            $discount = (float) $order->get_total_discount();

            // Calculate purchaseAmount according to formula
            $purchaseAmount = $totalPrice + $shippingAmount + $vat - $discount;

            // Get customer phone from order
            $billing_phone = $order->get_billing_phone();
            // Format phone number if needed
            $formatted_phone = preg_match('/^(966|\+966|0)?[5][0-9]{8}$/', $billing_phone) ? $billing_phone : '';
        $billing_email = $order->get_billing_email();

            $payload = json_encode(array(
                'version' => 'v1.2',
                'orderId' => (string) $orderId,
            'purchaseAmount' => (string) $purchaseAmount,
            'totalPrice' => (string) $totalPrice,
            'shippingAmount' => (string) $shippingAmount,
            'vat' => (string) $vat,
            'discount' => [
                'amount' => (string) $discount,
            ],
                'purchaseCurrency' => $order->get_currency(),
                'lang' => get_locale() === 'ar' ? 'ar' : 'en',
                'customerDetails' => array(
                'mobileNumber' => $formatted_phone,
                'email' => $billing_email
                ),
                'orderDetails' => array(
                    'items' => $items
                )
            ));

            $response = wp_remote_post($api_url, array(
                'body'    => $payload,
                'headers' => array(
                    'x-app-id' => $this->APP_ID,
                    'Authorization' => 'Bearer ' . $this->ACCESS_TOKEN,
                    'Content-Type' => 'application/json',
                    'accept' => 'application/json',
                ),
                'timeout' => 30,
            ));

            $data = $this->handle_api_response($response, 'start-checkout');
        
        // If the first call fails, it might be a token issue. handle_api_response attempts a refresh.
        // We will try the call one more time with the (potentially) new token.
            if (!$data) {
            error_log('MISPay: Retrying start-checkout once after potential token refresh.');
            $response = wp_remote_post($api_url, array(
                'body'    => $payload,
                'headers' => array(
                    'x-app-id' => $this->APP_ID,
                    'Authorization' => 'Bearer ' . $this->ACCESS_TOKEN,
                    'Content-Type' => 'application/json',
                    'accept' => 'application/json',
                ),
                'timeout' => 30,
            ));

            $data = $this->handle_api_response($response, 'start-checkout');

        }

        if (!$data) {
                return false;
            }

            // Save trackId to order meta
            $order->update_meta_data('mispay_track_id', $data['result']['trackId']);
            $order->save();

            // Log the initial transaction creation
            $this->log_transaction_status($order, 'created', array(
                'track_id' => $data['result']['trackId'],
                'redirect_url' => $data['result']['url'],
                'message' => 'Checkout session created successfully'
            ));

        // Clean up the URL by removing escaped slashes
        $redirect_url = str_replace('\\/', '/', $data['result']['url']);
        
        return $redirect_url;
    }

    function track_checkout($orderId)
    {
            try {
                // Ensure we have a fresh token
                $this->ensure_valid_token();
            } catch (Exception $e) {
                error_log('MISPay Error: ' . $e->getMessage());
                    return false;
            }
            
            // Get WooCommerce order object
            $order = wc_get_order($orderId);
            if (!$order) {
                error_log('MISPay Error: Invalid order ID ' . $orderId);
                return false;
            }

            // Get the trackId from order meta
            $track_id = $order->get_meta('mispay_track_id');
            if (empty($track_id)) {
                error_log('MISPay Error: No track ID found for order ' . $orderId);
                return false;
            }

            $api_url = $this->API_URL . 'track-checkout/' . $track_id;
            
            $response = wp_remote_get($api_url, array(
                'headers' => array(
                    'x-app-id' => $this->APP_ID,
                    'Authorization' => 'Bearer ' . $this->ACCESS_TOKEN,
                    'Content-Type' => 'application/json',
                    'accept' => 'application/json',
                ),
                'timeout' => 30,
            ));

            $data = $this->handle_api_response($response, 'track-checkout');
        
        // If the first call fails, it might be a token issue. handle_api_response attempts a refresh.
        // We will try the call one more time with the (potentially) new token.
        if (!$data) {
            error_log('MISPay: Retrying track-checkout once after potential token refresh.');
            $response = wp_remote_get($api_url, array(
                'headers' => array(
                    'x-app-id' => $this->APP_ID,
                    'Authorization' => 'Bearer ' . $this->ACCESS_TOKEN,
                    'Content-Type' => 'application/json',
                    'accept' => 'application/json',
                ),
                'timeout' => 30,
            ));
            $data = $this->handle_api_response($response, 'track-checkout');

            if (!$data) {
                return false;
            }
            }

            $checkout_status = $data['result']['status'];

            // Log the transaction status update
            $this->log_transaction_status($order, $checkout_status, $data['result']);

            // If we get a final status, return the result
            if (in_array($checkout_status, ['success', 'canceled', 'error'])) {
                // Clear the scheduled cron job if it exists
                wp_clear_scheduled_hook('mispay_track_checkout_cron', array($orderId));
                return $data['result'];
            }

            // If status is still pending or in-progress, schedule next check if not already scheduled
            if (in_array($checkout_status, ['pending', 'in-progress'])) {
                $start_time = $order->get_meta('mispay_tracking_start_time');
                if (empty($start_time)) {
                    $start_time = time();
                    $order->update_meta_data('mispay_tracking_start_time', $start_time);
                    $order->save();
                }

                // Check if we've exceeded 24 hours
                if ((time() - $start_time) > (24 * 60 * 60)) {
                    wp_clear_scheduled_hook('mispay_track_checkout_cron', array($orderId));
                    error_log('MISPay Error: Tracking timeout exceeded 24 hours for order ' . $orderId);
                    
                    // Log timeout error
                    $this->log_transaction_status($order, 'timeout', array(
                        'message' => 'Tracking timeout exceeded 24 hours',
                        'track_id' => $track_id
                    ));
                    
                    return array(
                        'status' => 'error',
                        'message' => 'Tracking timeout exceeded 24 hours'
                    );
                }

                // Schedule next check in 3 minutes if not already scheduled
                if (!wp_next_scheduled('mispay_track_checkout_cron', array($orderId))) {
                    wp_schedule_single_event(time() + (3 * 60), 'mispay_track_checkout_cron', array($orderId));
                }
            }

            return $data['result'];
    }

    /**
     * Log transaction status updates to order meta
     */
    private function log_transaction_status($order, $status, $details = array())
    {
        $log_entry = array(
            'timestamp' => current_time('Y-m-d H:i:s'),
            'status' => $status,
            'message' => $this->get_status_message($status),
            'details' => $details
        );
        
        // Get existing logs
        $existing_logs = $order->get_meta('mispay_transaction_logs');
        $logs = is_array($existing_logs) ? $existing_logs : array();
        
        // Add new log entry
        $logs[] = $log_entry;
        
        // Keep only last 20 entries to prevent excessive storage
        if (count($logs) > 20) {
            $logs = array_slice($logs, -20);
        }
        
        $order->update_meta_data('mispay_transaction_logs', $logs);
        $order->save();
    }
    
    /**
     * Get human-readable status message
     */
    private function get_status_message($status)
    {
        $messages = array(
            'created' => 'Checkout session created',
            'callback_received' => 'Callback received from MISPay',
            'status_change_trigger' => 'Order status change triggered tracking',
            'cron_executed' => 'Cron job executed for payment tracking',
            'pending' => 'Payment is pending',
            'in-progress' => 'Payment is being processed',
            'success' => 'Payment completed successfully',
            'canceled' => 'Payment was canceled',
            'error' => 'Payment failed',
            'timeout' => 'Payment tracking timeout'
        );
        
        return isset($messages[$status]) ? $messages[$status] : 'Status: ' . $status;
    }

    function decrypt(string $token)
    {
        $input = base64_decode($token);
        if ($input === false) {
            return false;
        }
        $salt = substr($input, 0, 16);
        $nonce = substr($input, 16, 12);
        $ciphertext = substr($input, 28, -16);
        $tag = substr($input, -16);
        $key = hash_pbkdf2("sha256", $this->APP_SECRET, $salt, 40000, 32, true);
        $decryptToken = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, 1, $nonce, $tag);
        if ($decryptToken === false) {
            return false;
        }
        $jsonResponse = json_decode($decryptToken, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        return $jsonResponse;
    }
}
