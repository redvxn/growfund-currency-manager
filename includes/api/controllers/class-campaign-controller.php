<?php
/**
 * Campaign Controller - Handles campaign endpoints
 */

use Growfund\DTO\Campaign\CampaignFiltersDTO as CampaignFilterDTO;
use Growfund\Services\CampaignService;
use Growfund\Services\BookmarkService;
use Growfund\DTO\JsonResponseDTO;
use Growfund\PostTypes\Campaign;
use Growfund\Validation\Validator;
use Growfund\Sanitizer;
use Growfund\Views\Components\Campaign\CampaignList;
use Growfund\DTO\Campaign\UpdateCampaignDTO;
use Growfund\Constants\Status\CampaignStatus;

if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_Campaign_Controller {

    protected $campaign_service;
    protected $bookmark_service;

    public function __construct()
    {
        $this->campaign_service = new CampaignService();
        $this->bookmark_service = new BookmarkService();
    }

    /**
     * Get all published campaigns
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_campaigns( $request ) {
        $filters_dto = new CampaignFilterDTO();
        $filters_dto->page = max( 1, intval( $request->get_param( 'page' ) ?? 1 ) );
        $filters_dto->limit = max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?? 20 ) ) );
        $filters_dto->search = $request->get_param( 'search' ) ?? '';
        $filters_dto->category_slug = $request->get_param( 'category_slug' ) ?? '';
        $filters_dto->orderby = $request->get_param( 'orderby' ) ?? 'date';
        $filters_dto->order = $request->get_param( 'order' ) ?? '';
        $filters_dto->is_featured = $request->get_param( 'is_featured' ) ?? '';
        $filters_dto->status = $request->get_param( 'status' ) ?? 'launched-and-beyond';

        $paginated = $this->campaign_service->paginated( $filters_dto );

        $campaign_list = new CampaignList();
        $campaign_list->campaigns = $paginated->results;
        $campaign_list->classname = 'growfund-ajax-campaign-list';

        $response_dto = new JsonResponseDTO([
            'data' => $paginated,
        ]);

        

        return rest_ensure_response( [
            'success' => true,
            'data'    => $response_dto,
            'paginated' => $paginated,
        ] );
    }

    /**
     * Get single campaign by ID
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_campaign( $request ) {
        $id = intval( $request->get_param( 'id' ) );

        if ( ! $id ) {
            return new WP_Error(
                'invalid_campaign_id',
                'Campaign ID is required',
                [ 'status' => 400 ]
            );
        }

        $result = $this->campaign_service->get_by_id( $id );

        if ( ! $result ) {
            return new WP_Error(
                'campaign_not_found',
                'Campaign not found',
                [ 'status' => 404 ]
            );
        }

        

        return rest_ensure_response( [
            'success' => true,
            'data'    => $result,
        ] );
    }

    /**
     * Create a new campaign
     * 
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */

    public function create_campaign( $request ) {
        $id = $this->campaign_service->create();

        if ( ! $id ) {
            return new WP_Error(
                'campaign_creation_failed',
                'Failed to create campaign',
                [ 'status' => 500 ]
            );
        }

        

        return rest_ensure_response( [
            'success' => true,
            'data'    => $id,
        ] );

    }

    /**
     * Update an existing campaign
     * 
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function update_campaign( $request ) { // <-- ONLY ONE ARGUMENT
        
        // Grab the ID from the URL route parameter
        $id = intval( $request->get_param( 'id' ) ); 
        
        $user_id = $request->get_param( 'jwt_user_id' );
        $user = get_user_by( 'ID', $user_id );
        $roles = $user ? $user->roles : [];

        $is_admin = false;
        $is_fundraiser = false;

        if ( in_array( 'administrator', $roles ) ) {
            $is_admin = true;
        } elseif ( in_array( 'growfund_fundraiser', $roles ) ) {
            $is_fundraiser = true;
        } else {
            return new WP_Error(
                'unauthorized',
                'You do not have permission to update this campaign',
                [ 'status' => 403 ]
            );
        }

        if ( ! $id ) {
            return new WP_Error(
                'invalid_campaign_id',
                'Campaign ID is required',
                [ 'status' => 400 ]
            );
        }


        $data = $request->get_params();
        $data['id'] = $id;
        $data['author_id'] = $user_id;

        $validator = Validator::make( $data, UpdateCampaignDTO::validation_rules() );

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

        $sanitized_data = Sanitizer::make($data, UpdateCampaignDTO::sanitization_rules())->get_sanitized_data();

        $campaign_dto = UpdateCampaignDTO::from_array( $sanitized_data );

        if ( ! $is_admin ) {
            $campaign_dto->status = CampaignStatus::PENDING;
        }

        try {
            // Attempt to update the campaign
            $result = $this->campaign_service->update( $campaign_dto->id, $campaign_dto );

            // If it returns false instead of throwing
            if ( ! $result ) {
                return new WP_Error( 
                    'campaign_update_failed', 
                    'Failed to update the campaign.', 
                    [ 'status' => 500 ] 
                );
            }

        } catch ( \Exception $e ) {
            // Capture the Exception and return a 400 Bad Request for ALL exceptions
            return new WP_Error(
                'campaign_update_error', 
                $e->getMessage(), // This will output "Failed to update campaign with ID..."
                [ 'status' => 400 ] // <-- Assigning your single code right here
            );
        }

        

        return rest_ensure_response( [
            'success' => true,
            'data'    => $result,
        ] );
    }


    /**
     * Format campaign data for API response
     *
     * @param int $campaign_id Campaign post ID
     * @return array
     */
    private function format_campaign( $campaign_id ) {
        $campaign = get_post( $campaign_id );
        $fundraiser_id = get_post_meta( $campaign_id, 'growfund_fundraiser_id', true );
        $goal_cents = intval( get_post_meta( $campaign_id, 'growfund_goal_amount', true ) );
        $fundraiser = get_user_by( 'ID', $fundraiser_id );
        $image_data = get_post_meta( $campaign_id, 'growfund_images', true );
        $image_url = '';
        if ( is_array( $image_data ) && ! empty( $image_data ) ) {
            $attachment_id = reset( $image_data );
            $image_url = wp_get_attachment_url( $attachment_id );
        }

        return [
            'id'              => $campaign_id,
            'title'           => $campaign->post_title,
            'description'     => wp_trim_words( $campaign->post_content, 20 ),
            'goal'            => $goal_cents / 100,
            'raised_amount'   => $this->get_campaign_raised_amount( $campaign_id ),
            'status'          => get_post_meta( $campaign_id, 'growfund_status', true ),
            'image_url'       => $image_url,
            'fundraiser_name' => $fundraiser ? $fundraiser->display_name : 'Unknown',
            'fundraiser_id'   => $fundraiser_id,
            'created_at'      => get_post_meta( $campaign_id, 'growfund_start_date', true ),
            'deadline'        => get_post_meta( $campaign_id, 'growfund_end_date', true ),
        ];
    }

    /**
     * Format detailed campaign data for API response
     *
     * @param int $campaign_id Campaign post ID
     * @return array
     */
    private function format_campaign_detail( $campaign_id ) {
        $campaign = get_post( $campaign_id );
        $fundraiser_id = get_post_meta( $campaign_id, 'growfund_fundraiser_id', true );
        $goal_cents = intval( get_post_meta( $campaign_id, 'growfund_goal_amount', true ) );
        $fundraiser = get_user_by( 'ID', $fundraiser_id );
        $image_data = get_post_meta( $campaign_id, 'growfund_images', true );
        $image_url = '';
        if ( is_array( $image_data ) && ! empty( $image_data ) ) {
            $attachment_id = reset( $image_data );
            $image_url = wp_get_attachment_url( $attachment_id );
        }

        return [
            'id'                 => $campaign_id,
            'title'              => $campaign->post_title,
            'description'        => $campaign->post_content,
            'goal'               => $goal_cents / 100,
            'raised_amount'      => $this->get_campaign_raised_amount( $campaign_id ),
            'status'             => get_post_meta( $campaign_id, 'growfund_status', true ),
            'image_url'          => $image_url,
            'fundraiser_name'    => $fundraiser ? $fundraiser->display_name : 'Unknown',
            'fundraiser_id'      => $fundraiser_id,
            'created_at'         => get_post_meta( $campaign_id, 'growfund_start_date', true ),
            'deadline'           => get_post_meta( $campaign_id, 'growfund_end_date', true ),
        ];
    }

    

    /**
     * Get campaign donations count
     *
     * @param int $campaign_id Campaign ID
     * @return int
     */
    // Inside get_campaign_donations_count()
    private function get_campaign_donations_count( $campaign_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'growfund_donations';
        
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} 
            WHERE campaign_id = %d 
            AND status IN ('completed', 'COMPLETED') 
            AND payment_status IN ('paid', 'PAID')",
            $campaign_id
        ) );
        
        return intval( $count );
    }

    /**
     * Get total raised amount for a campaign (in decimals)
     *
     * @param int $campaign_id Campaign ID
     * @return float
     */
    private function get_campaign_raised_amount( $campaign_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'growfund_donations';
        
        $stats = $wpdb->get_row( $wpdb->prepare( "
            SELECT SUM(amount) as total_raised 
            FROM {$table_name} 
            WHERE campaign_id = %d 
            AND status IN ('completed', 'COMPLETED') 
            AND payment_status IN ('paid', 'PAID')
        ", $campaign_id ) );

        // Extract the raw cents from the database
        $total_raised_cents = $stats ? intval( $stats->total_raised ) : 0;
        
        // Return as standard decimal format for the app
        return $total_raised_cents / 100;
    }
}
