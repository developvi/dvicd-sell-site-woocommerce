<?php

namespace DVICD\Controllers;

class FrontController extends BaseController
{
    function __construct()
    {

        // Action hook to add some values in the TITLE column when data is being shown on the front-end.
        add_action('wpcd_public_wpcd_app_table_after_row_actions_for_title', array($this, 'wpcd_app_table_content_public_title'), 10, 3); // no add

        // Filter hook to setup additional search fields specific to the wordpress-app.
        add_filter('wpcd_app_search_fields', array($this, 'wpcd_app_search_fields'), 10, 1);
        /* Add WC order id and subscription id to the APP SUMMARY column on the SITES list. */
        add_filter('wpcd_app_admin_list_summary_column', array($this, 'app_admin_list_show_wc_data'), 11, 2);


        /* Add some states to the app when its shown on the app list */
        add_filter('display_post_states', array($this, 'display_post_states'), 20, 2);

        /* Add to the list of fields that will be automatically stamped on the WordPress App Post */
		add_filter( 'wpcd_wordpress-app_add_wp_app_post_fields', array( &$this, 'wpcd_wpapp_add_wp_app_post_fields' ), 10, 1 ); // no add

    }

    /**
     * Show the order id and subscription id under the title column on the front-end.
     *
     * Action Hook: wpcd_public_wpcd_app_table_after_row_actions_for_title
     *
     * @param array   $item Post details.
     * @param string  $column_name This should always be "title".
     * @param boolean $primary Is this is the primary column.
     *
     * @return void Data is echoed to the screen.
     */
    public function wpcd_app_table_content_public_title($item, $column_name, $primary)
    {

        $wc_data = $this->get_order_and_subscription_id_for_display($item->ID);

        if (! empty($wc_data)) {
            // Put a horizontal line to separate out data section from others if there is data to show.
            $wc_data = '<hr />' . $wc_data;

            // Show the data.
            echo wp_kses_post($wc_data);
        }
    }

    /**
     * Include additional search fields when searching the app list.
     *
     * Filter Hook: wpcd_app_search_fields
     *
     * @see class-wpcd-posts-app.php - function wpcd_app_extend_admin_search
     *
     * @param array $search_fields The current list of fields.
     */
    public function wpcd_app_search_fields($search_fields)
    {

        $our_search_fields = array(
            'wpapp_wc_order_id',
            'wpapp_wc_product_id',
            'wpapp_wc_subscription_id',
        );

        $search_fields = array_merge($search_fields, $our_search_fields);

        return $search_fields;
    }





    /**
     * Add WC data to the app summary column that shows up in app admin list
     *
     * Filter Hook: wpcd_app_admin_list_summary_column
     *
     * @param string $column_data Data to show in the column.
     * @param int    $post_id Id of app post being displayed.
     *
     * @return string $column_data.
     */
    public function app_admin_list_show_wc_data($column_data, $post_id)
    {

        /* Bail out if the app being evaluated isn't a wp app. */
        if (WPCD_WORDPRESS_APP()->get_app_name() <> get_post_meta($post_id, 'app_type', true)) {
            return $column_data;
        }

        // Only show order id and subscription id data in this column if we're in the wp-admin area.
        if (is_admin()) {
            $wc_data = $this->get_order_and_subscription_id_for_display($post_id);
            /* Put a horizontal line to separate out data section from others if the column already contains data */
            if (! empty($column_data) && ! empty($wc_data)) {
                $column_data = $column_data . '<hr />';
            }
            $column_data .= $wc_data;
        }

        return $column_data;
    }
    /**
     * Create a formatted string with the order id and subscription id for display.
     *
     * @param int $post_id Post id of server.
     *
     * @return string
     */
    public function get_order_and_subscription_id_for_display($post_id)
    {

        // Initialize return string.
        $column_data = '';

        $wc_order_id        = get_post_meta($post_id, 'wpapp_wc_order_id', true);
        $wc_subscription_id = get_post_meta($post_id, 'wpapp_wc_subscription_id', true);
        if (is_array($wc_subscription_id)) {
            $wc_subscription_id = $wc_subscription_id[0];
        }
        if ($wc_order_id) {

            if (wpcd_is_admin()) {
                // Format the order id for display to the admin.
                $class_hint   = is_admin() ? 'wc_order_id' : 'wc_fe_order_id';
                $value        = __('Order Id: ', 'wpcd');
                $value        = WPCD_POSTS_APP()->wpcd_column_wrap_string_with_span_and_class($value, $class_hint, 'left');
                $link         = sprintf('<a href=%s>' . $wc_order_id . '</a>', esc_url(get_edit_post_link($wc_order_id)));
                $value       .= WPCD_POSTS_APP()->wpcd_column_wrap_string_with_span_and_class($link, $class_hint, 'right');
                $value        = WPCD_POSTS_APP()->wpcd_column_wrap_string_with_div_and_class($value, $class_hint);
                $column_data .= $value;

                // Format the subscription id for display to the admin.
                $class_hint   = is_admin() ? 'wc_subs_id' : 'wc_fe_subs_id';
                $value        = is_admin() ? __('Subs Id: ', 'wpcd') : __('Subscription Id: ', 'wpcd');
                $value        = WPCD_POSTS_APP()->wpcd_column_wrap_string_with_span_and_class($value, $class_hint, 'left');
                $link         = sprintf('<a href=%s>' . $wc_subscription_id . '</a>', esc_url(get_edit_post_link($wc_subscription_id)));
                $value       .= WPCD_POSTS_APP()->wpcd_column_wrap_string_with_span_and_class($link, $class_hint, 'right');
                $value        = WPCD_POSTS_APP()->wpcd_column_wrap_string_with_div_and_class($value, $class_hint);
                $column_data .= $value;
            } else {
                // Format for display on the front-end.
                $wc_order        = wc_get_order($wc_order_id);
                $wc_subscription = wcs_get_subscription($wc_subscription_id);

                // Format the order id for display to the end user.
                $class_hint   = is_admin() ? 'wc_order_id' : 'wc_fe_order_id';
                $value        = __('Order Id: ', 'wpcd');
                $value        = WPCD_POSTS_APP()->wpcd_column_wrap_string_with_span_and_class($value, $class_hint, 'left');
                $link         = sprintf('<a href=%s>' . $wc_order_id . '</a>', esc_url($wc_order->get_view_order_url()));
                $value       .= WPCD_POSTS_APP()->wpcd_column_wrap_string_with_span_and_class($link, $class_hint, 'right');
                $value        = WPCD_POSTS_APP()->wpcd_column_wrap_string_with_div_and_class($value, $class_hint);
                $column_data .= $value;

                // Format the subscription id for display to the end user on the front-end.
                if (! is_wp_error($wc_subscription) && ! empty($wc_subscription)) {
                    $class_hint   = is_admin() ? 'wc_subs_id' : 'wc_fe_subs_id';
                    $value        = is_admin() ? __('Subs Id: ', 'wpcd') : __('Subscription Id: ', 'wpcd');
                    $value        = WPCD_POSTS_APP()->wpcd_column_wrap_string_with_span_and_class($value, $class_hint, 'left');
                    $link         = sprintf('<a href=%s>' . $wc_subscription_id . '</a>', esc_url($wc_subscription->get_view_order_url()));
                    $value       .= WPCD_POSTS_APP()->wpcd_column_wrap_string_with_span_and_class($link, $class_hint, 'right');
                    $value        = WPCD_POSTS_APP()->wpcd_column_wrap_string_with_div_and_class($value, $class_hint);
                    $column_data .= $value;

                    // Get product names for front-end.
                    $items = $wc_subscription->get_items();
                    if (! empty($items)) {
                        foreach ($items as $item) {
                            $product_id     = $item->get_product_id();
                            $is_wpapp_sites = get_post_meta($product_id, 'wpcd_app_wpapp_sites_product', true);  // Is this product one that is a WP site purchase?
                            if ('yes' === $is_wpapp_sites) {
                                $class_hint   = is_admin() ? 'wc_product_name' : 'wc_fe_product_name';
                                $value        = __('Product: ', 'wpcd');
                                $value        = WPCD_POSTS_APP()->wpcd_column_wrap_string_with_span_and_class($value, $class_hint, 'left');
                                $item_name    = mb_substr($item->get_name(), 0, 30);
                                $link         = sprintf('<a href=%s>' . $item_name . '</a>', get_permalink($product_id));
                                $value       .= WPCD_POSTS_APP()->wpcd_column_wrap_string_with_span_and_class($link, $class_hint, 'right');
                                $value        = WPCD_POSTS_APP()->wpcd_column_wrap_string_with_div_and_class($value, $class_hint);
                                $column_data .= $value;
                            }
                        }
                    }
                }
            }
        }

        return $column_data;
    }
    /**
     * Set the post state display
     *
     * Filter Hook: display_post_states
     *
     * @param array  $states The current states for the CPT record.
     * @param object $post The post object.
     *
     * @return array $states
     */
    public function display_post_states($states, $post)
    {

        /* Show whether the site is scheduled for deletion */
        if ('wpcd_app' === get_post_type($post) && 'wordpress-app' == WPCD_WORDPRESS_APP()->get_app_type($post->ID)) {

            if ('yes' === get_post_meta($post->ID, 'wpapp_wc_delete_tag', true)) {
                $states['wpcd-wpapp-wc-to-be-deleted'] = __('WC Pending Delete', 'wpcd');
            }
        }

        return $states;
    }

    	/**
	 * Add to the list of fields that will be automatically stamped on the WordPress App Post
	 *
	 * Filter Hook: wpcd_{get_app_name()}_add_wp_app_post_fields | wpcd_wordpress-app_add_wp_app_post_fields
	 * The filter hook is located in the wordpress-app class.
	 *
	 * @param array $flds Current array of fields.
	 *
	 * @return array New array of fields with ours added into it.
	 */
	public function wpcd_wpapp_add_wp_app_post_fields( $flds ) {

		return array_merge( $flds, array( 'wc_order_id', 'wc_subscription_id', 'wc_suppress_global_email', 'wc_product_id' ) );

	}

}
