<?php

namespace DVICD\Controllers;

class SettingController extends \WPCD_APP_SETTINGS
{
    /**
     * Wordpress_SERVER_APP_SETTINGS constructor.
     */
    public function __construct()
    {

        // Setup WordPress and settings hooks.
        $this->hooks();
    }

    /**
     * Hook into WordPress and other plugins as needed.
     */
    private function hooks()
    {

        // Add new tabs to the WordPress settings tab.
        add_filter('wpcd_wordpress-app_settings_tabs', array($this, 'metabox_tabs'), 10, 1);

        // Add fields to the various tabs.
        add_filter('wpcd_wordpress-app_settings_fields', array($this, 'all_fields'), 10, 1);
    }

    /**
     * Return a list of tabs that will go inside the metabox.
     *
     * Filter Hook: wpcd_wordpress-app_settings_tabs
     *
     * @param array $tabs Array of existing tabs.
     *
     * @return array $tabs New array of tabs with ours included.
     */
    public function metabox_tabs($tabs)
    {

        if (! wpcd_is_woocommerce_activated()) {
            return $tabs;
        }

        $tabs = array_merge(
            $tabs,
            array(
                'wordpress-app-wc-sites' => array(
                    'label' => __('Sell WP Sites', 'wpcd'),
                    'icon'  => 'dashicons-admin-site-alt',
                ),
            )
        );

        return $tabs;
    }

    /**
     * Return an array that combines all fields that will go on all tabs.
     *
     * Filter Hook: wpcd_wordpress-app_settings_fields
     *
     * @param array $fields Current array of metabox fields.
     *
     * @return array New array of metabox fields.
     */
    public function all_fields($fields)
    {

        if (! wpcd_is_woocommerce_activated()) {
            return $fields;
        }
        $wc_site_general_fields            = $this->wc_site_general_fields();
        $wc_site_dns_fields                = $this->wc_site_dns_fields();
        $wc_site_promotional_fields        = $this->wc_site_promotion_fields();  // we're calling this function for now but not using its results in the array_merge statement before.
        $wc_site_general_link_fields       = $this->wc_site_general_link_fields();
        $wc_site_email_fields              = $this->wc_site_email_fields();
        $wc_site_cancellation_fields       = $this->wc_site_cancellation_handling_fields();
        $wc_site_subscription_hold_fields  = $this->wc_site_manage_subscription_holds_fields();
        $wc_site_cancellation_email_fields = $this->wc_site_cancellation_email_fields();
        $wc_site_checkout_fields           = $this->wc_site_checkout_fields();
        $wc_site_product_notice_fields     = $this->wc_site_product_notice_fields();
        $wc_order_processing_fields        = $this->wc_order_processing_fields();
        $all_fields                        = array_merge($fields, $wc_site_general_fields, $wc_site_dns_fields, $wc_site_checkout_fields, $wc_site_product_notice_fields, $wc_site_email_fields, $wc_site_cancellation_fields, $wc_site_subscription_hold_fields, $wc_site_cancellation_email_fields, $wc_site_general_link_fields, $wc_order_processing_fields);

        return $all_fields;
    }

    /**
     * Return array portion of field settings for use in the wc sites fields tab
     * Since none of these fields are implemented yet, we'll not be using this function.
     * We just wrote it in anticipation for future use.
     */
    public function wc_site_promotion_fields()
    {

        $fields = array(
            array(
                'type'  => 'heading',
                'name'  => __('Sell WordPress Sites With WooCommerce', 'wpcd'),
                'desc'  => __('These settings apply when you\'re using WooCommerce to sell WordPress sites from the front-end of your site.', 'wpcd'),
                'class' => 'wpcd_settings_larger_header_label',
                'tab'   => 'wordpress-app-wc-sites',
            ),
            array(
                'type' => 'heading',
                'name' => __('Promotions', 'wpcd'),
                'desc' => __('Use these settings to add links and other promotions to various aspects of the checkout and user account experience.', 'wpcd'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_promo_item01_url',
                'type'    => 'text',
                'name'    => __('URL To First Product Being Promoted', 'wpcd'),
                'std'     => get_site_url(),
                'tooltip' => __('You can add a link to the top of all subscriptions in the users Site Account screen.  This link can be to your store page or to a specific item.  Do NOT use a link that automatically adds a product to the cart.', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_promo_item01_text',
                'type'    => 'textarea',
                'name'    => __('Descriptive Text For Promotional Link', 'wpcd'),
                'desc'    => __('What is the text that the user should see for the promotional link?', 'wpcd'),
                'tooltip' => __('Example: Add a new site', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_promo_item01_button_option',
                'type'    => 'checkbox',
                'name'    => __('Make the Above Promo a Button?', 'wpcd'),
                'tooltip' => __('You can make the promo a button or just a standard text link. A button is more obvious but might annoy your users so be careful with this choice.', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'type' => 'divider',
                'tab'  => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_promo_item02_url',
                'type'    => 'text',
                'name'    => __('URL To Second Product Being Promoted', 'wpcd'),
                'std'     => get_site_url(),
                'tooltip' => __('You can add a link to the top of the site instances page when there are no sites on the page.  This link can be to your store page or to a specific item.  Do NOT use a link that automatically adds a product to the cart.', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_promo_item02_url',
                'type'    => 'textarea',
                'name'    => __('Descriptive Text For Promotional Link', 'wpcd'),
                'desc'    => __('What is the text that the user should see for the promotional link?', 'wpcd'),
                'tooltip' => __('Example: Add a new site', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_promo_item02_button_option',
                'type'    => 'checkbox',
                'name'    => __('Make the Above Promo a Button?', 'wpcd'),
                'tooltip' => __('You can make the promo a button or just a standard text link. A button is more obvious but might annoy your users so be careful with this choice.', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
        );

        return $fields;
    }

    /**
     *  Return array portion of field settings for use in the general section of the wc sites tab.
     */
    public function wc_site_general_fields()
    {

        $fields = array(
            array(
                'type'  => 'heading',
                'name'  => __('Sell WordPress Sites With WooCommerce', 'wpcd'),
                'desc'  => __('These settings apply when you\'re using WooCommerce to sell WordPress sites from the front-end of your site.', 'wpcd'),
                'class' => 'wpcd_settings_larger_header_label',
                'tab'   => 'wordpress-app-wc-sites',
            ),

            array(
                'type' => 'heading',
                'name' => __('General', 'wpcd'),
                'desc' => __('Select servers and other items needed to sell WP sites on the front-end.', 'wpcd'),
                'tab'  => 'wordpress-app-wc-sites',
            ),

            array(
                'type'       => 'post',
                'name'       => __('Allowed Servers', 'wpcd'),
                'id'         => 'wordpress_app_wc_sites_allowed_servers',
                'tooltip'    => __('Select the list of servers that can be used to host sites.  New sites will be randomly allocated to servers selected here.', 'wpcd'),
                'tab'        => 'wordpress-app-wc-sites',
                'post_type'  => 'wpcd_app_server',
                'query_args' => array(
                    'post_status'    => 'private',
                    'posts_per_page' => -1,
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                    'post__in'       => wpcd_get_posts_by_permission('view_server', 'wpcd_app_server'),  // should get all posts since only admins can view this settings screen.
                ),
                'field_type' => 'checkbox_tree',
            ),
            array(
                'id'         => 'wordpress_app_wc_sites_temp_domain',
                'type'       => 'text',
                'name'       => __('Temporary Domain', 'wpcd'),
                'tooltip'    => __('The domain under which a new site\'s temporary sub-domain will be created.', 'wpcd'),
                'desc'       => __('This needs to be a short domain - max 19 chars.'),
                'size'       => '20',
                'attributes' => array(
                    'maxlength' => '19',
                ),
                'tab'        => 'wordpress-app-wc-sites',
            ),

        );
        return $fields;
    }

    /**
     * Return array portion of field settings for use in the DNS section of the wc sites tab.
     */
    public function wc_site_dns_fields()
    {

        $fields = array(
            array(
                'type' => 'heading',
                'name' => __('Automatic DNS via CloudFlare', 'wpcd'),
                'desc' => __('When a site is provisioned it is assigned a subdomain based on the domain specified above. If this domain is setup in cloudflare, we can automatically point the IP address to the newly created subdomain. Note: If you want to use CLOUDFLARE when selling sites you must complete this section even if you have already completed CLOUDFLARE integration in the core plugin. This allows you to use a different default domain when selling sites.', 'wpcd'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_cf_enable',
                'type'    => 'checkbox',
                'name'    => __('Enable Cloudflare Auto DNS', 'wpcd'),
                'tooltip' => __('Turn this on so that when a new site is purchased, the newly created subdomain can be automatically added to your CloudFlare configuration.', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'         => 'wordpress_app_wc_sites_cf_zone_id',
                'type'       => 'text',
                'name'       => __('Zone ID', 'wpcd'),
                'desc'       => __('Your zone id can be found in the lower right of the CloudFlare overview page for your domain', 'wpcd'),
                'size'       => 35,
                'attributes' => array(
                    'spellcheck' => 'false',
                ),
                'tab'        => 'wordpress-app-wc-sites',
            ),
            array(
                'id'         => 'wordpress_app_wc_sites_cf_token',
                'type'       => 'text',
                'name'       => __('API Security Token', 'wpcd'),
                'desc'       => __('Generate a new token for your zone by using the GET YOUR API TOKEN link located in the lower right of the CloudFlare overview page for your domain.  This should use the EDIT ZONE DNS api token template.', 'wpcd'),
                'size'       => 35,
                'attributes' => array(
                    'spellcheck' => 'false',
                ),
                'tab'        => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_cf_disable_proxy',
                'type'    => 'checkbox',
                'name'    => __('Disable Cloudflare Proxy', 'wpcd'),
                'tooltip' => __('All new subdomains added to CloudFlare will automatically be proxied (orange flag turned on.) Check this box to turn off this behavior.', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_cf_auto_delete',
                'type'    => 'checkbox',
                'name'    => __('Auto Delete DNS Entry', 'wpcd'),
                'tooltip' => __('Should we attempt to delete the DNS entry for the domain at cloudflare when a site is deleted?', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),          // This should probably should be moved to it's own tab once we get more than one DNS provider.
            array(
                'id'      => 'wordpress_app_wc_sites_auto_issue_ssl',
                'type'    => 'checkbox',
                'name'    => __('Automatically Issue SSL', 'wpcd'),
                'tooltip' => __('If DNS was automatically updated after a new site is provisioned, attempt to get an SSL certificate from LETSENCRYPT?', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_auto_add_aaaa',
                'type'    => 'checkbox',
                'name'    => __('Add AAAA Record for IPv6?', 'wpcd'),
                'tooltip' => __('Add an AAAA DNS entry if the server has an IPv6 address?', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
        );
        return $fields;
    }

    /**
     * Return array portion of field settings for use in the links section of the wc sites tab.
     */
    public function wc_site_general_link_fields()
    {

        $fields = array(
            array(
                'type'  => 'heading',
                'name'  => __('Key Links', 'wpcd'),
                'desc'  => __('Define and setup URLs to key pages.', 'wpcd'),
                'class' => 'wpcd_settings_larger_header_label',
                'tab'   => 'wordpress-app-wc-sites',
            ),

            array(
                'type' => 'heading',
                'name' => __('Thank You Page', 'wpcd'),
                'desc' => __('Define data to be shown on the THANK YOU page after the user completes the check-out process.', 'wpcd'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_general_wc_thank_you_text_before',
                'type'    => 'textarea',
                'name'    => __('Thank You Page Text', 'wpcd'),
                'tooltip' => __('You will likely need to give the user some instructions on how to proceed after checking out. This will go at the top of the thank-you page after checkout - it will not completely replace any existing text though!', 'wpcd'),
                'rows'    => '10',
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_general_wc_show_acct_link_ty_page',
                'type'    => 'checkbox',
                'name'    => __('Link to the Account Page?', 'wpcd'),
                'desc'    => __('Show a link to the account page?', 'wpcd'),
                'tooltip' => __('You can offer the user an option to go straight to their account page after checkout.  Turn this on and fill out the two boxes below to enable this. IMPORTANT: For this to work, you do need the token ##WORDPRESSAPPWCSITESACCOUNTPAGE## in the thank you text above.', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_general_wc_ty_acct_link_url',
                'type'    => 'text',
                'name'    => __('URL to the Account Page', 'wpcd'),
                'std'     => get_site_url() . '/account',
                'tooltip' => __('You can offer the user an option to go straight to their account page after checkout. This link is the account page link and generally can be set to either 1. The page with the <em>wpcd_app_wpapp_wc_site_instances</em> shortcode or 2. A direct link to the DVICD screen in wp-admin.', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_general_wc_ty_acct_link_text',
                'type'    => 'text',
                'name'    => __('Account Page Link Text', 'wpcd'),
                'desc'    => __('Text that the user should see for the account link.', 'wpcd'),
                'tooltip' => __('Example: Go to your account page now', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),

            array(
                'id'      => 'wordpress_app_wc_sites_general_help_url',
                'type'    => 'text',
                'name'    => __('URL To Help Pages', 'wpcd'),
                'default' => get_site_url() . '/help',
                'tooltip' => __('Certain error messages will be more helpful to your users if it includes a link to additional help resources. Add that link here.', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
        );
        return $fields;
    }

    /**
     * Return array portion of field settings for use in the EMAILS section of the wc sites tab.
     */
    public function wc_site_email_fields()
    {

        $fields = array(
            array(
                'type' => 'heading',
                'name' => __('Confirmation Email For User After Site Is Provisioned', 'wpcd'),
                'desc' => __('This email is sent to the purchaser after a site has been purchased and is ready for use.  This message will be sent in addition to the standard WC order confirmation email but will only be sent AFTER the site is ready on the server.', 'wpcd'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
            array(
                'id'   => 'wordpress_app_wc_sites_user_email_after_purchase_subject',
                'type' => 'text',
                'name' => __('Subject', 'wpcd'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
            array(
                'id'   => 'wordpress_app_wc_sites_user_email_after_purchase_body',
                'type' => 'wysiwyg',
                'name' => __('Body', 'wpcd'),
                'desc' => __('Valid substitutions are:  ##FIRST_NAME##, ##LAST_NAME##, ##NICE_NAME##, ##WORDPRESSAPPWCSITESACCOUNTPAGE##, ##IPV4##, ##DOMAIN##, ##SITEUSERNAME##, ##SITEPASSWORD##, ##ORDERID##, ##SUBSCRIPTIONID##, ##FRONTURL##, ##ADMINURL##'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
        );
        return $fields;
    }

    /**
     * Return array portion of field settings for use in the CANCELLATION HANDLING section of the wc sites tab.
     */
    public function wc_site_cancellation_handling_fields()
    {

        $fields = array(
            array(
                'type' => 'heading',
                'name' => __('Site Options for Cancellation', 'wpcd'),
                'desc' => __('When a subscription is cancelled, what do you want to do with the site?', 'wpcd'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_delete_site_after_cancellation',
                'type'    => 'radio',
                'name'    => __('Delete Site Handling', 'wpcd'),
                'options' => array(
                    '1' => __('Just mark the site for deletion later', 'wpcd'),
                    '2' => __('Delete site when the subscription is cancelled', 'wpcd'),
                ),
                'tooltip' => __('If you mark the site for deletion, the admin can delete it later. Or, if you set an expiration limit the site can be automatically deleted after the expiration limit - BUT only if the expiration rules in settings allow for automatic deletion when a site expires.', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_on_cancel_password_protect_site',
                'type'    => 'checkbox',
                'name'    => __('Password Protect Site?', 'wpcd'),
                'tooltip' => __('If you choose not to delete the site and apply an HTTP AUTH password instead. This will prevent visitors from viewing it. Do not choose this option and the disable option below together!', 'wpcd'),
                'hidden'  => array('wordpress_app_wc_sites_delete_site_after_cancellation', '!=', '1'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_on_cancel_disable_site',
                'type'    => 'checkbox',
                'name'    => __('Disable Site?', 'wpcd'),
                'tooltip' => __('If you choose not to delete the site you can disable it completely instead. Disabling a site will prevent visitors from viewing it. Do not choose this option and the password option above together!', 'wpcd'),
                'hidden'  => array('wordpress_app_wc_sites_delete_site_after_cancellation', '!=', '1'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_on_cancel_admin_lock_site',
                'type'    => 'checkbox',
                'name'    => __('Activate Admin Lock?', 'wpcd'),
                'tooltip' => __('The admin lock will disable all tabs for the site if you choose not to delete it right away. Only an admin will be able to remove the lock.', 'wpcd'),
                'hidden'  => array('wordpress_app_wc_sites_delete_site_after_cancellation', '!=', '1'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_on_cancel_expire_site',
                'type'    => 'number',
                'min'     => 0,
                'size'    => 10,
                'name'    => __('Expire Site Days', 'wpcd'),
                'tooltip' => __('Expire the site after this number of days. The rules in the SITES tab of this SETTINGS area will govern what happens after expiration. Please see the SITE EXPIRATION section of the SITES tab above. ', 'wpcd'),
                'hidden'  => array('wordpress_app_wc_sites_delete_site_after_cancellation', '!=', '1'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
        );
        return $fields;
    }

    /**
     * Return array portion of field settings for use in the MANAGING SUBSCRIPTION HOLDS section of the wc sites tab.
     */
    public function wc_site_manage_subscription_holds_fields()
    {

        $fields = array(
            array(
                'type' => 'heading',
                'name' => __('Manage Subscription Holds', 'wpcd'),
                'desc' => __('When a subscription is placed on hold, what would you like to happen to sites? The most common reason for a subscription to be placed on hold is a failure in collecting a renewal payment.', 'wpcd'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_on_hold_password_protect_site',
                'type'    => 'checkbox',
                'name'    => __('Password Protect Site?', 'wpcd'),
                'tooltip' => __('This option will prevent visitors from viewing a site by showing a password popup. Only an admin can disable this option once it kicks in. Do not choose this option and the disable option below together!', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_on_hold_disable_site',
                'type'    => 'checkbox',
                'name'    => __('Disable Site?', 'wpcd'),
                'tooltip' => __('Disabling a site will prevent visitors from viewing it. Do not choose this option and the password option above together!', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_on_hold_admin_lock_site',
                'type'    => 'checkbox',
                'name'    => __('Apply Admin Lock?', 'wpcd'),
                'tooltip' => __('The admin lock will disable all tabs for the site. A the customer cannot manage it or reactivate it. Only an admin will be able to remove the lock.', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
        );
        return $fields;
    }

    /**
     * Return array portion of field settings for use in the CANCELLATION EMAILS section of the wc sites tab.
     */
    public function wc_site_cancellation_email_fields()
    {

        $fields = array(
            array(
                'type' => 'heading',
                'name' => __('Pending Cancellation Email', 'wpcd'),
                'desc' => __('This email is sent to the customer when a subscription is in pending-cancellation status.', 'wpcd'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
            array(
                'id'   => 'wordpress_app_wc_sites_user_email_pending_cancellation_subject',
                'type' => 'text',
                'name' => __('Subject', 'wpcd'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
            array(
                'id'   => 'wordpress_app_wc_sites_user_email_pending_cancellation_body',
                'type' => 'wysiwyg',
                'name' => __('Body', 'wpcd'),
                'desc' => __('Valid substitutions are:  ##FIRST_NAME##, ##LAST_NAME##, ##NICE_NAME##, ##IPV4##, ##DOMAIN##, ##ORDERID##, ##SUBSCRIPTIONID##'),
                'tab'  => 'wordpress-app-wc-sites',
            ),

            array(
                'type' => 'heading',
                'name' => __('Cancellation Email', 'wpcd'),
                'desc' => __('This email is sent to the customer when a subscription is fully cancelled.', 'wpcd'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
            array(
                'id'   => 'wordpress_app_wc_sites_user_email_cancellation_subject',
                'type' => 'text',
                'name' => __('Subject', 'wpcd'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
            array(
                'id'   => 'wordpress_app_wc_sites_user_email_cancellation_body',
                'type' => 'wysiwyg',
                'name' => __('Body', 'wpcd'),
                'desc' => __('Valid substitutions are:  ##FIRST_NAME##, ##LAST_NAME##, ##NICE_NAME##, ##IPV4##, ##DOMAIN##, ##ORDERID##, ##SUBSCRIPTIONID##'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
        );
        return $fields;
    }

    /**
     * Return array portion of field settings for use in the CHECKOUT FIELDS section of the wc sites tab.
     */
    public function wc_site_checkout_fields()
    {

        $fields = array(
            array(
                'type' => 'heading',
                'name' => __('Checkout Fields', 'wpcd'),
                'desc' => __('Which fields should be shown on checkout?', 'wpcd'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_ask_domain_name',
                'type'    => 'radio',
                'name'    => __('Where should we ask for the domain name?', 'wpcd'),
                'options' => array(
                    '0' => __('Do not ask user for domain name, we will generate a default domain name', 'wpcd'),
                    '1' => __('Request on product page', 'wpcd'),
                    '2' => __('Request on checkout page', 'wpcd'),
                ),
                'inline'  => false,
                'tab'     => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_ask_uid_pw',
                'type'    => 'radio',
                'name'    => __('Ask for password?', 'wpcd'),
                'options' => array(
                    '0' => __('Do not ask user for password, we will generate a strong unique password (recommended)', 'wpcd'),
                    '1' => __('Request on product page', 'wpcd'),
                    '2' => __('Request on checkout page', 'wpcd'),
                ),
                'inline'  => false,
                'tab'     => 'wordpress-app-wc-sites',
            ),
        );
        return $fields;
    }

    /**
     * Return array portion of field settings for use in the wc order processing section.
     */
    public function wc_order_processing_fields()
    {

        $fields = array(
            array(
                'type' => 'heading',
                'name' => __('Order Processing', 'wpcd'),
                'desc' => __('Handle WooCommerce order status transition between PROCESSING and COMPLETED.', 'wpcd'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_do_not_complete_order',
                'type'    => 'checkbox',
                'name'    => __('Do Not Complete Order', 'wpcd'),
                'tooltip' => __('WooCommerce will leave the order in the PROCESSING state by default. We usually switch to COMPLETE after server is deployed.  Check this box to leave the order in the PROCESSING state.', 'wpcd'),
                'desc'    => '',
                'tab'     => 'wordpress-app-wc-sites',
            ),

        );
        return $fields;
    }

    /**
     * Return array portion of field settings for use in the PRODUCT NOTICES section of the wc sites tab.
     */
    public function wc_site_product_notice_fields()
    {

        $fields = array(
            array(
                'type' => 'heading',
                'name' => __('Product Notices', 'wpcd'),
                'desc' => __('Notices shown at various points in the checkout process.', 'wpcd'),
                'tab'  => 'wordpress-app-wc-sites',
            ),
            array(
                'id'      => 'wordpress_app_wc_sites_product_notice_in_cart',
                'type'    => 'textarea',
                'name'    => __('Product Notice In Cart', 'wpcd'),
                'default' => '',
                'rows'    => 5,
                'desc'    => __('This message will be shown underneath all site products in the cart and on the product detail page.', 'wpcd'),
                'tooltip' => __('If set, it will be shown for all site products unless overridden by an individual product configuration. eg: Your site will be available for configuration in your dashboard after checkout.', 'wpcd'),
                'tab'     => 'wordpress-app-wc-sites',
            ),
        );
        return $fields;
    }
}
