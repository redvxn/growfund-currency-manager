<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GFCM Brevo (Sendinblue) Sync
 * Pushes new and existing users to Brevo, segments them by Category ID,
 * and tracks campaign publishing behavior for automation workflows.
 */
class GFCM_Brevo {

    // ==========================================
    // BREVO CREDENTIALS
    // ==========================================
    const API_KEY = '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';
    const LIST_ID = '2';

    public static function init() {
        add_action( 'user_register', [ __CLASS__, 'sync_single_user' ], 999, 1 );
        add_action( 'profile_update', [ __CLASS__, 'sync_single_user' ], 999, 1 );
        add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );

        // --- 1. REAL-TIME TRIGGER: Listen for Meta Status Changes ---
        add_action( 'updated_post_meta', [ __CLASS__, 'catch_campaign_meta_status' ], 10, 4 );
        add_action( 'added_post_meta', [ __CLASS__, 'catch_campaign_meta_status' ], 10, 4 );

        // --- 2. CRON TRIGGER: Daily Background Verification Loop ---
        add_action( 'wp', [ __CLASS__, 'setup_daily_sync_cron' ] );
        add_action( 'gfcm_daily_brevo_campaign_check', [ __CLASS__, 'execute_daily_campaign_check' ] );
    }

    /**
     * Real-time hook: Activates immediately when 'growfund_status' meta is updated to 'published'
     */
    public static function catch_campaign_meta_status( $meta_id, $post_id, $meta_key, $meta_value ) {
        if ( $meta_key === 'growfund_status' && $meta_value === 'published' ) {
            $post = get_post( $post_id );
            if ( $post && $post->post_type === 'growfund_campaign' ) {
                // Instantly sync the fundraiser to Brevo with their new TRUE state
                self::sync_single_user( $post->post_author );
            }
        }
    }

    /**
     * Registers an Action Scheduler recurring event to verify campaign states every 24 hours.
     */
    public static function setup_daily_sync_cron() {
        if ( function_exists( 'as_next_scheduled_action' ) && ! as_next_scheduled_action( 'gfcm_daily_brevo_campaign_check' ) ) {
            as_schedule_recurring_action( time(), DAY_IN_SECONDS, 'gfcm_daily_brevo_campaign_check', [], 'growfund' );
        }
    }

    /**
     * Executes the daily loop through all fundraisers to sync state updates.
     */
    public static function execute_daily_campaign_check() {
        $fundraisers = get_users([
            'role' => 'growfund_fundraiser',
            'fields' => 'ID'
        ]);

        if ( ! empty( $fundraisers ) ) {
            foreach ( $fundraisers as $user_id ) {
                self::sync_single_user( (int) $user_id );
            }
        }
    }

    /**
     * Core function maps attributes and pushes user states to the Brevo API.
     */
    public static function sync_single_user( $user_id ) {
        if ( empty(self::API_KEY) || self::API_KEY === 'YOUR_BREVO_API_KEY_HERE' || empty(self::LIST_ID) ) {
            if ( isset($_POST['gfcm_sync_brevo']) ) {
                echo "<div style='color: yellow; font-family: monospace;'>[WARNING] API Key or List ID is missing. Skipping {$user_id}...</div>";
            }
            return false;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) return false;

        $email      = $user->user_email;
        $first_name = $user->first_name ?: $user->display_name;
        $last_name  = $user->last_name ?: '';
        $roles      = (array) $user->roles;
        
        $donor_category_id      = 1;
        $fundraiser_category_id = 2;

        $is_fundraiser = in_array( 'growfund_fundraiser', $roles );
        $role_attr_id  = $is_fundraiser ? $fundraiser_category_id : $donor_category_id;

        // Check if this specific fundraiser has at least one active (published) campaign
        $has_active_campaigns = false;
        if ( $is_fundraiser ) {
            // Get campaigns strictly matching the custom post type and meta status
            $active_campaigns = get_posts([
                'post_type'   => 'growfund_campaign',
                'author'      => $user_id,
                'post_status' => 'any', // Check regardless of WP's native draft/publish state
                'meta_key'    => 'growfund_status',
                'meta_value'  => 'published',
                'fields'      => 'ids', // Only grab IDs for maximum speed
                'numberposts' => 1      // We only need to know if 1 exists to return true
            ]);

            if ( ! empty( $active_campaigns ) ) {
                $has_active_campaigns = true;
            }
        }

        $base_url = 'https://api.brevo.com/v3/contacts';

        // Brevo Payload incorporating the dynamic boolean property
        $body = json_encode([
            'email'         => $email,
            'attributes'    => [
                'FIRSTNAME'             => $first_name,
                'LASTNAME'              => $last_name,
                'TYPE'                  => (int) $role_attr_id,
                'HAS_ACTIVE_CAMPAIGNS'  => (bool) $has_active_campaigns
            ],
            'listIds'       => [ (int) self::LIST_ID ],
            'updateEnabled' => true
        ]);

        $args = [
            'method'  => 'POST',
            'headers' => [
                'api-key'      => self::API_KEY,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json'
            ],
            'body'    => $body,
            'timeout' => 15
        ];

        $response = wp_remote_request( $base_url, $args );

        if ( isset($_POST['gfcm_sync_brevo']) ) {
            if ( is_wp_error( $response ) ) {
                echo "<div style='color:#ff5555; font-family: monospace;'>[ERROR] Connection failed for {$email}.</div>";
            } else {
                $code = wp_remote_retrieve_response_code( $response );
                $resp_body = wp_remote_retrieve_body( $response );
                $color = (strpos((string)$code, '20') === 0) ? '#00ff00' : '#ff5555';
                $role_name = ($role_attr_id === 2) ? 'Fundraiser' : 'Donor';
                $has_published_text = $has_active_campaigns ? 'TRUE' : 'FALSE';
                
                echo "<div style='margin-bottom: 5px; font-family: monospace;'>";
                echo "<strong style='color:{$color}'>[{$code}]</strong> {$email} synced as <strong>{$role_name}</strong> | Active Campaign: <strong>{$has_published_text}</strong>";
                
                if ( $code != 200 && $code != 201 && $code != 204 ) {
                    echo "<br><span style='color:#aaaaaa; font-size:11px; padding-left: 45px;'>Brevo Response: " . esc_html($resp_body) . "</span>";
                }
                echo "</div>";
            }
        }
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'Brevo Sync',
            'Brevo Sync',
            'manage_options',
            'gfcm-brevo-sync',
            [ __CLASS__, 'render_admin_page' ]
        );
    }

    public static function render_admin_page() {
        echo '<div class="wrap"><h2>Hiilbox Brevo Synchronization</h2>';

        if ( empty(self::API_KEY) || self::API_KEY === 'YOUR_BREVO_API_KEY_HERE' ) {
            echo '<div class="error"><p>Please add your Brevo API Key to <code>class-gfcm-brevo.php</code>.</p></div></div>';
            return;
        }

        if ( isset($_POST['gfcm_sync_brevo']) && check_admin_referer('gfcm_brevo_action') ) {
            echo '<div style="background: #1e1e1e; color: #fff; padding: 20px; border-radius: 5px; margin-bottom: 20px;">';
            echo '<h3 style="color:#fff; margin-top:0;">Live API Terminal</h3>';
            
            $users = get_users();
            $count = 0;
            foreach ( $users as $user ) {
                self::sync_single_user( $user->ID );
                $count++;
            }
            
            echo "<br><strong style='color:#00ff00;'>Process Complete! Processed {$count} users.</strong>";
            echo '</div>';
        }
        ?>
        
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
            <form method="post" action="">
                <?php wp_nonce_field('gfcm_brevo_action'); ?>
                <p>Click below to push all current users to your Brevo list. The script will automatically assign them the <strong>TYPE</strong> attribute based on their role and track whether they have any active campaigns.</p>
                <input type="submit" name="gfcm_sync_brevo" class="button button-primary" value="Run Bulk Sync Now" />
            </form>
        </div>
        </div>
        <?php
    }
}

GFCM_Brevo::init();