<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// ==========================================
// 1. Tag: Total Raised (Formatted Currency)
// ==========================================
class Elementor_Growfund_Raised_Tag extends \Elementor\Core\DynamicTags\Tag {
    public function get_name() { return 'growfund-total-raised'; }
    public function get_title() { return 'Growfund: Total Raised'; }
    public function get_group() { return 'post'; }
    public function get_categories() { return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ]; }

    public function render() {
        $campaign_id = get_the_ID();
        if ( ! $campaign_id ) return;

        global $wpdb;
        $table_name = $wpdb->prefix . 'growfund_donations';
        $stats = $wpdb->get_row( $wpdb->prepare( "
            SELECT SUM(amount) as total_raised 
            FROM {$table_name} 
            WHERE campaign_id = %d AND status IN ('completed', 'COMPLETED') AND payment_status IN ('paid', 'PAID')
        ", $campaign_id ) );

        $total_raised_usd = $stats ? (intval( $stats->total_raised ) / 100) : 0;
        echo wp_kses_post( wc_price( $total_raised_usd ) );
    }
}

// ==========================================
// 2. Tag: Progress Bar Percentage (FIXED MATH)
// ==========================================
class Elementor_Growfund_Progress_Tag extends \Elementor\Core\DynamicTags\Tag {
    public function get_name() { return 'growfund-progress-percent'; }
    public function get_title() { return 'Growfund: Progress %'; }
    public function get_group() { return 'post'; }
    public function get_categories() { return [ \Elementor\Modules\DynamicTags\Module::NUMBER_CATEGORY ]; }

    public function render() {
        $campaign_id = get_the_ID();
        if ( ! $campaign_id ) {
            echo 0;
            return;
        }

        // 1. Calculate sum of paid donations from custom table (Leave as raw cents)
        global $wpdb;
        $table_name = $wpdb->prefix . 'growfund_donations';
        $stats = $wpdb->get_row( $wpdb->prepare( "
            SELECT SUM(amount) as total_raised 
            FROM {$table_name} 
            WHERE campaign_id = %d AND payment_status IN ('paid', 'PAID')
        ", $campaign_id ) );
        
        $total_raised_raw = $stats ? floatval( $stats->total_raised ) : 0;

        // 2. Fetch the exact goal meta key (This is also raw cents, e.g., 10000000)
        $goal_raw = floatval( get_post_meta( $campaign_id, 'growfund_goal_amount', true ) ); 

        // 3. Calculate Math Percentage
        $percentage = 0;
        if ( $goal_raw > 0 ) {
            // Since BOTH are in cents, they scale perfectly against each other
            $percentage = ceil( ( $total_raised_raw / $goal_raw ) * 100 );
        }
        
        // Cap at 100% so the Elementor widget doesn't physically break the UI layout
        if ( $percentage > 100 ) {
            $percentage = 100;
        }

        // Force strict integer output
        echo (int) $percentage;
    }
}

// ==========================================
// 3. Tag: Time Left (Custom Breakdown)
// ==========================================
class Elementor_Growfund_Time_Left_Tag extends \Elementor\Core\DynamicTags\Tag {
    public function get_name() { return 'growfund-time-left'; }
    public function get_title() { return 'Growfund: Time Left'; }
    public function get_group() { return 'post'; }
    public function get_categories() { return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ]; }

    public function render() {
        $campaign_id = get_the_ID();
        if ( ! $campaign_id ) return;

        $end_date_str = get_post_meta( $campaign_id, 'growfund_end_date', true ); 

        if ( empty( $end_date_str ) ) {
            echo 'No Time Limit';
            return;
        }

        $end_timestamp = strtotime( $end_date_str );
        $current_timestamp = current_time( 'timestamp' );
        $diff = $end_timestamp - $current_timestamp;

        if ( $diff <= 0 ) {
            echo 'Campaign Ended';
            return;
        }

        $minute = 60;
        $hour   = $minute * 60;
        $day    = $hour * 24;
        $week   = $day * 7;
        $month  = $day * 30;
        $year   = $day * 365;

        if ( $diff >= $year ) {
            $val = floor( $diff / $year );
            echo $val . ' year' . ( $val > 1 ? 's' : '' ) . ' left';
        } elseif ( $diff >= $month ) {
            $val = floor( $diff / $month );
            echo $val . ' month' . ( $val > 1 ? 's' : '' ) . ' left';
        } elseif ( $diff >= $week ) {
            $val = floor( $diff / $week );
            echo $val . ' week' . ( $val > 1 ? 's' : '' ) . ' left';
        } elseif ( $diff >= $day ) {
            $val = floor( $diff / $day );
            echo $val . ' day' . ( $val > 1 ? 's' : '' ) . ' left';
        } elseif ( $diff >= $hour ) {
            $val = floor( $diff / $hour );
            echo $val . ' hour' . ( $val > 1 ? 's' : '' ) . ' left';
        } elseif ( $diff >= $minute ) {
            $val = floor( $diff / $minute );
            echo $val . ' minute' . ( $val > 1 ? 's' : '' ) . ' left';
        } else {
            echo 'Less than a minute left';
        }
    }
}

// ==========================================
// 4. Tag: Campaign Image (Extracts Array Image)
// ==========================================
// Note: Images extend Data_Tag instead of Tag
class Elementor_Growfund_Image_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
    public function get_name() { return 'growfund-campaign-image'; }
    public function get_title() { return 'Growfund: Campaign Image'; }
    public function get_group() { return 'post'; }
    public function get_categories() { return [ \Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY ]; }

    public function get_value( array $options = [] ) {
        $campaign_id = get_the_ID();
        if ( ! $campaign_id ) return [];

        // WordPress get_post_meta automatically unserializes the a:1:{i:0;i:1302;} array!
        $image_data = get_post_meta( $campaign_id, 'growfund_images', true );
        $attachment_id = 0;

        if ( is_array( $image_data ) && ! empty( $image_data ) ) {
            // Grab the very first image ID in the array
            $attachment_id = reset( $image_data );
        } elseif ( is_numeric( $image_data ) ) {
            // Fallback just in case some campaigns saved it as a raw string ID
            $attachment_id = $image_data;
        }

        if ( ! $attachment_id ) {
            return [
                'id' => '',
                'url' => \Elementor\Utils::get_placeholder_image_src(),
            ];
        }

        return [
            'id' => $attachment_id,
            'url' => wp_get_attachment_url( $attachment_id ),
        ];
    }
}

// ==========================================
// INTEGRATION REGISTRATION & QUERY FILTERS
// ==========================================
class GFCM_Elementor_Integration {

    public static function init() {
        add_action( 'elementor/dynamic_tags/register', [ __CLASS__, 'register_dynamic_tags' ] );
        add_action( 'elementor/query/growfund_published', [ __CLASS__, 'filter_published_campaigns' ] );
        add_action( 'elementor/query/growfund_published_featured', [ __CLASS__, 'filter_published_featured_campaigns' ] );
    }

    public static function register_dynamic_tags( $dynamic_tags ) {
        $dynamic_tags->register( new Elementor_Growfund_Raised_Tag() );
        $dynamic_tags->register( new Elementor_Growfund_Progress_Tag() );
        $dynamic_tags->register( new Elementor_Growfund_Time_Left_Tag() );
        $dynamic_tags->register( new Elementor_Growfund_Image_Tag() ); // <-- NEW IMAGE TAG
    }

    public static function filter_published_campaigns( $query ) {
        $meta_query = $query->get( 'meta_query' );
        if ( ! is_array( $meta_query ) ) {
            $meta_query = [];
        }
        $meta_query[] = [
            'key'     => 'growfund_status',
            'value'   => 'published',
            'compare' => '='
        ];
        $query->set( 'meta_query', $meta_query );
    }

    public static function filter_published_featured_campaigns( $query ) {
        $meta_query = $query->get( 'meta_query' );
        if ( ! is_array( $meta_query ) ) {
            $meta_query = [];
        }
        $meta_query[] = [
            'key'     => 'growfund_is_featured',
            'value'   => '1',
            'compare' => '='
        ];
        $meta_query[] = [
            'key'     => 'growfund_status',
            'value'   => 'published',
            'compare' => '='
        ];
        $query->set( 'meta_query', $meta_query );
    }
}

GFCM_Elementor_Integration::init();