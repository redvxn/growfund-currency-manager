<?php
/**
 * Donor Controller - Handles donor-related endpoints
 */

use Growfund\Constants\Activities;
use Growfund\Constants\Pagination;
use Growfund\Services\DonationService;
use Growfund\Services\DonorService;
use Growfund\DTO\Activity\ActivityFilterDTO;
use Growfund\DTO\Donation\DonationFilterParamsDTO;
use Growfund\Sanitizer;
use Growfund\Validation\Validator;
use Growfund\Services\ActivityService;
use Growfund\Policies\DonorPolicy;
use Growfund\Constants\HookNames;

if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_Donor_Controller {

    protected $service;
    protected $donation_service;
    

    public function __construct() {
        $this->service = new DonorService();
        $this->donation_service = new DonationService();
    }

    /**
     * GET PAGINATED DONORS
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */

    public function get_paginated_donors( $request ) {
        
        $donors = $this->service->paginated([
            'page' => $request->get_param('page', 1),
            'limit' => $request->get_param('per_page', 10),
            'search' => $request->get_param('search', ''),
            'orderby' => $request->get_param('orderby', 'date'),
            'order' => $request->get_param('order', 'desc'),
            'start_date' => $request->get_param('start_date', ''),
            'end_date' => $request->get_param('end_date', ''),
            'status' => $request->get_param('status', ''),
            'campaign_id' => $request->get_param('campaign_id', ''),
        ]);


            

        return rest_ensure_response([
            'success' => true,
            'data'    => $donors,
        ]);
    }

    

}