<?php
/**
 * Campaign Controller - Handles campaign endpoints
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_Campaign_Controller {

    /**
     * Get all published campaigns
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_campaigns( $request ) {
        $page = max( 1, intval( $request->get_param( 'page' ) ?? 1 ) );
        $per_page = max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?? 20 ) ) );
        $search = $request->get_param( 'search' ) ?? '';
        $status = $request->get_param( 'status' ) ?? 'published';

        $offset = ( $page - 1 ) * $per_page;

        // Query campaigns from Growfund posts
        $args = [
            'post_type'      => 'growfund_campaign', // Growfund post type
            'post_status'    => [ $status ],
            'posts_per_page' => $per_page,
            'offset'         => $offset,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'     => 'growfund_status',
                    // 2. Pass the dynamic $status variable here instead of a hardcoded string
                    'value'   => sanitize_text_field( $status ), 
                    'compare' => '='
                ]
            ]
        ];

        // Add search filter if provided
        if ( ! empty( $search ) ) {
            $args['s'] = sanitize_text_field( $search );
        }

        $query = new WP_Query( $args );

        // Build response data
        $campaigns = [];
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $campaigns[] = $this->format_campaign( get_the_ID() );
            }
            wp_reset_postdata();
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => $campaigns,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $query->found_posts,
                'total_pages' => ceil( $query->found_posts / $per_page ),
            ],
        ] );
    }

    /**
     * Get single campaign by ID
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_campaign( $request ) {
        $campaign_id = intval( $request->get_param( 'id' ) );

        if ( ! $campaign_id ) {
            return new WP_Error(
                'invalid_campaign_id',
                'Campaign ID is required',
                [ 'status' => 400 ]
            );
        }

        $campaign = get_post( $campaign_id );

        if ( ! $campaign || 'growfund_campaign' !== $campaign->post_type ) {
            return new WP_Error(
                'campaign_not_found',
                'Campaign not found',
                [ 'status' => 404 ]
            );
        }

        // Fetch the custom Growfund status from post meta
        $growfund_status = get_post_meta( $campaign_id, 'growfund_status', true );

        // Check if published or user is owner/admin
        if ( 'published' !== $growfund_status && ! current_user_can( 'edit_post', $campaign_id ) ) {
            return new WP_Error(
                'campaign_not_published',
                'Campaign is not published',
                [ 'status' => 403 ]
            );
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => $this->format_campaign_detail( $campaign_id ),
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
