<?php
/**
 * Donation Controller - Handles donation retrieval endpoints
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_Donation_Controller {

    // ==================================================
    // 1. GET ALL DONATIONS (Combined Donor & Fundraiser Feed)
    // Route: GET /donations
    // ==================================================
    public function get_donations( $request ) {
        $user_id = get_current_user_id();
        $user = wp_get_current_user();

        // Check roles
        $roles = (array) $user->roles;
        $is_donor = in_array( 'growfund_donor', $roles );
        $is_fundraiser = in_array( 'growfund_fundraiser', $roles );
        $is_admin = current_user_can( 'administrator' );

        if ( ! $is_donor && ! $is_fundraiser && ! $is_admin ) {
            return new WP_Error( 'unauthorized', 'You do not have permission to view this data.', [ 'status' => 403 ] );
        }

        $page     = max( 1, intval( $request->get_param( 'page' ) ?? 1 ) );
        $per_page = max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?? 20 ) ) );
        $status   = $request->get_param( 'status' );

        $args = [
            'status'       => $status,
            'page'         => $page,
            'per_page'     => $per_page,
            'donor_id'     => null,
            'campaign_ids' => []
        ];

        // If the user is an Admin, bypass the user filters so they can see ALL donations in the system
        if ( ! $is_admin ) {
            
            // Rule 1: Always fetch donations this user made themselves
            $args['donor_id'] = $user_id;

            // Rule 2: If they are a Fundraiser, ALSO fetch all donations made to their campaigns
            if ( $is_fundraiser ) {
                global $wpdb;
                // Forcefully query the database to guarantee we find the campaigns (bypasses get_posts issues)
                $user_campaigns = $wpdb->get_col( $wpdb->prepare( 
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'growfund_fundraiser_id' AND meta_value = %s", 
                    $user_id 
                ) );
                
                if ( ! empty( $user_campaigns ) ) {
                    $args['campaign_ids'] = array_map( 'intval', $user_campaigns );
                }
            }
        }

        $donations = $this->query_donations( $args );

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
    }

    // ==================================================
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
    }

    // ==================================================
    // 3. GET DONATION BY ID
    // Route: GET /donations/123
    // ==================================================
    public function get_donation( $request ) {
        $donation_id = intval( $request->get_param( 'id' ) );
        $donation = $this->get_donation_by_id( $donation_id );

        if ( ! $donation ) {
            return new WP_Error( 'donation_not_found', 'Donation not found', [ 'status' => 404 ] );
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => $donation,
        ] );
    }

    // ==================================================
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
    }

    private function get_donation_by_id( $donation_id ) {
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
    }
}