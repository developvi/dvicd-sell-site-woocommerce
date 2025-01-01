<?php

namespace DVICD\Controllers;

use DVICD\Traits\SiteOrder;

class OrderController extends BaseController
{
    use SiteOrder;
    function __construct()
    {
        add_action('woocommerce_payment_complete', array(&$this, 'wc_spinup_wpapp'), 10, 1); // order
        add_action('woocommerce_order_status_processing', array(&$this, 'wc_order_completed'), 10, 1); //order 
        add_filter('woocommerce_display_item_meta', array(&$this, 'wc_display_misc_attributes'), 10, 3); //order


    }

    /**
     * Spin up the Site when payment suceeds.
     * Generally, this should happen only when
     * the status goes to "processing".
     * But, for certain gateways such as "Checks",
     * this has to be done for "completed".
     * In that case, this function will be called by
     * the wc_order_completed() function from above.
     *
     * Action Hook: woocommerce_payment_complete
     *
     * @param int $order_id  WooCommerce order id.
     *
     * @return void
     */
    public function wc_spinup_wpapp($order_id)
    {

        /* Do not spin up sites for renewal orders... */
        if (function_exists('wcs_order_contains_renewal')) {
            if (true == wcs_order_contains_renewal($order_id)) {
                return;
            }
        }
        if ($this->is_cart_renewal()) {
            return;
        }

        /* Are we switching subscriptions? */
        if ($this->is_order_subscription_switch($order_id)) {
            $this->handle_subscription_switch($order_id);
            return;
        }

        /* This is a new order so lets do the thing... */
        $order = wc_get_order($order_id);

        /* Is this order a combined server and site order? */
        $is_combined_server_and_site_order = $this->is_combined_server_and_site_order($order);

        $user  = $order->get_user();
        $items = $order->get_items();
        foreach ($items as $item) {
            $product_id            = $item->get_product_id();
            $is_wpapp_sites        = get_post_meta($product_id, 'wpcd_app_wpapp_sites_product', true);  // Is this product one that is a WP site purchase?
            $max_sites             = get_post_meta($product_id, 'wpcd_app_wpapp_sites_max_sites', true);
            $disk_quota            = get_post_meta($product_id, 'wpcd_app_wpapp_sites_disk_quota', true);
            $suppress_global_email = get_post_meta($product_id, 'wpcd_app_wpapp_sites_no_site_ready_email', true);
            $site_package_id       = get_post_meta($product_id, 'wpcd_app_wpapp_sites_product_package', true);

            if ('yes' !== $is_wpapp_sites) {
                continue;
            }

            do_action('wpcd_log_error', "got WordPress App order ($order_id), payment method " . $order->get_payment_method(), 'debug', __FILE__, __LINE__);

            // Get list of subscriptions on the order and filter them into an array so we only have one unique id for each subscription for the product.
            $subscription  = array();
            $subscriptions = wcs_get_subscriptions_for_order($order_id, array('product_id' => $item->get_product_id()));
            foreach ($subscriptions as $subscription_id => $subscription_obj) {
                $subscription[] = $subscription_id;
            }
            $subscription = array_filter(array_unique($subscription));

            // Now, loop in case we have an order for more than a quantity of one.
            // This is a failsafe - we should never order more than one site at a time since provisioning more than one site on the server at a time is likely going to fail!
            for ($x = 0; $x < $item->get_quantity(); $x++) {

                // which server will we put this thing on?
                if (true === $is_combined_server_and_site_order) {
                    $server_id = $this->get_server_id_on_combined_order($order_id);
                } else {
                    $server_id = $this->get_server_for_site($item);
                }

                // Maybe developers might want to override the server id so apply a filter to it.
                $server_id = apply_filters('wpcd_wc_site_server_assigned', $server_id);

                if ($server_id) {
                    // Create an args array to hold all the data to provision the site.
                    $args = array();

                    // What's the root domain if any?
                    $domain_root = wpcd_get_option('wordpress_app_wc_sites_temp_domain');
                    if (empty($domain_root)) {
                        $domain_root = 'notset.com';
                    }

                    // Get the subdomain to be used.
                    $subdomain = $this->get_sub_domain($order, $item);

                    // Get the password, if any.
                    $wp_password = $this->get_wp_password($order, $item);

                    // @codingStandardsIgnoreLine - added to ignore the misspelling in 'wordpress' below when linting with PHPcs. Otherwise linting will automatically uppercase the first letter.
                    $args['wpcd_app_type']                  = 'wordpress';
                    $args['wp_domain']                   = $subdomain . '.' . $domain_root;
                    $args['subdomain']                   = $subdomain;  // note that this doesn't have the 'wp' prefix for the key so it will not be stamped on the post record. It's used instead in the add_dns function.
                    $args['wp_original_domain']          = $subdomain;  // need to keep the original domain around so if the site is deleted we can attempt to delete it from the DNS.
                    $args['wp_user']                     = $user->user_nicename;
                    $args['wp_password']                 = $wp_password;
                    $args['wp_email']                    = $user->user_email;
                    $args['wp_version']                  = 'latest';
                    $args['wp_locale']                   = 'en_US';
                    $args['id']                          = $server_id;
                    $args['wc_user_id']                  = get_current_user_id();
                    $args['wp_wc_order_id']              = $order_id;
                    $args['wp_wc_subscription_id']       = implode(',', $subscription);
                    $args['wc_subscriptions']            = $subscriptions;
                    $args['wc_item']                     = $item;
                    $args['disk_quota']                  = $disk_quota;
                    $args['wp_wc_suppress_global_email'] = $suppress_global_email;
                    $args['wp_wc_product_id']            = $product_id;
                    $args['wp_site_package']             = $site_package_id;

                    // What type of WP app are we installing? Standard WP or a template site?  A value of zero in the function call below means a regular WP app; Greater than zero means we're copying from an existing app.
                    $wp_template_app_id = (int) get_post_meta($product_id, 'wpcd_app_wpapp_sites_template_site_options', true);

                    // Maybe we're in an MT environment so we might need to get the real template id.
                    $wp_template_app_id = $this->mt_get_real_template_id($wp_template_app_id, $server_id);

                    if ($wp_template_app_id > 0) {
                        /**
                         * Install from template site onto the server.
                         * This can be done one of two ways:
                         *   1. If the template site is present on the target server we can just use the CLONE SITE function.
                         *   2. If the template site is NOT present on the target server we have to use the COPY TO SERVER function.
                         */

                        // What server is the template site located on?
                        $template_server_id = WPCD_WORDPRESS_APP()->get_server_id_by_app_id($wp_template_app_id);

                        // Add some stuff to the $args array...
                        $args['wp_template_app_id']             = $wp_template_app_id;      // Add the source of the template to the array.
                        $args['author']                         = get_current_user_id();    // Who is going to own this site?  We'll need this later after the template is copied and domain changed.
                        $args['site_sync_destination']          = $server_id;               // Which server will we be copying the template site to?
                        $args['sec_source_dest_check_override'] = 1;                        // Disable some server level security checks in the site-sync program.

                        if ($server_id === $template_server_id) {
                            // Copy a local template site using the CLONE SITE operation.
                            $args['pending_tasks_type'] = 'wc-copy-local-template-site';  // The clone program will see this and create a task in the pending tasks log for us to be able to link back to this even later.
                            $args['new_domain']         = $args['wp_domain'];  // The CLONE SITE function expects this array element.

                            /* Setup pending task to install the New WordPress Site by copying an existing template site that is already present on the server */
                            $args['action_hook']     = 'wc_install_wp_from_local_template';
                            $new_pending_task_status = $is_combined_server_and_site_order ? 'not-ready' : 'ready'; // Note: 'not-ready' sites are released after a server is installed.  See the wpcd_wpapp_prepare_server_completed function in class-wordpress-wc-sell=server-subs.php file.
                            WPCD_POSTS_PENDING_TASKS_LOG()->add_pending_task_log_entry($server_id, 'wc-install-wp-from-local-template', $args['wp_domain'], $args, $new_pending_task_status, $server_id, __('Waiting To Install New WP Site from local template For WC Order', 'wpcd'));
                        } else {
                            // Copy template from one server to another.
                            $args['pending_tasks_type'] = 'wc-copy-template-site';  // The site-sync program will see this and create a task in the pending tasks log for us to be able to link back to this even later.

                            /* Setup pending task to install the New WordPress Site by copying an existing template site */
                            $args['action_hook']     = 'wc_install_wp_from_template';
                            $new_pending_task_status = $is_combined_server_and_site_order ? 'not-ready' : 'ready'; // Note: 'not-ready' sites are released after a server is installed.  See the wpcd_wpapp_prepare_server_completed function in class-wordpress-wc-sell=server-subs.php file.
                            WPCD_POSTS_PENDING_TASKS_LOG()->add_pending_task_log_entry($server_id, 'wc-install-wp-from-template', $args['wp_domain'], $args, $new_pending_task_status, $server_id, __('Waiting To Install New WP Site from template For WC Order', 'wpcd'));
                        }
                    } else {

                        /* Setup pending task to install the New WordPress Site */
                        $args['action_hook']     = 'wc_install_wp';
                        $new_pending_task_status = $is_combined_server_and_site_order ? 'not-ready' : 'ready';  // Note: 'not-ready' sites are released after a server is installed.  See the wpcd_wpapp_prepare_server_completed function in class-wordpress-wc-sell=server-subs.php file.
                        WPCD_POSTS_PENDING_TASKS_LOG()->add_pending_task_log_entry($server_id, 'wc-install-wp', $args['wp_domain'], $args, $new_pending_task_status, $server_id, __('Waiting To Install New WP Site For WC Order', 'wpcd'));
                    }

                    // Update the customer meta with the number of sites allowed in the subscription.
                    $this->adjust_customer_site_count_limit($max_sites);
                }
            }
        }
    }


    /**
     * When site is ready to be installed, figure out where to get the password.
     *
     * @param object $order WC order object.
     * @param object $item WC order item object.
     *
     * @return string The password.
     */
    public function get_wp_password($order, $item)
    {

        // Setup variable to hold subdomain.
        $pw = '';

        // Is there an entry on the order item?
        $pw = wc_get_order_item_meta($item->get_id(), 'wpcd_app_wpapp_wc_password', true);

        // Check the cart/order.
        if (empty($pw)) {
            $pw = $order->get_meta('wpcd_app_wpapp_wc_password');
        }

        if (empty($pw)) {
            // Note: replace this later with the core wpcd_generate_default_password() function.
            $pw = wpcd_random_str(32, '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-');
        }

        $pw = apply_filters('wpcd_wpapp_wc_new_wpsite_password', $pw);

        return $pw;
    }

    /**
     * Take a template id and determine the real site id to use.
     *
     * @param int $wp_template_app_id $wp_template_app_id The selected template id to analyze.
     * @param int $server_id The server id on which the template will be installed.
     *
     * @return int.
     */
    public function mt_get_real_template_id($wp_template_app_id, $server_id)
    {

        // If we're not running multi-tenant then there is no need to analyze anything.
        if (false === wpcd_is_mt_enabled()) {
            return $wp_template_app_id;
        }

        // Make sure we've got an int.
        $wp_template_app_id = (int) $wp_template_app_id;

        // if the id is not greater than zero, bail.
        if ($wp_template_app_id <= 0) {
            return $wp_template_app_id;
        }

        // Is there an MT version ID stamped on the site? If so, then use the template as-is since the admin wants this version.
        $mt_version = WPCD_WORDPRESS_APP()->get_mt_version($wp_template_app_id);
        if (! empty($mt_version)) {
            // @todo: Maybe check to see if there is a version clone on the provided server and use that instead.
            return $wp_template_app_id;
        }

        // If we got here, then we don't have an mt_version so we need to see if there is a production version associated with it.
        // If there is a production version, we need to find that site id and use that.
        $mt_default_version = WPCD_WORDPRESS_APP()->get_mt_default_version($wp_template_app_id);
        if (! empty($mt_default_version)) {
            // Locate sites with this version.
            $version_sites = WPCD_WORDPRESS_APP()->get_mt_version_sites_by_version($mt_default_version);

            // If we have some data, take the ID of the first site and use that.
            if (($version_sites) && is_array($version_sites) && ! is_wp_error($version_sites) && count($version_sites) > 0) {
                $new_template_id = $version_sites[0]->ID;  // This should be the production version.

                // If the production version (aka $new_template_id) is on the same server as the one we're working with then just return the new template id.
                if ((int) $new_template_id > 0 && (int) WPCD_WORDPRESS_APP()->get_server_id_by_app_id($new_template_id) === (int) $server_id) {
                    return $new_template_id;
                }

                /**
                 * At this point, it means that we have a production version site id but that id
                 * is not on the server we're working with.
                 * So lets figure out if there's a clone of this version on the server id provided.
                 * If so, use that instead (because then we can do a local clone operation instead
                 * of a remote clone operation which takes longer.)
                 * However, keep in mind that we can have a server with a site that is an mt_version_clone
                 * but not have the actual wpcd-mt-versions folders.  And vice-versa.
                 * So we need to make sure that BOTH exists on the server before using the version clone
                 * site on the server.
                 */
                $server_id = (int) $server_id;  // Cast to int just in case.
                if ($server_id > 0) {
                    $version_clone_sites = WPCD_WORDPRESS_APP()->get_mt_version_clone_sites_by_version_and_server_id($mt_default_version, $server_id);
                    if (($version_clone_sites) && is_array($version_clone_sites) && ! is_wp_error($version_clone_sites) && count($version_clone_sites) > 0) {
                        // Is the server in this list of clones?
                        $server_ok = false;
                        foreach ($version_clone_sites as $key => $clone_site_post) {
                            if ((int) WPCD_WORDPRESS_APP()->get_server_id_by_app_id($clone_site_post->ID) === (int) $server_id) {
                                $server_ok             = true;
                                $maybe_new_template_id = $clone_site_post->ID;
                                break;
                            }
                        }

                        // If it does, does it also have server have a copy of the wpcd-mt-versions folder for this version?
                        if (true === $server_ok) {
                            $version_history = WPCD_WORDPRESS_APP()->get_mt_version_history($wp_template_app_id);
                            if (($version_clone_sites) && is_array($version_clone_sites) && ! is_wp_error($version_clone_sites) && count($version_history) > 0) {
                                if (! empty($version_history[$mt_default_version])) {
                                    $server_list = $version_history[$mt_default_version]['destination_servers'];
                                    if (in_array((string) $server_id, $server_list, true)) {
                                        return $maybe_new_template_id;
                                    }
                                }
                            }
                        }
                        return $new_template_id;
                    } else {
                        return $new_template_id;
                    }
                } else {
                    return $new_template_id;
                }
            }
        }

        return $wp_template_app_id;
    }

    /**
     * When site is ready to be installed, we need to be able to select a server for it.
     *
     * @param object $item WC order item object.
     *
     * @return int Server post id.
     */
    public function get_server_for_site($item)
    {

        // If the item has a server associated with its product then just use that.
        $product_id     = $item->get_product_id();
        $product_server = (int) get_post_meta($product_id, 'wpcd_app_wpapp_sites_server_options', true);
        if ((! empty($product_server)) && (! is_array($product_server))) {
            return $product_server;
        }

        // Get list of servers allowed for sites.
        $servers = wpcd_get_option('wordpress_app_wc_sites_allowed_servers');  // this probably an array.

        // If we have a list, choose a random value from it.
        if ($servers) {
            $num_servers = count($servers);
            if ($num_servers > 0) {
                array_unshift($servers, 0);  // force the first element of the array to be nothing since random_int, used below, will never return a zero value.
                $arr_index = random_int(1, $num_servers);
                return $servers[$arr_index];
            }
        }

        return false;
    }

    /**
     * If an order is a combined order (one with both a server and site on it),
     * then use this function to get the server id associated with the order.
     *
     * @param int|string $wc_order_id The order id with the combined server and site order.
     *
     * @return int|boolean $server_id The server id we found or false if none.
     */
    public function get_server_id_on_combined_order($wc_order_id)
    {

        $server_id = false;

        // Search the list of servers to find the one that has its wpcd_server_wc_order_id meta value set to $wc_order_id.
        $args = array(
            'post_type'      => 'wpcd_app_server',
            'post_status'    => 'private',
            'posts_per_page' => 10,
            'meta_query'     => array(
                array(
                    'key'   => 'wpcd_server_wc_order_id',
                    'value' => $wc_order_id,
                ),
            ),
        );

        $servers = get_posts($args);

        if (! is_wp_error($servers) && ! empty($servers)) {
            if (1 === count($servers)) {
                $server_id = $servers[0]->ID;
            }
        }

        return $server_id;
    }

    /**
     * Check to see if this order is a combined server and site order.
     *
     * With a combined server and site order, the site goes on the
     * server in the same order.
     *
     * @param object $order The order to check.
     *
     * @return boolean;
     */
    public function is_combined_server_and_site_order($order)
    {

        $return = false;

        $server_count = $this->get_count_of_servers_on_wc_order($order);
        $site_count   = $this->get_count_of_sites_on_wc_order($order);

        // Only if there is only one server and at least one site on the order it is a combined order.
        if (1 === $server_count && $site_count >= 1) {
            $return = true;
        }

        return $return;
    }

    /**
     * Returns the number of servers on an order.
     *
     * @param object $order The order to check.
     *
     * @return int
     */
    public function get_count_of_servers_on_wc_order($order)
    {

        $server_count = 0;

        // Get items on order.
        $items = $order->get_items();

        // Loop through order.
        foreach ($items as $item) {

            // Get the WC product id.
            $product_id = $item->get_product_id();

            // Make sure the product is a server product.
            $is_wpapp = get_post_meta($product_id, 'wpcd_app_wpapp_wc_servers_product', true);
            if ('yes' !== $is_wpapp) {
                continue;
            }

            // If we're here, the $item is a server product.
            $server_count += (int) $item->get_quantity();
        }

        return $server_count;
    }


    /**
     * When site is ready to be installed, figure out where to get the subdomain.
     *
     * @param object $order WC order object.
     * @param object $item WC order item object.
     *
     * @return string The subdomain.
     */
    public function get_sub_domain($order, $item)
    {

        // Setup variable to hold subdomain.
        $subdomain = '';

        // Is there an entry on the order item?
        $subdomain = wc_get_order_item_meta($item->get_id(), 'wpcd_app_wpapp_wc_domain', true);

        // Check the cart/order.
        if (empty($subdomain)) {
            $subdomain = $order->get_meta('wpcd_app_wpapp_wc_domain');
        }

        // If we still don't have a subdomain then generate a random one.
        if (empty($subdomain)) {
            $subdomain = wpcd_random_str(12, '0123456789abcdefghijklmnopqrstuvwxyz');
        }

        // Allow developers to override.
        $subdomain = apply_filters('wpcd_wpapp_wc_new_wpsite_subdomain', $subdomain);

        return $subdomain;
    }
    /**
     * Returns the number of sites on an order.
     *
     * @param object $order The order to check.
     *
     * @return int
     */
    public function get_count_of_sites_on_wc_order($order)
    {

        $site_count = 0;

        // Get items on order.
        $items = $order->get_items();

        // Loop through order.
        foreach ($items as $item) {

            // Get the WC product id.
            $product_id = $item->get_product_id();

            // Make sure the product is a server product.
            $is_wpapp_sites = get_post_meta($product_id, 'wpcd_app_wpapp_sites_product', true);
            if ('yes' !== $is_wpapp_sites) {
                continue;
            }

            // If we're here, the $item is a server product.
            $site_count += (int) $item->get_quantity();
        }

        return $site_count;
    }


    public function handle_subscription_switch($order_id)
    {

        // Get list of subscriptions being upgraded.
        $affected_subscriptions = wcs_get_subscriptions_for_switch_order($order_id);

        /**
         * Get list of subscriptions and loop through them.
         * What we're going to do here is a little weird because
         * the subscription id on a site might be an array which
         * means it's hard to query the site for a subscription id
         * using just meta queries.
         * So, we're going to get the subcription id, get the associated order,
         * query for sites with that order id, then loop through those sites
         * looking for the subscription id on them and updating only those.
         */
        foreach ($affected_subscriptions as $wc_subscription) {

            // Get the subscription id.
            $subs_id = $wc_subscription->get_id();

            // Get the user id related to this subscription.
            $subs_user_id = $wc_subscription->get_user_id();

            // Get list of orders for this subscription.
            $orders = $wc_subscription->get_related_orders();
            if (! $orders) {
                return;
            }

            // Get list of sites for each order and loop through them.
            foreach ($orders as $order_id) {
                $wp_sites = get_posts(
                    array(
                        'post_type'   => 'wpcd_app',
                        'post_status' => 'private',
                        'numberposts' => 300,
                        'meta_query'  => array(
                            'relation' => 'AND',
                            array(
                                'key'   => 'wpapp_wc_order_id',
                                'value' => $order_id,
                            ),
                            array(
                                'key'   => 'app_type',
                                'value' => 'wordpress-app',
                            ),
                        ),
                        'fields'      => 'ids',
                    )
                );

                do_action('wpcd_log_error', 'Handling WP Site Subscription Switch ' . count($wp_sites) . " instances in WC order ($order_id) for subscription ($subs_id)", 'trace', __FILE__, __LINE__);

                if ($wp_sites) {
                    foreach ($wp_sites as $id) {

                        // $id is really an app id.
                        // This var added later for clarity but right now we're using both so can't really get rid of $id in the foreach statement above.
                        $app_id = $id;

                        // Get server id on which this app is installed.
                        $server_id = WPCD_WORDPRESS_APP()->get_server_id_by_app_id($app_id);

                        // Get the subscription id from the site post.
                        $site_subs_id = wpcd_maybe_unserialize(get_post_meta($id, 'wpapp_wc_subscription_id', true));

                        // Make sure all vars are ints.
                        $subs_id      = (int) $subs_id;
                        $site_subs_id = (int) $site_subs_id;

                        /**
                         * We're only going to handle sites that match the subscription id.
                         * This means that if the order id matches but the subscription id doesn't then
                         * the site remains unaffected.
                         */
                        if ($site_subs_id === $subs_id && $site_subs_id > 0 && $subs_id > 0) {

                            // Get the products on the subscription.  There should only be one but theoretically can be more than one.
                            $items = $wc_subscription->get_items();

                            foreach ($items as $item) {
                                $product_id     = $item->get_product_id();
                                $is_wpapp_sites = get_post_meta($product_id, 'wpcd_app_wpapp_sites_product', true);  // Is this product one that is a WP site purchase?
                                $max_sites      = get_post_meta($product_id, 'wpcd_app_wpapp_sites_max_sites', true);
                                $disk_quota     = get_post_meta($product_id, 'wpcd_app_wpapp_sites_disk_quota', true);

                                if ('yes' !== $is_wpapp_sites) {
                                    continue;
                                }
                                // log action...
                                do_action('wpcd_log_error', "Handling WP Site Subscription switch with Site id $id as part of WC order ($order_id) and subscription ($subs_id)", 'trace', __FILE__, __LINE__);

                                // Update the app record with the new product id and save the original product id somewhere.
                                $old_wc_product_id = get_post_meta($app_id, 'wpapp_wc_product_id', true);
                                update_post_meta($app_id, 'wpapp_wc_old_product_id_before_last_subscription_switch', $old_wc_product_id);  // Save the original product id.
                                update_post_meta($app_id, 'wpapp_wc_product_id', $product_id);

                                // Run product package rules.
                                $this->run_product_package_rules($server_id, $app_id, '', true);

                                // Update disk space quota. If it turns out there are multiple site items on the subscription then only the last quota will take.
                                WPCD_WORDPRESS_APP()->set_site_disk_quota($id, $disk_quota);
                            }
                        }
                    }
                }
            }
        }

        // Recalculate the user site count limit.
        $this->recalculate_user_site_count_limit($subs_user_id);
    }

    /**
     * Loop through a user's subscription and get a count of all
     * site limits.  Sum them up and update the user profile field.
     *
     * @param int $user_id Will default to current user if none is provided.
     *
     * @return void.
     */
    public function recalculate_user_site_count_limit($user_id = 0)
    {

        // Make sure we have a user id.
        if (0 === $user_id || empty($user_id)) {
            $user_id = get_current_user_id();
        }

        // Get user subscriptions.
        $subscriptions = wcs_get_users_subscriptions();

        // Set default max sites var.
        $max_user_sites = 0;

        foreach ($subscriptions as $wc_subscription) {
            if ('active' === $wc_subscription->get_status()) {

                // Get items in subscription.
                $items = $wc_subscription->get_items();
                foreach ($items as $item) {
                    $product_id     = $item->get_product_id();
                    $is_wpapp_sites = get_post_meta($product_id, 'wpcd_app_wpapp_sites_product', true);  // Is this product one that is a WP site purchase?
                    $max_sites      = get_post_meta($product_id, 'wpcd_app_wpapp_sites_max_sites', true);
                }

                // If not a site product, skip it.
                if ('yes' !== $is_wpapp_sites) {
                    continue;
                }

                // If we got here then we need to increment the count.
                $max_user_sites = $max_user_sites + (int) $max_sites;
            }
        }

        // Update user meta with count.
        $this->set_customer_site_count_limit($user_id, $max_user_sites);
    }

    /**
     * Set the max number of sites the customer is allowed in the customer record.
     *
     * @param int $user_id The user of the record that needs adjusting.
     * @param int $qty New value to place in the customer record.
     *
     * @return void.
     */
    public function set_customer_site_count_limit($user_id, $qty)
    {

        update_user_option($user_id, 'wpcd_wc_sites_allowed', $qty);
    }


    /**
     * Handle order completion status
     *
     * Action Hook: woocommerce_order_status_completed
     *
     * @param int $order_id  WooCommerce order id.
     *
     * @return void
     */
    public function wc_order_completed($order_id)
    {
        $order = wc_get_order($order_id);
        if ('cheque' == $order->get_payment_method()) {
            // Check payments need to fire the site initialization process when
            // the status goes to completion...
            $this->wc_spinup_wpapp($order_id);
        }
    }

    /**
     * Show the WP Site attributes after checkout on the order confirmation screen.
     * This also shows up on the receipt.
     *
     * Note that this only shows on the FRONT-END. It does not show in wp-admin.
     * Right now we are not doing anything special to handle the translation from
     * metakey to words in wp-admin.
     * (There does not seem to be a WC hook to handle that.)
     *
     * Filter Hook: woocommerce_display_item_meta
     *
     * @param string $html The current html that is being shown (usually blank).
     * @param object $item WC item object in cart.
     * @param array  $args Not sure what this is.
     *
     * @return string $html
     */
    public function wc_display_misc_attributes($html, $item, $args)
    {

        // Is this a WP Sites Product?  If not get out.
        $is_wpapp_sites = get_post_meta($item['product_id'], 'wpcd_app_wpapp_sites_product', true);
        if ('yes' !== $is_wpapp_sites) {
            return $html;
        }

        // Skip this whole section if we're handling a renewal order.
        if ($this->is_cart_renewal()) {
            return $html;
        }

        // Skip this whole section if we're handling a subscription switch.
        if ($this->is_cart_subscription_switch()) {
            return $html;
        }

        $strings = array();
        $html    = '';
        $args    = wp_parse_args(
            $args,
            array(
                'before'    => '<ul class="wc-item-meta"><li>',
                'after'     => '</li></ul>',
                'separator' => '</li><li>',
                'echo'      => true,
                'autop'     => false,
            )
        );

        foreach ($item->get_formatted_meta_data() as $meta_id => $meta) {
            $value = $args['autop'] ? wp_kses_post($meta->display_value) : wp_kses_post(make_clickable(trim($meta->display_value)));
            $key   = wp_kses_post($meta->display_key);
            switch ($key) {
                case 'wpcd_app_wpapp_wc_domain':
                    $key   = __('Site Name (subdomain)', 'wpcd');
                    $value = wpcd_clean_domain(sanitize_title(wc_clean($meta->value)));
                    break;

                case 'wpcd_app_wpapp_wc_password':
                    $key   = __('Password', 'wpcd');
                    $value = wpcd_clean_alpha_numeric_dashes(wc_clean($meta->value));
                    break;

                default:
                    // Maybe it needs to be handled by something else?
                    $key_value = apply_filters('wpcd_wpapp_wc_display_misc_attributes', $meta_id, $meta, $item);
                    if (is_array($key_value) && ! empty($key_value)) {
                        // We got back a key-value pair.
                        $key   = $key_value['key'];
                        $value = $key_value['value'];
                    }
                    break;
            }
            $value     = $args['autop'] ? $value : wp_kses_post(make_clickable(trim($value)));
            $strings[] = '<strong class="wc-item-meta-label">' . $key . ':</strong> ' . $value;
        }

        // Get message to be shown for the item.
        $message = get_post_meta($item['product_id'], 'wpcd_app_wpapp_sites_product_notice_in_cart', true);
        if (empty($message)) {
            $message = wpcd_get_option('wordpress_app_wc_sites_product_notice_in_cart');
        }

        // Add a fixed string to the end.
        $value     = $message;
        $value     = $args['autop'] ? $value : wp_kses_post(make_clickable(trim($value)));
        $strings[] = '<strong class="wc-item-meta-label">' . __('Message', 'wpcd') . ':</strong> ' . $value;

        // Close up the html.
        if ($strings) {
            $html = $args['before'] . implode($args['separator'], $strings) . $args['after'];
        }

        return $html;
    }
}
