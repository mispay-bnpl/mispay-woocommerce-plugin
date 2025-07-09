# MIS Pay - WooCommerce Plugin

A direct integration between WooCommerce and MisPay which adds MisPay as a payment method to your WooCommerce Store.

## Features

- Supports all WordPress themes (including block themes)
- Compatible with latest WordPress and WooCommerce versions
- Supports High-Performance Order Storage (HPOS)
- Supports subscriptions and recurring payments
- Multi-language support (English/Arabic)
- Customizable widget display
- Secure payment processing
- Sandbox mode for testing
- Enhanced error handling and logging
- Automatic compatibility checks
- **NEW: Automated payment tracking with cron jobs**
- **NEW: Order status change triggers**
- **NEW: Manual tracking triggers for admin**
- **NEW: Bulk tracking operations**

## Requirements

- WordPress 6.0 or higher (Tested up to 6.8.1)
- WooCommerce 7.0 or higher (Tested up to 9.9.2)
- PHP 7.4 or higher

## Installation

1. Upload the plugin files to `/wp-content/plugins/mispay-woocommerce`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Settings->WooCommerce->Payments screen to configure the plugin

## Payment Tracking Features

### Automatic Tracking
- **Cron-based tracking**: Automatically checks payment status every 3 minutes for pending payments
- **Status change triggers**: Automatically triggers tracking when order status changes to "Pending payment" or "On hold"
- **Final status protection**: **Does NOT trigger tracking for orders with final statuses** (processing, completed, cancelled, failed, refunded)
- **24-hour timeout**: Stops tracking after 24 hours to prevent resource waste
- **Comprehensive logging**: Full audit trail of all tracking attempts and status changes

### Manual Tracking
- **Individual order tracking**: Trigger tracking for specific orders from the order edit page
- **Bulk tracking**: Select multiple orders and trigger tracking for all MISPay orders at once
- **Admin interface**: Easy-to-use buttons and bulk actions in WooCommerce admin

### Order Status Management
- **Automatic status updates**: Updates WooCommerce order status based on MISPay payment status
- **Status mapping**:
  - MISPay `success` → WooCommerce `processing`
  - MISPay `canceled` → WooCommerce `cancelled`
  - MISPay `error` → WooCommerce `failed`
  - MISPay `timeout` → WooCommerce `failed`
- **Order notes**: Adds detailed notes about tracking results and status changes

## Compatibility

This plugin has been tested and is compatible with:
- WordPress 6.8.1
- WooCommerce 9.9.2
- High-Performance Order Storage (HPOS)
- PHP 7.4+
- All major WordPress themes
- WooCommerce block themes

## Support

For support, please contact MISPay support team.

---

All rights reserved MISPay
