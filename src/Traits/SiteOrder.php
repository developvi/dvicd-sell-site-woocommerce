<?php
namespace DVICD\Traits;

trait SiteOrder{
    /**
	 * Increment or decrement the number of sites counter in the WP user profile record.
	 *
	 * @param int $qty Amount to increment or decrement by - use -ive number to decrement.
	 * @param int $user_id The user of the record that needs adjusting; defaults to current user.
	 *
	 * @return void.
	 */
	public function adjust_customer_site_count_limit( $qty, $user_id = 0 ) {

		// Get the user id if not provided.
		if ( empty( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		// Update the customer meta with the number of sites allowed in the subscription.
		$current_customer_site_count = (int) get_user_option( 'wpcd_wc_sites_allowed', $user_id );  // Get the number of sites the customer is allowed from the user meta. Usually nothing is in there already  if the customer is new.

		// Do not allow negative numbers!
		if ( $current_customer_site_count < 0 ) {
			$current_customer_site_count = 0;
		}

		// New count.
		$new_customer_site_count = $current_customer_site_count + $qty;

		// Check for and do not allow negative numbers (again)!
		if ( $new_customer_site_count < 0 ) {
			$new_customer_site_count = 0;
		}

		// Update customer record with new limits/count.
		$x = update_user_option( $user_id, 'wpcd_wc_sites_allowed', $new_customer_site_count );

	}

    	/**
	 * Implement any product package rules associated with the product.
	 *
	 * @param int    $id                      post id of server.
	 * @param int    $app_id                  post id of wp app.
	 * @param string $pending_task_type       Task type to update.
	 * @param string $is_subscription_switch  Set to true if this function is being called during a subscription switch.
	 */
	public function run_product_package_rules( $id, $app_id, $pending_task_type, $is_subscription_switch = false ) {

		$app_post = get_post( $app_id );

		// Bail if not a post object.
		if ( ! $app_post || is_wp_error( $app_post ) ) {
			return;
		}

		// Bail if not a WordPress app...
		if ( 'wordpress-app' <> WPCD_WORDPRESS_APP()->get_app_type( $app_id ) ) {
			return;
		}

		// If the site wasn't the result of a WC order, then bail..
		if ( empty( get_post_meta( $app_id, 'wpapp_wc_order_id', true ) ) ) {
			return;
		}

		// Get the domain.
		$domain = WPCD_WORDPRESS_APP()->get_domain_name( $app_id, );

		// Bail if we have no domain.
		if ( empty( $domain ) ) {
			return;
		}

		// Lets get the product id from the app record.
		$product_id = (string) get_post_meta( $app_id, 'wpapp_wc_product_id', true );

		// Bail if we don't have a product id.
		if ( empty( $product_id ) ) {
			return;
		}

		// With the product id, lets get the package id.
		$product_package_id = get_post_meta( $product_id, 'wpcd_app_wpapp_sites_product_package', true );

		// Bail if we don't have a package id.
		if ( empty( $product_package_id ) ) {
			return;
		}

		// Get the wc product categories.
		$product_categories   = get_the_terms( $product_id, 'product_cat' );
		$product_category_ids = array();
		foreach ( $product_categories as $term_obj ) {
			$product_category_ids[] = $term_obj->term_id;
		}

		// Get the class instance that will allow us to send dynamic commands to the server via ssh.
		$ssh = new WPCD_WORDPRESS_TABS();

		// Push product id and associated categories to wp-config.php.
		do_action( 'wpcd_wordpress-app_do_update_wpconfig_option', $app_id, 'WC_PRODUCT_ID', $product_id, 'no' );
		do_action( 'wpcd_wordpress-app_do_update_wpconfig_option', $app_id, 'WC_CATEGORIES', implode( ',', $product_category_ids ), 'no' );

		// If this is a new site and core site-package rules have not been executed make sure we do that first.
		// Core will not run site-package rules if we are using template sites.
		// So we have do it here before we do anything else.
		if ( false === $is_subscription_switch ) {
			$core_site_rules_complete = (bool) get_post_meta( $app_id, 'wpapp_site_package_core_rules_complete', true );
			if ( ! $core_site_rules_complete ) {
				// Stamp the package id on the site record - it might not already be there if the site is a template site (i.e.: if it's a clone or or site-sync).
				update_post_meta( $app_id, 'wpapp_site_package', $product_package_id );
				WPCD_WORDPRESS_APP()->handle_site_package_rules( $app_id, $product_package_id, false );
				update_post_meta( $app_id, 'wpapp_site_package_core_rules_completed_by_wc', true ); // Flag to let WPCD know that it was this routine that completed the core package rules - we might need this in the future.
			}
		}

		// If it's not a new site but we're doing a subscription switch, instead...
		// then we need to run the core package rules with the new package id.
		if ( true === $is_subscription_switch ) {
			update_post_meta( $app_id, 'wpapp_site_package_before_last_wc_subscription_switch', get_post_meta( $app_id, 'wpapp_site_package', true ) );  // Save the old package id.
			update_post_meta( $app_id, 'wpapp_site_package', $product_package_id ); // Add the new one to the site record.
			WPCD_WORDPRESS_APP()->handle_site_package_rules( $app_id, $product_package_id, true );  // Execute package rules.
			// Put the new site package id on the app record and flag it as being completed.
			update_post_meta( $app_id, 'wpapp_site_package_core_rules_completed_by_wc', true );
		}

		/**
		 * Bash scripts example output (in one long script - line breaks here for readability.):
		 * export WC_PRODUCT_ID=94404 WC_PRODUCT_CATEGORIES=36 DOMAIN=test004.wpcd.cloud &&
		 * sudo -E wget --no-check-certificate -O wpcd_package_script_subscription_switch.sh "https://gist.githubusercontent.com/elindydotcom/4c9f96ac48199284227c0ad687aedf75/raw/5295a17b832d8bb3748e0970ba0857063fd63247/wpcd_subscription_switch_sample_script" > /dev/null 2>&1
		 * && sudo -E dos2unix wpcd_package_script_subscription_switch.sh > /dev/null 2>&1 &&
		 * echo "Executing Product Package Subscription Switch Bash Custom Script..." &&
		 * sudo -E bash ./wpcd_package_script_subscription_switch.sh
		 */
		if ( WPCD_SITE_PACKAGE()->can_user_execute_bash_scripts() ) {
			// Prepare export vars for bash scripts.
			$exportvars = 'export WC_PRODUCT_ID=%s WC_PRODUCT_CATEGORIES=%s DOMAIN=%s';
			$exportvars = sprintf( $exportvars, $product_id, implode( ',', $product_category_ids ), $domain );

			// Call bash subscription switch script.
			if ( true === $is_subscription_switch ) {
				$script = get_post_meta( $product_package_id, 'wpcd_bash_scripts_subscription_switch_after', true );
				if ( ! empty( $script ) ) {
					$command  = $exportvars . ' && ';
					$command .= 'sudo -E wget --no-check-certificate -O wpcd_package_script_subscription_switch_after.sh "%s" > /dev/null 2>&1 ';
					$command  = sprintf( $command, $script );  // add the script name to the string.
					$command .= ' && sudo -E dos2unix wpcd_package_script_subscription_switch_after.sh > /dev/null 2>&1';
					$command .= ' && echo "Executing Product Package Subscription Switch Bash Custom Script..." ';
					$command .= ' && sudo -E bash ./wpcd_package_script_subscription_switch_after.sh';

					$action     = 'wc_subscription_switch';
					$raw_status = $ssh->submit_generic_server_command( $id, $action, $command, true );
				}
			}
		}

	}


}