<?php
/*
Plugin Name: donation
Author: Supreme Technologies India 
Author URI: https://supremetechnologiesindia.com/ 
*/
// Shortcode to display the form

define( 'MY_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
include( MY_PLUGIN_PATH . 'payment-notification.php');

function custom_amount_payment_form() {
    ob_start();
    ?>
    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<div class="email_field">
			<label for="email"><?php _e('Email'); ?></label>
			<input type="text" name="guest_email" id="guest_email" required min="1">
		</div>
		<div class="amount_field">
			<label for="custom_amount"><?php _e('Enter your amount'); ?></label>
			<input type="number" name="custom_amount" id="custom_amount" required min="100">
		</div>
		<div class="submit_field">
		<p class="donate_note"><span>Note:</span>You may donate using PayPal or a credit card on the next screen.</p>
			<input type="hidden" name="action" value="process_custom_payment">
			<input type="submit" value="<?php _e('Pay Now'); ?>">
		</div>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('custom_amount_payment_form', 'custom_amount_payment_form');

// Hooks for processing the payment - ensure these are hooked correctly
// Action hook for processing the custom payment
add_action('admin_post_process_custom_payment', 'process_custom_payment'); // For logged-in users
add_action('admin_post_nopriv_process_custom_payment', 'process_custom_payment'); // For non-logged-in users

function process_custom_payment() {
    global $wpdb;

    // Redirect if the user is logged in
   

    // Process payment if required fields are set
    if (isset($_POST['custom_amount']) && isset($_POST['guest_email'])) {
        $custom_amount = floatval($_POST['custom_amount']);
        $guest_email = sanitize_email($_POST['guest_email']);

            $rcp_payments = new RCP_Payments();
            $gateway = 'paypal'; // Payment gateway
            $rcp_payment_meta = $wpdb->prefix . 'rcp_payment_meta'; // Payment meta table

            // Insert guest email as payment meta
            
				$current_user_id = get_current_user_id();
                // Create a new WordPress user for the guest
				if ($current_user_id) {
						$guest_user_id = $current_user_id;
					} else {
						// Check if the user already exists
						if (email_exists($guest_email)) {
							// If the email exists, get the user by email
							
							$users = $wpdb->prefix . 'users';

							$user_id = $wpdb->get_var(
								$wpdb->prepare(
									"SELECT ID FROM $users WHERE user_email = %s",
									$guest_email
								)
							);
							
						$guest_user_id = $user_id;
							
						} else {
							// Create a new user if it doesn't exist
							$guest_user_id = wp_create_user($guest_email, wp_generate_password(), $guest_email);
							
							
						}
					}
				
                if (($guest_user_id)) {
					
					$subscription_key = rcp_generate_subscription_key($guest_user_id);
					$payment_data = [
						'user_id'           => $guest_user_id, // No user ID for guests yet
						'amount'            => $custom_amount,
						'payment_type'      => $gateway,
						'status'            => 'pending', // Payment starts as pending
						'gateway'           => $gateway,
						'date'              => current_time('mysql'),
					];
					$payment_id = $rcp_payments->insert($payment_data);

					// Insert guest email as payment meta
						$meta_data = [
							'rcp_payment_id' => $payment_id,
							'meta_key'       => 'guest_email',
							'meta_value'     => $guest_email,
						];
						$meta_insert = $wpdb->insert($rcp_payment_meta, $meta_data);
				
                    // Fetch the user's subscription key and update the payment
                    


                    // Redirect to PayPal for payment
                    if ($gateway === 'paypal') {
                        $paypal_url = "https://www.paypal.com/cgi-bin/webscr?cmd=_xclick";
                        $paypal_url .= "&business=donations@afterlifeinstitute.org";
                        $paypal_url .= "&amount=" . urlencode($custom_amount);
                        $paypal_url .= "&item_name=Membership Payment";
                        $paypal_url .= "&currency_code=USD";
                        $paypal_url .= "&custom=" . urlencode($payment_id); // Include the payment ID
                        $paypal_url .= "&notify_url=" . urlencode(home_url('/?paypal-ipn')); // IPN URL
                        $paypal_url .= "&return=" . urlencode(home_url(''));
                        $paypal_url .= "&cancel_return=" . urlencode(home_url('/payment-cancelled'));

                        wp_redirect($paypal_url);
                        exit;
                    }
                }
				
            
        
    } else {
        wp_redirect(home_url('/missing-fields'));
        exit;
    }
}