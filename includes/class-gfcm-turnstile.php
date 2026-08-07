<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GFCM Cloudflare Turnstile Integration
 * Protects Login, Registration, Checkout, and provides a helper for KYC.
 */
class GFCM_Turnstile {

    public static function init() {
        // Stop if the wp-config constants are missing
        if ( ! defined('CF_TURNSTILE_SITE_KEY') || empty(CF_TURNSTILE_SITE_KEY) ) return;

        // Load the Turnstile Javascript API & Custom Form Injector
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
        add_action( 'login_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );

        // 1. RENDER WIDGET: Login & Registration (Both WooCommerce & Standard WP)
        add_action( 'woocommerce_login_form', [ __CLASS__, 'render_widget' ] );
        add_action( 'woocommerce_register_form', [ __CLASS__, 'render_widget' ] );
        add_action( 'login_form', [ __CLASS__, 'render_widget' ] );
        add_action( 'register_form', [ __CLASS__, 'render_widget' ] );

        // 2. VERIFY: Login & Registration
        add_filter( 'woocommerce_process_login_errors', [ __CLASS__, 'verify_wc_login' ], 10, 3 );
        add_filter( 'woocommerce_process_registration_errors', [ __CLASS__, 'verify_wc_registration' ], 10, 4 );
        add_filter( 'authenticate', [ __CLASS__, 'verify_wp_login' ], 21, 3 ); // Priority 21 to catch standard & Growfund auth
        add_filter( 'registration_errors', [ __CLASS__, 'verify_wp_registration' ], 10, 3 );

        // 3. RENDER & VERIFY: WooCommerce Checkout
        add_action( 'woocommerce_review_order_before_submit', [ __CLASS__, 'render_widget' ] );
        add_action( 'woocommerce_checkout_process', [ __CLASS__, 'verify_checkout' ] );
        
        // 4. RENDER & VERIFY: WordPress Comments
        // Renders the widget for guest commenters
        add_action( 'comment_form_after_fields', [ __CLASS__, 'render_widget' ] ); 
        // Renders the widget for logged-in commenters
        add_action( 'comment_form_logged_in_after', [ __CLASS__, 'render_widget' ] ); 
        // Intercepts the comment submission
        add_action( 'pre_comment_on_post', [ __CLASS__, 'verify_comment' ] );
    }

    /**
     * Loads the Cloudflare JS globally on the frontend & Injects into custom forms
     */
    public static function enqueue_scripts() {
        // 1. Append ?render=explicit to the script URL to block automatic DOM scanning
        wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', [], null, true );
        
        // Custom DOM Injection to target the Growfund Login/Signup forms that don't have native hooks
        $site_key = esc_js( CF_TURNSTILE_SITE_KEY );
        $custom_js = "
            document.addEventListener('DOMContentLoaded', function() {
                var authForms = document.querySelectorAll('form'); 
                authForms.forEach(function(form) {
                    // Check if form has a password field and verify we haven't processed this form yet
                    if (form.querySelector('input[type=\"password\"]') && !form.hasAttribute('data-gfcm-turnstile-active')) {
                        
                        // Mark form immediately to prevent duplicate runs
                        form.setAttribute('data-gfcm-turnstile-active', 'true');
                        
                        var turnstileDiv = document.createElement('div');
                        turnstileDiv.style.margin = '15px 0';
                        
                        var submitBtn = form.querySelector('button[type=\"submit\"], input[type=\"submit\"]');
                        if (submitBtn) {
                            submitBtn.parentNode.insertBefore(turnstileDiv, submitBtn);
                        } else {
                            form.appendChild(turnstileDiv);
                        }
    
                        // Safely execute the manual render once the global Turnstile object is ready
                        if (typeof turnstile !== 'undefined') {
                            turnstile.render(turnstileDiv, {
                                sitekey: '{$site_key}',
                                theme: 'light'
                            });
                        } else {
                            // Fallback if the Cloudflare script loads slightly slower than DOMContentLoaded
                            window.addEventListener('load', function() {
                                if (typeof turnstile !== 'undefined') {
                                    turnstile.render(turnstileDiv, {
                                        sitekey: '{$site_key}',
                                        theme: 'light'
                                    });
                                }
                            });
                        }
                    }
                });
            });
        ";
        wp_add_inline_script( 'cf-turnstile', $custom_js );
    }


    /**
     * Outputs the HTML placeholder for the Turnstile widget
     */
    public static function render_widget() {
        echo '<div class="cf-turnstile" data-sitekey="' . esc_attr( CF_TURNSTILE_SITE_KEY ) . '" data-theme="light" style="margin-bottom: 15px;"></div>';
    }

    /**
     * CORE VERIFICATION ENGINE
     * Pings Cloudflare API to ensure the token is legitimate
     */
    public static function verify_token( $token ) {
        if ( empty( $token ) ) return false;
        
        // Ensure secret key exists
        if ( ! defined('CF_TURNSTILE_SECRET_KEY') || empty(CF_TURNSTILE_SECRET_KEY) ) return false;

        $response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'body' => [
                'secret'   => CF_TURNSTILE_SECRET_KEY,
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR']
            ]
        ] );

        if ( is_wp_error( $response ) ) return false;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return isset( $body['success'] ) && $body['success'] === true;
    }

    // ==========================================
    // VALIDATION HOOKS (Intercepting Submissions)
    // ==========================================

    public static function verify_wc_login( $validation_error, $login, $password ) {
        if ( ! self::verify_token( $_POST['cf-turnstile-response'] ?? '' ) ) {
            $validation_error->add( 'turnstile_error', '<strong>Security Check Failed:</strong> Please confirm you are human.' );
        }
        return $validation_error;
    }

    public static function verify_wc_registration( $validation_error, $username, $password, $email ) {
        if ( ! self::verify_token( $_POST['cf-turnstile-response'] ?? '' ) ) {
            $validation_error->add( 'turnstile_error', '<strong>Security Check Failed:</strong> Please confirm you are human.' );
        }
        return $validation_error;
    }

    public static function verify_wp_login( $user, $username, $password ) {
        // We removed the strictly $_POST['wp-submit'] check here so it catches custom Growfund forms too!
        // But we ensure the Turnstile field is present so we don't break background API logins
        if ( isset( $_POST['cf-turnstile-response'] ) ) { 
            if ( ! self::verify_token( $_POST['cf-turnstile-response'] ) ) {
                return new WP_Error( 'turnstile_error', '<strong>Security Check Failed:</strong> Please confirm you are human.' );
            }
        }
        return $user;
    }

    public static function verify_wp_registration( $errors, $sanitized_user_login, $user_email ) {
        if ( ! self::verify_token( $_POST['cf-turnstile-response'] ?? '' ) ) {
            $errors->add( 'turnstile_error', '<strong>Security Check Failed:</strong> Please confirm you are human.' );
        }
        return $errors;
    }

    public static function verify_checkout() {
        if ( ! self::verify_token( $_POST['cf-turnstile-response'] ?? '' ) ) {
            wc_add_notice( 'Security Check Failed. Please refresh the page and verify you are human.', 'error' );
        }
    }
}

GFCM_Turnstile::init();