<?php

namespace DVICD\Controllers;

class CartController extends BaseController
{
    function __construct()
    {
        add_filter('woocommerce_add_cart_item_data', array(&$this, 'wc_save_misc_attributes'), 10, 3); //cart
        add_action('woocommerce_checkout_create_order_line_item', array(&$this, 'wc_checkout_create_order_line_item'), 10, 4); //cart
        add_action('woocommerce_check_cart_items', array(&$this, 'wc_check_cart_items')); //cart


    }

    /**
     * Save the WP Site attributes when item is added to the cart.
     *
     * Filter Hook: woocommerce_add_cart_item_data
     *
     * @param array  $cart_item_data Custom data about the cart item being added.
     * @param string $product_id WC product id.
     * @param string $variation_id WC variation id.
     *
     * @return array $cart_item_data modified cart items.
     */
    public function wc_save_misc_attributes($cart_item_data, $product_id, $variation_id)
    {

        // Skip this whole section if we're handling a renewal order.
        if ($this->is_cart_renewal()) {
            return $cart_item_data;
        }

        // Is this a WP Sites Product?  If not get out.
        $is_wpapp_sites = get_post_meta($product_id, 'wpcd_app_wpapp_sites_product', true);
        if ('yes' !== $is_wpapp_sites) {
            return $cart_item_data;
        }

        // If this is a cart with a subscription switch exit.
        // *** Unfortunately this check doesn't work here because the item isn't in the cart yet.
        // So we'll have to deal with nonsensical data showing up in the cart during an upgrade.
        if ($this->is_cart_subscription_switch()) {
            return $cart_item_data;
        }

        foreach ($_POST as $param => $value) {
            if (strpos($param, 'wpcd_app_wpapp_wc_domain') !== false) {

                $cart_item_data[$param] = sanitize_text_field($value);

                // If it's the domain name, we need to transform it into a valid domain name!
                if ('wpcd_app_wpapp_wc_domain' === $param) {
                    $cart_item_data[$param] = wpcd_clean_domain(sanitize_title(sanitize_text_field($value)));
                }
            }

            if (strpos($param, 'wpcd_app_wpapp_wc_password') !== false) {

                $cart_item_data[$param] = wpcd_clean_alpha_numeric_dashes(sanitize_text_field($value));
            }
        }
        return $cart_item_data;
    }


    /**
     * Save the WP Site attributes to the order line item when item is added to the cart.
     *
     * Action Hook: woocommerce_checkout_create_order_line_item
     *
     * To make this work we're depending on the use of the
     * woocommerce_add_cart_item_data hook to pre-populate some meta from.
     */
    public function wc_checkout_create_order_line_item($item, $cart_item_key, $values, $order)
    {
        do_action('wpcd_log_error', 'wc_checkout_create_order_line_item called for order ' . $order->get_id() . ' with values ' . print_r($values, true), 'debug', __FILE__, __LINE__);

        if (isset($values['wpcd_app_wpapp_wc_domain'])) {
            $item->add_meta_data(
                'wpcd_app_wpapp_wc_domain',
                wpcd_clean_domain(sanitize_title(wc_clean($values['wpcd_app_wpapp_wc_domain']))),
                true
            );
        }
        if (isset($values['wpcd_app_wpapp_wc_password'])) {
            $item->add_meta_data(
                'wpcd_app_wpapp_wc_password',
                wpcd_clean_alpha_numeric_dashes(wc_clean($values['wpcd_app_wpapp_wc_password'])),
                true
            );
        }

        /* Sections start - Forcibly add-in a subdomain to the order line if we're not asking for one anywhere.  */
        $product_id = $item->get_product_id();

        // Is this product one that is a WP site purchase?
        $is_wpapp_sites = get_post_meta($product_id, 'wpcd_app_wpapp_sites_product', true);
        if ('yes' === $is_wpapp_sites) {
            $location_domain = (string) wpcd_get_option('wordpress_app_wc_sites_ask_domain_name');
            if ('0' === $location_domain || empty($location_domain)) {
                // Generate domain.
                $subdomain = $this->generate_sub_domain();
                $item->add_meta_data(
                    'wpcd_app_wpapp_wc_domain',
                    $subdomain,
                    true
                );
            }
        }
        /* End forcibly add in subdomain if we're not asking for one anywhere. */
    }
/**
	 * Generate a subdomain string.
	 *
	 * @return string The subdomain.
	 */
	public function generate_sub_domain() {

		$subdomain = wpcd_random_str( 12, '0123456789abcdefghijklmnopqrstuvwxyz' );

		// Allow developers to override.
		$subdomain = apply_filters( 'wpcd_wpapp_wc_new_wpsite_subdomain', $subdomain );

		return $subdomain;
	}

	/**
	 * When site is ready to be installed, figure out where to get the subdomain.
	 *
	 * @param object $order WC order object.
	 * @param object $item WC order item object.
	 *
	 * @return string The subdomain.
	 */
	public function get_sub_domain( $order, $item ) {

		// Setup variable to hold subdomain.
		$subdomain = '';

		// Is there an entry on the order item?
		$subdomain = wc_get_order_item_meta( $item->get_id(), 'wpcd_app_wpapp_wc_domain', true );

		// Check the cart/order.
		if ( empty( $subdomain ) ) {
			$subdomain = $order->get_meta( 'wpcd_app_wpapp_wc_domain' );
		}

		// If we still don't have a subdomain then generate a random one.
		if ( empty( $subdomain ) ) {
			$subdomain = wpcd_random_str( 12, '0123456789abcdefghijklmnopqrstuvwxyz' );
		}

		// Allow developers to override.
		$subdomain = apply_filters( 'wpcd_wpapp_wc_new_wpsite_subdomain', $subdomain );

		return $subdomain;
	}
    
	/**
	 * Validation checks in the WooCommerce cart.  Make sure that only
	 * one WP SITE item is in there.
	 *
	 * Action hook: woocommerce_check_cart_items
	 */
	public function wc_check_cart_items() {

		// Check to see if the cart contains an item.
		if ( $this->does_cart_contain_item_of_type( 'wpapp_sites' ) ) {
			// Find the item and check its qty...
			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				$is_wpapp_sites = $this->is_product_type( $cart_item['product_id'], 'wpapp_sites' );
				if ( true === $is_wpapp_sites ) {
					if ( $cart_item['quantity'] > 1 ) {
						wc_add_notice( __( 'Hi - you can only order ONE WP Site at a time - please reduce your quantity to just *1* in your cart.', 'wpcd' ), 'error' );
						break;
					}
				}
			}

			// Make sure there is only one line item for a wp item...
			$wp_site_count = 0;
			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				$is_wpapp_sites = $this->is_product_type( $cart_item['product_id'], 'wpapp_sites' );
				if ( true === $is_wpapp_sites ) {
					$wp_site_count ++;
				}
			}
			if ( $wp_site_count > 1 ) {
				wc_add_notice( __( 'Hi - it looks like you have added multiple WP Site items to your cart. You can only add one at a time - please remove the others.', 'wpcd' ), 'error' );
			}

			// If we're doing a subscription switch then make sure that we only have that item in the cart and nothing else.
			if ( $this->is_cart_subscription_switch() && count( WC()->cart->get_cart() ) > 1 ) {
				wc_add_notice( __( 'Hi - It looks like you are switching/upgrading/downgrading a subscription.  You cannot add other items to your cart while doing this.  Please remove the extra items before attempting to checkout.', 'wpcd' ), 'error' );
			}
		}
	}

}
