<?php
/**
 * Authentication Service for API
 * 
 * Handles user login, registration, and authentication using WordPress built-in functions
 * with proper security implementations.
 * 
 */

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
     * check if reset key is valid
     * @param string $reset_key
     * @param int $user_id
     * @return bool
     */
    public function is_valid_reset_key(string $reset_key, int $user_id)
    {
        $stored_key = get_user_meta($user_id, 'growfund_password_reset_key', true);

        if (!$stored_key) {
            return false;
        }

        if ($reset_key !== $stored_key) {
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
}