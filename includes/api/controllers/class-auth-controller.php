<?php
/**
 * Auth Controller - Handles authentication endpoints
 */

if ( ! defined( 'ABSPATH' ) ) exit;


class GFCM_Auth_Controller {

    private $auth_service;

    public function __construct() {
        $this->auth_service = new GFCM_Auth_Service();
    }

    /**
     * Login endpoint
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function login( $request ) {
        $username = $request->get_param( 'username' );
        $password = $request->get_param( 'password' );

        // Validate required fields
        if ( empty( $username ) || empty( $password ) ) {
            return new WP_Error(
                'missing_credentials',
                'Username and password are required',
                [ 'status' => 400 ]
            );
        }

        // Authenticate user
        $user = wp_authenticate( $username, $password );

        if ( is_wp_error( $user ) ) {
            return new WP_Error(
                'invalid_credentials',
                'Invalid username or password',
                [ 'status' => 401 ]
            );
        }

        // Generate tokens
        $access_token = GFCM_JWT_Handler::issue_token( $user->ID, 'access' );
        $refresh_token = GFCM_JWT_Handler::issue_token( $user->ID, 'refresh' );

        if ( ! $access_token || ! $refresh_token ) {
            return new WP_Error(
                'token_generation_failed',
                'Failed to generate authentication tokens',
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response( [
            'success'       => true,
            'access_token'  => $access_token,
            'refresh_token' => $refresh_token,
            'user_id'       => $user->ID,
            'username'      => $user->user_login,
            'email'         => $user->user_email,
            'expires_in'    => HOUR_IN_SECONDS,
        ] );
    }

    /**
     * Register endpoint
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function register( $request ) {
        $username = $request->get_param( 'username' );
        $password = $request->get_param( 'password' );
        $email = $request->get_param( 'email' );
        $first_name = $request->get_param( 'first_name' ) ?? '';
        $last_name = $request->get_param( 'last_name' ) ?? '';
        $user_type = $request->get_param( 'user_type' ) ?? '';

        if ( ! in_array( $user_type, [ 'donor', 'fundraiser' ], true ) ) {
            return new WP_Error(
                'invalid_user_type',
                'User type must be either "donor" or "fundraiser"',
                [ 'status' => 400 ]
            );
        }

        // Validate required fields
        if ( empty( $username ) || empty( $password ) || empty( $email ) ) {
            return new WP_Error(
                'missing_fields',
                'Username, password, and email are required',
                [ 'status' => 400 ]
            );
        }

        // Validate email
        if ( ! is_email( $email ) ) {
            return new WP_Error(
                'invalid_email',
                'Invalid email format',
                [ 'status' => 400 ]
            );
        }

        // Check if username already exists
        if ( username_exists( $username ) ) {
            return new WP_Error(
                'username_exists',
                'Username already exists',
                [ 'status' => 400 ]
            );
        }

        // Check if email already exists
        if ( email_exists( $email ) ) {
            return new WP_Error(
                'email_exists',
                'Email already exists',
                [ 'status' => 400 ]
            );
        }

        // set user role based on user_type
        $role = ( 'fundraiser' === $user_type ) ? 'growfund_fundraiser' : 'growfund_donor';

        $user_data = [
            'user_login' => $username,
            'user_email' => $email,
            'user_pass'  => $password,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'display_name' => $first_name . ' ' . $last_name,
            'role'       => $role,
        ];

        // Create user
        $user_id = wp_insert_user( $user_data );

        if ( is_wp_error( $user_id ) ) {
            return new WP_Error(
                'user_creation_failed',
                'Failed to create user account',
                [ 'status' => 500 ]
            );
        }

        // Update user meta
        if ( ! empty( $first_name ) ) {
            update_user_meta( $user_id, 'first_name', sanitize_text_field( $first_name ) );
        }
        if ( ! empty( $last_name ) ) {
            update_user_meta( $user_id, 'last_name', sanitize_text_field( $last_name ) );
        }
        
        update_user_meta( $user_id, 'growfund_created_at', current_time( 'mysql', 1 ) );

        // Generate tokens
        $access_token = GFCM_JWT_Handler::issue_token( $user_id, 'access' );
        $refresh_token = GFCM_JWT_Handler::issue_token( $user_id, 'refresh' );

        if ( ! $access_token || ! $refresh_token ) {
            return new WP_Error(
                'token_generation_failed',
                'Failed to generate authentication tokens',
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response( [
            'success'       => true,
            'message'       => 'User registered successfully',
            'access_token'  => $access_token,
            'refresh_token' => $refresh_token,
            'user_id'       => $user_id,
            'username'      => $username,
            'email'         => $email,
            'expires_in'    => HOUR_IN_SECONDS,
        ] );
    }
    
    /**
     * Password reset mail endpoint
     */

    public function password_reset_mail( $request ) {
        $email = $request->get_param( 'email' );

        if ( empty( $email ) || ! is_email( $email ) ) {
            return new WP_Error(
                'invalid_email',
                'A valid email address is required',
                [ 'status' => 400 ]
            );
        }

        $user = get_user_by( 'email', $email );

        if ( ! $user ) {
            return new WP_Error(
                'user_not_found',
                'No user found with that email address',
                [ 'status' => 404 ]
            );
        }

        delete_user_meta( $user->ID, 'growfund_password_reset_key' );
        delete_user_meta( $user->ID, 'growfund_password_reset_key_consumed' );

        // Generate password reset key
        $reset_key = get_password_reset_key( $user );

        if ( is_wp_error( $reset_key ) ) {
            return new WP_Error(
                'reset_key_failed',
                'Failed to generate password reset key',
                [ 'status' => 500 ]
            );
        }

        update_user_meta( $user->ID, 'growfund_password_reset_key', $reset_key );

        // Send password reset email
        $mail = growfund_email( \Growfund\Mails\PasswordResetLinkMail::class );
        $is_sent = $mail->with( [ 'user_id' => $user->ID ] )->send();

        if ( ! $is_sent ) {
            return new WP_Error(
                'email_send_failed',
                'Failed to send password reset email',
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response( [
            'success' => true,
            'message' => 'Password reset email sent successfully',
        ] );
    }
    
    /**
     * Reset password endpoint
     * 
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function reset_password( $request ) {
        $login = $request->get_param( 'login' );
        $reset_key = $request->get_param( 'reset_key' );
        $new_password = $request->get_param( 'new_password' );

        if ( empty( $login ) || empty( $reset_key ) || empty( $new_password ) ) {
            return new WP_Error(
                'missing_fields',
                'login, reset key, and new password are required',
                [ 'status' => 400 ]
            );
        }

        $user = get_user_by( 'login', $login );

        
        if ( ! $user ) {
            return new WP_Error(
                'user_not_found',
                'No user found with that ID',
                [ 'status' => 404 ]
            );
        }
        
        $user_id = $user->ID;

        // Validate reset key
        if ($this->auth_service->is_valid_reset_key($reset_key, $user_id) === false) {
            return new WP_Error(
                'invalid_reset_key',
                'Invalid or expired reset key',
                [ 'status' => 400 ]
            );
        }
        

        // Update password
        wp_set_password( $new_password, $user_id );

        // Mark reset key as consumed
        update_user_meta( $user_id, 'growfund_password_reset_key_consumed', true );

        return rest_ensure_response( [
            'success' => true,
            'message' => 'Password has been reset successfully',
        ] );
    }

    /**
     * Refresh token endpoint
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function refresh_token( $request ) {
        $refresh_token = $request->get_param( 'refresh_token' );

        if ( empty( $refresh_token ) ) {
            return new WP_Error(
                'missing_refresh_token',
                'Refresh token is required',
                [ 'status' => 400 ]
            );
        }

        // Validate refresh token
        $user_id = GFCM_JWT_Handler::validate_token( $refresh_token );

        if ( ! $user_id ) {
            return new WP_Error(
                'invalid_refresh_token',
                'Invalid or expired refresh token',
                [ 'status' => 401 ]
            );
        }

        // Verify token type
        $payload = GFCM_JWT_Handler::decode( $refresh_token );
        if ( ! $payload || 'refresh' !== $payload['type'] ) {
            return new WP_Error(
                'invalid_token_type',
                'Token type must be refresh',
                [ 'status' => 401 ]
            );
        }

        // Generate new access token
        $new_access_token = GFCM_JWT_Handler::issue_token( $user_id, 'access' );

        if ( ! $new_access_token ) {
            return new WP_Error(
                'token_generation_failed',
                'Failed to generate new access token',
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response( [
            'success'      => true,
            'access_token' => $new_access_token,
            'expires_in'   => HOUR_IN_SECONDS,
        ] );
    }

    /**
     * Current user endpoint
     * Requires valid JWT token
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function current_user( $request ) {
        $user_id = get_current_user_id();

        if ( ! $user_id ) {
            return new WP_Error(
                'not_authenticated',
                'User is not authenticated',
                [ 'status' => 401 ]
            );
        }

        $user = get_user_by( 'ID', $user_id );

        return rest_ensure_response( [
            'success'    => true,
            'user_id'    => $user->ID,
            'username'   => $user->user_login,
            'email'      => $user->user_email,
            'first_name' => get_user_meta( $user->ID, 'first_name', true ),
            'last_name'  => get_user_meta( $user->ID, 'last_name', true ),
            'avatar_url' => get_avatar_url( $user->ID ),
        ] );
    }
}
