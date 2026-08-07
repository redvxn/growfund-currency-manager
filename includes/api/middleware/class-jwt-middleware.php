<?php
/**
 * JWT Middleware - Validates JWT tokens and sets user context
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_JWT_Middleware {

    /**
     * Validate JWT token from request and set user context
     *
     * @param WP_REST_Request $request The request object
     * @return bool|WP_Error True if valid, WP_Error if not
     */
    public static function validate( $request ) {
        $token = self::extract_token_from_request( $request );

        if ( ! $token ) {
            return new WP_Error(
                'missing_auth_header',
                'Authorization header missing or malformed',
                [ 'status' => 401 ]
            );
        }

        $user_id = GFCM_JWT_Handler::validate_token( $token );

        if ( ! $user_id ) {
            return new WP_Error(
                'invalid_token',
                'Invalid or expired JWT token',
                [ 'status' => 401 ]
            );
        }

        // Set WordPress user context
        wp_set_current_user( $user_id );

        return true;
    }

    /**
     * Extract JWT token from Authorization header
     *
     * @param WP_REST_Request $request The request object
     * @return string|false Token string or false if not found
     */
    private static function extract_token_from_request( $request ) {
        $auth_header = $request->get_header( 'Authorization' );

        if ( ! $auth_header ) {
            return false;
        }

        // Expected format: "Bearer <token>"
        if ( ! preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
            return false;
        }

        return $matches[1];
    }

    /**
     * Get current user from JWT token in request
     * Useful if you need to access user info without setting global context
     *
     * @param WP_REST_Request $request The request object
     * @return int|false User ID or false if token is invalid
     */
    public static function get_user_from_token( $request ) {
        $token = self::extract_token_from_request( $request );
        if ( ! $token ) {
            return false;
        }

        return GFCM_JWT_Handler::validate_token( $token );
    }
}
