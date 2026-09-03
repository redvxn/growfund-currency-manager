<?php
/**
 * Donation Controller - Handles donation retrieval endpoints
 */

use Growfund\Constants\Activities;
use Growfund\DTO\Donation\DonationFilterParamsDTO;
use Growfund\DTO\Activity\ActivityFilterDTO;
use Growfund\Constants\Status\DonationStatus;
use Growfund\Services\ActivityService;
use Growfund\Services\DonationService;
use Growfund\Policies\DonationPolicy;
use Growfund\DTO\PaginatedCollectionDTO;
use Growfund\DTO\Donation\DonationDTO;
use Growfund\DTO\Donation\CreateDonationDTO;
use Growfund\Supports\Payment;
use Growfund\Sanitizer;
use Growfund\Validation\Validator;
use Growfund\DTO\BulkActionDTO;
use Growfund\Supports\Arr;
use Growfund\Constants\OptionKeys;
use Growfund\Supports\Option;

if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_Donation_Controller {

    /**
     * DonationService instance.
     *
     * @var DonationService
     */
    protected $service;
    protected $policy;

    private $donation_service;
    
    /**
     * Initialize the controller with DonationService.
     */
    public function __construct(DonationService $service, DonationPolicy $policy)
    {
        $this->service = $service;
        $this->policy = $policy;
        $this->donation_service = new GFCM_Donation_Service();
        
    }


    /**
     * GET ALL DONATIONS
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */

    public function get_all_donations( $request ) {

        // $this->policy->authorize_paginated();
        $dto = new DonationFilterParamsDTO();

        $dto->page = max( 1, intval( $request->get_param( 'page' ) ?? 1 ) );
        $dto->limit = max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?? 20 ) ) );
        $dto->search = $request->get_param( 'search' ) ?? '';
        $dto->campaign_id = $request->get_param( 'campaign_id' ) ?? '';
        $dto->fund_id = $request->get_param( 'fund_id' ) ?? '';
        $dto->status = $request->get_param( 'status' ) ?? '';
        $dto->start_date = $request->get_param( 'start_date' ) ?? '';
        $dto->end_date = $request->get_param( 'end_date' ) ?? '';
        $dto->user_id = $request->get_param( 'user_id' ) ?? '';
        $dto->orderby = $request->get_param( 'orderby' ) ?? '';
        $dto->order = $request->get_param( 'order' ) ?? '';

        $donations = $this->service->all($dto);



        return rest_ensure_response( [
            'success' => true,
            'data'    => $donations,
        ] );

    }

    /**
     * GET PAGINATED DONATIONS
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */

    public function get_paginated_donations( $request ) {

        // $this->policy->authorize_paginated();
        $dto = new DonationFilterParamsDTO();

        $dto->page = max( 1, intval( $request->get_param( 'page' ) ?? 1 ) );
        $dto->limit = max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?? 20 ) ) );
        $dto->search = $request->get_param( 'search' ) ?? '';
        $dto->campaign_id = $request->get_param( 'campaign_id' ) ?? '';
        $dto->fund_id = $request->get_param( 'fund_id' ) ?? '';
        $dto->status = $request->get_param( 'status' ) ?? '';
        $dto->start_date = $request->get_param( 'start_date' ) ?? '';
        $dto->end_date = $request->get_param( 'end_date' ) ?? '';
        $dto->user_id = $request->get_param( 'user_id' ) ?? '';
        $dto->orderby = $request->get_param( 'orderby' ) ?? '';
        $dto->order = $request->get_param( 'order' ) ?? '';

        $donations = $this->service->paginated($dto);



        return rest_ensure_response( [
            'success' => true,
            'data'    => $donations,
        ] );

    }

    /**
     * GET Donation by id
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_donation_by_id( $request ) {
        $donation_id = intval( $request->get_param( 'id' ) );
        $donation = $this->service->get_by_id( $donation_id );

        try {
            $data = $this->service->get_by_id($donation_id);

         if ( ! $data ) {
                return new WP_Error( 
                    'donation_not_found', 
                    'Donation not found', 
                    [ 'status' => 500 ] 
                );
            }

        } catch ( \Exception $e ) {
            // Capture the Exception and return a 400 Bad Request for ALL exceptions
            return new WP_Error(
                'donation_not_found', 
                $e->getMessage(), // This will output "Failed to find donation..."
                [ 'status' => 400 ] // <-- Assigning your single code right here
            );
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => $data,
        ] );
    }

    /**
     * GET Currencies
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */

    public function get_currencies( $request ) {
        $currencies = $this->donation_service->all_currencies();

        if ( is_wp_error( $currencies ) ) {
            return $currencies;
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => $currencies,
        ] );

    }

    /**
     * GET Gateway & Platform rates
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */

    public function get_gateway_and_platform_rates( $request ) {
        $gprates = $this->donation_service->all_gateway_and_platform_rates();

        if ( is_wp_error( $gprates ) ) {
            return $gprates;
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => $gprates,
        ] );

    }

    /**
     * 
     * Create a donation 
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function create_donation( $request ) {
    
        // FIX 1: Build the associative array directly instead of mixing array/object syntax
        $data = [
            'uid'            => $request->get_param( 'uid' ) ?? '',
            'campaign_id'    => $request->get_param( 'campaign_id' ) ?? '',
            'fund_id'        => $request->get_param( 'fund_id' ) ?? '',
            'user_id'        => $request->get_param( 'user_id' ) ?? '',
            'email'          => $request->get_param( 'email' ) ?? '',
            'amount'         => $request->get_param( 'amount' ) ?? '',
            'notes'          => $request->get_param( 'notes' ) ?? '',
            'status'         => $request->get_param( 'status' ) ?? '',
            'transaction_id' => $request->get_param( 'transaction_id' ) ?? '',
            'payment_engine' => $request->get_param( 'payment_engine' ) ?? '',
            'payment_method' => $request->get_param( 'payment_method' ) ?? '',
            'payment_status' => $request->get_param( 'payment_status' ) ?? '',
            'is_anonymous'   => $request->get_param( 'is_anonymous' ) ?? '',
            'user_info'      => $request->get_param( 'user_info' ) ?? '',
        ];

        $validator = Validator::make($data, CreateDonationDTO::validation_rules());

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

        $sanitized_data = Sanitizer::make($data, CreateDonationDTO::sanitization_rules())->get_sanitized_data();
        $dto = CreateDonationDTO::from_array($sanitized_data);

        // FIX 2: Corrected typo 'sevice' to 'service'
        $id = $this->service->create($dto);

        // ==========================================
        // ADD THE EXTRA VALUES TO THE NEW DONATION
        // ==========================================
        if ( $id ) {
            global $wpdb;
            $table = $wpdb->prefix . 'growfund_donations';

            // Retrieve the values from the request (defaulting to 0 if missing)
            $processing_fee = $request->get_param( 'processing_fee' ) ?? 0;
            $gateway_fee    = $request->get_param( 'gateway_fee' ) ?? 0;
            $platform_fee   = $request->get_param( 'platform_fee' ) ?? 0;
            $tip_amount     = $request->get_param( 'tip_amount' ) ?? 0;

            // Use update() because the row already exists
            $wpdb->update(
                $table,
                [
                    'processing_fee' => $processing_fee,
                    'gateway_fee'    => $gateway_fee,
                    'platform_fee'   => $platform_fee,
                    'tip_amount'     => $tip_amount,
                ],
                [ 'id' => $id ], // WHERE id = $id
                [ '%f', '%f', '%f', '%f' ], // Data types: floats (use '%d' if integers or '%s' if strings)
                [ '%d' ] // WHERE type: integer
            );
        }

        // FIX 3: Cleaned up the trailing comma syntax error
        return rest_ensure_response([
            'success' => true,
            'id'      => $id,
            'message' => "Successfully created a donation",
        ]);
    }

    /**
     * Update the status of a donation
     *  
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function update_donation_status( $request ) {
        $data = [
            'id'    => intval( $request->get_param( 'id' ) ), // Make sure this matches your 'id' validation key
            'action' => $request->get_param('action'),
        ];

        $statuses = [
            DonationStatus::PENDING,
            DonationStatus::COMPLETED,
            DonationStatus::FAILED,
            DonationStatus::CANCELLED,
            DonationStatus::REFUNDED,
        ];

        $validator = Validator::make($data, [
            'id'       => 'required',
            'action'   => 'required|string|in:' . implode(',', $statuses),
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
            $is_updated = $this->service->update_status(
                intval( $request->get_param( 'id' ) ),
                $request->get_param('action')
            );

            // If it returns false instead of throwing
            if ( ! $is_updated ) {
                return new WP_Error( 
                    'donation_status_update_failed', 
                    'Failed to update the donation status.', 
                    [ 'status' => 500 ] 
                );
            }

        } catch ( \Exception $e ) {
            return new WP_Error(
                'donation_status_update_failed', 
                $e->getMessage(), 
                [ 'status' => 400 ] 
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $is_updated,
            'message' => "Donation status updated successfully.",
        ]);
    }

    /**
     * Delete a donation
     *  
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function delete_donation( $request )
    {
        $id = intval( $request->get_param( 'id' ) );
        $is_permanent = boolval( $request->get_param('is_permanent', false) );

        try {
            $result = $this->service->delete($id, $is_permanent);
            // If it returns false instead of throwing
            if ( ! $result ) {
                return new WP_Error( 
                    'delete_donation_failed', 
                    'Failed to delete the donation.', 
                    [ 'status' => 500 ] 
                );
            }
        } catch ( \Exception $e ) {
            return new WP_Error(
                'delete_donation_failed', 
                $e->getMessage(), 
                [ 'status' => 400 ] 
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $result,
            'message' => "Donation has been deleted successfully.",
        ]);
    }

    /**
     * Donation Empty Trash - Permanently deletes all donations in the trash
     *  
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function donation_empty_trash( $request )
    {
        try {
            $is_deleted = $this->service->empty_trash();
            // If it returns false instead of throwing
            if ( ! $is_deleted ) {
                return new WP_Error( 
                    'empty_trash_failed', 
                    'Failed to empty the donation trash.', 
                    [ 'status' => 500 ] 
                );
            }
        } catch ( \Exception $e ) {
            return new WP_Error(
                'empty_trash_failed', 
                $e->getMessage(), 
                [ 'status' => 400 ] 
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $is_deleted ,
            'message' => "Donation trash has been emptied successfully.",
        ]);
    }

    /**
     * Donation Handle bulk actions
     *  
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function donation_bulk_actions( $request )
    {
        $data = [
            'ids'    => $request->get_param('ids'), // Make sure this matches your 'ids' validation key
            'action' => $request->get_param('action'),
            'fund_id' => $request->get_param('fund_id'),
        ];

        $validator = Validator::make($data, [
            'ids'       => 'required|array',
            'action'    => 'required|string|in:trash,restore,delete,reassign_fund',
            'fund_id'   => 'required_if:action,reassign_fund',
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

        $dto = BulkActionDTO::from_array_with_sanitize($data);
        $dto->meta['fund_id'] = $request->get_param('fund_id');

        try {
            $result = $this->service->bulk_actions($dto);
            $failed = empty($result['failed']) ? [] : Arr::make($result['failed'])->pluck('id')->toArray();
                // If it returns false instead of throwing
                if ( ! empty( $failed ) ) {
                    // Generate the specific partial-success or failure message
                    $error_message = sprintf(
                        /* translators: %s: Donation ids */
                        __('Bulk action successfully applied for all the selected donations except the donations with id: %s.', 'growfund'),
                        implode(', ', $failed)
                    );

                    return new WP_Error(
                        'rest_bulk_action_failed', 
                        $error_message, 
                        [
                            'status'  => 422, 
                            'details' => [
                                'failed_donation_ids' => $failed // Allows the frontend to easily parse which IDs failed
                            ],
                        ]
                    );
                }
        } catch ( \Exception $e ) {
            return new WP_Error(
                'bulk_action_failed', 
                $e->getMessage(), 
                [ 'status' => 400 ] 
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $result,
            'message' => $error_message ?? "Bulk action has been applied successfully.",
        ]);
    }

    /**
     * Donation Activities
     *  
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function donation_activities( $request )
    {
        $activity_filter_dto = ActivityFilterDTO::from_array([
            'page' => max( 1, intval( $request->get_param( 'page' ) ?? 1 ) ),
            'limit' => max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?? 10 ) ) ),
            'orderby' => $request->get_param( 'orderby' ) ?? 'created_at',
            'order' => $request->get_param( 'order' ) ?? 'DESC',
            'donation_id' => $request->get_param( 'donation_id' ) ?? '',
        ]);

        try {
            $activities = (new ActivityService())->paginated($activity_filter_dto, Activities::DONATION);
            if ( ! $activities ) {
                return new WP_Error( 
                    'donation_activities_failed', 
                    'Failed to retrieve donation activities.', 
                    [ 'status' => 500 ] 
                );
            }
        } catch ( \Exception $e ) {
            return new WP_Error(
                'donation_activities_failed', 
                $e->getMessage(), 
                [ 'status' => 400 ] 
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $activities,
            'message' => "Donation activities retrieved successfully.",
        ]);
    }

    /**
     * Donation Receipt
     *  
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function donation_receipt( $request )
    {
        $uid = strval( $request->get_param( 'uid' ) );
        $data = [];

        try {
            $data = [
                'donation' => $this->service->get_by_uid($uid),
                'template' => Option::get(OptionKeys::PDF_DONATION_RECEIPT_TEMPLATE)
            ];
            if ( ! $data['donation'] ) {
                return new WP_Error( 
                    'donation_not_found', 
                    'Failed to find donation with UID.', 
                    [ 'status' => 500 ] 
                );
            }
            elseif ( empty( $data['template'] ) ) {
                return new WP_Error( 
                    'donation_receipt_template_missing', 
                    'Donation receipt template is missing.', 
                    [ 'status' => 500 ] 
                );
            }
        } catch ( \Exception $e ) {
            return new WP_Error(
                'donation_receipt_failed', 
                $e->getMessage(), 
                [ 'status' => 400 ] 
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $data,
            'message' => "Donation receipt generated successfully.",
        ]);
    }

    /**
     * Donation eCard
     *  
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function donation_ecard( $request )
    {
        $uid = strval( $request->get_param( 'uid' ) );
        $data = [];

        try {
            $data = [
                'donation' => $this->service->get_by_uid($uid),
                'template' => Option::get(OptionKeys::ECARD_TEMPLATE)
            ];
            if ( ! $data['donation'] ) {
                return new WP_Error( 
                    'donation_not_found', 
                    'Failed to find donation with UID.', 
                    [ 'status' => 500 ] 
                );
            }
            elseif ( empty( $data['template'] ) ) {
                return new WP_Error( 
                    'ecard_template_missing', 
                    'eCard template is missing.', 
                    [ 'status' => 500 ] 
                );
            }
        } catch ( \Exception $e ) {
            return new WP_Error(
                'ecard_failed', 
                $e->getMessage(), 
                [ 'status' => 400 ] 
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $data,
            'message' => "eCard generated successfully.",
        ]);
    }

    /* // ==================================================
    // 2. GET DONATIONS BY CAMPAIGN (Fundraiser Only)
    // Routes: GET /campaigns/donations OR /campaigns/123/donations
    // ==================================================
    public function get_donations_by_campaign( $request ) {
        $user_id = get_current_user_id();
        $user = wp_get_current_user();

        // Must be a fundraiser to see received donations
        if ( ! in_array( 'growfund_fundraiser', (array) $user->roles ) && ! current_user_can( 'administrator' ) ) {
            return new WP_Error( 'unauthorized', 'Only Fundraisers can view campaign receipts.', [ 'status' => 403 ] );
        }

        $campaign_id = $request->get_param( 'campaign_id' );
        $campaign_ids = [];

        // If a specific ID is provided in the URL, verify ownership via meta key
        if ( $campaign_id ) {
            $fundraiser_id = get_post_meta( intval( $campaign_id ), 'growfund_fundraiser_id', true );
            
            if ( $fundraiser_id != $user_id && ! current_user_can( 'administrator' ) ) {
                return new WP_Error( 'unauthorized', 'You do not manage this campaign.', [ 'status' => 403 ] );
            }
            $campaign_ids[] = intval( $campaign_id );
            
        // If NO ID is provided, grab ALL campaigns this user manages
        } else {
            global $wpdb;
            $user_campaigns = $wpdb->get_col( $wpdb->prepare( 
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'growfund_fundraiser_id' AND meta_value = %s", 
                $user_id 
            ) );
            
            if ( empty( $user_campaigns ) ) {
                return rest_ensure_response( [ 'success' => true, 'data' => [], 'pagination' => [ 'total' => 0 ] ] );
            }
            $campaign_ids = array_map( 'intval', $user_campaigns );
        }

        $page     = max( 1, intval( $request->get_param( 'page' ) ?? 1 ) );
        $per_page = max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?? 20 ) ) );
        $status   = $request->get_param( 'status' );

        $donations = $this->query_donations( [
            'campaign_ids' => $campaign_ids,
            'status'       => $status,
            'page'         => $page,
            'per_page'     => $per_page,
        ] );

        return rest_ensure_response( [
            'success'    => true,
            'data'       => $donations['items'],
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $donations['total'],
                'total_pages' => ceil( $donations['total'] / $per_page ),
            ],
        ] );
    } */

    /* // ==================================================
    // DATABASE QUERY ENGINE
    // ==================================================
    private function query_donations( $args ) {
        global $wpdb;
        $table = $wpdb->prefix . 'growfund_donations'; 

        $donor_id     = isset( $args['donor_id'] ) ? $args['donor_id'] : null;
        $campaign_ids = isset( $args['campaign_ids'] ) ? $args['campaign_ids'] : [];
        $status       = isset( $args['status'] ) ? $args['status'] : null;
        $page         = $args['page'] ?? 1;
        $per_page     = $args['per_page'] ?? 20;

        $offset = ( $page - 1 ) * $per_page;

        $where = [];
        $or_conditions = [];

        // Condition A: Matches the user who made the donation
        if ( $donor_id ) {
            $donor_user = get_userdata( $donor_id );
            $donor_email = $donor_user ? $donor_user->user_email : '';
            
            if ( $donor_email ) {
                $or_conditions[] = $wpdb->prepare( '(user_id = %d OR user_info LIKE %s)', $donor_id, '%' . $wpdb->esc_like( $donor_email ) . '%' );
            } else {
                $or_conditions[] = $wpdb->prepare( 'user_id = %d', $donor_id );
            }
        }
        
        // Condition B: Matches the campaigns that received the donation
        if ( ! empty( $campaign_ids ) ) {
            $ids_placeholder = implode( ',', array_fill( 0, count( $campaign_ids ), '%d' ) );
            $or_conditions[] = $wpdb->prepare( "campaign_id IN ($ids_placeholder)", ...$campaign_ids );
        }

        // Combine Condition A and Condition B with an "OR" statement
        if ( ! empty( $or_conditions ) ) {
            $where[] = '(' . implode( ' OR ', $or_conditions ) . ')';
        }

        // Apply explicit status filter if requested
        if ( $status ) {
            $where[] = $wpdb->prepare( 'status = %s', $status );
        }

        $where_clause = ! empty( $where ) ? implode( ' AND ', $where ) : '1=1';

        $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}" );
        $results = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT {$offset}, {$per_page}" );

        $items = [];
        if ( $results ) {
            foreach ( $results as $row ) {
                $items[] = $this->format_donation( $row );
            }
        }

        return [
            'items' => $items,
            'total' => intval( $total ),
        ];
    } */

    /* private function get_donation_by_id( $donation_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'growfund_donations';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ID = %d", $donation_id ) );
        return $row ? $this->format_donation( $row ) : false;
    }

    // ==================================================
    // FORMAT RESPONSE (Data Mapping)
    // ==================================================
    private function format_donation( $row ) {
        
        // Target uppercase ID directly
        $extracted_id = isset( $row->ID ) ? $row->ID : 0;

        // Amount Processing
        $amount = floatval( $row->amount );
        
        $gateway_fee    = isset( $row->gateway_fee ) ? floatval( $row->gateway_fee ) : 0;
        $platform_fee   = isset( $row->platform_fee ) ? floatval( $row->platform_fee ) : 0;
        $processing_fee = $gateway_fee + $platform_fee;
        
        $net_amount = $amount - $processing_fee;

        $amount     = $amount / 100;
        $net_amount = $net_amount / 100;

        // User JSON Processing
        $first_name = '';
        $last_name  = '';
        
        if ( ! empty( $row->user_info ) ) {
            $user_info = json_decode( $row->user_info, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $user_info = maybe_unserialize( $row->user_info );
            }
            if ( is_array( $user_info ) ) {
                $first_name = isset( $user_info['first_name'] ) ? $user_info['first_name'] : '';
                $last_name  = isset( $user_info['last_name'] ) ? $user_info['last_name'] : '';
            }
        }

        $donor_name = trim( $first_name . ' ' . $last_name );
        if ( empty( $donor_name ) ) {
            $donor_name = 'Anonymous';
        }

        $donor_type = ( intval( $row->user_id ) > 0 ) ? 'Registered' : 'Guest';
        if ( isset( $row->is_anonymous ) && $row->is_anonymous ) {
            $donor_type = 'Anonymous';
            $donor_name = 'Anonymous';
        }

        return [
            'id'            => intval( $extracted_id ),
            'amount'        => round( $amount, 2 ),
            'net_amount'    => round( $net_amount, 2 ),
            'campaign_name' => get_the_title( $row->campaign_id ),
            'donor_name'    => $donor_name,
            'donor_type'    => $donor_type,
            'date'          => $row->created_at,
            'status'        => $row->status,
        ];
    }*/
} 