<?php
/**
 * Authentication Service for API
 * 
 * Handles user login, registration, and authentication using WordPress built-in functions
 * with proper security implementations.
 * 
 */

use Growfund\DTO\Auth\ResetPasswordDTO;

if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_Auth_Service {

    /**
     * Authenticate user credentials against WordPress
     *
     * @param string $username Username or Email
     * @param string $password User password
     * @return WP_User|WP_Error
     */
    public function authenticate_user( $username, $password ) {
        // If the user passed an email instead of a username, resolve it first
        if ( is_email( $username ) ) {
            $user_obj = get_user_by( 'email', $username );
            if ( $user_obj ) {
                $username = $user_obj->user_login;
            }
        }

        // Execute WordPress core authentication
        $user = wp_authenticate( $username, $password );

        if ( is_wp_error( $user ) ) {
            return new WP_Error(
                'invalid_credentials',
                'Invalid username/email or password',
                [ 'status' => 401 ]
            );
        }

        return $user;
    }

    /**
     * Register a new user in WordPress
     *
     * @param array $user_data Associative array of user data
     * @return WP_User|WP_Error
     */
    public function register( $register_dto ) {
        $existing_user = get_user_by( 'email', $register_dto->email );
        if ( $existing_user ) {
            return new WP_Error(
                'email_exists',
                'Email already exists',
                [ 'status' => 400 ]
            );
        }

        $user_data = [
            'user_login' => $register_dto->username,
            'user_email' => $register_dto->email,
            'user_pass' => $register_dto->password,
            'first_name' => $register_dto->first_name,
            'last_name' => $register_dto->last_name,
            'display_name' => $register_dto->first_name . ' ' . $register_dto->last_name,
            'role' => $register_dto->role,
        ];

        $user_id = wp_insert_user( $user_data );

        if ( is_wp_error( $user_id ) ) {
            return new WP_Error(
                'registration_failed',
                'User registration failed',
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

        return get_user_by( 'ID', $user_id );

    }

    /**
     * send password reset email to user   
     * @param array $data
     * @return bool|WP_Error
     *
     */
    public function send_password_reset_email( array $data )
    {
        $email = $data['email'];

        if ( empty( $email ) || ! is_email( $email ) ) {
            return new WP_Error(
                'invalid_email',
                'A valid email address is required',
                [ 'status' => 400 ]
            );
        }

        $user = get_user_by('email', $email);

        if (!$user) {
            return new WP_Error(
                'user_not_found', 
                'No user found with that email address', 
                ['status' => 404]);
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

        return true;

    }
    /**
     * check if reset key is valid
     * @param string $key
     * @param int $user_id
     * @return bool
     */
    public function is_valid_reset_key(string $key, int $user_id)
    {
        $stored_key = get_user_meta($user_id, 'growfund_password_reset_key', true);

        if (!$stored_key) {
            return false;
        }

        if ($key !== $stored_key) {
            return false;
        }

        if ($this->is_reset_key_consumed($user_id)) {
            return false;
        }

        return true;
    }

    /**
     * Check if reset key has been consumed
     * @param int $user_id
     * @return bool
     */
    public function is_reset_key_consumed(int $user_id)
    {
        return (bool) get_user_meta($user_id, 'growfund_password_reset_key_consumed', true);
    }

    /**
     * Mark reset key as consumed
     * @param int $user_id
     * @return bool
     */
    public function mark_reset_key_consumed(int $user_id)
    {
        return update_user_meta($user_id, 'growfund_password_reset_key_consumed', true);
    }

    /**
     * Reset user password
     * @param ResetPasswordDTO $reset_password_dto
     * @return bool|\WP_Error
     */
    public function reset_password( ResetPasswordDTO $reset_password_dto ) { // Removed int $user_id parameter since we look it up here anyway
        $user = get_user_by( 'login', $reset_password_dto->login );

        if ( ! $user ) {
            return new \WP_Error(
                'user_not_found',
                'No user found with that login',
                [ 'status' => 404 ]
            );
        }

        $user_id = $user->ID;

        // Validate reset key
        if ( ! $this->is_valid_reset_key( $reset_password_dto->key, $user_id ) ) {
            return new \WP_Error(
                'invalid_reset_key',
                'Invalid or expired reset key',
                [ 'status' => 400 ]
            );
        }

        // Update password
        wp_set_password( $reset_password_dto->password, $user_id ); // Assuming the DTO property is $password based on your controller

        // Mark reset key as consumed
        $this->mark_reset_key_consumed( $user_id );

        return true;
    }
}