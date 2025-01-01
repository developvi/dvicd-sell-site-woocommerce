<?php

namespace DVICD\Controllers;

class SubscriptionStatusController extends BaseController
{

    function __construct()
	{
        add_action( 'woocommerce_subscription_status_cancelled', array( &$this, 'wc_kill_wpapp' ), 10, 1 ); //subscription status
        add_action( 'woocommerce_subscription_status_expired', array( &$this, 'wc_kill_wpapp' ), 10, 1 ); //subscription status
        //subscription status
        add_action( 'woocommerce_subscription_status_updated', array( &$this, 'wc_manage_subscription_on_hold' ), 10, 3 ); // When a subscription is placed on hold.
        add_action( 'woocommerce_subscription_status_updated', array( &$this, 'wc_manage_subscription_off_hold' ), 10, 3 ); // When a subscription is taken off hold and reactivated.

        add_action( 'woocommerce_subscription_status_updated', array( &$this, 'wc_manage_subscription_pending_cancel' ), 10, 3 ); // When a subscription is placed in pending-cancel status.
         // end //subscription status


    }

    /**
	 * Kill the WP Site when subscription expires.
	 *
	 * Action Hook: woocommerce_subscription_status_cancelled | woocommerce_subscription_status_expired
	 *
	 * @param \WC_Subscription $subscription WC Subscription object.
	 */
	public function wc_kill_wpapp( \WC_Subscription $subscription ) {

		// Get the wc subscription id related to this request.
		$subs_id = $subscription->get_id();

		// Get the user id related to this subscription.
		$subs_user_id = $subscription->get_user_id();

		// Get list of orders for this subscription.
		$orders = $subscription->get_related_orders();
		if ( ! $orders ) {
			return;
		}

		// Get list of sites for each orders and loop through them.
		foreach ( $orders as $order_id ) {
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

			do_action( 'wpcd_log_error', 'Canceling WC WP SITES ' . count( $wp_sites ) . " instances in WC order ($order_id)", 'trace', __FILE__, __LINE__ );

			if ( $wp_sites ) {
				foreach ( $wp_sites as $id ) {
					// Get the subscription id from the site post.
					$site_subs_id = wpcd_maybe_unserialize( get_post_meta( $id, 'wpapp_wc_subscription_id', true ) );

					// Make sure all vars are ints.
					$subs_id      = (int) $subs_id;
					$site_subs_id = (int) $site_subs_id;

					/**
					 * We're only going to handle sites that match the subscription id.
					 * This means that if the order id matches but the subscription id doesn't then
					 * the site remains unaffected.
					 */
					if ( $site_subs_id === $subs_id && $site_subs_id > 0 && $subs_id > 0 ) {

						// log action...
						do_action( 'wpcd_log_error', "Canceling WP Site $id as part of WC order ($order_id)", 'trace', __FILE__, __LINE__ );

						// Add a meta to the site record so we can tell that the site should be deleted - just in case the delete fails...
						update_post_meta( $id, 'wpapp_wc_delete_tag', 'yes' );

						// Send Email - needs to be sent before the site record is possibly deleted later since we'll be pulling data from it.
						$this->wc_send_subscription_cancelled_email( $id, $subs_id, $order_id, $subscription );

						// Settings specify that site should not be deleted when canceled.  Check to see what other actions we should perform.
						if ( 1 === (int) wpcd_get_option( 'wordpress_app_wc_sites_delete_site_after_cancellation' ) ) {

							// Maybe disable the site.
							if ( boolval( wpcd_get_option( 'wordpress_app_wc_sites_on_cancel_disable_site' ) ) ) {
								do_action( 'wpcd_wordpress-app_do_toggle_site_status', $id, 'site-status', 'off' );
							}

							// Maybe password-protect the site.
							if ( boolval( wpcd_get_option( 'wordpress_app_wc_sites_on_cancel_password_protect_site' ) ) ) {
								do_action( 'wpcd_wordpress-app_do_site_enable_http_auth', $id );
							}

							// Maybe apply an admin lock to the site.
							if ( boolval( wpcd_get_option( 'wordpress_app_wc_sites_on_cancel_admin_lock_site' ) ) ) {
								WPCD_WORDPRESS_APP()->set_admin_lock_status( $id, 'on' );
							}

							// Maybe set an expiration date.
							$expiration_days = (int) wpcd_get_option( 'wordpress_app_wc_sites_on_cancel_expire_site' );
							if ( $expiration_days > 0 ) {
								WPCD_APP_EXPIRATION()->set_expiration( $id, $expiration_days, 'days' );
							}
						}

						// Settings specify that the site should be deleted when subscription is cancelled.
						if ( 2 === (int) wpcd_get_option( 'wordpress_app_wc_sites_delete_site_after_cancellation' ) ) {
							// Attempt to delete the site.
							// This action hook is handled in the tabs/misc.php file.
							do_action( 'wpcd_app_delete_wp_site', $id, 'remove_full' );

						}
					}
				}
			}

			/**
			 *  Adjust the number of sites allowed for the customer.
			 */
			$this->recalculate_user_site_count_limit( $subs_user_id );

		}
	}

	/**
	 * Loop through a user's subscription and get a count of all
	 * site limits.  Sum them up and update the user profile field.
	 *
	 * @param int $user_id Will default to current user if none is provided.
	 *
	 * @return void.
	 */
	public function recalculate_user_site_count_limit( $user_id = 0 ) {

		// Make sure we have a user id.
		if ( 0 === $user_id || empty( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		// Get user subscriptions.
		$subscriptions = wcs_get_users_subscriptions();

		// Set default max sites var.
		$max_user_sites = 0;

		foreach ( $subscriptions as $wc_subscription ) {
			if ( 'active' === $wc_subscription->get_status() ) {

				// Get items in subscription.
				$items = $wc_subscription->get_items();
				foreach ( $items as $item ) {
					$product_id     = $item->get_product_id();
					$is_wpapp_sites = get_post_meta( $product_id, 'wpcd_app_wpapp_sites_product', true );  // Is this product one that is a WP site purchase?
					$max_sites      = get_post_meta( $product_id, 'wpcd_app_wpapp_sites_max_sites', true );
				}

				// If not a site product, skip it.
				if ( 'yes' !== $is_wpapp_sites ) {
					continue;
				}

				// If we got here then we need to increment the count.
				$max_user_sites = $max_user_sites + (int) $max_sites;

			}
		}

		// Update user meta with count.
		$this->set_customer_site_count_limit( $user_id, $max_user_sites );

	}
		/**
	 * Set the max number of sites the customer is allowed in the customer record.
	 *
	 * @param int $user_id The user of the record that needs adjusting.
	 * @param int $qty New value to place in the customer record.
	 *
	 * @return void.
	 */
	public function set_customer_site_count_limit( $user_id, $qty ) {

		update_user_option( $user_id, 'wpcd_wc_sites_allowed', $qty );

	}

    /**
	 * Handle site status when a subscription is placed on hold.
	 *
	 * Action Hook: woocommerce_subscription_status_updated
	 *
	 * @param \WC_Subscription $subscription WC Subscription object.
	 * @param string           $new_wc_status New WooCommerce Subscription Status (eg: hold).
	 * @param string           $old_wc_status Old WooCommerce Subscription Status (eg: active).
	 */
	public function wc_manage_subscription_on_hold( \WC_Subscription $subscription, string $new_wc_status, string $old_wc_status ) {

		// if the new status is not hold, exit right away.
		if ( 'on-hold' !== $new_wc_status ) {
			return;
		}

		// Get list of orders for this subscription.
		$orders = $subscription->get_related_orders();
		if ( ! $orders ) {
			return;
		}

		// Get list of sites for each order and loop through them.
		foreach ( $orders as $order_id ) {
			$wp_sites = get_posts(
				array(
					'post_type'   => 'wpcd_app',
					'post_status' => 'private',
					'numberposts' => 300,
					'meta_query'  => array(
						array(
							'key'   => 'wpapp_wc_order_id',
							'value' => $order_id,
						),
					),
					'fields'      => 'ids',
				)
			);

			do_action( 'wpcd_log_error', 'Handling WC WP SITES subscription moving to hold status.' . count( $wp_sites ) . " instances in WC order ($order_id)", 'trace', __FILE__, __LINE__ );

			if ( $wp_sites ) {
				foreach ( $wp_sites as $id ) {

					// log action...
					do_action( 'wpcd_log_error', "Handling WP Site $id moving to hold status as part of WC order ($order_id)", 'trace', __FILE__, __LINE__ );

					// Maybe disable the site.
					if ( boolval( wpcd_get_option( 'wordpress_app_wc_sites_on_hold_disable_site' ) ) ) {
						do_action( 'wpcd_wordpress-app_do_toggle_site_status', $id, 'site-status', 'off' );
					}

					// Maybe password-protect the site.
					if ( boolval( wpcd_get_option( 'wordpress_app_wc_sites_on_hold_password_protect_site' ) ) ) {
						do_action( 'wpcd_wordpress-app_do_site_enable_http_auth', $id );
					}

					// Maybe apply an admin lock to the site.
					if ( boolval( wpcd_get_option( 'wordpress_app_wc_sites_on_hold_admin_lock_site' ) ) ) {
						WPCD_WORDPRESS_APP()->set_admin_lock_status( $id, 'on' );
					}
				}
			}
		}

	}

	/**
	 * Handle site status when a subscription is taken off hold and reactivated.
	 *
	 * Action Hook: woocommerce_subscription_status_updated
	 *
	 * @param \WC_Subscription $subscription WC Subscription object.
	 * @param string           $new_wc_status New WooCommerce Subscription Status (eg: hold).
	 * @param string           $old_wc_status Old WooCommerce Subscription Status (eg: active).
	 */
	public function wc_manage_subscription_off_hold( \WC_Subscription $subscription, string $new_wc_status, string $old_wc_status ) {

		// If the new status is not active, exit right away. If the old status is not hold exit right away.
		// We can only handle transitions from on-hold to active in this function.
		if ( 'active' !== $new_wc_status || 'on-hold' !== $old_wc_status ) {
			return;
		}

		// Get list of orders for this subscription.
		$orders = $subscription->get_related_orders();
		if ( ! $orders ) {
			return;
		}

		// Get list of sites for each order and loop through them.
		foreach ( $orders as $order_id ) {
			$wp_sites = get_posts(
				array(
					'post_type'   => 'wpcd_app',
					'post_status' => 'private',
					'numberposts' => 300,
					'meta_query'  => array(
						array(
							'key'   => 'wpapp_wc_order_id',
							'value' => $order_id,
						),
					),
					'fields'      => 'ids',
				)
			);

			do_action( 'wpcd_log_error', 'Handling WC WP SITES subscription moving to active status from on-hold status.' . count( $wp_sites ) . " instances in WC order ($order_id)", 'trace', __FILE__, __LINE__ );

			if ( $wp_sites ) {
				foreach ( $wp_sites as $id ) {

					// log action...
					do_action( 'wpcd_log_error', "Handling WP Site $id moving to active status from on-hold status as part of WC order ($order_id)", 'trace', __FILE__, __LINE__ );

					// Force the site to be re-enabled just in case it was disabled.
					do_action( 'wpcd_wordpress-app_do_toggle_site_status', $id, 'site-status', 'on' );

					do_action( 'wpcd_wordpress-app_do_site_disable_http_auth', $id );

					// Force the admin lock to be removed from the site if one was applied.
					WPCD_WORDPRESS_APP()->set_admin_lock_status( $id, 'off' );

				}
			}
		}

	}
    	/**
	 * Handle when a subscription is pending-cancel.
	 * Tasks:
	 *   - Send Pending Cancellation Email.
	 *
	 * Action Hook: woocommerce_subscription_status_updated
	 *
	 * @param \WC_Subscription $subscription WC Subscription object.
	 * @param string           $new_wc_status New WooCommerce Subscription Status (eg: hold).
	 * @param string           $old_wc_status Old WooCommerce Subscription Status (eg: active).
	 */
	public function wc_manage_subscription_pending_cancel( \WC_Subscription $subscription, string $new_wc_status, string $old_wc_status ) {

		// if the new status is not hold, exit right away.
		if ( 'pending-cancel' !== $new_wc_status ) {
			return;
		}

		// Get list of orders for this subscription.
		$orders = $subscription->get_related_orders();
		if ( ! $orders ) {
			return;
		}

		// Get the wc subscription id related to this request.
		$subs_id = $subscription->get_id();
		if ( ! $subs_id ) {
			return;
		}

		// Get list of sites for each order and loop through them.
		foreach ( $orders as $order_id ) {
			$wp_sites = get_posts(
				array(
					'post_type'   => 'wpcd_app',
					'post_status' => 'private',
					'numberposts' => 300,
					'meta_query'  => array(
						array(
							'key'   => 'wpapp_wc_order_id',
							'value' => $order_id,
						),
					),
					'fields'      => 'ids',
				)
			);

			do_action( 'wpcd_log_error', 'Handling WC WP SITES subscription moving to pending-cancel.' . count( $wp_sites ) . " instances in WC order ($order_id)", 'trace', __FILE__, __LINE__ );

			if ( $wp_sites ) {
				foreach ( $wp_sites as $id ) {

					// log action...
					do_action( 'wpcd_log_error', "Handling WP Site $id moving to pending-cancel as part of WC order ($order_id)", 'trace', __FILE__, __LINE__ );

					// Get post object for site.
					$app_post = get_post( $id );

					// Bail if not a post object.
					if ( ! $app_post || is_wp_error( $app_post ) ) {
						continue;
					}

					// Get server id.
					$server_id = WPCD_WORDPRESS_APP()->get_server_by_app_id( $id );
					if ( ! $server_id || is_wp_error( $server_id ) ) {
						continue;
					}

					// Get app instance array.
					$instance = WPCD_WORDPRESS_APP()->get_app_instance_details( $id );

					// Get email subject & body from settings.
					$email_subject = apply_filters( 'wpcd_wpapp_wc_wpsite_pending_cancellation_email_subject', wpcd_get_option( 'wordpress_app_wc_sites_user_email_pending_cancellation_subject' ) );
					$email_body    = apply_filters( 'wpcd_wpapp_wc_wpsite_pending_cancellation_email_body', wpcd_get_option( 'wordpress_app_wc_sites_user_email_pending_cancellation_body' ) );

					// Who are we sending emails to?
					$user_email = get_the_author_meta( 'user_email', $app_post->post_author );

					// Now get a standard array of replaceable parameters.
					$tokens = WPCD_WORDPRESS_APP()->get_std_email_fields_for_user( $app_post->post_author );

					// Add our own tokens that are unique to this email type.
					$tokens['IPV4']           = WPCD_WORDPRESS_APP()->get_ipv4_address( $id );
					$tokens['DOMAIN']         = WPCD_WORDPRESS_APP()->get_domain_name( $id );
					$tokens['ORDERID']        = $order_id;
					$tokens['SUBSCRIPTIONID'] = $subs_id;

					// Replace tokens in email..
					$email_body = WPCD_WORDPRESS_APP()->replace_script_tokens( $email_body, $tokens );

					// Let developers have their way again with the email contents.
					$email_body = apply_filters( 'wpcd_wpapp_wc_wpsite_pending_cancellation_email_body_final', $email_body, $id, $server_id, $tokens, $instance );

					// Send the email...
					if ( ! empty( $email_subject ) && ! empty( $email_body ) ) {
						$sent = wp_mail(
							$user_email,
							$email_subject,
							$email_body,
							array( 'Content-Type: text/html; charset=UTF-8' )
						);

						if ( ! $sent ) {
							do_action( 'wpcd_log_error', sprintf( 'Could not send pending cancellation email for subscription id: %s and order id: %s.', $order_id, $subs_id ), 'trace', __FILE__, __LINE__ );
						}
					} else {
						do_action( 'wpcd_log_error', sprintf( 'Could not send pending cancellation email because no subject or body was specified in settings. Subscription id: %s Order id: %s.', $order_id, $subs_id ), 'trace', __FILE__, __LINE__ );
					}
				}
			}
		}

	}

	/**
	 * Send emails to customer when a subscription is cancelled
	 *
	 * Action Hook: None - called from function wc_kill_wpapp (which is itself called from hooks: woocommerce_subscription_status_cancelled | woocommerce_subscription_status_expired)
	 *
	 * @param int              $app_id The post id of the site.
	 * @param int              $subs_id The WooCommerce subscription id.
	 * @param int              $order_id The WooCommerce order id.
	 * @param \WC_Subscription $subscription WC Subscription object.
	 */
	public function wc_send_subscription_cancelled_email( $app_id, $subs_id, $order_id, \WC_Subscription $subscription ) {

		// Get post object for site.
		$app_post = get_post( $app_id );

		// Bail if not a post object.
		if ( ! $app_post || is_wp_error( $app_post ) ) {
			return false;
		}

		// Get server id.
		$server_id = WPCD_WORDPRESS_APP()->get_server_by_app_id( $app_id );
		if ( ! $server_id || is_wp_error( $server_id ) ) {
			return false;
		}

		// Get app instance array.
		$instance = WPCD_WORDPRESS_APP()->get_app_instance_details( $app_id );

		// Get email subject & body from settings.
		$email_subject = apply_filters( 'wpcd_wpapp_wc_wpsite_pending_cancellation_email_subject', wpcd_get_option( 'wordpress_app_wc_sites_user_email_cancellation_subject' ) );
		$email_body    = apply_filters( 'wpcd_wpapp_wc_wpsite_pending_cancellation_email_body', wpcd_get_option( 'wordpress_app_wc_sites_user_email_cancellation_body' ) );

		// Who are we sending emails to?
		$user_email = get_the_author_meta( 'user_email', $app_post->post_author );

		// Now get a standard array of replaceable parameters.
		$tokens = WPCD_WORDPRESS_APP()->get_std_email_fields_for_user( $app_post->post_author );

		// Add our own tokens that are unique to this email type.
		$tokens['IPV4']           = WPCD_WORDPRESS_APP()->get_ipv4_address( $app_id );
		$tokens['DOMAIN']         = WPCD_WORDPRESS_APP()->get_domain_name( $app_id );
		$tokens['ORDERID']        = $order_id;
		$tokens['SUBSCRIPTIONID'] = $subs_id;

		// Replace tokens in email..
		$email_body = WPCD_WORDPRESS_APP()->replace_script_tokens( $email_body, $tokens );

		// Let developers have their way again with the email contents.
		$email_body = apply_filters( 'wpcd_wpapp_wc_wpsite_cancellation_email_body_final', $email_body, $app_id, $server_id, $tokens, $instance );

		// Send the email...
		if ( ! empty( $email_subject ) && ! empty( $email_body ) ) {
			$sent = wp_mail(
				$user_email,
				$email_subject,
				$email_body,
				array( 'Content-Type: text/html; charset=UTF-8' )
			);

			if ( ! $sent ) {
				do_action( 'wpcd_log_error', sprintf( 'Could not send cancellation email for subscription id: %s and order id: %s.', $order_id, $subs_id ), 'trace', __FILE__, __LINE__ );
			}
		} else {
			do_action( 'wpcd_log_error', sprintf( 'Could not send cancellation email because no subject or body was specified in settings. Subscription id: %s Order id: %s.', $order_id, $subs_id ), 'trace', __FILE__, __LINE__ );
		}

	}


}