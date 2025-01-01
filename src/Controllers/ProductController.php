<?php

namespace DVICD\Controllers;

class ProductController extends BaseController
{
        function __construct()
        {
                add_action('woocommerce_product_data_panels', array(&$this, 'wc_wpapp_options')); //product
                add_filter('woocommerce_product_data_tabs', array(&$this, 'wc_wpapp_options_tabs')); //product
                add_action('woocommerce_process_product_meta', array(&$this, 'wc_wpapp_save'), 10, 2); //product
                add_action('woocommerce_before_add_to_cart_button', array(&$this, 'wc_add_misc_attributes'), 10); //product
                add_filter('woocommerce_quantity_input_max', array(&$this, 'wc_set_max_quantity_input'), 10, 2); //product


        }

        /**
         * Add the contents to the WP Sites tab of the product add/modify page.
         */
        public function wc_wpapp_options()
        {

                echo '<div id="wpcd_app_wpapp_sites_product_data" class="panel woocommerce_options_panel hidden">';

                woocommerce_wp_checkbox(
                        array(
                                'id'          => 'wpcd_app_wpapp_sites_product',
                                'value'       => get_post_meta(get_the_ID(), 'wpcd_app_wpapp_sites_product', true),
                                'label'       => __('This is a WordPress Site', 'wpcd'),
                                'desc_tip'    => true,
                                'description' => __('When this product is purchased a new WordPress site will automatically be created.', 'wpcd'),
                        )
                );

                // Get list of servers.
                $server_list = wpcd_post_ids_to_key_value_array(wpcd_get_posts_by_permission('view_server', 'wpcd_app_server'));  // should get all posts since only admins can view this settings screen

                // Add a new option to the top of the server list array.
                // We HAVE to do it this way because the keys for the $server_list array are numeric post ids
                // and any other  array operation WILL CHANGE THE POST IDS that are the indexes on the array!
                $new_server_list = array(0 => __('Use Server List From Global Settings Screen', 'wpcd'));
                foreach ($server_list as $key => $value) {
                        $web_server_type = WPCD_WORDPRESS_APP()->get_web_server_description_by_id($key);
                        /* Translators: %s is the web server type such as NGINX or OLS */
                        $new_server_list[$key] = sprintf($value . ' [%s]', $web_server_type);
                        if (wpcd_is_mt_enabled()) {
                                /* Translators: %s is a server id. */
                                $new_server_list[$key] = sprintf('[%s] ', $key) . $new_server_list[$key];
                        }
                }

                // Get list of product packages into an array to prepare it for display.
                $wpcd_product_packages = WPCD_SITE_PACKAGE()->get_site_packages();

                // Show the server list.
                woocommerce_wp_select(
                        array(
                                'id'          => 'wpcd_app_wpapp_sites_server_options',
                                'value'       => get_post_meta(get_the_ID(), 'wpcd_app_wpapp_sites_server_options', true),
                                'label'       => __('Always place sites on this server', 'wpcd'),
                                'desc_tip'    => true,
                                'description' => __('When a user purchases this product, the new wp site will always be placed on this server.', 'wpcd'),
                                'options'     => $new_server_list,
                        )
                );

                // Get list of template sites.
                $site_template_posts = $this->get_template_sites();

                // Show the list of template sites.
                woocommerce_wp_select(
                        array(
                                'id'          => 'wpcd_app_wpapp_sites_template_site_options',
                                'value'       => get_post_meta(get_the_ID(), 'wpcd_app_wpapp_sites_template_site_options', true),
                                'label'       => __('Use A Template Site?', 'wpcd'),
                                'desc_tip'    => true,
                                'description' => __('When a user purchases this product, the new wp site will be a copy of this site.', 'wpcd'),
                                'options'     => $site_template_posts,
                        )
                );

                woocommerce_wp_text_input(
                        array(
                                'id'                => 'wpcd_app_wpapp_sites_max_sites',
                                'value'             => get_post_meta(get_the_ID(), 'wpcd_app_wpapp_sites_max_sites', true),
                                'label'             => __('Number of Sites', 'wpcd'),
                                'desc_tip'          => true,
                                'description'       => __('This controls the number of sites that a customer can clone. Only one value can be set for a customer - the last product a customer purchased will govern the total number of sites they can clone.  Please see our documentation for more information.', 'wpcd'),
                                'type'              => 'number',
                                'custom_attributes' => array('min' => 0),
                        )
                );

                woocommerce_wp_text_input(
                        array(
                                'id'                => 'wpcd_app_wpapp_sites_disk_quota',
                                'value'             => get_post_meta(get_the_ID(), 'wpcd_app_wpapp_sites_disk_quota', true),
                                'label'             => __('Disk Quota (MB)', 'wpcd'),
                                'desc_tip'          => true,
                                'description'       => __('The amount of disk space the user is allowed - should include database + files + backups.', 'wpcd'),
                                'type'              => 'number',
                                'custom_attributes' => array('min' => 0),
                        )
                );

                // Show the product packages list.
                woocommerce_wp_select(
                        array(
                                'id'          => 'wpcd_app_wpapp_sites_product_package',
                                'value'       => get_post_meta(get_the_ID(), 'wpcd_app_wpapp_sites_product_package', true),
                                'label'       => __('Implement This Product Package', 'wpcd'),
                                'desc_tip'    => true,
                                'description' => __('When a user purchases this product, the new wp site will follow the rules in this product package set.', 'wpcd'),
                                'options'     => $wpcd_product_packages,
                        )
                );

                /* Add a divider and header Product Notices */
                echo '<div style="border-bottom:solid 1px #0073AA; min-width: 100%; margin-top:30px; margin-bottom: 30px;">';
                echo '<div style="margin-left: 10px; margin-top: 10px; font-weight: bold; color:#0073AA;">' . __('Product Notices') . '</div>';
                echo '</div>';

                woocommerce_wp_text_input(
                        array(
                                'id'          => 'wpcd_app_wpapp_sites_product_notice_in_cart',
                                'value'       => get_post_meta(get_the_ID(), 'wpcd_app_wpapp_sites_product_notice_in_cart', true),
                                'label'       => __('Product Notice In Cart', 'wpcd'),
                                'desc_tip'    => true,
                                'description' => __('If set, this text will be shown beneath the product on the product detail page and in the cart.', 'wpcd'),
                        )
                );

                woocommerce_wp_checkbox(
                        array(
                                'id'          => 'wpcd_app_wpapp_sites_no_global_thankyou_notice',
                                'value'       => get_post_meta(get_the_ID(), 'wpcd_app_wpapp_sites_no_global_thankyou_notice', true),
                                'label'       => __('Hide global thank you notice?', 'wpcd'),
                                'desc_tip'    => true,
                                'description' => __('Do not show the global THANK YOU page notice defined in the settings area.', 'wpcd'),
                        )
                );

                woocommerce_wp_textarea_input(
                        array(
                                'id'          => 'wpcd_app_wpapp_sites_product_thankyou_notice',
                                'value'       => get_post_meta(get_the_ID(), 'wpcd_app_wpapp_sites_product_thankyou_notice', true),
                                'label'       => __('Thank you notice for this product', 'wpcd'),
                                'desc_tip'    => true,
                                'rows'        => 15,
                                'cols'        => 120,
                                'description' => __('This is the text that will be shown after checkout on the thankyou page. It will be in addition to any global text defined in settings unless the global settings are disabled by the checkbox above.', 'wpcd'),
                        )
                );

                /* Add a divider and header - Email Notifications */
                echo '<div style="border-bottom:solid 1px #0073AA; min-width: 100%; margin-top:30px; margin-bottom: 30px;">';
                echo '<div style="margin-left: 10px; margin-top: 10px; font-weight: bold; color:#0073AA;">' . __('Email Notifications') . '</div>';
                echo '</div>';

                woocommerce_wp_checkbox(
                        array(
                                'id'          => 'wpcd_app_wpapp_sites_no_site_ready_email',
                                'value'       => get_post_meta(get_the_ID(), 'wpcd_app_wpapp_sites_no_site_ready_email', true),
                                'label'       => __('Suppress global site ready email?', 'wpcd'),
                                'desc_tip'    => true,
                                'description' => __('Do not send global email defined in settings to customer when site is ready. This can be used when you are combining multiple products (eg: server + site) in a cart but you only want one product to send an email when things are ready.', 'wpcd'),
                        )
                );

                woocommerce_wp_text_input(
                        array(
                                'id'          => 'wpcd_app_wpapp_sites_product_ready_email_subject',
                                'value'       => get_post_meta(get_the_ID(), 'wpcd_app_wpapp_sites_product_ready_email_subject', true),
                                'label'       => __('Send this email when site is ready (Subject)', 'wpcd'),
                                'desc_tip'    => true,
                                'description' => __('Send this email when site is ready.  This will be in addition to the global email from settings unless you use the option above to disable it. The same tokens in the global email are applicable here.', 'wpcd'),
                        )
                );

                woocommerce_wp_textarea_input(
                        array(
                                'id'          => 'wpcd_app_wpapp_sites_product_ready_email_body',
                                'value'       => get_post_meta(get_the_ID(), 'wpcd_app_wpapp_sites_product_ready_email_body', true),
                                'label'       => __('Send this email when site is ready (Body)', 'wpcd'),
                                'desc_tip'    => true,
                                'rows'        => 15,
                                'cols'        => 120,
                                'description' => __('Send this email when site is ready.  This will be in addition to the global email from settings unless you use the option above to disable it. The same tokens in the global email are applicable here.', 'wpcd'),
                        )
                );

                /*
		woocommerce_wp_text_input( array(
			'id'                => 'wpcd_app_wpapp_sites_scripts_version',
			'value'             => get_post_meta( get_the_ID(), 'wpcd_app_wpapp_sites_scripts_version', true ),
			'label'   => __( 'Version of Scripts', 'wpcd' ),
			'description'       => __( 'Default version will be pulled from settings if nothing is entered here', 'wpcd' ),
			'type' => 'text',
		) );
		*/

                echo '</div>';
        }
        /**
	 * Get a list of apps that are "template" apps.
	 *
	 * @return array key-value array of post_id and post title (usually the domain name);
	 */
	public function get_template_sites() {

		$args = array(
			'post_type'      => 'wpcd_app',
			'post_status'    => 'private',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'   => 'wpcd_is_template_site',
					'value' => true,
				),
				array(
					'key'   => 'wpcd_app_mt_site_type',
					'value' => 'mt_version',
				),
				array(
					'key'   => 'wpcd_app_mt_site_type',
					'value' => 'mt_version_clone',
				),
				array(
					'key'   => 'wpcd_app_mt_site_type',
					'value' => 'mt_template_clone',
				),
			),

		);

		$site_template_posts = get_posts( $args );

		$return = array();
		foreach ( $site_template_posts as $site ) {
			$return[ $site->ID ] = $site->post_title;

			// Get the server the site is on.
			$server_name = WPCD_WORDPRESS_APP()->get_server_name( $site->ID );

			// MT Product Name.
			$wpcd_product_name = WPCD_WORDPRESS_APP()->get_product_name( $site->ID );

			// MT Version.
			$mt_version = WPCD_WORDPRESS_APP()->get_mt_version( $site->ID );

			// MT Site Type.
			$mt_site_type = WPCD_WORDPRESS_APP()->get_mt_site_type( $site->ID );

			// MT Parent Domain & ID.
			$mt_parent_id     = WPCD_WORDPRESS_APP()->get_mt_parent( $site->ID );
			$mt_parent_domain = WPCD_WORDPRESS_APP()->get_domain_name( $mt_parent_id );

			// What server is the parent domain on?
			$mt_parent_domain_server = '';
			if ( ! empty( $mt_parent_id ) ) {
				$mt_parent_domain_server = WPCD_WORDPRESS_APP()->get_server_name( $mt_parent_id );
			}

			// If we don't have a product, maybe it's stamped on the parent?
			if ( empty( $wpcd_product_name ) ) {
				$wpcd_product_name = WPCD_WORDPRESS_APP()->get_product_name( $mt_parent_id );
			}
			// If we don't have a version, maybe it's stamped on the parent?
			if ( empty( $mt_version ) ) {
				$mt_version = WPCD_WORDPRESS_APP()->get_mt_version( $mt_parent_id );
			}

			// Put all the data into the display string.
			if ( ! empty( $wpcd_product_name ) ) {
				$return[ $site->ID ] .= " ($wpcd_product_name)";
			}
			if ( ! empty( $mt_version ) ) {
				$return[ $site->ID ] .= " - ($mt_version)";
			}
			if ( ! empty( $server_name ) ) {
				/* Translators: %s is the name of the server that the site resides on. */
				$return[ $site->ID ] .= sprintf( __( ' on server %s', 'wpcd' ), $server_name );
			}
			if ( true === wpcd_is_mt_enabled() ) {

				// Prepend the string with the site id.
				$return[ $site->ID ] = "[$site->ID] " . $return[ $site->ID ];

				// Add the site type to the string.
				if ( ! empty( $mt_site_type ) && 'template' !== $mt_site_type ) {
					$return[ $site->ID ] .= " [$mt_site_type]";
				}

				// Add the parent domain to the string.
				if ( ! empty( $mt_parent_domain ) ) {
					if ( ! empty( $mt_parent_domain_server ) ) {
						/* Translators: %1$s is the name of the parent domain if site is an MT version site; %2$s is the server it resides on. */
						$return[ $site->ID ] .= sprintf( __( ' (Parent Domain: %1$s on server %2$s)', 'wpcd' ), $mt_parent_domain, $mt_parent_domain_server );
					} else {
						/* Translators: %s is the name of the parent domain if site is an MT version site. */
						$return[ $site->ID ] .= sprintf( __( ' (Parent Domain: %s)', 'wpcd' ), $mt_parent_domain );
					}
				}
			}
		}

		// Add a new option to the top of the array.
		// We HAVE to do it this way because the keys for the array are numeric post ids
		// and any other array operation WILL CHANGE THE POST IDS that are the indexes on the array!
		$new_array = array( 0 => __( 'Use Default WP Site', 'wpcd' ) );
		foreach ( $return as $key => $value ) {
			$new_array[ $key ] = $value;
		}
		$return = $new_array;

		return $return;

	}

        /**
         * Add the WordPress SITES tab to the product add/modify page.
         *
         * @param array $tabs List of current tabs.
         *
         * @return array $tabs Revised list of tabs with ours added.
         */
        public function wc_wpapp_options_tabs($tabs)
        {

                $tabs['wpcd_app_wpapp_sites'] = array(
                        'label'    => __('WordPress Sites', 'wpcd'),
                        'target'   => 'wpcd_app_wpapp_sites_product_data',
                        'class'    => array('show_if_subscription', 'show_if_variable'),
                        'priority' => 23,
                );
                return $tabs;
        }


        /**
         * Save the product details.
         *
         * @param int    $id The WC id of the product.
         * @param object $post The WC product.
         *
         * @return void
         */
        public function wc_wpapp_save($id, $post)
        {
                // Assume that this isn't a WP SITES product.
                delete_post_meta($id, 'wpcd_app_wpapp_sites_product');

                // Check to see if it is a WP Sites product.
                if (isset($_POST['wpcd_app_wpapp_sites_product'])) {
                        $product = sanitize_text_field($_POST['wpcd_app_wpapp_sites_product']);
                        update_post_meta($id, 'wpcd_app_wpapp_sites_product', $product);
                } else {
                        $product = '';
                }

                // Remove existing WP SITES data...
                delete_post_meta($id, 'wpcd_app_wpapp_scripts_version');
                delete_post_meta($id, 'wpcd_app_wpapp_sites_server_options');
                delete_post_meta($id, 'wpcd_app_wpapp_sites_template_site_options');
                delete_post_meta($id, 'wpcd_app_wpapp_sites_max_sites');
                delete_post_meta($id, 'wpcd_app_wpapp_sites_disk_quota');
                delete_post_meta($id, 'wpcd_app_wpapp_sites_product_package');
                delete_post_meta($id, 'wpcd_app_wpapp_sites_product_notice_in_cart');
                delete_post_meta($id, 'wpcd_app_wpapp_sites_no_global_thankyou_notice');
                delete_post_meta($id, 'wpcd_app_wpapp_sites_product_thankyou_notice ');
                delete_post_meta($id, 'wpcd_app_wpapp_sites_no_site_ready_email');
                delete_post_meta($id, 'wpcd_app_wpapp_sites_product_ready_email_subject');
                delete_post_meta($id, 'wpcd_app_wpapp_sites_product_ready_email_body');

                // Add new data.
                if ('yes' === $product) {
                        // Commenting out this line - will add it back in if we ever do scripts versions...
                        // update_post_meta( $id, 'wpcd_app_wpapp_scripts_version', sanitize_text_field( $_POST['wpcd_app_wpapp_sites_scripts_version'] ) );

                        $server_id = (int) $_POST['wpcd_app_wpapp_sites_server_options'];
                        update_post_meta($id, 'wpcd_app_wpapp_sites_server_options', $server_id);
                        update_post_meta($id, 'wpcd_app_wpapp_sites_template_site_options', sanitize_text_field($_POST['wpcd_app_wpapp_sites_template_site_options']));
                        update_post_meta($id, 'wpcd_app_wpapp_sites_max_sites', (int) sanitize_text_field($_POST['wpcd_app_wpapp_sites_max_sites']));
                        update_post_meta($id, 'wpcd_app_wpapp_sites_disk_quota', (int) sanitize_text_field($_POST['wpcd_app_wpapp_sites_disk_quota']));
                        update_post_meta($id, 'wpcd_app_wpapp_sites_product_package', (int) sanitize_text_field($_POST['wpcd_app_wpapp_sites_product_package']));
                        update_post_meta($id, 'wpcd_app_wpapp_sites_product_notice_in_cart', sanitize_text_field($_POST['wpcd_app_wpapp_sites_product_notice_in_cart']));
                        update_post_meta($id, 'wpcd_app_wpapp_sites_no_global_thankyou_notice', ! empty($_POST['wpcd_app_wpapp_sites_no_global_thankyou_notice']) ? sanitize_text_field($_POST['wpcd_app_wpapp_sites_no_global_thankyou_notice']) : '');
                        update_post_meta($id, 'wpcd_app_wpapp_sites_product_thankyou_notice', wp_kses_post($_POST['wpcd_app_wpapp_sites_product_thankyou_notice']));
                        update_post_meta($id, 'wpcd_app_wpapp_sites_no_site_ready_email', ! empty($_POST['wpcd_app_wpapp_sites_no_site_ready_email']) ? sanitize_text_field($_POST['wpcd_app_wpapp_sites_no_site_ready_email']) : '');
                        update_post_meta($id, 'wpcd_app_wpapp_sites_product_ready_email_subject', wp_kses_post($_POST['wpcd_app_wpapp_sites_product_ready_email_subject']));
                        update_post_meta($id, 'wpcd_app_wpapp_sites_product_ready_email_body', wp_kses_post($_POST['wpcd_app_wpapp_sites_product_ready_email_body']));
                }
        }

        /**
         * Add the WP Site attributes to the product detail page.
         */
        public function wc_add_misc_attributes()
        {
                global $product;

                // Make sure we're on the correct product type.
                $is_wpapp_sites = get_post_meta($product->get_id(), 'wpcd_app_wpapp_sites_product', true);
                if ('yes' !== $is_wpapp_sites) {
                        return;
                }

                // Opening div.
                echo '<div class="wpcd-wpapp-sites-custom-fields">';

                // Collect site name in this screen location if enabled.
                if ('1' === (string) wpcd_get_option('wordpress_app_wc_sites_ask_domain_name')) {
                        woocommerce_form_field(
                                'wpcd_app_wpapp_wc_domain',
                                array(
                                        'type'         => 'text',
                                        'class'        => array('form-row-wide', 'wpcd-wpapp-wc-domain'),
                                        'label'        => apply_filters('wpcd_wc_checkout_site_name_label', __('Site Name (Subdomain)', 'wpcd')),
                                        'description'  => apply_filters('wpcd_wc_checkout_site_name_desc', ''),
                                        'required'     => true,
                                        'autocomplete' => true,
                                        'autofocus'    => true,
                                        'maxlength'    => apply_filters('wpcd_wpapp_wc_max_subdomain_length', 20),
                                )
                        );
                }

                // Collect password in this screen location if enabled.
                if ('1' === (string) wpcd_get_option('wordpress_app_wc_sites_ask_uid_pw')) {
                        woocommerce_form_field(
                                'wpcd_app_wpapp_wc_password',
                                array(
                                        'type'         => 'text',
                                        'class'        => array('form-row-wide', 'wpcd-wpapp-wc-password'),
                                        'label'        => __('Password', 'wpcd'),
                                        'required'     => true,
                                        'autocomplete' => true,
                                        'autofocus'    => true,
                                )
                        );
                        echo __('Your login/username will be the email address used for this purchase.', 'wpcd');
                        echo '<br />';
                }

                // Let the user know that the site will be available for additional configuration after checkout.
                $message = get_post_meta($product->get_id(), 'wpcd_app_wpapp_sites_product_notice_in_cart', true);
                if (empty($message)) {
                        $message = wpcd_get_option('wordpress_app_wc_sites_product_notice_in_cart');
                }
                if (! empty($message)) {
                        echo wp_kses_post($message);
                }

                // Closing divs.
                echo '</div>
		<br clear="all">';
        }

        /**
         * Force max quantity to one for our wc sites products
         *
         * Filter Hook: woocommerce_quantity_input_max
         *
         * @param int          $max Maximum number of sites allowed for a product.
         * @param array|object $product WC Product Object.
         */
        public function wc_set_max_quantity_input($max, $product)
        {

                $is_wpapp_sites = $this->is_product_type($product->get_id(), 'wpapp_sites');
                if (true === $is_wpapp_sites) {
                        $max = 1;
                        return $max;
                }

                return $max;
        }
}
