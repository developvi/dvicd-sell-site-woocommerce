<?php

namespace DVICD\Controllers;

class CheckoutController extends BaseController
{
	function __construct()
	{
		// The following hooks are for things on the Woocommerce checkout page - collect domain and password fields.
		add_action('woocommerce_checkout_before_customer_details', array(&$this, 'wc_checkout_fields_before_billing_details'), 20);  // Ask user for domain and password fields on checkout page.
		add_action('woocommerce_checkout_process', array(&$this, 'wc_checkout_fields_validate'), 20); // Validate the domain and password fields on the checkout page.
		add_action('woocommerce_checkout_create_order', array(&$this, 'wc_checkout_fields_update_meta'), 10, 2);  // Save the domain and password fields from checkout page.
		add_filter('woocommerce_thankyou_order_received_text', array(&$this, 'wc_thankyou_show_checkout_fields'), 20, 3);  // Show the custom checkout fields on the thank you page.
		add_filter('woocommerce_thankyou_order_received_text', array(&$this, 'wc_thankyou_order_received_text'), 20, 3);
		add_filter('woocommerce_get_item_data', array(&$this, 'wc_show_misc_attributes'), 10, 2);
	}

	/**
	 * Add the domain and password fields to the WC checkout page if necessary.
	 *
	 * Action Hook:woocommerce_checkout_before_customer_details
	 *
	 * @return void
	 */
	public function wc_checkout_fields_before_billing_details()
	{

		// Skip this whole section if we're handling a renewal order.
		if ($this->is_cart_renewal()) {
			return;
		}

		// Skip this whole section if we're handling a subscription switch.
		if ($this->is_cart_subscription_switch()) {
			return;
		}

		// Grab WC checkout object.
		$checkout = WC()->checkout;

		// Skip if a WordPress site order isn't present in the cart.
		if (! $this->does_cart_contain_item_of_type('wpapp_sites')) {
			return;
		}

		// Get WPCD configuration item that controls whether fields need to be painted on the checkout form.
		$location_domain = (string) wpcd_get_option('wordpress_app_wc_sites_ask_domain_name');
		$location_pw     = (string) wpcd_get_option('wordpress_app_wc_sites_ask_uid_pw');

		// Bail out if we don't have to show any fields .
		if ((empty($location_domain) || '0' === $location_domain) && (empty($location_pw) || '0' === $location_pw)) {
			return;
		}

		// Open form div.
		echo '<div id="wpcd_sites_custom_checkout_fields_wrap">';

		// Header on the form.
		echo '<h3>' . apply_filters('wpcd_wc_checkout_site_information_label_header', __('Site Information', 'wpcd')) . '</h3>';

		// Collect site name in this screen location if enabled.
		if ('2' === $location_domain) {
			woocommerce_form_field(
				'wpcd_app_wpapp_wc_domain',
				array(
					'type'         => 'text',
					'label'        => apply_filters('wpcd_wc_checkout_site_name_label', __('Site Name (Subdomain)', 'wpcd')),
					'description'  => apply_filters('wpcd_wc_checkout_site_name_desc', ''),
					'class'        => array('form-row-wide', 'wpcd-wpapp-wc-domain'),
					'required'     => true,
					'autocomplete' => true,
					'autofocus'    => true,
					'maxlength'    => apply_filters('wpcd_wpapp_wc_max_subdomain_length', 20),
				),
				$checkout->get_value('wpcd_app_wpapp_wc_domain')
			);
		}

		// Collect password in this screen location if enabled.
		if ('2' === $location_pw) {
			woocommerce_form_field(
				'wpcd_app_wpapp_wc_password',
				array(
					'type'         => 'text',
					'class'        => array('form-row-wide', 'wpcd-wpapp-wc-password'),
					'label'        => apply_filters('wpcd_wc_checkout_site_password_label', __('Password', 'wpcd')),
					'required'     => true,
					'autocomplete' => true,
					'autofocus'    => true,
				)
			);
			echo __('Your login/username will be the email address used for this purchase.', 'wpcd');
			echo '<br />';
		}

		// Close form div.
		echo '</div>';
	}

	/**
	 * Validate the domain and password fields to the WC checkout page if necessary.
	 *
	 * Action Hook:woocommerce_checkout_process
	 *
	 * @return void
	 */
	public function wc_checkout_fields_validate()
	{

		// Skip this whole section if we're handling a renewal order.
		if ($this->is_cart_renewal()) {
			return;
		}

		// Skip this whole section if we're handling a subscription switch.
		if ($this->is_cart_subscription_switch()) {
			return;
		}

		// Skip if the order is not an order for a new WordPress site.
		if (! $this->does_cart_contain_item_of_type('wpapp_sites')) {
			return;
		}

		// Get WPCD configuration item that controls whether fields need to be painted on the checkout form.
		$location_domain = (string) wpcd_get_option('wordpress_app_wc_sites_ask_domain_name');
		$location_pw     = (string) wpcd_get_option('wordpress_app_wc_sites_ask_uid_pw');

		// We asked for the domain so lets validate it.
		if ('2' === $location_domain) {
			$domain = apply_filters('dvicd_wpapp_wc_subdomain', $_POST['wpcd_app_wpapp_wc_domain']);
			// Make sure the domain name is not empty.
			if (empty($domain)) {
				wc_add_notice(__('Site/Domain information should not be empty.', 'wpcd'), 'error');
			}

			// Make sure there are no invalid characters in the domain.
			 $check_domain = apply_filters('dvicd_clean_domain', wpcd_clean_domain(sanitize_title(wc_clean(($_POST['wpcd_app_wpapp_wc_domain'])))),$_POST['wpcd_app_wpapp_wc_domain']);
			if ($domain <> $check_domain) {
				/* Translators: %s is the correct domain. */
				wc_add_notice(sprintf(__('Site/Domain format is incorrect. It should probably be %s. Note that only lower-case alphanumerics and dashes are allowed.', 'wpcd'), $check_domain), 'error');
			}

			// Make sure that the domain name does not already exist.
			$domain_root = wpcd_get_option('wordpress_app_wc_sites_temp_domain');
			if (empty($domain_root)) {
				wc_add_notice(__('The domain root is not set for this checkout process - please contact the store owner. This is a problem with the store itself and only the store owner can resolve it.', 'wpcd'), 'error');
			}
			$full_domain = apply_filters('dvicd_wpapp_wc_domain_root', $domain . '.' . $domain_root, $domain, $domain_root);
			$app_id = WPCD_WORDPRESS_APP()->get_app_id_by_domain_name($full_domain);

			if (! empty($app_id)) {
				wc_add_notice(__('Site/Domain already exists - please choose another one.', 'wpcd'), 'error');
			}

			// Allow developers to do custom validation on the requested domain name.
			if (! apply_filters('wpcd_wpapp_wc_validate_domain_on_checkout', true, $domain, $domain_root)) {
				wc_add_notice(__('The domain you have are attempting to use is not allowed.  Please try again.', 'wpcd'), 'error');
			}
		}

		// We asked for the password so lets validate it.
		if ('2' === $location_pw) {
			$pw = $_POST['wpcd_app_wpapp_wc_password'];
			if (empty($pw)) {
				wc_add_notice(__('Your password should not be blank.', 'wpcd'), 'error');
			}

			$check_pw = wpcd_clean_alpha_numeric_dashes(wc_clean(($_POST['wpcd_app_wpapp_wc_password'])));
			if ($pw <> $check_pw) {
				/* Translators: %s is the correct password. */
				wc_add_notice(sprintf(__('The password format is incorrect. It should probably be %s. Note that only alphanumerics and dashes are allowed.', 'wpcd'), $check_pw), 'error');
			}

			// Allow developers to do custom validation on the password.
			if (! apply_filters('wpcd_wpapp_wc_validate_password_on_checkout', true, $pw)) {
				wc_add_notice(__('The password you have are attempting to use has not met our minimum criteria or is otherwise invalid.  Please try again.', 'wpcd'), 'error');
			}
		}
	}


	/**
	 * Save the domain and password fields on the checkout page to the order.
	 *
	 * Action Hook: woocommerce_checkout_create_order
	 *
	 * @param object         $order WC Order object.
	 * @param object | array $data Some sort of data (WC docs don't tell you much about this one).
	 *
	 * @return void.
	 */
	public function wc_checkout_fields_update_meta($order, $data)
	{

		// Skip this whole section if we're handling a renewal order.
		if ($this->is_cart_renewal()) {
			return;
		}

		// Skip this whole section if we're handling a subscription switch.
		if ($this->is_cart_subscription_switch()) {
			return;
		}

		// Skip if the order is not an order for a new WordPress site.
		if (! $this->does_order_contain_item_of_type($order, 'wpapp_sites')) {
			return;
		}

		if (isset($_POST['wpcd_app_wpapp_wc_domain']) && ! empty($_POST['wpcd_app_wpapp_wc_domain'])) {
			$domain = apply_filters('dvicd_cleaned_wpapp_wc_subdomain',$_POST['wpcd_app_wpapp_wc_domain']);

			$domain_root = wpcd_get_option('wordpress_app_wc_sites_temp_domain');
			$full_domain = apply_filters('dvicd_wpapp_wc_domain_root', $domain . '.' . $domain_root, $domain, $domain_root);
			$cleaned_domain = str_replace('.' . $domain_root, '', $full_domain);

			$order->update_meta_data('wpcd_app_wpapp_wc_domain', $cleaned_domain);
		}

		if (isset($_POST['wpcd_app_wpapp_wc_password']) && ! empty($_POST['wpcd_app_wpapp_wc_password'])) {
			$order->update_meta_data('wpcd_app_wpapp_wc_password', wpcd_clean_alpha_numeric_dashes(wc_clean(($_POST['wpcd_app_wpapp_wc_password']))));
		}
	}

	/**
	 * Display the domain and password fields from the checkout page on the thank you page.
	 *
	 * Filter Hook: woocommerce_thankyou_order_received_text
	 *
	 * Note: In WC 8.0 and later, we have to ECHO out our text
	 * instead of returning it.  grrr.
	 * This is because if we return it, WC will not render the HTML.
	 * This is changed behavior in WC 8.x. In prior versions we
	 * were able to just return the $str concatenated with our data.
	 * Now, we have to ECHO out our text and return the incoming $str.
	 * By returning the incoming $str variable, we do not break
	 * other plugins that might rely on this value.
	 *
	 * @param string $str The current text of the thank you page.
	 * @param array  $order The woocommerce order object array.
	 *
	 * @return string The text to show on the WC thank you page.
	 */
	public function wc_thankyou_show_checkout_fields($str, $order)
	{

		// Skip this whole section if we're handling a renewal order.
		if ($this->is_cart_renewal()) {
			return $str;
		}

		// Skip this whole section if we're handling a subscription switch.
		if ($this->is_order_subscription_switch($order)) {
			return $str;
		}

		// Skip if the order is not an order for a new WordPress site.
		if (! $this->does_order_contain_item_of_type($order, 'wpapp_sites')) {
			return $str;
		}

		$subdomain = $order->get_meta('wpcd_app_wpapp_wc_domain');
		$pw        = $order->get_meta('wpcd_app_wpapp_wc_password');

		$new_str = '';
		if (! empty($subdomain) && ! empty($subdomain)) {
			$new_str .= '<br />';
			if (! empty($subdomain)) {
				$new_str .= '<br /><b>' . __('Site Name (Subdomain):', 'wpcd') . ' ' . '</b>' . $subdomain;
			}
			if (! empty($pw)) {
				$new_str .= '<br /><b>' . __('Password:', 'wpcd') . ' ' . '</b>' . $pw;
			}
			$new_str .= '<br /><br />';
		}

		echo wp_kses_post($new_str);

		return $str;
	}


	/**
	 * Add text to the top of the WC thank you page.
	 *
	 * Filter Hook: woocommerce_thankyou_order_received_text
	 *
	 * Note: In WC 8.0 and later, we have to ECHO out our text
	 * instead of returning it.  grrr.
	 * This is because if we return it, WC will not render the HTML.
	 * This is changed behavior in WC 8.x. In prior versions we
	 * were able to just return the $str concatenated with our data.
	 * Now, we have to ECHO out our text and return the incoming $str.
	 * By returning the incoming $str variable, we do not break
	 * other plugins that might rely on this value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $str The current text of the thank you page.
	 * @param array  $order The woocommerce order object array.
	 *
	 * @return string The text to show on the WC thank you page.
	 */
	public function wc_thankyou_order_received_text( $str, $order ) {

		// Skip this whole section if we're handling a renewal order.
		if ( $this->is_cart_renewal( $order ) ) {
			return $str;
		}
		// Skip this whole section if we're handling a subscription switch.
		if ( $this->is_order_subscription_switch( $order ) ) {
			return $str;
		}

		// Show global thank you text.
		$new_str = '';
		if ( $this->does_order_contain_item_of_type( $order, 'wpapp_sites' ) ) {
			if ( ! $this->does_order_suppress_thank_you_notice( $order, 'wpapp_sites' ) ) {
				$new_str = $this->get_thank_you_text( $str, $order, 'wordpress_app_wc_sites' );
			}
		}

		// Get and show custom thank you text for each product that has one.
		$new_str = $this->get_product_thank_you_text( $new_str, $order, 'wpapp_sites', 'wordpress_app_wc_sites' );

		// Allow developers to hook in.
		$new_str = apply_filters( 'wpcd_wpapp_wordpress-app_wc_new_wpsite_thank_you_text', $new_str, $order );

		// Output directly.
		echo wp_kses_post( $new_str );

		// Return original incoming string.
		return $str;
	}


	/**
	 * Show the WP Site attributes when item is added to the cart.
	 * This is shown directly in-line in the cart - under the item.
	 * It is also shown in the list of items at the bottom of the checkout page.
	 *
	 * Filter Hook: woocommerce_get_item_data
	 *
	 * @param array $item_data Data about a product.
	 * @param array $cart_item_data Cart data for the item.
	 *
	 * @return array $item_data modified cart item.
	 */
	public function wc_show_misc_attributes($item_data, $cart_item_data)
	{

		// Is this a WP Sites Product?  If not get out.
		$is_wpapp_sites = get_post_meta($cart_item_data['product_id'], 'wpcd_app_wpapp_sites_product', true);
		if ('yes' !== $is_wpapp_sites) {
			return $item_data;
		}

		// Show domain name.
		if (isset($cart_item_data['wpcd_app_wpapp_wc_domain'])) {
			$name        = $cart_item_data['wpcd_app_wpapp_wc_domain'];
			$item_data[] = array(
				'key'   => __('Site Name (Subdomain)', 'wpcd'),
				'value' => wpcd_clean_domain(sanitize_title(wc_clean($name))),
			);
		}

		// Show Password name.
		if (isset($cart_item_data['wpcd_app_wpapp_wc_password'])) {
			$name        = $cart_item_data['wpcd_app_wpapp_wc_password'];
			$item_data[] = array(
				'key'   => __('Password', 'wpcd'),
				'value' => wpcd_clean_alpha_numeric_dashes(wc_clean($name)),
			);
		}

		// Add a label/message as needed.
		$message = get_post_meta($cart_item_data['product_id'], 'wpcd_app_wpapp_sites_product_notice_in_cart', true);
		if (empty($message)) {
			$message = wpcd_get_option('wordpress_app_wc_sites_product_notice_in_cart');
		}
		if (! empty($message)) {
			$item_data[] = array(
				'key'   => __('Message', 'wpcd'),
				'value' => $message,
			);
		}

		return $item_data;
	}
}
