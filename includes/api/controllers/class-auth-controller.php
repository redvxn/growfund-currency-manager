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
        // 1. Extract and sanitize inputs
        $username = sanitize_text_field( $request->get_param( 'username' ) );
        $password = $request->get_param( 'password' ); // Don't sanitize password (can contain special characters)

        // 2. Validate required fields
        if ( empty( $username ) || empty( $password ) ) {
            return new WP_Error(
                'missing_credentials',
                'Username and password are required',
                [ 'status' => 400 ]
            );
        }

        // 3. Delegate authentication to AuthService (Controller -> AuthService)
        $user = $this->auth_service->authenticate_user( $username, $password );

        if ( is_wp_error( $user ) ) {
            return $user; // Returns 401 WP_Error formatted by AuthService
        }

        // 4. Issue JWT tokens
        $access_token  = GFCM_JWT_Handler::issue_token( $user->ID, 'access' );
        $refresh_token = GFCM_JWT_Handler::issue_token( $user->ID, 'refresh' );

        if ( ! $access_token || ! $refresh_token ) {
            return new WP_Error(
                'token_generation_failed',
                'Failed to generate authentication tokens',
                [ 'status' => 500 ]
            );
        }

        // 5. Formulate response for Next.js
        return rest_ensure_response( [
            'success'       => true,
            'access_token'  => $access_token,
            'refresh_token' => $refresh_token,
            'expires_in'    => HOUR_IN_SECONDS,
            'user'          => [
                'id'         => $user->ID,
                'username'   => $user->user_login,
                'email'      => $user->user_email,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'roles'      => $user->roles,
            ],
        ] );
    }

    /**
     * Register endpoint
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function register( $request ) {
        $user_type = $request->get_param( 'user_type' ) ?? '';

        if ( ! in_array( $user_type, [ 'donor', 'fundraiser' ], true ) ) {
            return new WP_Error(
                'invalid_user_type',
                'User type must be either "donor" or "fundraiser"',
                [ 'status' => 400 ]
            );
        }

        

        // set user role based on user_type
        $role = ( 'fundraiser' === $user_type ) ? 'growfund_fundraiser' : 'growfund_donor';

        $user_data = [
            'first_name' => $request->get_param( 'first_name' ) ?? '',
            'last_name'  => $request->get_param( 'last_name' ) ?? '',
            'username' => $request->get_param( 'username' ) ?? '',
            'email' => $request->get_param( 'email' ) ?? '',
            'password'  => $request->get_param( 'password' ) ?? '',
            'password_confirmation' => $request->get_param( 'password_confirmation' ) ?? '',
            'role'       => $role,
        ];

        $validator = \Growfund\Validation\Validator::make($user_data, \Growfund\DTO\Auth\RegisterDTO::validation_rules());

        if ($validator->is_failed()) {
            return new WP_Error(
                'validation_failed',
                'Validation failed',
                [
                    'status' => 400,
                    'errors' => $validator->get_errors(),
                ]
            );
        }

        $sanitized_data = /Growfund\Sanitizer::make($user_data, /Growfund/DTO/RegisterDTO::sanitization_rules())->get_sanitized_data();

        $register_dto = new /Growfund/DTO/RegisterDTO($sanitized_data);

        $result = $this->auth_service->register($register_dto);

        if ( is_wp_error( $result ) ) {
            return $result; // Returns WP_Error formatted by AuthService
        }

        $user_id = $result->ID;
        $username = $result->user_login;
        $email = $result->user_email;
        $first_name = $result->first_name;
        $last_name = $result->last_name;

        

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
        $raw_data = [
            'email' => $request->get_param( 'email' ),
        ];

        $validator = Growfund\Validation\Validator::make( $raw_data, [
            'email' => 'required|email',
        ] );

        if ( $validator->is_failed() ) {
            return new WP_Error(
                'validation_failed',
                'Validation failed',
                [
                    'status' => 400,
                    'errors' => $validator->get_errors(),
                ]
            );
        }

        $sanitized_data = Growfund\Sanitizer::make( $raw_data, [
            'email' => Growfund\Sanitizer::EMAIL,
        ] )->get_sanitized_data();

        $result = $this->auth_service->send_password_reset_email( [
            'email' => $sanitized_data['email'],
        ] );

        if ( is_wp_error( $result ) ) {
            return $result; // Returns WP_Error formatted by AuthService
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
        $raw_data = [
            'login'                 => $request->get_param( 'login' ),
            'key'                   => $request->get_param( 'key' ),
            'password'              => $request->get_param( 'password' ),
            'password_confirmation' => $request->get_param( 'password_confirmation' ),
        ];

        $validator = \Growfund\Validation\Validator::make( $raw_data, \Growfund\DTO\Auth\ResetPasswordDTO::validation_rules() );

        if ( $validator->is_failed() ) {
            return new WP_Error(
                'validation_failed',
                'Validation failed',
                [
                    'status' => 400,
                    'errors' => $validator->get_errors(),
                ]
            );
        }

        $sanitized_data = \Growfund\Sanitizer::make( $raw_data, \Growfund\DTO\Auth\ResetPasswordDTO::sanitization_rules() )->get_sanitized_data();

        // Instantiate the DTO
        $reset_password_dto = new \Growfund\DTO\Auth\ResetPasswordDTO( $sanitized_data );

        // Delegate ALL business logic to the Service (No need to duplicate get_user_by or key checks here)
        $result = $this->auth_service->reset_password( $reset_password_dto );

        // Handle Service errors
        if ( is_wp_error( $result ) ) {
            return $result; 
        }

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
        // 1. Get the stateless user ID passed from the JWT middleware
        $user_id = $request->get_param( 'jwt_user_id' );

        if ( ! $user_id ) {
            return new WP_Error(
                'not_authenticated',
                'User is not authenticated or token is missing',
                [ 'status' => 401 ]
            );
        }

        // 2. Fetch the user
        $user = get_user_by( 'ID', $user_id );

        // 3. Prevent fatal errors if the user was deleted from the database 
        // but their JWT token hasn't expired yet
        if ( ! $user ) {
            return new WP_Error(
                'user_not_found',
                'The user associated with this token no longer exists.',
                [ 'status' => 404 ]
            );
        }

        // 4. Return the response
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
