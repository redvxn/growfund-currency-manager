<?php
/**
 * KYC Reminder Email Template (Plugin Version)
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Ensure the variables we need were passed in
if ( ! isset( $user ) || ! isset( $kyc_link ) || ! isset( $email_heading ) || ! isset( $mailer ) ) {
    return;
}

// Load the WooCommerce Header
do_action( 'woocommerce_email_header', $email_heading, $mailer ); 
?>

<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f9f9f9;">
    <div style="background-color: #ffffff; padding: 40px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); text-align: center;">
        
        <h2 style="color: #2c3e50; font-size: 24px; margin-bottom: 20px;">Complete Your Identity Verification</h2>
        
        <p style="color: #555555; font-size: 16px; line-height: 1.6; margin-bottom: 30px; text-align: left;">
            Hi <?php echo esc_html( $user->first_name ); ?>,
        </p>
        
        <p style="color: #555555; font-size: 16px; line-height: 1.6; margin-bottom: 30px; text-align: left;">
            We noticed you haven't completed your KYC (Know Your Customer) verification yet. To ensure a safe and secure fundraising environment, we require all fundraisers to complete this quick step before they can start raising funds or withdraw donations.
        </p>
        
        <a href="<?php echo esc_url( $kyc_link ); ?>" style="display: inline-block; background-color: #4CAF50; color: #ffffff; text-decoration: none; padding: 15px 30px; border-radius: 5px; font-size: 18px; font-weight: bold; margin-bottom: 30px;">
            Complete KYC Now
        </a>
        
        <p style="color: #555555; font-size: 16px; line-height: 1.6; margin-bottom: 20px; text-align: left;">
            If the button above does not work, please copy and paste the following link into your browser:
            <br><br>
            <a href="<?php echo esc_url( $kyc_link ); ?>" style="color: #4CAF50; word-break: break-all;"><?php echo esc_url( $kyc_link ); ?></a>
        </p>

        <p style="color: #888888; font-size: 14px; margin-top: 40px;">
            If you need assistance, please reply to this email or contact our support team.
        </p>
    </div>
</div>

<?php 
// Load the WooCommerce Footer
do_action( 'woocommerce_email_footer', $mailer ); 
?>