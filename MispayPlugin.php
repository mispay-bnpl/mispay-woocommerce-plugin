<?php
/*
 * Plugin Name: Mispay WooCommerce
 * Plugin URI: https://mispay.co
 * Description: MISPay API Integration
 * Author: MisPay
 * Author URI: https://mispay.co
 * Version: 1.0.3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.9.2
 * Text Domain: mispay-woocommerce
 * Domain Path: /languages
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

require_once('MispayController.php');

// Declare compatibility with High-Performance Order Storage (HPOS)
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// Activation hook for proper initialization
register_activation_hook(__FILE__, 'mispay_activate_plugin');

function mispay_activate_plugin() {
    // Flush rewrite rules for custom endpoints
    flush_rewrite_rules();
    
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('This plugin requires WooCommerce to be installed and activated.', 'mispay-woocommerce'));
    }
}

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
        wp_enqueue_script('mispay-sdk', esc_url($widget_url . $options['access_key']), array(), '1.0.2', true);
    }
}

// Add widget to product page with better theme compatibility
add_action('woocommerce_single_product_summary', 'add_mispay_widget_to_product_detail_page', 25);
function add_mispay_widget_to_product_detail_page() {
    global $product;

    if (!$product || !is_a($product, 'WC_Product')) {
        return;
    }
    $price = $product->get_price();
    if (!$price || $price <= 0) {
        return;
    }

    $lang = 'en';
    if (strpos(get_locale(), 'ar') === 0) {
        $lang = 'ar';
    }

    echo '<div class="mispay-widget-container">';
    echo '<mispay-widget amount="' . esc_attr($price) . '" lang="' . esc_attr($lang) . '"></mispay-widget>';
    echo '</div>';
}

// Add widget to cart page with better theme compatibility
add_action('woocommerce_cart_totals_after_order_total', 'add_mispay_widget_to_cart_detail_page', 25);
function add_mispay_widget_to_cart_detail_page() {
    if (!is_cart() || !WC()->cart || WC()->cart->is_empty()) {
        return;
    }

    $cart_total = WC()->cart->get_total('edit');
    if (!$cart_total || $cart_total <= 0) {
        return;
    }

    $lang = 'en';
    if (strpos(get_locale(), 'ar') === 0) {
        $lang = 'ar';
    }

    echo '<div class="mispay-widget-container">';
    echo '<mispay-widget amount="' . esc_attr($cart_total) . '" lang="' . esc_attr($lang) . '"></mispay-widget>';
    echo '</div>';
}

// Add custom styles for better theme compatibility
add_action('wp_head', 'add_mispay_custom_styles');
function add_mispay_custom_styles() {
    ?>
    <style>
        .mispay-widget-container {
            margin: 15px 0;
            padding: 10px;
            border-radius: 4px;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .mispay-widget-container mispay-widget {
            display: block;
            width: 100%;
        }
    </style>
    <?php
}

// Add this near the top of the file with other add_action calls
add_action('mispay_track_checkout_cron', 'mispay_track_checkout_cron_callback');

// Add hooks for order status changes to trigger tracking
add_action('woocommerce_order_status_pending', 'mispay_trigger_tracking_on_status_change', 10, 1);
add_action('woocommerce_order_status_on-hold', 'mispay_trigger_tracking_on_status_change', 10, 1);

// Add hook for order creation to catch initial status
add_action('woocommerce_new_order', 'mispay_check_initial_order_status', 10, 1);

// Add hook for existing orders that might need tracking
add_action('init', 'mispay_check_existing_orders_for_tracking');

function mispay_trigger_tracking_on_status_change($order_id) {
    $order = wc_get_order($order_id);
    
    if (!$order) {
        return;
    }
    
    // Check if this order uses MISPay payment method
    if ($order->get_payment_method() !== 'mispay') {
        return;
    }
    
    // Check if order already has a final status - don't trigger tracking for completed orders
    $current_status = $order->get_status();
    $final_statuses = array('processing', 'completed', 'cancelled', 'failed', 'refunded');
    
    if (in_array($current_status, $final_statuses)) {
        error_log('MISPay: Order ' . $order_id . ' has final status "' . $current_status . '" - skipping tracking trigger');
        return;
    }
    
    // Check if we have a track ID for this order
    $track_id = $order->get_meta('mispay_track_id');
    if (empty($track_id)) {
        error_log('MISPay: No track ID found for order ' . $order_id . ' when status changed to ' . $order->get_status());
        return;
    }
    
    // Log the status change trigger
    $log_entry = array(
        'timestamp' => current_time('Y-m-d H:i:s'),
        'status' => 'status_change_trigger',
        'message' => 'Order status changed to ' . $order->get_status() . ' - triggering tracking',
        'details' => array(
            'order_status' => $order->get_status(),
            'track_id' => $track_id
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
    
    // Schedule immediate tracking check
    if (!wp_next_scheduled('mispay_track_checkout_cron', array($order_id))) {
        wp_schedule_single_event(time() + 30, 'mispay_track_checkout_cron', array($order_id));
        error_log('MISPay: Scheduled tracking for order ' . $order_id . ' due to status change to ' . $order->get_status());
    }
}

function mispay_track_checkout_cron_callback($orderId) {
    $gateway = new WC_MisPay();
    $track_result = $gateway->MisPayController->track_checkout($orderId);
    
    if ($track_result) {
        $order = wc_get_order($orderId);
        
        if (!$order) {
            error_log('MISPay Error: Order not found in cron callback: ' . $orderId);
            return;
        }
        
        // Log the cron execution
        $log_entry = array(
            'timestamp' => current_time('Y-m-d H:i:s'),
            'status' => 'cron_executed',
            'message' => 'Cron job executed - MISPay status: ' . $track_result['status'],
            'details' => $track_result
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
        
        switch($track_result['status']) {
            case 'success':
                // Update order status to 'Processing' for successful MISPay payments
                $order->update_status('processing', __('MISPay payment completed successfully - order moved to processing.', 'mispay-woocommerce'));
                $order->add_order_note(__('MISPay payment completed successfully via cron tracking - order status updated to Processing.', 'mispay-woocommerce'));
                break;
                
            case 'canceled':
                $order->update_status('cancelled', __('MISPay payment was canceled - detected via cron tracking.', 'mispay-woocommerce'));
                break;
                
            case 'error':
                $order->update_status('failed', __('MISPay payment verification failed - detected via cron tracking.', 'mispay-woocommerce'));
                break;
                
            case 'timeout':
                $order->update_status('failed', __('MISPay payment tracking timeout - detected via cron tracking.', 'mispay-woocommerce'));
                break;
                
            case 'pending':
            case 'in-progress':
                // Don't change order status, but log the current status
                $order->add_order_note(sprintf(
                    __('MISPay payment status: %s - continuing to track via cron.', 'mispay-woocommerce'),
                    $track_result['status']
                ));
                break;
                
            default:
                // Log unknown status
                $order->add_order_note(sprintf(
                    __('MISPay payment status: %s (unknown) - detected via cron tracking.', 'mispay-woocommerce'),
                    $track_result['status']
                ));
                break;
        }
        
        $order->save();
    } else {
        // Log failed tracking attempt
        $order = wc_get_order($orderId);
        if ($order) {
            $order->add_order_note(__('MISPay tracking failed via cron - will retry on next scheduled check.', 'mispay-woocommerce'));
            error_log('MISPay Error: Tracking failed for order ' . $orderId . ' in cron callback');
        }
    }
}

// Clean up any pending cron jobs on plugin deactivation
register_deactivation_hook(__FILE__, 'mispay_cleanup_cron_jobs');

function mispay_cleanup_cron_jobs() {
    // Clear all MISPay related cron jobs
    wp_clear_scheduled_hook('mispay_track_checkout_cron');
    wp_clear_scheduled_hook('mispay_check_order_track_id');
    
    // Clear any cached data
    wp_cache_flush();
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Add compatibility check function
function mispay_check_compatibility() {
    $errors = array();
    
    // Check WordPress version
    if (version_compare(get_bloginfo('version'), '6.0', '<')) {
        $errors[] = __('WordPress 6.0 or higher is required.', 'mispay-woocommerce');
    }
    
    // Check WooCommerce version
    if (class_exists('WooCommerce')) {
        $wc_version = WC()->version;
        if (version_compare($wc_version, '7.0', '<')) {
            $errors[] = __('WooCommerce 7.0 or higher is required.', 'mispay-woocommerce');
        }
    } else {
        $errors[] = __('WooCommerce is required but not active.', 'mispay-woocommerce');
    }
    
    // Check PHP version
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        $errors[] = __('PHP 7.4 or higher is required.', 'mispay-woocommerce');
    }
    
    return $errors;
}

// Display compatibility errors in admin
add_action('admin_notices', 'mispay_admin_notices');

function mispay_admin_notices() {
    if (isset($_GET['page']) && $_GET['page'] === 'wc-settings' && isset($_GET['section']) && $_GET['section'] === 'mispay') {
        $errors = mispay_check_compatibility();
        if (!empty($errors)) {
            echo '<div class="notice notice-error"><p><strong>MISPay Compatibility Issues:</strong></p><ul>';
            foreach ($errors as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul></div>';
        }
    }
}

// Add MISPay transaction logs to admin order page
add_action('add_meta_boxes', 'mispay_add_order_meta_box');

function mispay_add_order_meta_box() {
    add_meta_box(
        'mispay-transaction-logs',
        __('MISPay Transaction Logs', 'mispay-woocommerce'),
        'mispay_display_transaction_logs',
        'shop_order',
        'side',
        'default'
    );
}

function mispay_display_transaction_logs($post) {
    $order = wc_get_order($post->ID);
    
    if (!$order) {
        echo '<p>' . __('Order not found.', 'mispay-woocommerce') . '</p>';
        return;
    }
    
    // Check if this order used MISPay
    $payment_method = $order->get_payment_method();
    if ($payment_method !== 'mispay') {
        echo '<p>' . __('This order was not processed through MISPay.', 'mispay-woocommerce') . '</p>';
        return;
    }
    
    // Get MISPay specific data
    $track_id = $order->get_meta('mispay_track_id');
    $tracking_start_time = $order->get_meta('mispay_tracking_start_time');
    $transaction_logs = $order->get_meta('mispay_transaction_logs');
    
    echo '<div class="mispay-transaction-logs">';
    
    // Display basic MISPay info
    echo '<h4>' . __('MISPay Information', 'mispay-woocommerce') . '</h4>';
    echo '<table class="widefat">';
    echo '<tr><td><strong>' . __('Track ID:', 'mispay-woocommerce') . '</strong></td><td>' . esc_html($track_id ?: __('Not available', 'mispay-woocommerce')) . '</td></tr>';
    echo '<tr><td><strong>' . __('Payment Method:', 'mispay-woocommerce') . '</strong></td><td>' . esc_html($order->get_meta('Payment_method') ?: __('MISPay', 'mispay-woocommerce')) . '</td></tr>';
    echo '</table>';
    
    // Display transaction logs
    if (!empty($transaction_logs)) {
        echo '<h4>' . __('Transaction History', 'mispay-woocommerce') . '</h4>';
        echo '<div class="mispay-logs-container" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">';
        
        $logs = is_array($transaction_logs) ? $transaction_logs : array($transaction_logs);
        foreach ($logs as $log) {
            if (is_array($log)) {
                echo '<div class="mispay-log-entry" style="margin-bottom: 10px; padding: 8px; border-left: 3px solid #0073aa; background: white;">';
                echo '<div style="font-weight: bold; color: #0073aa;">' . esc_html($log['timestamp'] ?? '') . '</div>';
                echo '<div style="margin-top: 5px;"><strong>' . __('Status:', 'mispay-woocommerce') . '</strong> ' . esc_html($log['status'] ?? '') . '</div>';
                if (!empty($log['message'])) {
                    echo '<div style="margin-top: 5px;"><strong>' . __('Message:', 'mispay-woocommerce') . '</strong> ' . esc_html($log['message']) . '</div>';
                }
                if (!empty($log['details'])) {
                    echo '<div style="margin-top: 5px;"><strong>' . __('Details:', 'mispay-woocommerce') . '</strong> <pre style="font-size: 11px; margin: 5px 0;">' . esc_html(print_r($log['details'], true)) . '</pre></div>';
                }
                echo '</div>';
            } else {
                echo '<div class="mispay-log-entry" style="margin-bottom: 10px; padding: 8px; border-left: 3px solid #0073aa; background: white;">';
                echo esc_html($log);
                echo '</div>';
            }
        }
        echo '</div>';
    } else {
        echo '<p>' . __('No transaction logs available.', 'mispay-woocommerce') . '</p>';
    }
    
    // Add action buttons
    echo '<div style="margin-top: 15px;">';
    echo '<button type="button" class="button button-secondary" onclick="refreshMispayLogs(' . $order->get_id() . ')">' . __('Refresh Transaction Status', 'mispay-woocommerce') . '</button>';
    echo '<span id="mispay-refresh-status" style="margin-left: 10px; display: none;">' . __('Refreshing...', 'mispay-woocommerce') . '</span>';
    echo '</div>';
    
    // Add trigger tracking button for pending/on-hold orders
    if (in_array($order->get_status(), array('pending', 'on-hold'))) {
        echo '<div style="margin-top: 10px;">';
        echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=mispay_trigger_tracking&order_id=' . $order->get_id()), 'mispay_trigger_tracking') . '" class="button button-primary">' . __('Trigger MISPay Tracking', 'mispay-woocommerce') . '</a>';
        echo '<p style="font-size: 11px; color: #666; margin-top: 5px;">' . __('Click to manually trigger payment status tracking for this order.', 'mispay-woocommerce') . '</p>';
        echo '</div>';
    }
    
    echo '</div>';
    
    // Add JavaScript for refresh functionality
    ?>
    <script type="text/javascript">
    function refreshMispayLogs(orderId) {
        var statusElement = document.getElementById('mispay-refresh-status');
        statusElement.style.display = 'inline';
        statusElement.textContent = '<?php _e('Refreshing...', 'mispay-woocommerce'); ?>';
        
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'refresh_mispay_logs',
                order_id: orderId,
                nonce: '<?php echo wp_create_nonce('mispay_refresh_logs'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    statusElement.textContent = '<?php _e('Error refreshing logs', 'mispay-woocommerce'); ?>';
                    setTimeout(function() {
                        statusElement.style.display = 'none';
                    }, 3000);
                }
            },
            error: function() {
                statusElement.textContent = '<?php _e('Error refreshing logs', 'mispay-woocommerce'); ?>';
                setTimeout(function() {
                    statusElement.style.display = 'none';
                }, 3000);
            }
        });
    }
    </script>
    <?php
}

// AJAX handler for refreshing MISPay logs
add_action('wp_ajax_refresh_mispay_logs', 'mispay_refresh_logs_ajax');

function mispay_refresh_logs_ajax() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'mispay_refresh_logs')) {
        wp_die(__('Security check failed.', 'mispay-woocommerce'));
    }
    
    $order_id = intval($_POST['order_id']);
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error(__('Order not found.', 'mispay-woocommerce'));
    }
    
    // Check if this order used MISPay
    if ($order->get_payment_method() !== 'mispay') {
        wp_send_json_error(__('This order was not processed through MISPay.', 'mispay-woocommerce'));
    }
    
    try {
        // Get gateway instance
        $gateways = WC()->payment_gateways()->payment_gateways();
        if (!isset($gateways['mispay'])) {
            wp_send_json_error(__('MISPay gateway not found.', 'mispay-woocommerce'));
        }
        
        $gateway = $gateways['mispay'];
        
        // Track the checkout status
        $track_result = $gateway->MisPayController->track_checkout($order_id);
        
        if ($track_result) {
            // Add log entry
            $log_entry = array(
                'timestamp' => current_time('Y-m-d H:i:s'),
                'status' => $track_result['status'] ?? 'unknown',
                'message' => 'Transaction status refreshed',
                'details' => $track_result
            );
            
            // Get existing logs
            $existing_logs = $order->get_meta('mispay_transaction_logs');
            $logs = is_array($existing_logs) ? $existing_logs : array();
            
            // Add new log entry
            $logs[] = $log_entry;
            
            // Save logs (keep only last 20 entries)
            if (count($logs) > 20) {
                $logs = array_slice($logs, -20);
            }
            
            $order->update_meta_data('mispay_transaction_logs', $logs);
            $order->save();
            
            wp_send_json_success(__('Transaction status refreshed successfully.', 'mispay-woocommerce'));
        } else {
            wp_send_json_error(__('Failed to refresh transaction status.', 'mispay-woocommerce'));
        }
        
    } catch (Exception $e) {
        wp_send_json_error(__('Error refreshing transaction status: ', 'mispay-woocommerce') . $e->getMessage());
    }
}

// Add admin action to manually trigger tracking for stuck orders
add_action('admin_post_mispay_trigger_tracking', 'mispay_admin_trigger_tracking');
add_action('admin_post_mispay_trigger_tracking_all', 'mispay_admin_trigger_tracking_all');

function mispay_admin_trigger_tracking() {
    // Verify nonce and permissions
    if (!current_user_can('manage_woocommerce') || !wp_verify_nonce($_GET['_wpnonce'], 'mispay_trigger_tracking')) {
        wp_die(__('Security check failed.', 'mispay-woocommerce'));
    }
    
    $order_id = intval($_GET['order_id']);
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_die(__('Order not found.', 'mispay-woocommerce'));
    }
    
    if ($order->get_payment_method() !== 'mispay') {
        wp_die(__('This order was not processed through MISPay.', 'mispay-woocommerce'));
    }
    
    // Trigger immediate tracking
    mispay_trigger_tracking_on_status_change($order_id);
    
    // Redirect back to order page
    wp_redirect(admin_url('post.php?post=' . $order_id . '&action=edit&mispay_tracking_triggered=1'));
    exit;
}

function mispay_admin_trigger_tracking_all() {
    // Verify nonce and permissions
    if (!current_user_can('manage_woocommerce') || !wp_verify_nonce($_GET['_wpnonce'], 'mispay_trigger_tracking_all')) {
        wp_die(__('Security check failed.', 'mispay-woocommerce'));
    }
    
    // Get all MISPay orders with pending or on-hold status
    $orders = wc_get_orders(array(
        'payment_method' => 'mispay',
        'status' => array('pending', 'on-hold'),
        'limit' => 50, // Limit to prevent timeout
        'return' => 'ids'
    ));
    
    $triggered_count = 0;
    
    foreach ($orders as $order_id) {
        $order = wc_get_order($order_id);
        if ($order && !empty($order->get_meta('mispay_track_id'))) {
            mispay_trigger_tracking_on_status_change($order_id);
            $triggered_count++;
        }
    }
    
    // Redirect back with success message
    wp_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=mispay&mispay_tracking_triggered_all=' . $triggered_count));
    exit;
}

// Add admin notice for tracking trigger
add_action('admin_notices', 'mispay_admin_tracking_notices');

function mispay_admin_tracking_notices() {
    if (isset($_GET['mispay_tracking_triggered']) && $_GET['mispay_tracking_triggered'] == '1') {
        echo '<div class="notice notice-success is-dismissible"><p>' . __('MISPay tracking has been triggered for this order.', 'mispay-woocommerce') . '</p></div>';
    }
    
    if (isset($_GET['mispay_tracking_triggered_all'])) {
        $count = intval($_GET['mispay_tracking_triggered_all']);
        echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('MISPay tracking has been triggered for %d orders.', 'mispay-woocommerce'), $count) . '</p></div>';
    }
}

// Add bulk actions to orders list
add_filter('bulk_actions-edit-shop_order', 'mispay_add_bulk_actions');
add_filter('handle_bulk_actions-edit-shop_order', 'mispay_handle_bulk_actions', 10, 3);

function mispay_add_bulk_actions($bulk_actions) {
    $bulk_actions['mispay_trigger_tracking'] = __('Trigger MISPay Tracking', 'mispay-woocommerce');
    return $bulk_actions;
}

function mispay_handle_bulk_actions($redirect_to, $doaction, $post_ids) {
    if ($doaction !== 'mispay_trigger_tracking') {
        return $redirect_to;
    }
    
    $triggered_count = 0;
    $skipped_count = 0;
    
    foreach ($post_ids as $post_id) {
        $order = wc_get_order($post_id);
        if ($order && $order->get_payment_method() === 'mispay' && !empty($order->get_meta('mispay_track_id'))) {
            // Check if order already has a final status - don't trigger tracking for completed orders
            $current_status = $order->get_status();
            $final_statuses = array('processing', 'completed', 'cancelled', 'failed', 'refunded');
            
            if (in_array($current_status, $final_statuses)) {
                $skipped_count++;
                continue; // Skip this order as it has a final status
            }
            
            mispay_trigger_tracking_on_status_change($post_id);
            $triggered_count++;
        }
    }
    
    $redirect_to = add_query_arg('mispay_bulk_tracking_triggered', $triggered_count, $redirect_to);
    if ($skipped_count > 0) {
        $redirect_to = add_query_arg('mispay_bulk_tracking_skipped', $skipped_count, $redirect_to);
    }
    return $redirect_to;
}

// Add notice for bulk action
add_action('admin_notices', 'mispay_bulk_action_notices');

function mispay_bulk_action_notices() {
    if (isset($_GET['mispay_bulk_tracking_triggered'])) {
        $count = intval($_GET['mispay_bulk_tracking_triggered']);
        $skipped_count = isset($_GET['mispay_bulk_tracking_skipped']) ? intval($_GET['mispay_bulk_tracking_skipped']) : 0;
        
        if ($count > 0) {
            $message = sprintf(__('MISPay tracking has been triggered for %d orders.', 'mispay-woocommerce'), $count);
            if ($skipped_count > 0) {
                $message .= ' ' . sprintf(__('%d orders were skipped because they already have final status (processing, completed, cancelled, failed, or refunded).', 'mispay-woocommerce'), $skipped_count);
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
        } else {
            $message = __('No MISPay orders were found to trigger tracking.', 'mispay-woocommerce');
            if ($skipped_count > 0) {
                $message .= ' ' . sprintf(__('%d orders were skipped because they already have final status.', 'mispay-woocommerce'), $skipped_count);
            }
            echo '<div class="notice notice-warning is-dismissible"><p>' . $message . '</p></div>';
        }
    }
}

function mispay_check_initial_order_status($order_id) {
    $order = wc_get_order($order_id);
    
    if (!$order) {
        return;
    }
    
    // Check if this order uses MISPay payment method
    if ($order->get_payment_method() !== 'mispay') {
        return;
    }
    
    // Check if order already has a final status - don't trigger tracking for completed orders
    $current_status = $order->get_status();
    $final_statuses = array('processing', 'completed', 'cancelled', 'failed', 'refunded');
    
    if (in_array($current_status, $final_statuses)) {
        error_log('MISPay: New order ' . $order_id . ' has final status "' . $current_status . '" - skipping tracking trigger');
        return;
    }
    
    // Check if order status is pending or on-hold
    $status = $order->get_status();
    if (in_array($status, array('pending', 'on-hold'))) {
        // Log the initial order creation
        error_log('MISPay: New order ' . $order_id . ' created with status ' . $status . ' - will trigger tracking when track ID is available');
        
        // Schedule a delayed check to ensure track ID is available
        wp_schedule_single_event(time() + 60, 'mispay_check_order_track_id', array($order_id));
    }
}

// Add action for delayed track ID check
add_action('mispay_check_order_track_id', 'mispay_delayed_track_id_check');

function mispay_delayed_track_id_check($order_id) {
    $order = wc_get_order($order_id);
    
    if (!$order) {
        return;
    }
    
    // Check if this order uses MISPay payment method
    if ($order->get_payment_method() !== 'mispay') {
        return;
    }
    
    // Check if we have a track ID for this order
    $track_id = $order->get_meta('mispay_track_id');
    if (!empty($track_id)) {
        // Track ID is available, trigger tracking
        mispay_trigger_tracking_on_status_change($order_id);
        error_log('MISPay: Track ID found for order ' . $order_id . ' - triggering tracking');
    } else {
        // Track ID not available yet, schedule another check
        wp_schedule_single_event(time() + 120, 'mispay_check_order_track_id', array($order_id));
        error_log('MISPay: Track ID not yet available for order ' . $order_id . ' - scheduling another check');
    }
}

function mispay_check_existing_orders_for_tracking() {
    // Only run this check once per day to avoid performance issues
    $last_check = get_option('mispay_last_existing_orders_check', 0);
    if ((time() - $last_check) < (24 * 60 * 60)) {
        return;
    }
    
    // Get all MISPay orders with pending or on-hold status that don't have tracking scheduled
    $orders = wc_get_orders(array(
        'payment_method' => 'mispay',
        'status' => array('pending', 'on-hold'),
        'limit' => 100,
        'return' => 'ids'
    ));
    
    $triggered_count = 0;
    
    foreach ($orders as $order_id) {
        $order = wc_get_order($order_id);
        if ($order && !empty($order->get_meta('mispay_track_id'))) {
            // Check if order already has a final status - don't trigger tracking for completed orders
            $current_status = $order->get_status();
            $final_statuses = array('processing', 'completed', 'cancelled', 'failed', 'refunded');
            
            if (in_array($current_status, $final_statuses)) {
                continue; // Skip this order as it has a final status
            }
            
            // Check if tracking is already scheduled
            if (!wp_next_scheduled('mispay_track_checkout_cron', array($order_id))) {
                mispay_trigger_tracking_on_status_change($order_id);
                $triggered_count++;
            }
        }
    }
    
    // Update last check time
    update_option('mispay_last_existing_orders_check', time());
    
    if ($triggered_count > 0) {
        error_log('MISPay: Triggered tracking for ' . $triggered_count . ' existing orders that needed tracking');
    }
}

// Register the MISPay webhook endpoint
add_action('woocommerce_api_mispay-webhook', 'mispay_handle_webhook');

function mispay_handle_webhook() {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (isset($data['orderId']) && isset($data['code'])) {
        $order = wc_get_order($data['orderId']);
        if ($order) {
            switch ($data['code']) {
                case 'MP00':
                    $order->update_status('processing', __('MISPay webhook: Payment successful.', 'mispay-woocommerce'));
                    break;
                case 'MP02':
                    $order->update_status('cancelled', __('MISPay webhook: Payment cancelled.', 'mispay-woocommerce'));
                    break;
                default:
                    $order->update_status('failed', __('MISPay webhook: Payment failed or unknown status.', 'mispay-woocommerce'));
                    break;
            }
            $order->add_order_note('MISPay webhook received: ' . print_r($data, true));
        }
    }
    status_header(200);
    exit;
}

// Display the webhook URL in the admin panel with a copy button
add_action('woocommerce_admin_field_mispay_webhook_url', 'mispay_display_webhook_url_field');
function mispay_display_webhook_url_field($value) {
    $webhook_url = home_url('/wc-api/mispay-webhook');
    ?>
    <tr valign="top">
        <th scope="row">MISPay Webhook URL</th>
        <td>
            <input type="text" id="mispay-webhook-url" value="<?php echo esc_attr($webhook_url); ?>" readonly style="width: 400px;" />
            <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('mispay-webhook-url').value);">Copy</button>
            <p class="description">Provide this URL to MISPay for real-time payment status updates.</p>
        </td>
    </tr>
    <?php
}

// Add the webhook URL field to the MISPay settings
add_filter('woocommerce_get_settings_checkout', function($settings, $current_section) {
    if ($current_section === 'mispay') {
        $settings[] = array(
            'type' => 'mispay_webhook_url',
        );
    }
    return $settings;
}, 20, 2);
