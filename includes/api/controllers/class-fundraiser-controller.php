<?php
/**
 * Fundraiser Controller - Handles donor-related endpoints
 */

use Growfund\Constants\Activities;
use Growfund\Constants\Status\FundraiserStatus;
use Growfund\Constants\UserDeleteType;
use Growfund\DTO\Activity\ActivityFilterDTO;
use Growfund\Sanitizer;
use GrowfundPro\Services\FundraiserService;
use GrowfundPro\Policies\FundraiserPolicy;
use Growfund\DTO\Fundraiser\CreateFundraiserDTO;
use Growfund\DTO\Fundraiser\UpdateFundraiserDTO;
use GrowfundPro\DTO\Fundraiser\PayoutMethodDTO;
use Growfund\Services\ActivityService;
use Growfund\Supports\Arr;
use Growfund\Validation\Validator;
use Growfund\Constants\UserTypes\Fundraiser;
use Growfund\Supports\Date;
use Growfund\Supports\UserMeta;

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

    /**
     * Create Fundraiser
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */

    public function create_fundraiser( $request ) 
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

        $data = $request->get_json_params();

        // Validate the request data
        $validator = Validator::make($data, CreateFundraiserDTO::validation_rules());

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

        $sanitized_data = Sanitizer::make($data, CreateFundraiserDTO::sanitization_rules())->get_sanitized_data();

        $fundraiser_dto = CreateFundraiserDTO::from_array($sanitized_data);

        // Create the fundraiser
        try {
            $fundraiser_id = $this->service->store($fundraiser_dto);

            if ( ! $fundraiser_id ) {
                return new WP_Error(
                    'fundraiser_creation_failed',
                    'Failed to create fundraiser.',
                    [ 'status' => 500 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'fundraiser_creation_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $fundraiser_id,
            'message' => 'Fundraiser created successfully.',
        ]);
    }

    /**
     * Make user a Fundraiser
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function make_user_fundraiser( $request )
    {
        $request_user_id = $request->get_param( 'jwt_user_id' );
        $roles = get_userdata( $request_user_id )->roles;
        if ( ! in_array( 'administrator', $roles ) ) {
            return new WP_Error(
                'unauthorized',
                'You do not have permission to make user a fundraiser.',
                [ 'status' => 403 ]
            );
        }

        $user_id = $request->get_param('user_id');

        // Check if the user exists
        if ( ! get_userdata( $user_id ) ) {
            return new WP_Error(
                'user_not_found',
                'User not found.',
                [ 'status' => 404 ]
            );
        }

        // Check if the user is already a fundraiser
        $user_roles = get_userdata( $user_id )->roles;
        if ( in_array( 'fundraiser', $user_roles ) ) {
            return new WP_Error(
                'user_already_fundraiser',
                'User is already a fundraiser.',
                [ 'status' => 400 ]
            );
        }

        $user = get_userdata( $user_id );

        // Make the user a fundraiser
        try {
            $is_role_added = $user->add_new_role(Fundraiser::ROLE);
            UserMeta::update($user->get_id(), 'joined_at', Date::current_sql_safe());
            UserMeta::update($user->get_id(), 'status', FundraiserStatus::ACTIVE );

            if ( ! $is_role_added ) {
                return new WP_Error(
                    'role_assignment_failed',
                    'Failed to assign fundraiser role to the user.',
                    [ 'status' => 500 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'fundraiser_creation_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'User has been made a fundraiser successfully.',
        ]);
    }

    /**
     * Update an existing Fundraiser
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function update_fundraiser( $request )
    {
        $request_user_id = $request->get_param( 'jwt_user_id' );
        $roles = get_userdata( $request_user_id )->roles;
        if ( ! in_array( 'administrator', $roles ) ) {
            return new WP_Error(
                'unauthorized',
                'You do not have permission to update fundraiser information.',
                [ 'status' => 403 ]
            );
        }

        $fundraiser_id = $request->get_param('fundraiser_id');
        $user_roles = get_userdata( $fundraiser_id )->roles;
        if ( ! in_array( 'growfund_fundraiser', $user_roles ) ) {
            return new WP_Error(
                'user_not_fundraiser',
                'The specified user is not a fundraiser.',
                [ 'status' => 400 ]
            );
        }


        $data = $request->get_json_params();

        // Validate the request data
        $validator = Validator::make($data, UpdateFundraiserDTO::validation_rules());
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

        $sanitized_data = Sanitizer::make($data, UpdateFundraiserDTO::sanitization_rules())->get_sanitized_data();

        $fundraiser_id = $request->get_param('fundraiser_id');

        $fundraiser_dto = UpdateFundraiserDTO::from_array($sanitized_data);

        try {
            $result = $this->service->update($fundraiser_id, $fundraiser_dto);

            if ( ! $result ) {
                return new WP_Error(
                    'fundraiser_update_failed',
                    'Failed to update fundraiser information.',
                    [ 'status' => 500 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'fundraiser_update_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Fundraiser information updated successfully.',
        ]);

    }

    /**
     * Update fundraiser payout method
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function update_fundraiser_payout_method( $request )
    {
        $fundraiser_id = $request->get_param( 'jwt_user_id' );
        $roles = get_userdata( $fundraiser_id )->roles;
        if ( ! in_array( 'growfund_fundraiser', $roles ) ) {
            return new WP_Error(
                'unauthorized',
                'You do not have permission to update fundraiser information.',
                [ 'status' => 403 ]
            );
        }

        $data = $request->get_json_params();

        $validator = Validator::make($data, PayoutMethodDTO::validation_rules());

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

        $sanitized_data = Sanitizer::make($data, PayoutMethodDTO::sanitization_rules())->get_sanitized_data();

        $payout_dto = PayoutMethodDTO::from_array($sanitized_data);

        try {
            $result = $this->service->update_payout_method($fundraiser_id, $payout_dto);

            if ( ! $result ) {
                return new WP_Error(
                    'fundraiser_update_failed',
                    'Failed to update fundraiser payout method.',
                    [ 'status' => 500 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'fundraiser_update_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Fundraiser payout method updated successfully.',
        ]);
    }

    /**
     * Update fundraiser status
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function update_fundraiser_status( $request )
    {
        $request_user_id = $request->get_param( 'jwt_user_id' );
        $roles = get_userdata( $request_user_id )->roles;
        if ( ! in_array( 'administrator', $roles ) ) {
            return new WP_Error(
                'unauthorized',
                'You do not have permission to update fundraiser information.',
                [ 'status' => 403 ]
            );
        }

        $fundraiser_id = $request->get_param('fundraiser_id');
        $user_roles = get_userdata( $fundraiser_id )->roles;
        if ( ! in_array( 'growfund_fundraiser', $user_roles ) ) {
            return new WP_Error(
                'user_not_fundraiser',
                'The specified user is not a fundraiser.',
                [ 'status' => 400 ]
            );
        }

        $data = [
            'id' => $request->get_param('fundraiser_id'),
            'action' => $request->get_param('action'),
            'reason' => $request->get_param('reason', ''),
        ];

        $validator = Validator::make($data, [
            'id'       => 'required',
            'action'   => 'required|string|in:approve,decline',
            'reason'   => 'prohibited_if:action,approve|required_if:action,decline|string',
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

        $status = $request->get_param('action') === 'approve' ? FundraiserStatus::ACTIVE : FundraiserStatus::INACTIVE;
        $reason =  $request->get_param('reason');

        try {
            $result = $this->service->update_status($fundraiser_id, $status, $reason);

            if ( ! $result ) {
                return new WP_Error(
                    'fundraiser_update_failed',
                    'Failed to update fundraiser status.',
                    [ 'status' => 500 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'fundraiser_update_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $result,
            'message' => 'Fundraiser status updated successfully.',
        ]);
    }

    /**
     * Delete a fundraiser
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function delete_fundraiser( $request )
    {
        $request_user_id = $request->get_param( 'jwt_user_id' );
        $roles = get_userdata( $request_user_id )->roles;
        if ( ! in_array( 'administrator', $roles ) ) {
            return new WP_Error(
                'unauthorized',
                'You do not have permission to update fundraiser information.',
                [ 'status' => 403 ]
            );
        }

        $fundraiser_id = $request->get_param('fundraiser_id');
        $user_roles = get_userdata( $fundraiser_id )->roles;
        if ( ! in_array( 'growfund_fundraiser', $user_roles ) ) {
            return new WP_Error(
                'user_not_fundraiser',
                'The specified user is not a fundraiser.',
                [ 'status' => 400 ]
            );
        }

        $delete_type = $request->get_param('delete_type', 'trash'); // Default to soft delete

        try {
            $is_deleted = $this->service->delete($fundraiser_id, $delete_type);

            if ( ! $is_deleted ) {
                return new WP_Error(
                    'fundraiser_delete_failed',
                    'Failed to delete fundraiser.',
                    [ 'status' => 500 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'fundraiser_delete_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $is_deleted,
            'message' => 'Fundraiser deleted successfully.',
        ]);
    }

    /**
     * Fundraiser Empty Trash
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function fundraiser_empty_trash( $request )
    {
        $request_user_id = $request->get_param( 'jwt_user_id' );
        $roles = get_userdata( $request_user_id )->roles;
        if ( ! in_array( 'administrator', $roles ) ) {
            return new WP_Error(
                'unauthorized',
                'You do not have permission to empty fundraiser trash.',
                [ 'status' => 403 ]
            );
        }

        try {
            $is_deleted= $this->service->empty_trash($request->get_param('is_permanent_delete'));

            if ( ! $is_deleted) {
                return new WP_Error(
                    'fundraiser_empty_trash_failed',
                    'Failed to empty fundraiser trash.',
                    [ 'status' => 500 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'fundraiser_empty_trash_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $is_deleted,
            'message' => 'Fundraiser trash emptied successfully.',
        ]);
    }

    /**
     * Handle Fundraiser Bulk Actions
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function fundraiser_bulk_actions( $request )
    {
        $request_user_id = $request->get_param( 'jwt_user_id' );
        $roles = get_userdata( $request_user_id )->roles;
        if ( ! in_array( 'administrator', $roles ) ) {
            return new WP_Error(
                'unauthorized',
                'You do not have permission to perform bulk actions on fundraisers.',
                [ 'status' => 403 ]
            );
        }

        $data = [
            'ids'    => $request->get_param('ids'), // Make sure this matches your 'ids' validation key
            'action' => $request->get_param('action'),
            'is_permanent_delete' => $request->get_param('is_permanent_delete', false),
        ];

        $validator = Validator::make($data, [
            'ids'    => 'required|array',
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
                    $type = $request->get_bool('is_permanent_delete', false) ? UserDeleteType::PERMANENT : UserDeleteType::ANONYMIZE;
                    $result = $this->service->bulk_delete($request->get_param('ids'), $type);
                    break;
                case 'restore':
                    $result = $this->service->bulk_restore($request->get_param('ids'));
                    break;
            }
            $failed = empty($result['failed']) ? [] : Arr::make($result['failed'])->pluck('id')->toArray();
                // If it returns false instead of throwing
                if ( ! empty( $failed ) ) {
                    // Generate the specific partial-success or failure message
                    $error_message = sprintf(
                        /* translators: %s: Fundraiser ids */
                        __('Bulk action successfully applied for all the selected fundraisers except the fundraisers with id: %s.', 'growfund'),
                        implode(', ', $failed)
                    );

                    return new WP_Error(
                        'rest_bulk_action_failed', 
                        $error_message, 
                        [
                            'status'  => 422, 
                            'details' => [
                                'failed_fundraiser_ids' => $failed // Allows the frontend to easily parse which IDs failed
                            ],
                        ]
                    );
                }
        } catch (Exception $e) {
            return new WP_Error(
                'fundraiser_bulk_action_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $result,
            'message' => $error_message ?? "Bulk action has been applied successfully.",
        ]);
    }

    /**
     * Fundraiser Overview
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function fundraiser_overview( $request )
    {
        $request_user_id = $request->get_param( 'jwt_user_id' );
        $roles = get_userdata( $request_user_id )->roles;
        if ( ! in_array( 'administrator', $roles ) ) {
            return new WP_Error(
                'unauthorized',
                'You do not have permission to view fundraiser overview.',
                [ 'status' => 403 ]
            );
        }

        $fundraiser_id = $request->get_param('fundraiser_id');
        $user_roles = get_userdata( $fundraiser_id )->roles;
        if ( ! in_array( 'growfund_fundraiser', $user_roles ) ) {
            return new WP_Error(
                'user_not_fundraiser',
                'The specified user is not a fundraiser.',
                [ 'status' => 400 ]
            );
        }

        try {
            $overview = $this->service->get_overview($fundraiser_id);
        } catch (Exception $e) {
            return new WP_Error(
                'fundraiser_overview_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $overview,
        ]);
    }

    /**
     * Fundraiser Activities
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function fundraiser_activities( $request )
    {
        $request_user_id = $request->get_param( 'jwt_user_id' );
        $roles = get_userdata( $request_user_id )->roles;
        if ( ! in_array( 'administrator', $roles ) ) {
            return new WP_Error(
                'unauthorized',
                'You do not have permission to view fundraiser overview.',
                [ 'status' => 403 ]
            );
        }

        $fundraiser_id = $request->get_param('fundraiser_id');
        $user_roles = get_userdata( $fundraiser_id )->roles;
        if ( ! in_array( 'growfund_fundraiser', $user_roles ) ) {
            return new WP_Error(
                'user_not_fundraiser',
                'The specified user is not a fundraiser.',
                [ 'status' => 400 ]
            );
        }

        $activity_filter_dto = ActivityFilterDTO::from_array([
            'page' => $request->get_param('page', 1),
            'limit' => $request->get_param('per_page', 10),
            'orderby' => $request->get_param('orderby', 'created_at', ['ID', 'type', 'created_at']),
            'order' => $request->get_param('order', 'DESC'),
            'user_id' => $fundraiser_id,
        ]);

        try {
            $activities = (new ActivityService())->get_paginated_activities($activity_filter_dto);
            if ( ! $activities ) {
                return new WP_Error(
                    'fundraiser_activities_failed',
                    'Failed to retrieve fundraiser activities.',
                    [ 'status' => 500 ]
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'fundraiser_activities_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $activities,
        ]);
    }

}