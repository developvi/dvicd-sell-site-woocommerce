<?php

/**
 * Plugin Name: Developvi Sell Site Woocommerce
 * Plugin URI: https://developvi.com
 * Description: An add-on for the DVICD plugin to allow you to sell WordPress site subscriptions .
 * Author: Developvi
 * Author URI: https://developvi.com
 * License: GPLv2 or later
 * Requires at least: 5.0
 * Requires PHP: 8.1
 * Version: 1.1.0
 *
 */

use DVICD\DVICDINIT;
use DVICD\DVICDPluginCheck;

if (! defined('ABSPATH')) {
	exit;
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';

require_once('vendor/autoload.php');



// Check required plugins
add_action('plugins_loaded', [DVICDPluginCheck::class, 'checkRequiredPlugins']);

add_action('init', function () {

	DVICDINIT::init();
});

class_alias(DVICDINIT::class,  'WPCD_WooCommerce_Init');
