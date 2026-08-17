<?php
/**
 * API Routes - Registers all REST API endpoints
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_API_Routes {

    private $namespace = 'growfund-currency-manager/v1';

    /**
     * Initialize routes
     */
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register all REST API routes
     */
    public function register_routes() {
        // ===== Auth Routes (Public) =====

        register_rest_route(
            $this->namespace,
            '/auth/system-token',
            [
                'methods'             => 'POST',
                'callback'            => [ new GFCM_Auth_Controller(), 'get_system_token' ],
                'permission_callback' => '__return_true', // Validation happens inside the controller
            ]
        );
        
        register_rest_route(
            $this->namespace,
            '/auth/login',
            [
                'methods'             => 'POST',
                'callback'            => [ new GFCM_Auth_Controller(), 'login' ],
                'permission_callback' => '__return_true', // Public
                'args'                => [
                    'username' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                    'password' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/auth/register',
            [
                'methods'             => 'POST',
                'callback'            => [ new GFCM_Auth_Controller(), 'register' ],
                'permission_callback' => '__return_true', // Public
                'args'                => [
                    'username' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                    'password' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                    'password_confirmation' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                    'email' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                    'first_name' => [
                        'type' => 'string',
                    ],
                    'last_name' => [
                        'type' => 'string',
                    ],
                    'user_type' => [
                        'type' => 'string',
                    ],
                ],
            ]
        );
        
        register_rest_route(
            $this->namespace,
            '/auth/password-reset-mail',
            [
                'methods'             => 'POST',
                'callback'            => [ new GFCM_Auth_Controller(), 'password_reset_mail' ],
                'permission_callback' => '__return_true', // Public
                'args'                => [
                    'email' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ]
        );
        
        register_rest_route(
            $this->namespace,
            '/auth/password-reset',
            [
                'methods'             => 'POST',
                'callback'            => [ new GFCM_Auth_Controller(), 'reset_password' ],
                'permission_callback' => '__return_true', // Public
                'args'                => [
                    'login' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                    'key' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                    'password' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                    'password_confirmation' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/auth/refresh',
            [
                'methods'             => 'POST',
                'callback'            => [ new GFCM_Auth_Controller(), 'refresh_token' ],
                'permission_callback' => '__return_true', // Public
                'args'                => [
                    'refresh_token' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/auth/me',
            [
                'methods'             => 'GET',
                'callback'            => [ new GFCM_Auth_Controller(), 'current_user' ],
                'permission_callback' => [ 'GFCM_JWT_Middleware', 'validate' ], // Protected
            ]
        );

        // ===== Campaign Routes (Public) =====

        register_rest_route(
            $this->namespace,
            '/campaigns',
            [
                'methods'             => 'GET',
                'callback'            => [ new GFCM_Campaign_Controller(), 'get_campaigns' ],
                'permission_callback' => [ 'GFCM_Combined_Auth_Middleware', 'validate' ], // Protected
                'args'                => [
                    'page' => [
                        'type' => 'integer',
                    ],
                    'per_page' => [
                        'type' => 'integer',
                    ],
                    'search' => [
                        'type' => 'string',
                    ],
                    'category_slug' => [
                        'type' => 'string',
                    ],
                    'orderby' => [
                        'type' => 'string',
                    ],
                    'order' => [
                        'type' => 'string',
                    ],
                    'is_featured' => [
                        'type' => 'integer',
                    ],
                    'status' => [
                        'type' => 'string',
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/campaigns/(?P<id>\d+)',
            [
                'methods'             => 'GET',
                'callback'            => [ new GFCM_Campaign_Controller(), 'get_campaign' ],
                'permission_callback' => [ 'GFCM_Combined_Auth_Middleware', 'validate' ], // Protected
                'args'                => [
                    'id' => [
                        'type' => 'integer',
                    ],
                ],
            ]
        );

        // ===== Donation Routes (Protected) =====

        // 1. GET all donations 
        register_rest_route(
            $this->namespace,
            '/donations',
            [
                'methods'             => 'GET',
                'callback'            => [ new GFCM_Donation_Controller(), 'get_donations' ],
                'permission_callback' => [ 'GFCM_JWT_Middleware', 'validate' ], // Protected
                'args'                => [
                    'page' => [
                        'type' => 'integer',
                    ],
                    'per_page' => [
                        'type' => 'integer',
                    ],
                    'status' => [
                        'type' => 'string',
                    ],
                    'search' => [
                        'type' => 'string',
                    ],
                    'campaign_id' => [
                        'type' => 'integer',
                    ],
                    'fund_id' => [
                        'type' => 'integer',
                    ],
                    'start_date' => [
                        'type' => 'string',
                    ],
                    'end_date' => [
                        'type' => 'string',
                    ],
                    'user_id' => [
                        'type' => 'integer',
                    ],
                    'orderby' => [
                        'type' => 'string',
                    ],
                    'order' => [
                        'type' => 'string',
                    ],
                ],
            ]
        );

        // ===== Donation Routes (Protected) =====

        // 1. GET paginated donations made by the current user (Donor or Fundraiser)
        register_rest_route(
            $this->namespace,
            '/donations/paginated',
            [
                'methods'             => 'GET',
                'callback'            => [ new GFCM_Donation_Controller(), 'get_paginated_onations' ],
                'permission_callback' => [ 'GFCM_JWT_Middleware', 'validate' ], // Protected
                'args'                => [
                    'page' => [
                        'type' => 'integer',
                    ],
                    'per_page' => [
                        'type' => 'integer',
                    ],
                    'status' => [
                        'type' => 'string',
                    ],
                    'search' => [
                        'type' => 'string',
                    ],
                    'campaign_id' => [
                        'type' => 'integer',
                    ],
                    'fund_id' => [
                        'type' => 'integer',
                    ],
                    'start_date' => [
                        'type' => 'string',
                    ],
                    'end_date' => [
                        'type' => 'string',
                    ],
                    'user_id' => [
                        'type' => 'integer',
                    ],
                    'orderby' => [
                        'type' => 'string',
                    ],
                    'order' => [
                        'type' => 'string',
                    ],
                ],
            ]
        );

        // ===== Donor Routes (Protected) =====

        // 1. GET paginated donations made by the current user (Donor or Fundraiser)
        register_rest_route(
            $this->namespace,
            '/donors/paginated',
            [
                'methods'             => 'GET',
                'callback'            => [ new GFCM_Donor_Controller(), 'get_paginated_donors' ],
                'permission_callback' => [ 'GFCM_JWT_Middleware', 'validate' ], // Protected
                'args'                => [
                    'page' => [
                        'type' => 'integer',
                    ],
                    'per_page' => [
                        'type' => 'integer',
                    ],
                    'status' => [
                        'type' => 'string',
                    ],
                    'search' => [
                        'type' => 'string',
                    ],
                    'campaign_id' => [
                        'type' => 'integer',
                    ],
                    'start_date' => [
                        'type' => 'string',
                    ],
                    'end_date' => [
                        'type' => 'string',
                    ],
                    'orderby' => [
                        'type' => 'string',
                    ],
                    'order' => [
                        'type' => 'string',
                    ],
                ],
            ]
        );

        // 2. GET a single donation by ID
        register_rest_route(
            $this->namespace,
            '/donations/(?P<id>\d+)',
            [
                'methods'             => 'GET',
                'callback'            => [ new GFCM_Donation_Controller(), 'get_donation' ],
                'permission_callback' => [ 'GFCM_JWT_Middleware', 'validate' ], // Protected
                'args'                => [
                    'id' => [
                        'type' => 'integer',
                    ],
                ],
            ]
        );

        // 3. GET all donations received by the current Fundraiser (Across all their campaigns)
        register_rest_route(
            $this->namespace,
            '/campaigns/donations',
            [
                'methods'             => 'GET',
                'callback'            => [ new GFCM_Donation_Controller(), 'get_donations_by_campaign' ],
                'permission_callback' => [ 'GFCM_JWT_Middleware', 'validate' ], // Protected
                'args'                => [
                    'page' => [ 'type' => 'integer' ],
                    'per_page' => [ 'type' => 'integer' ],
                ],
            ]
        );

        // 4. GET donations received for a specific campaign (Must be owned by Fundraiser)
        register_rest_route(
            $this->namespace,
            '/campaigns/(?P<campaign_id>\d+)/donations',
            [
                'methods'             => 'GET',
                'callback'            => [ new GFCM_Donation_Controller(), 'get_donations_by_campaign' ],
                'permission_callback' => [ 'GFCM_JWT_Middleware', 'validate' ], // Protected
                'args'                => [
                    'campaign_id' => [ 'type' => 'integer' ],
                    'page' => [ 'type' => 'integer' ],
                    'per_page' => [ 'type' => 'integer' ],
                ],
            ]
        );

        // ===== Checkout Routes (Protected) =====

        register_rest_route(
            $this->namespace,
            '/checkout/handoff',
            [
                'methods'             => 'POST',
                'callback'            => [ new GFCM_Checkout_Controller(), 'handoff' ],
                'permission_callback' => [ 'GFCM_JWT_Middleware', 'validate' ], // Protected
                'args'                => [
                    'donation_id' => [
                        'type'     => 'integer',
                        'required' => true,
                    ],
                ],
            ]
        );
    }
}

// Initialize routes on plugin load
new GFCM_API_Routes();
