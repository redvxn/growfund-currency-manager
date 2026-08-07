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