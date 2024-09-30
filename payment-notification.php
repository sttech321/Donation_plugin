<?php

// Handle the PayPal IPN request
function handle_paypal_ipn() {
    global $wpdb;

    // Read POST data from PayPal
    $raw_post_data = file_get_contents('php://input');
    $raw_post_array = explode('&', $raw_post_data);
    $myPost = array();
    foreach ($raw_post_array as $keyval) {
        $keyval = explode('=', $keyval);
        if (count($keyval) == 2)
            $myPost[$keyval[0]] = urldecode($keyval[1]);
    }

    // Prepare request for validation
    $req = 'cmd=_notify-validate';
    foreach ($myPost as $key => $value) {
        $value = urlencode($value);
        $req .= "&$key=$value";
    }

    // Post request back to PayPal for verification
    $paypal_url = "https://ipnpb.sandbox.paypal.com/cgi-bin/webscr"; // Sandbox for testing
    $ch = curl_init($paypal_url);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close'));
    $res = curl_exec($ch);
    curl_close($ch);

    // Check if IPN is verified
    if (strcmp($res, "VERIFIED") == 0) {
        // Process IPN message
        $payment_status = $_POST['payment_status'];
        $payment_id = intval($_POST['custom']); // Use the custom field to store the payment_id
        // Verify payment status is 'Completed'
        if ($payment_status == "Completed") {
            // Update payment status to completed in RCP_Payments table
            $rcp_payments = new RCP_Payments();
            $rcp_payments->update($payment_id, ['status' => 'complete']);

            // Prepare the SQL query
           // Retrieve guest email from rcp_payment_meta
			$guest_email = $wpdb->get_var($wpdb->prepare("
				SELECT meta_value 
				FROM {$wpdb->prefix}rcp_payment_meta 
				WHERE rcp_payment_id = %d 
				AND meta_key = 'guest_email'
			", $payment_id));
				$rcp_payments_table = $wpdb->prefix . 'rcp_payments';

					$user_id = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT user_id FROM $rcp_payments_table WHERE id = %d ",
							$payment_id
						)
					);
					
					$amount = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT amount FROM $rcp_payments_table WHERE id = %d ",
							$payment_id
						)
					);
					
					$rcp_memberships = $wpdb->prefix . 'rcp_memberships';

					$object_id = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT object_id FROM $rcp_memberships WHERE user_id = %d ",
							$user_id
						)
					);
					if($object_id){
						if($object_id == 1){
							$membership = 'Special Access (Monthly)';
						} else {
							$membership = 'Special Access (Annual)';
						}
					}
				
				$user_info = get_userdata($user_id);
				$user_email = $user_info->data->user_email;
				$reset_password = 'Add new password';
				
					
				if ($object_id) {
					$to = get_option('admin_email');; // Admin email
					$subject = 'Completed Donation Payment';
					$body_content = 'The email address of the person who made the donation is: <strong>' . esc_html($user_email) . '</strong><br>';
					$body_content .= '$Donation Amount: <strong>' . esc_html($amount) . '</strong>';
					$body_content .= '<p class="note">Note: This user already exists, and they have membership to: <strong>' . esc_html($membership) . '</strong></p><br>';
					$message = get_email_template($subject, $body_content);
				} else {
					$to = get_option('admin_email');; // Admin email
					$subject = 'Completed Donation Payment';
					$body_content = 'The email address of the person who made the donation is: <strong>' . esc_html($guest_email) . '</strong>';
					$body_content .= 'Donation Amount: <strong>' . esc_html($amount) . '</strong>';
					$message = get_email_template($subject, $body_content);
				}
				// Set headers for HTML email
					$headers = array('Content-Type: text/html; charset=UTF-8');

					// Send the email
					wp_mail($to, $subject, $message, $headers);


				if ($object_id) {
					$to = $guest_email; // User's email
					$subject = 'Thank you for Donating';
					$body_content = 'You have donated to our society. We are very thankful for your support!';
					$message = get_email_template($subject, $body_content);
					wp_mail($to, $subject, $message, $headers);
				} else {
					
					$user = get_user_by('email', $guest_email);
					$reset_key = get_password_reset_key($user);
					$reset_link = add_query_arg(
						['action' => 'rp', 'key' => $reset_key, 'login' => rawurlencode($user->user_login)],
						wp_login_url()
					);
					// Email the new user their login information
					$to = $guest_email; // User's email
					$subject = 'Thank you for Donating';
					$body_content = 'Your Email: <strong>' . esc_html($guest_email) . '</strong>';
					$body_content .= '<p>To reset your password, click the following link: <a href="' . esc_url($reset_link) . '">' . esc_html($reset_password) . '</a></p>';
					$message = get_email_template($subject, $body_content);
					wp_mail($to, $subject, $message, $headers);
				}
             
		}
    }

    exit; // IPN listener must return a 200 response code to PayPal
}

// Add the IPN listener to the init action
add_action('init', 'add_paypal_ipn_listener');
function add_paypal_ipn_listener() {
    if (isset($_GET['paypal-ipn'])) {
        if (ob_get_length()) ob_end_clean(); // Disable any output buffering
        handle_paypal_ipn(); // Call your IPN handler function
    }
}

function get_email_template($title, $body_content) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>' . esc_html($title) . '</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                color: #333;
                line-height: 1.6;
                background-color: #f9f9f9;
                padding: 20px;
            }
            .container {
                max-width: 600px;
                margin: auto;
                padding: 20px;
                border: 1px solid #ddd;
                border-radius: 5px;
                background-color: #fff;
            }
            h2 {
                color: #4CAF50;
            }
            .note {
                color: red;
                font-weight: bold;
                margin-top: 20px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h2>' . esc_html($title) . '</h2>
            <p>' . $body_content . '</p>
        </div>
    </body>
    </html>';
}