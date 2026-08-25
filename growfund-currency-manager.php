<?php
/*
Plugin Name: Growfund Custom Checkout, Currency & KYC Manager
Description: Modular checkout replacement, dynamic currency exchange, and multi-step KYC onboarding.
Version: 3.0
Author: Fude
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GFCM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFCM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// 1. Include Core Functionality (Your existing checkout, currency, and DB sync logic)
// -> Move your existing hooks, Section 1 through 15 into this file:
// Load the core functionality
require_once GFCM_PLUGIN_DIR . 'includes/class-gfcm-core.php';

// Load the newly created email scheduling handler
require_once GFCM_PLUGIN_DIR . 'includes/class-gfcm-emails.php';

// Load the brevo synchronization handler
require_once GFCM_PLUGIN_DIR . 'includes/class-gfcm-brevo.php';

// Load Cloudflare Turnstile Protection
require_once GFCM_PLUGIN_DIR . 'includes/class-gfcm-turnstile.php';

// Load Automated Wallet Sync Trigger
require_once GFCM_PLUGIN_DIR . 'includes/class-gfcm-wallet-trigger.php';

//  Include the New KYC Module
require_once GFCM_PLUGIN_DIR . 'includes/class-gfcm-kyc.php';

//  Include the New KYC Module
require_once GFCM_PLUGIN_DIR . 'includes/class-gfcm-elementor.php';

//  Include the New Donations Exporter
require_once GFCM_PLUGIN_DIR . 'includes/class-gfcm-export-donations.php';

// Include API Infrastructure
require_once GFCM_PLUGIN_DIR . 'includes/api/class-jwt-handler.php';
require_once GFCM_PLUGIN_DIR . 'includes/api/middleware/class-jwt-middleware.php';
require_once GFCM_PLUGIN_DIR . 'includes/api/middleware/class-combined-auth-middleware.php';

// Include API Controllers
require_once GFCM_PLUGIN_DIR . 'includes/api/controllers/class-auth-controller.php';
require_once GFCM_PLUGIN_DIR . 'includes/api/controllers/class-campaign-controller.php';
require_once GFCM_PLUGIN_DIR . 'includes/api/controllers/class-donation-controller.php';
require_once GFCM_PLUGIN_DIR . 'includes/api/controllers/class-donor-controller.php';
require_once GFCM_PLUGIN_DIR . 'includes/api/controllers/class-fundraiser-controller.php';
require_once GFCM_PLUGIN_DIR . 'includes/api/controllers/class-checkout-controller.php';
require_once GFCM_PLUGIN_DIR . 'includes/api/controllers/class-media-controller.php';

// Include API Sevices
require_once GFCM_PLUGIN_DIR . 'includes/api/services/class-auth-service.php';
require_once GFCM_PLUGIN_DIR . 'includes/api/services/class-donation-service.php';

// Include API Routes
require_once GFCM_PLUGIN_DIR . 'includes/api/class-routes.php';

// 3. Unified Enqueue Scripts
add_action( 'wp_enqueue_scripts', 'gfcm_enqueue_all_assets' );
function gfcm_enqueue_all_assets() {
    // Core CSS with new :root variables
    wp_enqueue_style( 'gfcm-main-css', GFCM_PLUGIN_URL . 'assets/css/gfcm-styles.css', array(), '3.0' );

    

    // KYC JS
    if ( is_page( 'fundraiser-kyc' ) ) { // Make sure you create a page with this slug!
        wp_enqueue_script( 'gfcm-kyc-js', GFCM_PLUGIN_URL . 'assets/js/gfcm-kyc.js', array('jquery'), '3.0', true );
        wp_localize_script( 'gfcm-kyc-js', 'gfcm_kyc_params', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'gfcm_kyc_nonce' )
        ));
    }
}

// ==========================================
// ADMIN DASHBOARD CSS INJECTION
// ==========================================
add_action( 'admin_enqueue_scripts', 'gfcm_enqueue_admin_custom_css' );
function gfcm_enqueue_admin_custom_css( $hook ) {
    // OPTIONAL: Only load this CSS on Growfund-specific admin pages to prevent bloating the rest of WordPress
    if ( strpos( $hook, 'growfund' ) === false ) {
        return; 
    }

    // Enqueue your custom stylesheet
    // Make sure you create an 'admin-style.css' file inside your plugin's /assets/css/ folder!
    wp_enqueue_style( 
        'gfcm-admin-style', 
        GFCM_PLUGIN_URL . 'assets/css/admin-style.css', 
        array(), 
        '1.0' 
    );
}

// ==========================================
// FRONTEND CONTENT PROTECTION (NOT RECOMMENDED FOR UX)
// ==========================================
add_action( 'wp_head', 'gfcm_disable_right_click_and_selection' );
function gfcm_disable_right_click_and_selection() {
    // Only apply to the frontend, never the admin dashboard
    if ( ! is_admin() ) {
        ?>
        <style>
            /* Disable text highlighting/selection */
            body {
                -webkit-touch-callout: none; /* iOS Safari */
                -webkit-user-select: none;   /* Safari */
                -khtml-user-select: none;    /* Konqueror HTML */
                -moz-user-select: none;      /* Old versions of Firefox */
                -ms-user-select: none;       /* Internet Explorer/Edge */
                user-select: none;           /* Non-prefixed version, currently supported by Chrome, Edge, Opera and Firefox */
            }
        </style>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                // Disable Right-Click
                document.addEventListener('contextmenu', function(e) {
                    e.preventDefault();
                });

                // Disable some common keyboard shortcuts (Ctrl+C, Ctrl+S, Ctrl+U)
                document.addEventListener('keydown', function(e) {
                    if (e.ctrlKey && (e.key === 'c' || e.key === 's' || e.key === 'u' || e.key === 'C' || e.key === 'S' || e.key === 'U')) {
                        e.preventDefault();
                    }
                });
            });
        </script>
        <?php
    }
}



// ==========================================
// CUSTOM AUTH ROUTING & 404 BLOCKER
// ==========================================
add_action( 'init', 'gfcm_secure_auth_routing', 1 );

function gfcm_secure_auth_routing() {
    $request_uri  = $_SERVER['REQUEST_URI'];
    $is_logged_in = is_user_logged_in();
    $path         = trim( parse_url( $request_uri, PHP_URL_PATH ), '/' );

    // 1. Safely ignore AJAX, Cron, and background processes
    if ( defined( 'DOING_AJAX' ) || defined( 'DOING_CRON' ) || strpos( $request_uri, 'admin-ajax.php' ) !== false || strpos( $request_uri, 'admin-post.php' ) !== false ) {
        return;
    }

    // 2. Route "/admin-login" to the native login system invisibly
    if ( $path === 'admin-login' ) {
        if ( $is_logged_in ) {
            wp_redirect( admin_url() );
            exit;
        }
        
        // Flag this as a valid login attempt so we don't 404 it below
        define( 'GFCM_VALID_LOGIN_REQUEST', true );
        
        // FIX: Explicitly declare the variables wp-login.php expects in the global scope
        global $user_login, $error, $action, $interim_login, $redirect_to;
        $user_login    = isset( $_POST['log'] ) ? wp_unslash( $_POST['log'] ) : '';
        $error         = '';
        $action        = isset( $_REQUEST['action'] ) ? sanitize_text_field( $_REQUEST['action'] ) : 'login';
        $interim_login = isset( $_REQUEST['interim-login'] );
        $redirect_to   = isset( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : '';

        $_SERVER['REQUEST_URI'] = '/wp-login.php';
        require_once ABSPATH . 'wp-login.php';
        exit;
    }

    // 3. Block direct access to wp-login.php (Returns 404)
    if ( strpos( $request_uri, 'wp-login.php' ) !== false && ! defined( 'GFCM_VALID_LOGIN_REQUEST' ) ) {
        
        // Allow necessary background actions (like logging out or resetting passwords) to pass through safely
        $allowed_actions = array( 'logout', 'rp', 'resetpass', 'interim-login' );
        $action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';
        
        if ( ! in_array( $action, $allowed_actions ) ) {
            gfcm_force_404_page();
        }
    }

    // 4. Block direct access to /wp-admin/ for guests (Returns 404)
    if ( strpos( $request_uri, '/wp-admin' ) !== false && ! $is_logged_in ) {
        gfcm_force_404_page();
    }
    
    // 5. Handle "/admin" URL alias
    if ( $path === 'admin' ) {
        if ( ! $is_logged_in ) {
            wp_redirect( home_url( '/admin-login/' ) );
            exit;
        } else {
            wp_redirect( admin_url() );
            exit;
        }
    }
}

// Helper Function: Force Elementor 404 Page via Frontend Redirect
function gfcm_force_404_page() {
    // Redirect to a clearly fake URL so the WordPress frontend naturally throws a 404
    // This allows Elementor's Theme Builder to intercept and display your custom 404 template!
    wp_safe_redirect( home_url( '/404-not-found/' ) );
    exit;
}

// Update WordPress generated Login URLs (like in emails) to use the new /admin-login/
add_filter( 'login_url', 'gfcm_custom_login_url_filter', 10, 3 );
function gfcm_custom_login_url_filter( $login_url, $redirect, $force_reauth ) {
    $url = home_url( '/admin-login/' );
    if ( ! empty( $redirect ) ) {
        $url = add_query_arg( 'redirect_to', urlencode( $redirect ), $url );
    }
    return $url;
}

// ==========================================
// FIX THE LOGIN FORM SUBMISSION ACTION
// ==========================================
add_filter( 'site_url', 'gfcm_fix_login_form_action', 10, 4 );
function gfcm_fix_login_form_action( $url, $path, $scheme, $blog_id ) {
    // When WordPress tries to build the form submission URL, change it to our custom slug!
    if ( $scheme === 'login_post' && strpos( $url, 'wp-login.php' ) !== false ) {
        return str_replace( 'wp-login.php', 'admin-login', $url );
    }
    return $url;
}

// Disable WordPress's native guess-routing for "login" and "admin" so it doesn't conflict
remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );