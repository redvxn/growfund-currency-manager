<?php
/**
 * Checkout Controller - Handles checkout handoff endpoints
 */

use sifalo-pay/sifalo-pay;

if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_Checkout_Controller {

    const HANDOFF_TOKEN_EXPIRY = 15 * MINUTE_IN_SECONDS; // 15 minutes

    protected $sifalo_service;

    public function __construct()
    {
        $this->sifalo_service = new WC_Gateway_Sifalo_Pay();
    }

    /**
     * Process Sifalo Pay Mobile Payment
     * 
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */

    public function process_sifalo_wallet_payment( $request ) {

        $order_id = $request->get_param( 'order_id' );

        if( ! $order_id ) {
            return new WP_Error(
                'Invalid Order_id',
                'Order_id is required',
                [ 'status' => 400 ]
            );
        }

        $result = $this->sifalo_service->process_payment($order_id);

        return rest_ensure_response( [
            'success' => true,
            'data'    => $result,
        ] );


    }

    /**
     * Generate checkout handoff URL
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function handoff( $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error(
                'not_authenticated',
                'You must be logged in',
                [ 'status' => 401 ]
            );
        }

        $donation_id = intval( $request->get_param( 'donation_id' ) );

        if ( ! $donation_id ) {
            return new WP_Error(
                'missing_donation_id',
                'Donation ID is required',
                [ 'status' => 400 ]
            );
        }

        // Verify donation exists and belongs to current user
        global $wpdb;
        $table = $wpdb->prefix . 'gf_donations';
        $donation = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
            $donation_id,
            $user_id
        ) );

        if ( ! $donation ) {
            return new WP_Error(
                'donation_not_found',
                'Donation not found or does not belong to you',
                [ 'status' => 404 ]
            );
        }

        // Check donation status
        if ( 'pending_payment' !== $donation->status ) {
            return new WP_Error(
                'invalid_donation_status',
                'Donation is not pending payment',
                [ 'status' => 400 ]
            );
        }

        // Generate handoff token
        $handoff_token = $this->generate_handoff_token( $donation_id, $user_id );

        if ( ! $handoff_token ) {
            return new WP_Error(
                'token_generation_failed',
                'Failed to generate handoff token',
                [ 'status' => 500 ]
            );
        }

        // Generate checkout URL
        $checkout_url = $this->get_checkout_url( $donation_id, $handoff_token );

        return rest_ensure_response( [
            'success'          => true,
            'checkout_url'     => $checkout_url,
            'handoff_token'    => $handoff_token,
            'expires_at'       => current_time( 'mysql', 1 ) . '+' . self::HANDOFF_TOKEN_EXPIRY,
            'expires_in'       => self::HANDOFF_TOKEN_EXPIRY,
        ] );
    }

    /**
     * Validate and process handoff token
     * Called from the website checkout page
     *
     * @param string $token Handoff token
     * @return array|false Token data or false if invalid
     */
    public static function validate_handoff_token( $token ) {
        $payload = GFCM_JWT_Handler::decode( $token );

        if ( ! $payload || 'handoff' !== $payload['type'] ) {
            return false;
        }

        // Check expiry
        if ( isset( $payload['exp'] ) && $payload['exp'] < time() ) {
            return false;
        }

        return $payload;
    }

    /**
     * Generate a handoff token
     *
     * @param int $donation_id Donation ID
     * @param int $user_id User ID
     * @return string|false Token string or false on failure
     */
    private function generate_handoff_token( $donation_id, $user_id ) {
        $payload = [
            'donation_id' => $donation_id,
            'user_id'     => $user_id,
            'type'        => 'handoff',
            'iat'         => time(),
            'exp'         => time() + self::HANDOFF_TOKEN_EXPIRY,
        ];

        $token = $this->encode_handoff_token( $payload );

        // Store token in options for reference (optional, for audit/revocation)
        $token_key = 'gfcm_handoff_token_' . md5( $token );
        set_transient( $token_key, [
            'donation_id' => $donation_id,
            'user_id'     => $user_id,
            'created_at'  => current_time( 'mysql', 1 ),
        ], self::HANDOFF_TOKEN_EXPIRY );

        return $token;
    }

    /**
     * Encode handoff token (using JWT)
     *
     * @param array $payload Token payload
     * @return string JWT token
     */
    private function encode_handoff_token( $payload ) {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $header_encoded = self::base64_url_encode( json_encode( $header ) );
        $payload_encoded = self::base64_url_encode( json_encode( $payload ) );
        $signature = $this->create_signature( $header_encoded . '.' . $payload_encoded );

        return $header_encoded . '.' . $payload_encoded . '.' . $signature;
    }

    /**
     * Create HMAC SHA256 signature
     *
     * @param string $data Data to sign
     * @return string Signature
     */
    private function create_signature( $data ) {
        $signature = hash_hmac( 'sha256', $data, GFCM_JWT_Handler::get_secret_key(), true );
        return self::base64_url_encode( $signature );
    }

    /**
     * Base64 URL encode
     *
     * @param string $data Data to encode
     * @return string Encoded data
     */
    private static function base64_url_encode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /**
     * Get the checkout URL for the donation
     *
     * @param int    $donation_id Donation ID
     * @param string $handoff_token Handoff token
     * @return string Checkout URL
     */
    private function get_checkout_url( $donation_id, $handoff_token ) {
        $site_url = home_url( '/' );
        
        // You can customize the checkout page slug
        $checkout_page = get_page_by_path( 'donation-checkout' );
        if ( $checkout_page ) {
            return add_query_arg( [
                'donation_id'    => $donation_id,
                'handoff_token'  => $handoff_token,
            ], get_permalink( $checkout_page ) );
        }

        // Fallback to WooCommerce checkout
        return add_query_arg( [
            'donation_id'    => $donation_id,
            'handoff_token'  => $handoff_token,
        ], wc_get_checkout_url() );
    }
}
