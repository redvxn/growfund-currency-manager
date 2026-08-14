<?php
/**
 * JWT Handler - Handles token issuance and validation
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_JWT_Handler {

    /**
     * JWT Secret Key
     */
    private static $secret_key = null;

    /**
     * Get or initialize the JWT secret key
     */
    public static function get_secret_key() {
        if ( null === self::$secret_key ) {
            // Try to get from wp-config.php constant
            if ( defined( 'GROWFUND_JWT_SECRET' ) ) {
                self::$secret_key = GROWFUND_JWT_SECRET;
            } else {
                // Fallback: generate and store in WordPress options
                self::$secret_key = get_option( 'gfcm_jwt_secret' );
                if ( ! self::$secret_key ) {
                    self::$secret_key = wp_generate_password( 64, true, true );
                    update_option( 'gfcm_jwt_secret', self::$secret_key );
                }
            }
        }
        return self::$secret_key;
    }

    /**
     * Issue a JWT token
     *
     * @param int    $user_id  WordPress user ID
     * @param string $type     Token type: 'access' or 'refresh'
     * @return string JWT token
     */
    public static function issue_token( $user_id, $type = 'access' ) {
        $user = get_user_by( 'ID', $user_id );
        if ( ! $user ) {
            return false;
        }

        // Set token expiry based on type
        $expiry = ( 'refresh' === $type ) ? 30 * DAY_IN_SECONDS : HOUR_IN_SECONDS;

        // Create token payload
        $payload = [
            'user_id'   => $user_id,
            'username'  => $user->user_login,
            'email'     => $user->user_email,
            'type'      => $type,
            'iat'       => time(),
            'exp'       => time() + $expiry,
        ];

        return self::encode( $payload );
    }

    /**
     * Issue a short-lived System JWT for machine-to-machine requests
     *
     * @return string JWT token
     */
    public static function issue_system_token() {
        // Very short expiry: 5 minutes
        $expiry = 5 * MINUTE_IN_SECONDS; 

        $payload = [
            'type' => 'system', // Differentiate this from 'access' or 'refresh'
            'iat'  => time(),
            'exp'  => time() + $expiry,
        ];

        // Utilize your existing private encode method
        return self::encode( $payload ); 
    }

    /**
     * Validate a JWT token
     *
     * @param string $token JWT token
     * @return int|false User ID if valid, false otherwise
     */
    public static function validate_token( $token ) {
        $payload = self::decode( $token );
        if ( ! $payload ) {
            return false;
        }

        // Check expiry
        if ( isset( $payload['exp'] ) && $payload['exp'] < time() ) {
            return false;
        }

        return isset( $payload['user_id'] ) ? $payload['user_id'] : false;
    }

    /**
     * Decode a JWT token
     *
     * @param string $token JWT token
     * @return array|false Token payload if valid, false otherwise
     */
    public static function decode( $token ) {
        if ( ! is_string( $token ) || empty( $token ) ) {
            return false;
        }

        // JWT format: header.payload.signature
        $parts = explode( '.', $token );
        if ( count( $parts ) !== 3 ) {
            return false;
        }

        list( $header, $payload, $signature ) = $parts;

        // Verify signature
        $expected_signature = self::create_signature( $header . '.' . $payload );
        if ( ! hash_equals( $signature, $expected_signature ) ) {
            return false;
        }

        // Decode payload
        $decoded = json_decode( self::base64_url_decode( $payload ), true );
        return is_array( $decoded ) ? $decoded : false;
    }

    /**
     * Encode a JWT token
     *
     * @param array $payload Token payload
     * @return string JWT token
     */
    private static function encode( $payload ) {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $header_encoded = self::base64_url_encode( json_encode( $header ) );
        $payload_encoded = self::base64_url_encode( json_encode( $payload ) );
        $signature = self::create_signature( $header_encoded . '.' . $payload_encoded );

        return $header_encoded . '.' . $payload_encoded . '.' . $signature;
    }

    /**
     * Create HMAC SHA256 signature
     *
     * @param string $data Data to sign
     * @return string Signature
     */
    private static function create_signature( $data ) {
        $signature = hash_hmac( 'sha256', $data, self::get_secret_key(), true );
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
     * Base64 URL decode
     *
     * @param string $data Data to decode
     * @return string Decoded data
     */
    private static function base64_url_decode( $data ) {
        $remainder = strlen( $data ) % 4;
        if ( $remainder ) {
            $data .= str_repeat( '=', 4 - $remainder );
        }
        return base64_decode( strtr( $data, '-_', '+/' ) );
    }
}
