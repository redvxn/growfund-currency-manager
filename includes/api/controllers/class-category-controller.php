<?php
/**
 * Category Controller - Handles category endpoints
 */

use Growfund\Policies\CategoryPolicy;
use Growfund\Sanitizer;
use Growfund\Services\CampaignCategoryService;
use Growfund\Supports\Arr;
use Growfund\Validation\Validator;

if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_Category_Controller {

    protected $campaign_category_service;

    public function __construct()
    {
        $this->campaign_category_service = new CampaignCategoryService();
    }

    /**
     * Retrieve all campaign categories
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function categories_list( $request )
    {
        $categories_list = $this->campaign_category_service->get_all();

        return rest_ensure_response( [
            'success' => true,
            'data'    => $categories_list,
        ] );
    }

    /**
     * Retrieve all top level campaign categories
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function categories_top_level( $request )
    {
        $categories_top_level = $this->campaign_category_service->get_top_level_categories();

        return rest_ensure_response( [
            'success' => true,
            'data'    => $categories_top_level,
        ] );
    }

}