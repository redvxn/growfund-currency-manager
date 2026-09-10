<?php
/**
 * Campaign Post Controller - Handles campaign endpoints
 */

use Growfund\DTO\CampaignPost\CampaignPostFilterDTO;
use Growfund\DTO\JsonResponseDTO;
use Growfund\Services\CampaignPostService;
use Growfund\PostTypes\Campaign;
use Growfund\Views\Components\CampaignUpdate\UpdateDetail;
use Growfund\Views\Components\CampaignUpdate\UpdateList;
use Growfund\Validation\Validator;

if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_CampaignPost_Controller {


    /**
     * @var CampaignPostService
     */
    protected $service;

    /**
     * Initialize the controller with CampaignPostService.
     */
    public function __construct()
    {
        $this->service = new CampaignPostService();
    }

    /**
     * Get Paginated Campaign Post Updates
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */

    public function paginated_campaign_post_updates($request) 
    {
        $filters_dto = new CampaignPostFilterDTO();
        $filters_dto->page = $request->get_param('page', 1);
        $filters_dto->limit = $request->get_param('per_page', 6);
        $filters_dto->campaign_id = $request->get_param('campaign_id');

        try {
            $paginated = $this->service->paginated($filters_dto);
            if ( ! $paginated ) {
                return new WP_Error( 
                    'paginated_campaign_update_fetch_failed', 
                    'Failed to fetch paginated campaign updates.', 
                    [ 'status' => 500 ] 
                );
            }
        } catch ( \Exception $e ) {
            // Capture the Exception and return a 400 Bad Request for ALL exceptions
            return new WP_Error(
                'paginated_campaign_update_fetch_error', 
                $e->getMessage(), // This will output "Failed to update campaign with ID..."
                [ 'status' => 400 ] // <-- Assigning your single code right here
            );
        }

        $update_collection = new UpdateList();
        $update_collection->updates = $paginated->results;

        return rest_ensure_response( [
            'success' => true,
            'data'    => $update_collection->updates,
            'message' => 'Paginated campaign updates fetched successfully.',
        ] );
    }

    /**
     * Get Campaign Post Update Detail
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */

    public function get_campaign_post_update_detail($request) 
    {
        $campaign_update_id = $request->get_param('id');

        try {
            $campaign_update = $this->service->get_by_id($campaign_update_id);
            if ( ! $campaign_update ) {
                return new WP_Error( 
                    'campaign_update_not_found', 
                    'Campaign update not found.', 
                    [ 'status' => 404 ] 
                );
            }
        } catch ( \Exception $e ) {
            // Capture the Exception and return a 400 Bad Request for ALL exceptions
            return new WP_Error(
                'campaign_update_fetch_error', 
                $e->getMessage(), // This will output "Failed to update campaign with ID..."
                [ 'status' => 400 ] // <-- Assigning your single code right here
            );
        }
       
        try {
            $neighbors = $this->service->get_neighbors($campaign_update);
            if ( ! $neighbors ) {
                    return new WP_Error( 
                        'campaign_update_neighbors_not_found', 
                        'Neighbors for the campaign update not found.', 
                        [ 'status' => 404 ] 
                    );
                }
        } catch ( \Exception $e ) {
            // Capture the Exception and return a 400 Bad Request for ALL exceptions
            return new WP_Error(
                'campaign_update_neighbors_fetch_error', 
                $e->getMessage(), // This will output "Failed to update campaign with ID..."
                [ 'status' => 400 ] // <-- Assigning your single code right here
            );
        }

        $update_detail_component = new UpdateDetail();
        $update_detail_component->update = $campaign_update;
        $update_detail_component->previous_update = $neighbors['previous'];
        $update_detail_component->next_update = $neighbors['next'];

        return rest_ensure_response( [
            'success' => true,
            'data'    => [
                'detail' => $update_detail_component->update,
                'next_id' => $update_detail_component->next_update,
                'prev_id' => $update_detail_component->previous_update,
            ],
            'message' => 'Campaign update detail fetched successfully.',
        ] );
    }

    /**
     * Create Campaign Post
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function create_campaign_post( $request )
    {
        $data = [
            'campaign_id'    => $request->get_param('campaign_id'), // Make sure this matches your 'ids' validation key
            'title' => $request->get_param('title'),
            'slug' => $request->get_param('slug'),
            'description' => $request->get_param('description'),
            'image' => $request->get_param('image'),
        ];

        $validator = Validator::make($data, [
            'campaign_id'   => 'required|integer|post_exists:post_type=' . Campaign::NAME,
            'title'         => 'required|string',
            'slug'          => 'string',
            'description'   => 'string',
            'image'         => 'integer|is_valid_image_id',
        ]);

        if ( $validator->is_failed() ) {
            $specific_errors = $validator->get_errors();
            
            // Convert the array of specific errors into a single readable string
            // e.g., "title: Required field, goal_amount: Must be numeric"
            $error_string = is_array( $specific_errors ) 
                ? implode( ', ', array_map(
                    function( $v, $k ) { return $k . ': ' . ( is_array( $v ) ? implode( ' ', $v ) : $v ); }, 
                    $specific_errors, 
                    array_keys( $specific_errors )
                )) 
                : 'Validation failed';

            return new WP_Error(
                'rest_invalid_param', // Standard WP REST API code for invalid parameters
                'Validation errors - ' . $error_string, 
                [
                    'status' => 422,
                    'details' => $specific_errors, // Next.js can read response.data.details to highlight specific inputs
                ]
            );
        }

        try {
            $post_id = $this->service->save($request->get_param('campaign_id'), [
                'title'         => $request->get_param('title'),
                'slug'          => $request->get_param('slug'),
                'description'   => $request->get_param('description'),
                'image'         => $request->get_param('image'),
            ]);
            if ( ! $post_id ) {
                return new WP_Error( 
                    'create_campaign_post_update_failed', 
                    'Failed to create to create campaign post update', 
                    [ 'status' => 404 ] 
                );
            }
        } catch ( \Exception $e ) {
            // Capture the Exception and return a 400 Bad Request for ALL exceptions
            return new WP_Error(
                'campaign_post_update_create_error', 
                $e->getMessage(), // This will output "Failed to update campaign with ID..."
                [ 'status' => 400 ] // <-- Assigning your single code right here
            );
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => $post_id,
            'message' => 'Campaign post update created successfully.',
        ] );
    }
}