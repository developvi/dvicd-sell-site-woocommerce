<?php

namespace DVICD;

/**
 * Class DVICDPluginCheck
 */
class DVICDPluginCheck {
    /**
     * Check if required plugins are active and deactivate the plugin if not.
     */
    public static function checkRequiredPlugins() {
        if (!is_plugin_active('meta-box/meta-box.php') || !is_plugin_active('wp-cloud-deploy/wpcd.php')) {
            deactivate_plugins("dvicd-sell-site-woocommerce/dvicd-sell-site-woocommerce.php");
            wp_die('One or both required plugins (Meta Box or WP Cloud Deploy) are not active or installed. The current plugin has been deactivated.');
        }
    }
}
