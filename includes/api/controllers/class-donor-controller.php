<?php
/**
 * Donor Controller - Handles donor-related endpoints
 */

use Growfund\Constants\Activities;
use Growfund\Constants\Pagination;
use Growfund\Constants\UserDeleteType;
use Growfund\Services\DonationService;
use Growfund\Services\DonorService;
use Growfund\DTO\Activity\ActivityFilterDTO;
use Growfund\DTO\Donation\DonationFilterParamsDTO;
use Growfund\Sanitizer;
use Growfund\Validation\Validator;
use Growfund\Services\ActivityService;
use Growfund\DTO\Donor\CreateDonorDTO;
use Growfund\DTO\Donor\UpdateDonorDTO;
use Growfund\Policies\DonorPolicy;
use Growfund\Constants\HookNames;
use Growfund\Supports\Arr;
use Growfund\Supports\Money;

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

    /**
     * Donor Activities
     *  
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function donor_activities( $request )
    {
        $donor_id = $request->get_param('donor_id');

        if ( ! $donor_id ) {
            return new WP_Error(
                'missing_donor_id',
                'Donor ID is required.',
                [ 'status' => 400 ]
            );
        }

        $user = get_user_by('id', $donor_id);
        $is_donor = in_array('growfund_donor', (array) $user->roles);

        if ( ! $is_donor ) {
            return new WP_Error(
                'invalid_donor',
                'The provided user is not a donor.',
                [ 'status' => 400 ]
            );
        }

        $activity_filter_dto = ActivityFilterDTO::from_array([
            'page' => max( 1, intval( $request->get_param( 'page' ) ?? 1 ) ),
            'limit' => max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?? 10 ) ) ),
            'orderby' => $request->get_param( 'orderby' ) ?? 'created_at',
            'order' => $request->get_param( 'order' ) ?? 'DESC',
            'user_id' => $request->get_param( 'donor_id' ) ?? '',
        ]);

        try {
            $activities = (new ActivityService())->paginated($activity_filter_dto, Activities::DONOR);
            if ( ! $activities ) {
                return new WP_Error( 
                    'donor_activities_failed', 
                    'Failed to retrieve donor activities.', 
                    [ 'status' => 500 ] 
                );
            }
        } catch ( \Exception $e ) {
            return new WP_Error(
                'donor_activities_failed', 
                $e->getMessage(), 
                [ 'status' => 400 ] 
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $activities,
            'message' => "Donor activities retrieved successfully.",
        ]);
    }

    /**
     * Get the overview of a donor by ID
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function donor_overview( $request ) {
        $donor_id = $request->get_param('donor_id');

        if ( ! $donor_id ) {
            return new WP_Error(
                'missing_donor_id',
                'Donor ID is required.',
                [ 'status' => 400 ]
            );
        }

        $user = get_user_by('id', $donor_id);
        $is_donor = in_array('growfund_donor', (array) $user->roles);

        if ( ! $is_donor ) {
            return new WP_Error(
                'invalid_donor',
                'The provided user is not a donor.',
                [ 'status' => 400 ]
            );
        }

        try {
            $donor_overview = $this->service->get_overview($donor_id);

            if ( ! $donor_overview ) {
                return new WP_Error(
                    'donor_not_found',
                    'Donor not found.',
                    [ 'status' => 404 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'donor_overview_failed',
                'Failed to retrieve donor overview.',
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $donor_overview,
        ]);

    }

    /**
     * Get donor stats by ID
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function donor_stats( $request )
    {
        $donor_id = $request->get_param('donor_id');

        if ( ! $donor_id ) {
            return new WP_Error(
                'missing_donor_id',
                'Donor ID is required.',
                [ 'status' => 400 ]
            );
        }

        $user = get_user_by('id', $donor_id);
        $is_donor = in_array('growfund_donor', (array) $user->roles);

        if ( ! $is_donor ) {
            return new WP_Error(
                'invalid_donor',
                'The provided user is not a donor.',
                [ 'status' => 400 ]
            );
        }

        $donor_stats = [];

        try {
            $donor_stats = [
                'total_number_of_donations' => $this->donation_service->get_total_number_of_donations($donor_id),
                'total_supported_campaigns' => $this->donation_service->get_successfully_donated_campaigns_by_donor($donor_id),
                'total_contributions' => Money::prepare_for_display($this->donation_service->get_total_contribution_amount_by_donor($donor_id)),
                'average_contributions' => Money::prepare_for_display($this->donation_service->get_average_contribution_amount_by_donor($donor_id)),
            ];

            if ( ! $donor_stats ) {
                return new WP_Error(
                    'donor_stats_not_found',
                    'Donor stats not found.',
                    [ 'status' => 404 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'donor_stats_failed',
                'Failed to retrieve donor stats.',
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => [
                'total_number_of_donations' => $donor_stats['total_number_of_donations'],
                'total_supported_campaigns' => $donor_stats['total_supported_campaigns'],
                'total_contributions' => $donor_stats['total_contributions'],
                'average_contributions' => $donor_stats['average_contributions'],
            ],
            'message' => 'Donor stats retrieved successfully.',
        ]);
    }   

    /**
     * Create Donor
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function create_donor( $request )
    {
        $data = $request->get_json_params();

        $validator = Validator::make($data, CreateDonorDTO::validation_rules());
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

        $sanitized_data = Sanitizer::make($data, CreateDonorDTO::sanitization_rules())->get_sanitized_data();

        $donor_dto = CreateDonorDTO::from_array($sanitized_data);

        try {
            $donor_id = $this->service->store($donor_dto);

            if ( ! $donor_id ) {
                return new WP_Error(
                    'donor_creation_failed',
                    'Failed to create donor.',
                    [ 'status' => 500 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'donor_creation_failed',
                'Failed to create donor: ' . $e->getMessage(),
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $donor_id,
            'message' => 'Donor created successfully.',
        ]);
    }

    /**
     * Update existing donor by ID
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function update_donor( $request )
    {
        $donor_id = $request->get_param('donor_id');
        $data = $request->get_json_params();

        if ( ! $donor_id ) {
            return new WP_Error(
                'missing_donor_id',
                'Donor ID is required.',
                [ 'status' => 400 ]
            );
        }

        $validator = Validator::make($data, UpdateDonorDTO::validation_rules());
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

        $sanitized_data = Sanitizer::make($data, UpdateDonorDTO::sanitization_rules())->get_sanitized_data();
        $donor_dto = UpdateDonorDTO::from_array($sanitized_data);

        try {
            $result = $this->service->update($donor_id, $donor_dto);

            if ( ! $result ) {
                return new WP_Error(
                    'donor_update_failed',
                    'Failed to update donor.',
                    [ 'status' => 500 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'donor_update_failed',
                'Failed to update donor: ' . $e->getMessage(),
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $result,
            'message' => 'Donor updated successfully.',
        ]);
    }

    /**
     * Get the donations of a donor by ID
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function donor_donations( $request )
    {
        $donor_id = $request->get_param('donor_id');

        if ( ! $donor_id ) {
            return new WP_Error(
                'missing_donor_id',
                'Donor ID is required.',
                [ 'status' => 400 ]
            );
        }

        $user = get_user_by('id', $donor_id);
        $is_donor = in_array('growfund_donor', (array) $user->roles);

        if ( ! $is_donor ) {
            return new WP_Error(
                'invalid_donor',
                'The provided user is not a donor.',
                [ 'status' => 400 ]
            );
        }

        $donation_filter_params_dto = DonationFilterParamsDTO::from_array([
            'page' => max( 1, intval( $request->get_param( 'page' ) ?? 1 ) ),
            'limit' => max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?? 10 ) ) ),
            'orderby' => $request->get_param( 'orderby' ) ?? 'created_at',
            'order' => $request->get_param( 'order' ) ?? 'DESC',
            'user_id' => $request->get_param( 'donor_id' ) ?? '',
        ]);

        try {
            $donations = $this->service->get_paginated_donations($donation_filter_params_dto);

            if ( ! $donations ) {
                return new WP_Error(
                    'donor_donations_not_found',
                    'Donor donations not found.',
                    [ 'status' => 404 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'donor_donations_failed',
                'Failed to retrieve donor donations.',
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $donations,
            'message' => "Donor donations retrieved successfully.",
        ]);
    }

    /**
     * Delete a donor by ID
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function delete_donor( $request )
    {
        $donor_id = $request->get_param('donor_id');
        $delete_type = $request->get_param('delete_type', 'trash'); // Default to soft delete

        if ( ! $donor_id ) {
            return new WP_Error(
                'missing_donor_id',
                'Donor ID is required.',
                [ 'status' => 400 ]
            );
        }

        $request_user_id = $request->get_param( 'jwt_user_id' );
        $roles = get_userdata( $request_user_id )->roles;
        if ( ! in_array( 'administrator', $roles ) ) {
            return new WP_Error(
                'unauthorized',
                'You do not have permission to delete donors.',
                [ 'status' => 403 ]
            );
        }

        try {
            $result = $this->service->delete($donor_id, $delete_type);

            if ( ! $result ) {
                return new WP_Error(
                    'donor_deletion_failed',
                    'Failed to delete donor.',
                    [ 'status' => 500 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'donor_deletion_failed',
                'Failed to delete donor: ' . $e->getMessage(),
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Donor deleted successfully.',
        ]);
    }

    /**
     * Donor Empty Trash - Permanently delete all donors in the trash
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function donor_empty_trash( $request )
    {
        $request_user_id = $request->get_param( 'jwt_user_id' );
        $is_permanent_delete = $request->get_param( 'is_permanent_delete', false );
        $roles = get_userdata( $request_user_id )->roles;
        if ( ! in_array( 'administrator', $roles ) ) {
            return new WP_Error(
                'unauthorized',
                'You do not have permission to empty donor trash.',
                [ 'status' => 403 ]
            );
        }

        try {
            $is_deleted = $this->service->empty_trash( $is_permanent_delete );

            if ( ! $is_deleted ) {
                return new WP_Error(
                    'donor_empty_trash_failed',
                    'Failed to empty donor trash.',
                    [ 'status' => 500 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'donor_empty_trash_failed',
                'Failed to empty donor trash: ' . $e->getMessage(),
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Donor trash emptied successfully.',
        ]);
    }

    /**
     * Donor Bulk Actions - Perform bulk actions on donors (e.g., delete, restore)
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function donor_bulk_actions( $request )
    {
        $request_user_id = $request->get_param( 'jwt_user_id' );
        $is_permanent_delete = $request->get_param( 'is_permanent_delete', false );
        $roles = get_userdata( $request_user_id )->roles;
        if ( ! in_array( 'administrator', $roles ) ) {
            return new WP_Error(
                'unauthorized',
                'You do not have permission to empty donor trash.',
                [ 'status' => 403 ]
            );
        }

        $data = [
            'ids'    => $request->get_param('ids'), // Make sure this matches your 'ids' validation key
            'action' => $request->get_param('action'),
            'is_permanent_delete' => $request->get_param('is_permanent_delete', false),
        ];

        $validator = Validator::make($data, [
            'ids'       => 'required|array',
            'action'    => 'required|string|in:trash,delete,restore',
            'is_permanent_delete' => 'required_if:action,delete|boolean',
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

        $result = [];

        try {
            switch ($request->get_param('action')) {
                case 'trash':
                    $result = $this->service->bulk_delete($request->get_param('ids'), UserDeleteType::TRASH);
                    break;
                case 'delete':
                    $type = $request->get_param('is_permanent_delete', false) ? UserDeleteType::PERMANENT : UserDeleteType::ANONYMIZE;
                    $result = $this->service->bulk_delete($request->get_param('ids'), $type);
                    break;
                case 'restore':
                    $result = $this->service->bulk_restore($request->get_param('ids'));
                    break;
            }

            if ( ! $result ) {
                return new WP_Error(
                    'donor_bulk_action_failed',
                    'Failed to perform bulk action on donors.',
                    [ 'status' => 500 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'donor_bulk_action_failed',
                'Failed to perform bulk action on donors: ' . $e->getMessage(),
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $result,
            'message' => "Bulk action '{$data['action']}' performed successfully on selected donors.",
        ]);
    }

}