<?php
/**
 * Fundraiser Controller - Handles donor-related endpoints
 */

use GrowfundPro\Services\FundraiserService;
use GrowfundPro\Policies\FundraiserPolicy;

if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_Fundraiser_Controller {

    /**
     * Fundraiser service instance.
     *
     * @var FundraiserService
     */
    protected $service;

    /**
     * FundraiserPolicy instance.
     *
     * @var FundraiserPolicy
     */
    protected $policy;

    /**
     * Initialize the controller with FundraiserService.
     */
    public function __construct() 
    {
        $this->service = new FundraiserService();
        $this->policy = new FundraiserPolicy();
    }


    /**
     * GET PAGINATED Fundraisers
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */

    public function get_paginated_fundraisers( $request ) {

        $fundraisers = $this->service->paginated([
            'page' => $request->get_param('page', 1),
            'limit' => $request->get_param('per_page', 10),
            'search' => $request->get_param('search', ''),
            'orderby' => $request->get_param('orderby', 'date'),
            'order' => $request->get_param('order', 'desc'),
            'status' => $request->get_param('status', ''),
        ]);

        return rest_ensure_response([
            'success' => true,
            'data'    => $fundraisers,
        ]);

    }
}