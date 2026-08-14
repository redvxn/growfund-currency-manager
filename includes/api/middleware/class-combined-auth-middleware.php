<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_Combined_Auth_Middleware {

    public static function validate( $request ) {
        // --- 1. Verify API Key ---
        $client_api_key = $request->get_header( 'x_api_key' );
        $server_api_key = defined( 'GROWFUND_CLIENT_API_KEY' ) ? GROWFUND_CLIENT_API_KEY : '';

        if ( ! $server_api_key || ! hash_equals( $server_api_key, $client_api_key ) ) {
            return new WP_Error( 'invalid_api_key', 'Invalid or missing API Key', [ 'status' => 401 ] );
        }

        // --- 2. Verify System JWT ---
        $auth_header = $request->get_header( 'Authorization' );

        if ( ! $auth_header || ! preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
            return new WP_Error( 'missing_token', 'Authorization Bearer token missing', [ 'status' => 401 ] );
        }

        $token = $matches[1];
        
        // Use your existing decode method to inspect the payload
        $payload = GFCM_JWT_Handler::decode( $token ); 

        if ( ! $payload ) {
            return new WP_Error( 'invalid_token', 'Malformed or tampered JWT', [ 'status' => 401 ] );
        }

        // Check if token has expired
        if ( isset( $payload['exp'] ) && $payload['exp'] < time() ) {
            return new WP_Error( 'expired_token', 'System token has expired', [ 'status' => 401 ] );
        }

        // Ensure this is a 'system' token, not a user token
        if ( ! isset( $payload['type'] ) || $payload['type'] !== 'system' ) {
            return new WP_Error( 'invalid_token_type', 'Expected a system token', [ 'status' => 403 ] );
        }

        return true;
    }
}