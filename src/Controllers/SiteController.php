<?php

namespace DVICD\Controllers;

use DVICD\Traits\SiteOrder;
use DVICD\Traits\Sites\Dns;

class SiteController extends BaseController
{
    use SiteOrder, Dns;

    function __construct()
    {
        /* Trigger installation of a new WP site */
        add_action('wc_install_wp', array($this, 'wc_install_wp'), 10, 3);

        /* Trigger installation of a new WP site from template */
        add_action('wc_install_wp_from_template', array($this, 'wc_install_wp_from_template'), 10, 3);

        /* Trigger installation of a new WP site from template that is already present on the server (clone site operation) */
        add_action('wc_install_wp_from_local_template', array($this, 'wc_install_wp_from_local_template'), 10, 3); //site

        /* When a site sync is complete (because the user ordered a site with a template), it's time to change the domain */
        add_action('wpcd_wordpress-app_site_sync_new_post_completed', array($this, 'site_sync_complete'), 100, 3); // Priority set to run after almost everything else.

        /* When a local template clone is complete (because the user ordered a site with a template), it's time to do some other things */
        add_action('wpcd_wordpress-app_site_clone_new_post_completed', array($this, 'clone_site_complete'), 100, 3); // Priority set to run after almost everything else.

        /* When a domain change is complete from a template site, update the site records to contain all the other data it needs */
        add_action('wpcd_wordpress-app_site_change_domain_completed', array($this, 'site_change_domain_complete'), 100, 4);

        /* When a site is deleted we might need to delete the DNS and adjust the max sites allowed in the customer profile. */
        add_action('wpcd_before_remove_site_action', array($this, 'site_delete'), 10, 2);

        /* Disable fields on the CLONE SITES tab if the number of sites a user is allowed is exceeded. */
        add_filter('wpcd_app_wordpress-app_clone-site_get_fields', array($this, 'disable_clone_site'), 10, 3);

        /* We can disable fields on the clone sites tab using the above filter.  BUT, someone can still hack the html and submit data as if the fields were active.  So we need to apply this security filter. */
        add_filter('wpcd_app_wordpress-app_tab_actions_general_security_check', array($this, 'check_clone_site_security'), 10, 4);

        /* Make sure we add the WC fields on the site post record to cloned and staged sites. */
        add_action('wpcd_wordpress-app_site_clone_new_post_completed', array($this, 'site_clone_new_post_completed'), 10, 3);
        add_action('wpcd_wordpress-app_site_staging_new_post_completed', array($this, 'site_clone_new_post_completed'), 10, 3);

        /* Send email and possibly auto-issue ssl when a site has been installed */
        add_action('wpcd_command_wordpress-app_completed_after_cleanup', array($this, 'wpcd_wpapp_install_complete'), 10, 4);

        /* Add a metabox to set a flag on whether a site is a template site and add a field to the user screen to hold max site limits. */
        add_filter('rwmb_meta_boxes', array($this, 'register_app_meta_boxes'), 10, 1); // no add


    }

    /**
     * Install WordPress on a server - new site.
     *
     * Called from an action hook from the pending logs background process - WPCD_POSTS_PENDING_TASKS_LOG()->do_tasks()
     *
     * Action Hook: wc_install-wp
     *
     * @param int   $task_id    Id of pending task that is firing this thing...
     * @param int   $server_id  Id of server on which to install the new website.
     * @param array $args       All the data needed to install the WP site on the server.
     */
    public function wc_install_wp($task_id, $server_id, $args)
    {

        /* Add DNS - has to be added first since installation of WP will attempt to add an SSL so DNS must be present in order for that to succeed! */
        $this->add_dns($server_id, $args);

        /* Install standard wp app on the designated server */
        $additional = WPCD_WORDPRESS_APP()->install_wp_validate($args);

        /* Add cross-reference data to order lines and subscription lines and update the app record with other items as necessary. */
        $this->add_wp_data_to_wc_orders($additional['new_app_post_id'], $args);
    }

    /**
     * Add cross-reference data to order lines and subscription lines
     *
     * @param int   $new_app_post_id The post id of the new app record.
     * @param array $args An array of data.
     *
     */
    public function add_wp_data_to_wc_orders($new_app_post_id, $args)
    {

        $order_id        = $args['wp_wc_order_id'];
        $subscription_id = $args['wp_wc_subscription_id'];
        $subscriptions   = $args['wc_subscriptions'];
        $item            = $args['wc_item'];
        $disk_quota      = (int) $args['disk_quota'];
        if (isset($args['wc_suppress_global_email'])) {
            $suppress_global_email = $args['wc_suppress_global_email'];  // Not used in this function but grabbing it just in case we need it later.
        } else {
            $suppress_global_email = '';
        }

        /**
         * Stamp the order record with the new app id for the site.
         * But, since multiple sites can theoretically be on the same
         * order, we have to handle that.
         */
        if (! empty($new_app_post_id)) {

            $wpcd_app_post_ids = wpcd_maybe_unserialize(get_post_meta($order_id, 'wpcd_app_post_ids', true));
            if (empty($wpcd_app_post_ids)) {
                $wpcd_app_post_ids = array();
            }
            $wpcd_app_post_ids[] = $new_app_post_id;

            // re-get the order data just in case it's been updated in the DB.
            $order = wc_get_order($order_id);

            // Update post meta on the order and save it.
            $order->update_meta_data('wpcd_app_post_ids', $wpcd_app_post_ids);
            $order->save();
        }

        /**
         * Stamp the subscription record with the new app id for the site.
         * But, since multiple sites can theoretically be on the same
         * subscription, we have to handle that.
         */
        if (! empty($new_app_post_id)) {

            foreach ($subscriptions as $sub_id => $sub) {
                $wpcd_app_post_ids = wpcd_maybe_unserialize(get_post_meta($sub_id, 'wpcd_app_post_ids', true));
                if (empty($wpcd_app_post_ids)) {
                    $wpcd_app_post_ids = array();
                }
                $wpcd_app_post_ids[] = $new_app_post_id;
                update_post_meta($sub_id, 'wpcd_app_post_ids', $wpcd_app_post_ids);
            }
        }

        /**
         * Stamp the items on the order with the new app id for the site.
         * But, since we can have theoretically have more than one
         * site on the same item because qty > 1, we have to handle that.
         */
        if (! empty($new_app_post_id)) {
            $wpcd_app_post_ids      = wpcd_maybe_unserialize($item->get_meta('wpcd_app_post_ids', true));  // arrays will not automatically show up on the order confirmation screens.
            $wpcd_app_post_ids_text = $item->get_meta('WPSiteIDs', true);   // Text strings will show up so we give it a different name that is more user friendly.

            // Initialize the array if needed.
            if (empty($wpcd_app_post_ids)) {
                $wpcd_app_post_ids = array();
            }

            // Update the user friendly text string.
            if (empty($wpcd_app_post_ids_text)) {
                $wpcd_app_post_ids_text = $new_app_post_id;
            } else {
                $wpcd_app_post_ids_text .= ',' . $new_app_post_id;
            }

            // Update the array for possible programmatic use later.
            $wpcd_app_post_ids[] = $new_app_post_id;

            // Save both array and string to item.
            $item->add_meta_data('wpcd_app_post_ids', $wpcd_app_post_ids, true);
            $item->add_meta_data('WPSiteIDs', $wpcd_app_post_ids_text, true);
            $item->save();
        }

        /**
         * Update the disk quota on the app record.
         */
        if (! empty($new_app_post_id) && ! empty($disk_quota)) {
            WPCD_WORDPRESS_APP()->set_site_disk_quota($new_app_post_id, $disk_quota);
        }
    }

    /**
     * Install WordPress on a server by copying a template site.
     *
     * Called from an action hook from the pending logs background process - WPCD_POSTS_PENDING_TASKS_LOG()->do_tasks()
     *
     * Action Hook: wc_install_wp_from_template
     *
     * @param int   $task_id    Id of pending task that is firing this thing...
     * @param int   $server_id  Id of server on which to install the new website.
     * @param array $args       All the data needed to install the WP site on the server.
     */
    public function wc_install_wp_from_template($task_id, $server_id, $args)
    {

        /* Add DNS - has to be added first since installation of WP will attempt to add an SSL so DNS must be present in order for that to succeed! */
        $this->add_dns($server_id, $args);

        /* Now fire the action located in the includes/core/apps/wordpress-app/tabs/site-sync.php file to copy the template site. */
        do_action('wpcd_wordpress-app_do_site_sync', $args['wp_template_app_id'], $args);
    }


    /**
     * Install WordPress on a server by copying a template site that is already present on the server.
     *
     * Called from an action hook from the pending logs background process - WPCD_POSTS_PENDING_TASKS_LOG()->do_tasks()
     *
     * Action Hook: wc_install_wp_from_local_template
     *
     * @param int   $task_id    Id of pending task that is firing this thing...
     * @param int   $server_id  Id of server on which to install the new website.
     * @param array $args       All the data needed to install the WP site on the server.
     */
    public function wc_install_wp_from_local_template($task_id, $server_id, $args)
    {

        /* Add DNS - has to be added first since installation of WP will attempt to add an SSL so DNS must be present in order for that to succeed! */
        $this->add_dns($server_id, $args);

        /* Now fire the action located in the includes/core/apps/wordpress-app/tabs/clone-site.php file to copy the template site. */
        do_action('wpcd_wordpress-app_do_clone_site', $args['wp_template_app_id'], $args);
    }

    /**
     * When a site sync is complete we need to change the domain if:
     * 1. It was because of a WC sites order and
     * 2. The order was a template site order.
     *
     * Action Hook: wpcd_{$this->get_app_name()}_site_sync_new_post_completed || wpcd_wordpress-app_site_sync_new_post_completed
     *
     * @param int    $new_app_post_id    The post id of the new app record.
     * @param int    $id                 ID of the template site (source site being synced to a destination server).
     * @param string $name               The command name.
     */
    public function site_sync_complete($new_app_post_id, $id, $name)
    {

        // The name will have a format as such: command---domain---number.  For example: dry_run---cf1110.wpvix.com---905
        // Lets tear it into pieces and put into an array.  The resulting array should look like this with exactly three elements.
        // [0] => dry_run
        // [1] => cf1110.wpvix.com
        // [2] => 911
        $command_array = explode('---', $name);

        // if the command is to copy a site to a new server then we need to do some things.
        if ('site-sync' == $command_array[0]) {

            // Lets pull the logs.
            $logs = WPCD_WORDPRESS_APP()->get_app_command_logs($id, $name);

            // Was the command successful?
            $success = WPCD_WORDPRESS_APP()->is_ssh_successful($logs, 'site_sync.txt');

            if ($success === true) {
                // Now check the pending tasks table for a record where the key=$name and type='wc-copy-template-site' and state='not-ready'.
                $posts = WPCD_POSTS_PENDING_TASKS_LOG()->get_tasks_by_key_state_type($name, 'not-ready', 'wc-copy-template-site');

                /**
                 * Start process of changing domain on the copied template site. We're assuming $posts has one and only one item in it!
                 * If we got a record in here we have satisfied the criteria we outlined at the top of this function in order to proceed.
                 */
                if ($posts) {

                    // Grab our data array from pending tasks record...
                    $data = WPCD_POSTS_PENDING_TASKS_LOG()->get_data_by_id($posts[0]->ID);

                    // Set the new domain using data from our data array.
                    $data['new_domain'] = $data['wp_domain'];

                    // Remove any TEMPLATE flags from the new site.
                    // The standard SITE SYNC operation resets this flag so we have to remove it here.
                    // Be careful about moving this to an outer control block.  If you do, it might
                    // get executed for every site sync operation regardless of where it originated.
                    // If you do decide to move this to an outer control block then you have to check
                    // to make sure that the SITE SYNC operation is for a WC order and not from
                    // some other thing that triggered a SYNC operation.
                    // By keeping this function call here, if the site-sync failed then the template flag
                    // will NOT be removed.  That's not necessarily a side effect we want but for
                    // now is acceptable.
                    WPCD_WORDPRESS_APP()->wpcd_set_template_flag($new_app_post_id, false);

                    // Do something similar for the mt_version flags.
                    if (in_array(WPCD_WORDPRESS_APP()->get_mt_site_type($new_app_post_id), array('mt_version', 'mt_version_clone'), true)) {
                        WPCD_WORDPRESS_APP()->set_mt_site_type($new_app_post_id, '');
                    }

                    // Mark our pending record as complete.  Later, when the domain change is complete it will set a new pending record.
                    // The domain change complete hook will see that 'pending_tasks_type' element in our data array and create a new pending record.
                    // Also, we're removing some elements from the array because those are HUGE and there is no real need to have them in there now.
                    $data_to_save                     = $data;
                    $data_to_save['wc_subscriptions'] = '***removed***';
                    $data_to_save['wc_item']          = '***removed***';
                    WPCD_POSTS_PENDING_TASKS_LOG()->update_task_by_id($posts[0]->ID, $data_to_save, 'complete');

                    // Action hook to fire: wpcd_wordpress-app_do_change_domain_full_live - need $id and $args ($data).
                    do_action('wpcd_wordpress-app_do_change_domain_full_live', $new_app_post_id, $data);
                }
            }
        }
    }

    /**
     * When a site clone is complete we need to finalize some things if:
     * 1. It was because of a WC sites order and
     * 2. The order was a template site order.
     *
     * *** Note that changes to this function might also need to be done to the
     * site_change_domain_complete() function below the
     * execute_update_plan_site_change_domain_complete in core (copy-to-existing-site.php.)
     *
     * Action Hook: wpcd_{$this->get_app_name()}_site_clone_new_post_completed || wpcd_wordpress-app_site_clone_new_post_completed
     *
     * @param int    $new_app_post_id    The post id of the new app record.
     * @param int    $template_id        ID of the template site (source site being cloned).
     * @param string $name               The command name.
     */
    public function clone_site_complete($new_app_post_id, $template_id, $name)
    {

        // The name will have a format as such: command---domain---number.  For example: dry_run---cf1110.wpvix.com---905
        // Lets tear it into pieces and put into an array.  The resulting array should look like this with exactly three elements.
        // [0] => dry_run
        // [1] => cf1110.wpvix.com
        // [2] => 911
        $command_array = explode('---', $name);

        // if the command is to copy a site to a new server then we need to do some things.
        if ('clone-site' == $command_array[0]) {

            // Lets pull the logs.
            $logs = WPCD_WORDPRESS_APP()->get_app_command_logs($template_id, $name);

            // Was the command successful?
            $success = WPCD_WORDPRESS_APP()->is_ssh_successful($logs, 'clone_site.txt');

            if (true == $success) {

                // What is the domain for our new site?
                $domain = WPCD_WORDPRESS_APP()->get_domain_name($new_app_post_id);

                // Now check the pending tasks table for a record where the key=$new_app_post_id and type='wc-copy-local-template-site' and state='in-process'.
                $posts = WPCD_POSTS_PENDING_TASKS_LOG()->get_tasks_by_key_state_type($domain, 'in-process', 'wc-install-wp-from-local-template');

                /**
                 * Start process of changing domain on the copied template site. We're assuming $posts has one and only one item in it!
                 * If we got a record in here we have satisfied the criteria we outlined at the top of this function in order to proceed.
                 */
                if ($posts) {

                    // Grab our data array from pending tasks record...
                    $data = WPCD_POSTS_PENDING_TASKS_LOG()->get_data_by_id($posts[0]->ID);

                    // Set the new domain using data from our data array.
                    $data['new_domain'] = $data['wp_domain'];

                    // Mark our pending record as complete.  Later, when the domain change is complete it will set a new pending record.
                    // The domain change complete hook will see that 'pending_tasks_type' element in our data array and create a new pending record.
                    // Also, we're removing some elements from the array because those are HUGE and there is no real need to have them in there now.
                    $data_to_save                     = $data;
                    $data_to_save['wc_subscriptions'] = '***removed***';
                    $data_to_save['wc_item']          = '***removed***';
                    WPCD_POSTS_PENDING_TASKS_LOG()->update_task_by_id($posts[0]->ID, $data_to_save, 'complete');

                    /**
                     * Now update the app record with new data about the user, passwords etc.
                     * It is possible that some of these actions were already performed by
                     * the normal clone-site completion action hook and some of this is
                     * duplicate work. Better safe than sorry.
                     *
                     * Also, changes in this code block might need to be done in the
                     * site_change_domain_complete() function just below.
                     */
                    // Start by getting the app post to make sure it's valid.
                    $app_post = get_post($new_app_post_id);
                    if ($app_post) {
                        // reset the author since it probably has data from the template site.
                        $author    = get_user_by('email', $data['wp_email'])->ID;
                        $post_data = array(
                            'ID'          => $new_app_post_id,
                            'post_author' => $author,
                        );
                        wp_update_post($post_data);

                        // Handle Post Template Copy Actions including adding a new admin user.
                        $this->do_after_copy_template_actions($new_app_post_id, $data);

                        // @TODO - do we need to copy teams from the template site?  Probably not.

                        // Update domain, user id, password, email etc...
                        $update_items = array(
                            'wpapp_domain'             => $data['wp_domain'],
                            'wpapp_original_domain'    => $data['wp_domain'],
                            'wpapp_email'              => $data['wp_email'],
                            'wpapp_wc_order_id'        => $data['wp_wc_order_id'],
                            'wpapp_wc_product_id'      => $data['wp_wc_product_id'],
                            'wpapp_wc_subscription_id' => $data['wp_wc_subscription_id'],
                            'wpapp_wc_suppress_global_email' => $data['wp_wc_suppress_global_email'],
                        );
                        foreach ($update_items as $metakey => $value) {
                            update_post_meta($new_app_post_id, $metakey, $value);
                        }
                    }

                    // Remove any TEMPLATE flags from the new site.
                    // The standard SITE CLONE operation resets this flag so we have to remove it here.
                    // Be careful about moving this to an outer control block.  If you do, it might
                    // get executed for every site clone operation regardless of where it originated.
                    // If you do decide to move this to an outer control block then you have to check
                    // to make sure that the SITE CLONE operation is for a WC order and not from
                    // some other thing that triggered a CLONE operation.
                    // By keeping this function call here, if the site-sync failed then the template flag
                    // will NOT be removed.  That's not necessarily a side effect we want but for
                    // now is acceptable.
                    WPCD_WORDPRESS_APP()->wpcd_set_template_flag($new_app_post_id, false);

                    // Do something similar for the mt_version flags.
                    if (in_array(WPCD_WORDPRESS_APP()->get_mt_site_type($new_app_post_id), array('mt_version', 'mt_version_clone'), true)) {
                        WPCD_WORDPRESS_APP()->set_mt_site_type($new_app_post_id, '');
                    }

                    /**
                     * Maybe convert site to an mt tenant.
                     * If this proves to take a long time, causing timeouts,
                     *  we might have to restructure to use a background process instead.
                     * Changes here might need to be made to the
                     * site_change_domain_complete() function below.
                     */
                    $this->maybe_convert_to_tenant($new_app_post_id);

                    /**
                     * Send email to end user & issue SSL (if applicable).
                     * We're calling a function in this class that is otherwise called by an action hook.
                     */
                    $server_id = WPCD_WORDPRESS_APP()->get_server_id_by_app_id($new_app_post_id);
                    $this->register_app_meta_boxes($server_id, $new_app_post_id, $name, 'install_wp', 'wc-install-wp-from-local-template');  // Note that the fourth parameter, which is the name of the basecommand, is being hardcoded otherwise we'd be sending "replace_domain" instead.

                    // Mark our pending record as complete.  We're removing some elements from the array because those are HUGE and no real need to have them in there now.
                    $data_to_save                     = $data;
                    $data_to_save['wc_subscriptions'] = '***removed***';
                    $data_to_save['wc_item']          = '***removed***';
                    WPCD_POSTS_PENDING_TASKS_LOG()->update_task_by_id($posts[0]->ID, $data_to_save, 'complete');
                }
            }
        }
    }

    /**
     * When a domain change is complete from a template site, update the site records to contain all the other data it needs.
     *
     * We should only do this if we're on WC order and it's a template site.
     *
     * *** Note that changes to this function might also need to be done to the
     * clone_site_complete() function above as well as the
     * execute_update_plan_site_change_domain_complete in core (copy-to-existing-site.php.)
     *
     * Filter Hook: wpcd_{$this->get_app_name()}_site_change_domain_completed | wpcd_wordpress-app_site_change_domain_completed
     *
     * @param int    $id The id of the post app.
     * @param string $old_domain The domain we're changing from.
     * @param string $new_domain The domain we're changing to.
     * @param string $name The name of the command that was executed - it contains parts that we might need later.
     */
    public function site_change_domain_complete($id, $old_domain, $new_domain, $name)
    {

        // The name will have a format as such: command---domain---number.  For example: dry_run---cf1110.wpvix.com---905
        // Lets tear it into pieces and put into an array.  The resulting array should look like this with exactly three elements.
        // [0] => dry_run
        // [1] => cf1110.wpvix.com
        // [2] => 911
        $command_array = explode('---', $name);

        // Check to see if the command is to replace a domain otherwise exit.
        if ('replace_domain' == $command_array[0]) {

            // Lets pull the logs.
            $logs = WPCD_WORDPRESS_APP()->get_app_command_logs($id, $name);

            // Was the command successful?
            $success = WPCD_WORDPRESS_APP()->is_ssh_successful($logs, 'change_domain_full.txt');

            if (true == $success) {
                // now check the pending tasks table for a record where the key=$name and type='wc-copy-template-site' and state='not-ready'.
                $posts = WPCD_POSTS_PENDING_TASKS_LOG()->get_tasks_by_key_state_type($name, 'not-ready', 'wc-copy-template-site');

                /**
                 * Start process of updating the app cpt record. We're assuming $posts has one and only one item in it!
                 * If we got a record in here we have satisfied the criteria we outlined at the top of this function in order to proceed.
                 */
                if ($posts) {

                    // Grab our data array from pending tasks record...
                    $data = WPCD_POSTS_PENDING_TASKS_LOG()->get_data_by_id($posts[0]->ID);

                    /* Add cross-reference data to order lines and subscription lines */
                    $this->add_wp_data_to_wc_orders($id, $data);

                    /**
                     * Now update the app record with new data about the user, passwords etc.
                     *
                     * Changes in this code block might need to be done in the
                     * clone_site_complete() function just above.
                     */
                    // Start by getting the app post to make sure it's valid.
                    $app_post = get_post($id);

                    if ($app_post) {
                        // reset the author since it probably has data from the template site.
                        $author    = get_user_by('email', $data['wp_email'])->ID;
                        $post_data = array(
                            'ID'          => $id,
                            'post_author' => $author,
                        );
                        wp_update_post($post_data);

                        // Handle Post Template Copy Actions including adding a new admin user.
                        $this->do_after_copy_template_actions($id, $data);

                        // @TODO - do we need to copy teams from the template site?  Probably not.

                        // Update domain, user id, password, email etc...
                        $update_items = array(
                            'wpapp_domain'             => $data['wp_domain'],
                            'wpapp_original_domain'    => $data['wp_domain'],
                            'wpapp_email'              => $data['wp_email'],
                            'wpapp_wc_order_id'        => $data['wp_wc_order_id'],
                            'wpapp_wc_product_id'      => $data['wp_wc_product_id'],
                            'wpapp_wc_subscription_id' => $data['wp_wc_subscription_id'],
                            'wpapp_wc_suppress_global_email' => $data['wp_wc_suppress_global_email'],
                        );
                        foreach ($update_items as $metakey => $value) {
                            update_post_meta($id, $metakey, $value);
                        }
                    }
                    /* End update the app record with new data */

                    /**
                     * Maybe convert site to an mt tenant.
                     * If this proves to take a long time, causing timeouts,
                     * we might have to restructure to use a background process instead.
                     * Changes here might need to be made to the clone_site_complete()
                     * function above.
                     */
                    $this->maybe_convert_to_tenant($id);

                    /**
                     * Send email to end user & issue SSL (if applicable).
                     * We're calling a function in this class that is otherwise called by an action hook.
                     */
                    $server_id = WPCD_WORDPRESS_APP()->get_server_id_by_app_id($id);
                    $this->wpcd_wpapp_install_complete($server_id, $id, $name, 'install_wp', 'wc-install-wp-from-template');  // Note that the fourth parameter, which is the name of the basecommand, is being hardcoded otherwise we'd be sending "replace_domain" instead.

                    // Mark our pending record as complete.  We're removing some elements from the array because those are HUGE and no real need to have them in there now.
                    $data_to_save                     = $data;
                    $data_to_save['wc_subscriptions'] = '***removed***';
                    $data_to_save['wc_item']          = '***removed***';
                    WPCD_POSTS_PENDING_TASKS_LOG()->update_task_by_id($posts[0]->ID, $data_to_save, 'complete');
                }
            }
        }
    }

    /**
     * Handle Post Template Copy Actions including adding a new admin user.
     *
     * @param int   $id ID of the new site.
     * @param array $data Data from pending tasks.
     *
     * @return void.
     */
    public function do_after_copy_template_actions($id, $data)
    {

        // Get template site id.
        if (! empty($data['wp_template_app_id'])) {
            $template_site_post_id = $data['wp_template_app_id'];
        } else {
            return;  // bail since we have no template site.
        }

        /**
         * Get the real template id.
         * In an MT configuration, the template id we got above might be an
         * id for a template clone or version site.  We need to find the
         * parent which would be the real template and take our data from there.
         */
        $maybe_mt_parent = WPCD_WORDPRESS_APP()->get_mt_parent($template_site_post_id);
        if (! empty($maybe_mt_parent)) {
            $template_site_post_id = $maybe_mt_parent;
        }

        // Do we need to create a new admin or update an existing one?
        $create_new_admin = get_post_meta($template_site_post_id, 'wpcd_template_add_customer_to_site', true);
        switch ($create_new_admin) {
            case '0':
                // We're not updating the new site with anything, just recording the incoming data in the site metas.
                update_post_meta($id, 'wpapp_user', $data['wp_user']);
                update_post_meta($id, 'wpapp_password', WPCD()->encrypt($data['wp_password']));
                update_post_meta($id, 'wpapp_email', $data['wp_email']);
            case '1':
                // Add customer as new admin.
                $args['add_admin_user_name'] = $data['wp_user'];
                $args['add_admin_pw']        = $data['wp_password'];
                $args['add_admin_email']     = $data['wp_email'];
                do_action('wpcd_wordpress-app_add_new_wp_admin', $id, $args);

                // Update metas on new site to record the new credentials.
                update_post_meta($id, 'wpapp_user', $data['wp_user']);
                update_post_meta($id, 'wpapp_password', WPCD()->encrypt($data['wp_password']));
                break;
            case '2':
                // Update existing admin to match customer.
                $args['wps_user']         = get_post_meta($template_site_post_id, 'wpcd_template_existing_admin_id', true);
                $args['wps_new_email']    = $data['wp_email'];
                $args['wps_new_password'] = $data['wp_password'];
                do_action('wpcd_wordpress-app_change_wp_credentials', $id, $args);

                // Update metas on new site to record the new credentials.
                update_post_meta($id, 'wpapp_user', $args['wps_user']);
                update_post_meta($id, 'wpapp_password', WPCD()->encrypt($data['wp_password']));
                break;
        }

        // Do we need to change the site administration email to match the purchaser's email address?
        $change_site_admin_email = get_post_meta($template_site_post_id, 'wpcd_template_change_site_admin_email_after_copy', true);
        if (! empty($change_site_admin_email)) {
            $args['wps_option']           = 'admin_email';
            $args['wps_new_option_value'] = $data['wp_email'];
            do_action('wpcd_wordpress-app_update_wp_site_option', $id, $args);
        }

        // Do we need to stamp a version label on the new site record?
        $version_label = get_post_meta($template_site_post_id, 'wpcd_template_std_site_version_label', true);
        if (! empty($version_label)) {
            do_action('wpcd_wordpress-app_do_update_wpconfig_option', $id, 'WPCD_VERSION_LABEL', $version_label);
            update_post_meta($id, 'wpcd_app_std_site_version_label', $version_label);
        }

        // Do we need to stamp a template type on the new site record?
        $template_type = get_post_meta($template_site_post_id, 'wpcd_template_type', true);
        if (! empty($template_type)) {
            do_action('wpcd_wordpress-app_do_update_wpconfig_option', $id, 'WPCD_TEMPLATE_TYPE', $template_type, 'no');
            update_post_meta($id, 'wpcd_app_template_type', $template_type);
        }

        // Do we need to stamp a template name on the new site record?
        $template_name = get_post_meta($template_site_post_id, 'wpcd_template_name', true);
        if (! empty($template_name)) {
            do_action('wpcd_wordpress-app_do_update_wpconfig_option', $id, 'WPCD_TEMPLATE_NAME', $template_name, 'no');
            update_post_meta($id, 'wpcd_app_template_name', $template_name);
        }

        // Do we need to stamp a template group on the new site record?
        $template_group = get_post_meta($template_site_post_id, 'wpcd_template_group', true);
        if (! empty($template_group)) {
            do_action('wpcd_wordpress-app_do_update_wpconfig_option', $id, 'WPCD_TEMPLATE_GROUP', $template_group, 'no');
            update_post_meta($id, 'wpcd_app_template_group', $template_group);
        }

        // Do we need to stamp a template label on the new site record?
        $template_label = get_post_meta($template_site_post_id, 'wpcd_template_label', true);
        if (! empty($template_label)) {
            do_action('wpcd_wordpress-app_do_update_wpconfig_option', $id, 'WPCD_TEMPLATE_LABEL', $template_label, 'no');
            update_post_meta($id, 'wpcd_app_template_label', $template_label);
        }

        // Do we need to stamp a template name on the new site record?
        $template_tags = get_post_meta($template_site_post_id, 'wpcd_template_tags', true);
        if (! empty($template_tags)) {
            do_action('wpcd_wordpress-app_do_update_wpconfig_option', $id, 'WPCD_TEMPLATE_TAGS', $template_tags, 'no');
            update_post_meta($id, 'wpcd_app_template_tags', $template_tags);
        }
    }

    /**
     * Perhaps convert a site to a tenant in an MT tenant situation.
     *
     * *** Changes to this function should probably be done to the same
     * *** function [maybe_convert_to_tenant()] in core  (copy-to-existing-site.php.)
     *
     * @param int $id Postid of site that we might convert to a tenant.
     */
    public function maybe_convert_to_tenant($id)
    {

        if (false === wpcd_is_mt_enabled()) {
            return;
        }

        /**
         * Is there a parent id meta?
         * The presence of a mt parent meta value is what tells us that
         * The site should be an MT site.
         */
        $parent_id = WPCD_WORDPRESS_APP()->get_mt_parent($id);

        if (! empty($parent_id)) {
            $args['mt_product_template'] = $parent_id;
            $args['mt_version']          = WPCD_WORDPRESS_APP()->get_mt_version($id);
            /* Now fire the action located in the includes/core/apps/wordpress-app/tabs/multitenant-site.php file to convert the site. */
            do_action('wpcd_wordpress-app_do_mt_apply_version', $id, $args);
        }
    }

    /**
     * When a site is deleted we might need to delete the DNS
     * and adjust the max sites allowed in the customer profile.
     *
     * Action Hook: wpcd_before_remove_site_action
     *
     * @param int $id     ID of app record to be deleted.
     * @param int $action Action passed into the usual TABS functions.
     */
    public function site_delete($id, $action)
    {

        // Make sure that the action is indeed to remove the site.
        if (! in_array($action, array('remove', 'remove_full'), true)) {
            return;
        }

        // Check to see if this is a woocommerce order.  If it's not, exit.
        if (empty(get_post_meta($id, 'wpapp_wc_order_id', true))) {
            return;
        }

        if (! empty(get_post_meta($id, 'wpapp_original_domain', true))) {
            // Are we allowed to delete DNS entries?
            if (wpcd_get_option('wordpress_app_wc_sites_cf_auto_delete')) {
                $this->delete_dns(get_post_meta($id, 'wpapp_original_domain', true));
                $this->delete_dns(get_post_meta($id, 'wpapp_domain', true));
            }
        }

        // Update the customer meta with the number of sites allowed in the subscription.
        $this->adjust_customer_site_count_limit(-1);
    }



    /**
     * Return a new set of fields to the clone-site class if the number of sites a user is allowed has been exceeded.
     *
     * Filter Hook: wpcd_app_{$this->get_app_name()}_clone-site_get_fields | wpcd_app_wordpress-app_clone-site_get_fields
     *
     * @param array $new_fields This is usually a blank array - we will fill this out with the new fields to be shown on the clone-site tab.  We'll leave it blank if the number of sites the user is allowed is not exceeded.
     * @param array $existing_fields This is the existing set of fields in the metabox fields array.
     * @param int   $id The post ID we're working with - i.e.: the post id of the site the user is viewing.
     *
     * @return array $new_fields - we'll return a blank array or an array with the new fields that will completely override the existing fields on the clone site tab.
     */
    public function disable_clone_site($new_fields, $existing_fields, $id)
    {

        // If the current user is admin, just return.
        if (wpcd_is_admin()) {
            return $new_fields;
        }

        // If the current user is the owner (author) of the server the site is on or can view the server the site is on, just return.
        $server_id = WPCD_WORDPRESS_APP()->get_server_id_by_app_id($id);
        if (! empty($server_id)) {
            if (WPCD_WORDPRESS_APP()->wpcd_wpapp_server_user_can('view_server', $server_id)) {
                return $new_fields;
            }
        }

        // Check if current user has exceeded their count.  If so, return a special set of fields for the CLONE site tab indicating that cloning is disabled.
        if ($this->has_user_exceeded_allowed_site_count()) {

            $desc = __('You have met or exceeded the number of sites allowed in your subscription.  Cloning sites have been disabled.', 'wpcd');

            $new_fields[] = array(
                'name' => __('Clone Site', 'wpcd'),
                'tab'  => 'clone-site',
                'type' => 'heading',
                'desc' => $desc,
            );
        }

        return $new_fields;
    }

    /**
     * Returns true if the user has exceeded their allowed site count.
     *
     * @param int $user_id The user whose site count quota we're checking.
     *
     * @return boolean.
     */
    public function has_user_exceeded_allowed_site_count($user_id = 0)
    {

        // Make sure we have a user.
        if (empty($user_id)) {
            $user_id = get_current_user_id();
        }

        // If the user is an admin then just return false.
        if (wpcd_is_admin($user_id)) {
            return false;
        }

        // Get the number of sites the user is allowed.
        $allowed_site_count = (int) get_user_option('wpcd_wc_sites_allowed', $user_id);

        // Return right away if the count is negative - a negative number means the user isn't allowed to add any more sites.
        if ($allowed_site_count < 0) {
            return true;
        }

        // Return right away if the count is zero - it means we're not checking for the user.
        if (0 === $allowed_site_count) {
            return false;
        }

        // Grab the user site posts.  We have to loop through them and exclude some of them.
        $args = array(
            'post_type'      => 'wpcd_app',
            'post_status'    => 'private',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => array(
                array(
                    'key'   => 'app_type',
                    'value' => 'wordpress-app',
                ),
            ),
        );

        $sites = get_posts($args);

        $count = 0;
        foreach ($sites as $site) {

            $include = true;  // whether to include the post in the count.

            // Exclude site from count if its a staging site.
            if (WPCD_WORDPRESS_APP()->is_staging_site($site->ID)) {
                $include = false;
            }

            // Exclude site from count if the site is on a server the user can view.
            if ($include) {
                $server_id = WPCD_WORDPRESS_APP()->get_server_id_by_app_id($site->ID);
                if (! empty($server_id)) {
                    if (WPCD_WORDPRESS_APP()->wpcd_wpapp_server_user_can('view_server', $server_id)) {
                        $include = false;
                    }
                }
            }

            if ($include) {
                $count++;
            }
        }

        // Compare and return if necessary.
        if ($count >= $allowed_site_count) {
            return true;
        }

        // Got here?  Then user has not exceeded their limit and we will return false.
        return false;
    }
    /**
     * We'll check to see if we're allowed to perform the clone site action.
     *
     * We will not be allowed to perform the clone site action if the user has exceeded their alloted number of sites.
     * The logic is the same as the function disable_clone_site() above.
     *
     * We can disable fields on the clone sites tab using an earlier filter.
     * BUT, someone can still hack the html and submit data as if the fields were active.
     * So we need to apply this security filter.
     *
     * Filter Hook: wpcd_app_{$this->get_app_name()}_tab_actions_general_security_check | wpcd_app_wordpress-app_tab_actions_general_security_check
     *
     * @param array  $check This is a 2 element array - first element is boolean and will be set to FALSE if our checks fail and we need to disallow the clone.  The second element is the message that will be shown to the user.
     * @param int    $tab_id This is the tab id we're evaluating. Only if this is of value 'clone-site' should we do anything.  Otherwise, just return the $check parameter array.
     * @param string $action The action the user is attempting to perform.  Usually we can include this in our conditional checks but there is only one action on the clone tab anyway.
     * @param int    $id The post ID we're working with - i.e.: the post id of the site the user is viewing.
     *
     * @return array $new_fields - we'll return a blank array or an array with the new fields that will completely override the existing fields on the clone site tab.
     */
    public function check_clone_site_security($check, $tab_id, $action, $id)
    {

        // Return if not the clone site tab.
        if ('clone-site' <> $tab_id) {
            return $check;
        }

        // If the current user is admin, just return.
        if (wpcd_is_admin()) {
            return $check;
        }

        // If the current user is the owner (author) of the server the site is on or can view the server the site is on, just return.
        $server_id = WPCD_WORDPRESS_APP()->get_server_id_by_app_id($id);
        if (! empty($server_id)) {
            if (WPCD_WORDPRESS_APP()->wpcd_wpapp_server_user_can('view_server', $server_id)) {
                return $check;
            }
        }

        // Check if current user has exceeded their count.  If so, update the $check array to disable the site clone that the user has requested.
        if ($this->has_user_exceeded_allowed_site_count()) {

            $check['check'] = false;
            $check['msg']   = __('You have met or exceeded the number of sites allowed in your subscription.  Cloning sites have been disabled.', 'wpcd');
        }

        return $check;
    }
    /**
     * When a site is cloned, update the new record with the WC order id and other WC related fields.
     *
     * Action Hook: wpcd_{$this->get_app_name()}_site_clone_new_post_completed | wpcd_wordpress-app_site_clone_new_post_completed
     *
     * @param int    $new_app_post_id Post ID of new cloned site.
     * @param int    $original_app_post_id Post ID of original site.
     * @param string $command_name The command string for the clone operation.
     *
     * @return void.
     */
    public function site_clone_new_post_completed($new_app_post_id, $original_app_post_id, $command_name)
    {

        // Get order and subscription ids from the original post.
        $wc_order_id        = get_post_meta($original_app_post_id, 'wpapp_wc_order_id', true);
        $wc_subscription_id = get_post_meta($original_app_post_id, 'wpapp_wc_subscription_id', true);

        // Update new post with the WC order and subscription ids.
        if (! empty($wc_order_id) && ! empty($wc_subscription_id)) {
            update_post_meta($new_app_post_id, 'wpapp_wc_order_id', $wc_order_id);
            update_post_meta($new_app_post_id, 'wpapp_wc_subscription_id', $wc_subscription_id);
        }
    }


    /**
     * Send an email and possibly auto-issue SSL after a site has been installed.
     *
     * Action Hook: wpcd_command_wordpress-app_completed_after_cleanup
     *
     * @param int    $id                 post id of server.
     * @param int    $app_id             post id of wp app.
     * @param string $name               command name executed for new site.
     * @param string $base_command       basename of command.
     * @param string $pending_task_type  Task type to update when we're done. This is not part of the action hook definition - it's only passed in explicitly when this is called as a function.
     */
    public function wpcd_wpapp_install_complete($id, $app_id, $name, $base_command, $pending_task_type = 'wc-install-wp')
    {

        // If not installing an app, return.
        if ('install_wp' <> $base_command) {
            return;
        }

        $app_post = get_post($app_id);

        // Bail if not a post object.
        if (! $app_post || is_wp_error($app_post)) {
            return;
        }

        // Bail if not a WordPress app...
        if ('wordpress-app' <> WPCD_WORDPRESS_APP()->get_app_type($app_id)) {
            return;
        }

        // If the site wasn't the result of a WC order, then bail..
        if (empty(get_post_meta($app_id, 'wpapp_wc_order_id', true))) {
            return;
        }

        // Get app instance array.
        $instance = WPCD_WORDPRESS_APP()->get_app_instance_details($app_id);

        // If the app install was done via a background pending tasks process then get that pending task post data here...
        // We do that by checking the pending tasks table for a record where the key=domain and type='wc-install-wp' and state='in-process'.
        $pending_task_posts = WPCD_POSTS_PENDING_TASKS_LOG()->get_tasks_by_key_state_type(WPCD_WORDPRESS_APP()->get_domain_name($app_id), 'in-process', $pending_task_type);

        // Attempt to issue SSL.
        if (wpcd_get_option('wordpress_app_wc_sites_auto_issue_ssl')) {

            // Log what we're doing.
            do_action('wpcd_log_error', 'Attempting to set SSL for: ' . print_r($instance, true), 'trace', __FILE__, __LINE__);

            // Call SSL action.
            do_action('wpcd_wordpress-app_do_toggle_ssl_status_on', $app_id, 'ssl-status');
        }

        // Implement product package (site package) rules.
        $this->run_product_package_rules($id, $app_id, $pending_task_type);

        // Let other apps know that we are done with the WP app install.
        do_action('wpcd_wpapp_install_complete_wc_before_emails', $id, $app_id, $name, $base_command, $pending_task_type);

        // Who are we sending emails to?
        $user_email = get_the_author_meta('user_email', $app_post->post_author);

        // Now get a standard array of replaceable parameters.
        $tokens = WPCD_WORDPRESS_APP()->get_std_email_fields_for_user($app_post->post_author);

        // Add our own tokens that are unique to this email type.
        $tokens['WORDPRESSAPPWCSITESACCOUNTPAGE'] = wpcd_get_option('wordpress_app_wc_sites_general_wc_ty_acct_link_url');
        $tokens['IPV4']                           = WPCD_SERVER()->get_ipv4_address($id);
        $tokens['DOMAIN']                         = get_post_meta($app_id, 'wpapp_domain', true);
        $tokens['FRONTURL']                       = 'http://' . get_post_meta($app_id, 'wpapp_domain', true);
        $tokens['ADMINURL']                       = 'http://' . get_post_meta($app_id, 'wpapp_domain', true) . '/wp-admin';
        $tokens['SITEUSERNAME']                   = get_post_meta($app_id, 'wpapp_email', true);
        $tokens['SITEPASSWORD']                   = WPCD()->decrypt(get_post_meta($app_id, 'wpapp_password', true));
        $tokens['ORDERID']                        = get_post_meta($app_id, 'wpapp_wc_order_id', true);
        $tokens['SUBSCRIPTIONID']                 = get_post_meta($app_id, 'wpapp_wc_subscription_id', true);

        // Should we send the global email?
        $suppress_global_email = get_post_meta($app_id, 'wpapp_wc_suppress_global_email', true);

        // Convert suppress global email flag to boolean.
        $suppress_global_email = 'yes' !== $suppress_global_email ? false : true;

        // Allow devs to hook in and suppress global email.
        $suppress_global_email = apply_filters('wpcd_wpapp_wc_suppress_global_email', $suppress_global_email, $app_id, $id);

        if (! $suppress_global_email) {
            // Log what we're doing - in this case time to send email.
            do_action('wpcd_log_error', 'Sending new wc site global email for ' . print_r($instance, true), 'trace', __FILE__, __LINE__);

            // Get email subject & body from settings.
            $email_subject = apply_filters('wpcd_wpapp_wc_new_wpsite_global_email_subject', wpcd_get_option('wordpress_app_wc_sites_user_email_after_purchase_subject'));
            $email_body    = apply_filters('wpcd_wpapp_wc_new_wpsite_global_email_body', wpcd_get_option('wordpress_app_wc_sites_user_email_after_purchase_body'));

            // Replace tokens in email..
            $email_body = WPCD_WORDPRESS_APP()->replace_script_tokens($email_body, $tokens);

            // Let developers have their way again with the email contents.
            $email_body = apply_filters('wpcd_wpapp_wc_new_wpsite_global_email_body_final', $email_body, $app_id, $id, $tokens, $instance);

            // Send the email...
            if (! empty($email_subject) && ! empty($email_body)) {
                $sent = wp_mail(
                    $user_email,
                    $email_subject,
                    $email_body,
                    array('Content-Type: text/html; charset=UTF-8')
                );

                if (! $sent) {
                    do_action('wpcd_log_error', 'Could not send email for ' . print_r($instance, true), 'trace', __FILE__, __LINE__);
                }
            }
        } else {
            // No email should be sent so just log condition.
            do_action('wpcd_log_error', 'Sending global email suppressed for new wc site for ' . print_r($instance, true), 'trace', __FILE__, __LINE__);
        }
        // End possibly sending global email.

        // Send product-specific email if set.
        $wc_product_id         = get_post_meta($app_id, 'wpapp_wc_product_id', true);
        $product_email_body    = wp_kses_post(get_post_meta($wc_product_id, 'wpcd_app_wpapp_sites_product_ready_email_body', true));
        $product_email_subject = wp_kses_post(get_post_meta($wc_product_id, 'wpcd_app_wpapp_sites_product_ready_email_subject', true));
        if (! empty($product_email_body)) {

            // Log what we're doing - in this case time to send email.
            do_action('wpcd_log_error', 'Sending new wc site product email for ' . print_r($instance, true), 'trace', __FILE__, __LINE__);

            // Get email subject & body from settings.
            $email_subject = apply_filters('wpcd_wpapp_wc_new_wpsite_product_email_subject', $product_email_subject);
            $email_body    = apply_filters('wpcd_wpapp_wc_new_wpsite_product_email_body', $product_email_body);

            // Replace tokens in email..
            $email_body = WPCD_WORDPRESS_APP()->replace_script_tokens($email_body, $tokens);

            // Let developers have their way again with the email contents.
            $email_body = apply_filters('wpcd_wpapp_wc_new_wpsite_product_email_body_final', $email_body, $app_id, $id, $tokens, $instance, $wc_product_id);

            // Send the email...
            if (! empty($email_subject) && ! empty($email_body)) {
                $sent = wp_mail(
                    $user_email,
                    $email_subject,
                    $email_body,
                    array('Content-Type: text/html; charset=UTF-8')
                );

                if (! $sent) {
                    do_action('wpcd_log_error', 'Could not send product email for ' . print_r($instance, true), 'trace', __FILE__, __LINE__);
                }
            }
        }
        // End possibly send product-specific email.

        // Update pending tasks table if applicable.
        if ($pending_task_posts) {
            $data_to_save                     = WPCD_POSTS_PENDING_TASKS_LOG()->get_data_by_id($pending_task_posts[0]->ID);
            $data_to_save['wc_subscriptions'] = '***removed***';  // Large data that we have no need to carry around at this point.
            $data_to_save['wc_item']          = '***removed***';  // Large data that we have no need to carry around at this point.
            $data_to_save['wp_password']      = '***removed***';  // remove the password data from the pending log table.
            WPCD_POSTS_PENDING_TASKS_LOG()->update_task_by_id($pending_task_posts[0]->ID, $data_to_save, 'complete');
        }

        // Finally Finally maybe update WC order status to completed.
        $this->wpcd_wpapp_maybe_mark_wc_order_complete($app_id);

        // Let other apps know that the we are done with the WP app install.
        do_action('wpcd_wpapp_install_complete_wc', $id, $app_id, $name, $base_command, $pending_task_type);
    }

    /**
     * Maybe mark the WC order as complete.
     *
     * @param int $app_id The post id of the app record.
     */
    public function wpcd_wpapp_maybe_mark_wc_order_complete($app_id)
    {

        // Bail if the global setting to not set wc order status to 'complete' is enabled.
        if (true === (bool) wpcd_get_option('wordpress_app_wc_sites_do_not_complete_order')) {
            return;
        }

        // Get app post array/object.
        $app_post = get_post($app_id);

        // Bail if not a post object.
        if (! $app_post || is_wp_error($app_post)) {
            return;
        }

        // Bail if not a WordPress app.
        if ('wordpress-app' <> WPCD_WORDPRESS_APP()->get_app_type($app_id)) {
            return;
        }

        // If the app wasn't the result of a WC order, then bail.
        $wc_order_id = get_post_meta($app_id, 'wpapp_wc_order_id', true);
        if (empty($wc_order_id)) {
            return;
        }

        // Set order status to complete.
        $order = wc_get_order($wc_order_id);
        $order->update_status('completed');
    }
    /**
     * 1. Add a custom metabox on site details screen to
     * set a flag that indicates the site is a "template" site.
     * 2. Add a custom metabox on the customer screen to
     * allow the admin to ajust the site limit count for the user.
     *
     * Filter hook: rwmb_meta_boxes
     *
     * @param  array $metaboxes Existing array of metaboxes.
     *
     * @return array
     */
    public function register_app_meta_boxes($metaboxes)
    {

        // This ability is only available to admins.
        if (! wpcd_is_admin()) {
            return $metaboxes;
        }

        $prefix = 'wpcd_';

        // Register the metabox to set the TEMPLATE flag.
        $metaboxes[] = array(
            'id'      => $prefix . 'template_flags',
            'title'   => __('Sell Sites With WooCommerce - Template Settings', 'wpcd'),
            'pages'   => array('wpcd_app'), // displays on wpcd_app post type only.
            'class'   => 'wpcd-wpapp-actions',
            'columns' => array(
                'wpcd-wc-column-1' => array(
                    'size'  => 4,
                    'class' => 'wpcd-column wpcd-column-1 wpcd-wc-column wpcd-wc-column-1',
                ),
                'wpcd-wc-column-2' => array(
                    'size'  => 4,
                    'class' => 'wpcd-column wpcd-column-2 wpcd-wc-column wpcd-wc-column-2',
                ),
                'wpcd-wc-column-3' => array(
                    'size'  => 4,
                    'class' => 'wpcd-column wpcd-column-3 wpcd-wc-column wpcd-wc-column-3',
                ),
            ),
            'fields'  => array(

                // Add a checkbox field to indicate that this site is a template site.
                array(
                    'name'   => __('Site Template Flag', 'wpcd'),
                    'type'   => 'heading',
                    'column' => 'wpcd-wc-column-1',
                ),
                array(
                    'name'    => __('Is This a Template?', 'wpcd'),
                    'tooltip' => __('Use this site as a template to create new sites when selling sites with WooCommerce.', 'wpcd'),
                    'id'      => $prefix . 'is_template_site',
                    'type'    => 'checkbox',
                    'column'  => 'wpcd-wc-column-1',
                ),

                // Fields to indicate we should change the site administration email address to the purchaser.
                array(
                    'name'   => __('Change Site Administration Email Address', 'wpcd'),
                    'desc'   => __('Should the primary site administration email be changed?  You would usually enable this so that the customer can receive admin emails from the site.', 'wpcd'),
                    'type'   => 'heading',
                    'column' => 'wpcd-wc-column-2',
                ),
                array(
                    'name'    => __('Change Site Administration Email?', 'wpcd'),
                    'tooltip' => __('After this site is copied, should we change the site administration email address to that of the?', 'wpcd'),
                    'id'      => $prefix . 'template_change_site_admin_email_after_copy',
                    'type'    => 'checkbox',
                    'column'  => 'wpcd-wc-column-2',
                ),

                // Fields that control how the customer should be added to the site after the template is copied.
                array(
                    'name'   => __('Add Customer As Admin', 'wpcd'),
                    'desc'   => __('How should the customer be added to the new site?', 'wpcd'),
                    'type'   => 'heading',
                    'column' => 'wpcd-wc-column-3',
                ),

                array(
                    'name'    => '',
                    'tooltip' => __('After this site is copied, should we add the purchaser as a new user with the wp admin role?', 'wpcd'),
                    'id'      => $prefix . 'template_add_customer_to_site',
                    'type'    => 'radio',
                    'options' => array(
                        '0' => __('Do Not Add Customer', 'wpcd'),
                        '1' => __('Add As New Admin', 'wpcd'),
                        '2' => __('Change An Existing Admin', 'wpcd'),
                    ),
                    'inline'  => false,
                    'std'     => '0',
                    'column'  => 'wpcd-wc-column-3',
                ),

                array(
                    'name'    => __('Which Admin Should We Update?', 'wpcd'),
                    'tooltip' => __('If we need to change the credentials of an existing admin in the template, specify the template admin user id, user name or email address. This admin email and password will be changed.  However, the user name will NOT be updated nor will other attributes such as first name or last name be modified.', 'wpcd'),
                    'desc'    => __('Enter the user id, user name or email address of an admin that exists in the site template.', 'wpcd'),
                    'id'      => $prefix . 'template_existing_admin_id',
                    'type'    => 'text',
                    'column'  => 'wpcd-wc-column-3',
                ),

                // Misc.
                array(
                    'name' => __('Misc', 'wpcd'),
                    'desc' => '',
                    'type' => 'heading',
                ),
                array(
                    'name'    => __('Template Type', 'wpcd'),
                    'tooltip' => __('A free-form label usually used to indicate the industry or niche or subtype of the template. For example, if this is the only template to be used for INTERIOR DESIGN you might set this to interior-design. But if it is one of two templates for interior design you would set it something like interior-design-01. This value is injected into the wp-config.php file of the tenant site. Use it to control actions by a custom plugin - eg: to search and replace data that might only exist in one type of template.', 'wpcd'),
                    'id'      => $prefix . 'template_type',
                    'type'    => 'text',
                    'columns' => 4,
                ),
                array(
                    'name'    => __('Template Name', 'wpcd'),
                    'tooltip' => __('Another free-form label. This value is injected into the wp-config.php file of the tenant site. Use it to control actions by a custom plugin - eg: to search and replace data that might only exist in a particular template.', 'wpcd'),
                    'id'      => $prefix . 'template_name',
                    'type'    => 'text',
                    'columns' => 4,
                ),
                array(
                    'name'    => __('Template Group', 'wpcd'),
                    'tooltip' => __('Another free-form label. This value is injected into the wp-config.php file of the tenant site. Use it to control actions by a custom plugin - eg: to search and replace data that might only exist for a particular group of templates.', 'wpcd'),
                    'id'      => $prefix . 'template_group',
                    'type'    => 'text',
                    'columns' => 4,
                ),
                array(
                    'name'    => __('Template Label', 'wpcd'),
                    'tooltip' => __('Another free-form label. This value is injected into the wp-config.php file of the tenant site. Use it to control actions by a custom plugin.', 'wpcd'),
                    'id'      => $prefix . 'template_label',
                    'type'    => 'text',
                    'columns' => 4,
                ),
                array(
                    'name'    => __('Template Tags', 'wpcd'),
                    'tooltip' => __('Separate tags with commas. This value is injected into the wp-config.php file of the tenant site. Use it to control actions by a custom plugin - eg: to search and replace data that might only exist for templates with particular tags.', 'wpcd'),
                    'id'      => $prefix . 'template_tags',
                    'type'    => 'text',
                    'columns' => 4,
                ),
                array(
                    'name'    => __('Tag The New Site With This Version Label?', 'wpcd'),
                    'tooltip' => __('You probably do not need to set this for multi-tenant sites.  You can still set it if you want to manage two sets of version numbers. This label will be added as a meta on the new site record', 'wpcd'),
                    'id'      => $prefix . 'template_std_site_version_label',
                    'type'    => 'text',
                    'columns' => 4,
                ),
            ),

        );

        /**
         * Register a metabox in the user profile to allow the admin to adjust
         * the number of sites a user is allowed to create/clone.
         */
        global $wpdb;
        $metaboxes[] = array(
            'title'  => 'DVICD: Max Sites User Can Clone',
            'type'   => 'user', // THIS: Specifically for user.
            'fields' => array(
                array(
                    'name' => __('Max Sites Allowed', 'wpcd'),
                    'id'   => $wpdb->prefix . 'wpcd_wc_sites_allowed',  // The prefix is required to ensure we have unique values for each site in a multisite. Other areas also use update_user_option and get_user_option to ensure unique values in each site in a multisite.
                    'type' => 'number',
                    'size' => 10,
                ),
            ),
        );

        return $metaboxes;
    }
}
