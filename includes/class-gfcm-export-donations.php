<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GFCM_Export_Donations {

    public static function init() {
        // Register the admin menu page
        add_action( 'admin_menu', [ __CLASS__, 'add_export_menu_page' ] );
        
        // Listen for the export trigger
        add_action( 'admin_init', [ __CLASS__, 'process_csv_export' ] );
    }

    /**
     * Add the menu page under WooCommerce
     */
    public static function add_export_menu_page() {
        add_submenu_page(
            'woocommerce',
            'Export Donations',
            'Export Donations',
            'manage_woocommerce', // Requires shop manager or admin
            'gfcm-export-donations',
            [ __CLASS__, 'render_admin_page' ]
        );
    }

    /**
     * Render the admin page UI
     */
    public static function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Export Growfund Donations</h1>
            <p>Select your filters below to generate a highly specific CSV report of all campaigns, donations, and fees.</p>
            
            <form method="post" action="" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); max-width: 800px; margin-top: 15px;">
                <?php wp_nonce_field( 'gfcm_export_donations_nonce', 'gfcm_export_nonce' ); ?>
                <input type="hidden" name="gfcm_action" value="export_donations_csv">
                
                <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 20px;">
                    
                    <div style="flex: 1 1 45%;">
                        <label for="date_from" style="font-weight: 600; display: block; margin-bottom: 5px;">From Date:</label>
                        <input type="date" id="date_from" name="date_from" style="padding: 5px 10px; width: 100%;">
                    </div>
                    <div style="flex: 1 1 45%;">
                        <label for="date_to" style="font-weight: 600; display: block; margin-bottom: 5px;">To Date:</label>
                        <input type="date" id="date_to" name="date_to" style="padding: 5px 10px; width: 100%;">
                    </div>

                    <div style="flex: 1 1 30%;">
                        <label for="gf_status" style="font-weight: 600; display: block; margin-bottom: 5px;">Growfund Status:</label>
                        <select name="gf_status" id="gf_status" style="width: 100%;">
                            <option value="">-- All Statuses --</option>
                            <option value="pending">Pending</option>
                            <option value="completed">Completed</option>
                            <option value="failed">Failed</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="refunded">Refunded</option>
                            <option value="trashed">Trashed</option>
                        </select>
                    </div>

                    <div style="flex: 1 1 30%;">
                        <label for="gf_payment_status" style="font-weight: 600; display: block; margin-bottom: 5px;">Payment Status:</label>
                        <select name="gf_payment_status" id="gf_payment_status" style="width: 100%;">
                            <option value="">-- All Payment Statuses --</option>
                            <option value="pending">Pending</option>
                            <option value="paid">Paid</option>
                            <option value="unpaid">Unpaid</option>
                            <option value="refunded">Refunded</option>
                            <option value="failed">Failed</option>
                        </select>
                    </div>

                    <div style="flex: 1 1 30%;">
                        <label for="campaign_status" style="font-weight: 600; display: block; margin-bottom: 5px;">Campaign Status:</label>
                        <select name="campaign_status" id="campaign_status" style="width: 100%;">
                            <option value="">-- All Campaigns --</option>
                            <option value="published">Published</option>
                            <option value="draft">Draft</option>
                            <option value="pending">Pending</option>
                            <option value="funded">Funded</option>
                            <option value="declined">Declined</option>
                            <option value="trashed">Trashed</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>

                </div>

                <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;" />

                <button type="submit" class="button button-primary button-large">
                    <span class="dashicons dashicons-download" style="margin-top:4px;"></span> Download CSV Report
                </button>
            </form>
        </div>
        <?php
    }

    /**
     * Process the CSV generation and download
     */
    public static function process_csv_export() {
        if ( isset( $_POST['gfcm_action'] ) && $_POST['gfcm_action'] === 'export_donations_csv' ) {
            
            // Verify security nonce and user permissions
            if ( ! isset( $_POST['gfcm_export_nonce'] ) || ! wp_verify_nonce( $_POST['gfcm_export_nonce'], 'gfcm_export_donations_nonce' ) ) {
                wp_die( 'Security check failed.' );
            }
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( 'You do not have permission to export donations.' );
            }

            global $wpdb;

            // Define table names
            $donations_table = $wpdb->prefix . 'growfund_donations';
            $wc_orders_table = $wpdb->prefix . 'wc_orders'; 
            $postmeta_table  = $wpdb->prefix . 'postmeta';

            // 1. Build the Base Query (Added gd.payment_method)
            $query = "
                SELECT 
                    gd.campaign_id, 
                    gd.transaction_id as order_id, 
                    gd.user_info, 
                    gd.is_anonymous, 
                    gd.created_at, 
                    gd.status as gf_status,
                    gd.payment_status, 
                    gd.amount, 
                    gd.gateway_fee, 
                    gd.platform_fee, 
                    gd.processing_fee, 
                    gd.tip_amount, 
                    gd.payment_method,
                    wo.total_amount as original_total, 
                    wo.currency as original_currency, 
                    wo.user_agent,
                    wo.customer_id,
                    wo.status as wc_status,
                    pm.meta_value as campaign_status
                FROM {$donations_table} gd
                LEFT JOIN {$wc_orders_table} wo ON gd.transaction_id = wo.id
                LEFT JOIN {$postmeta_table} pm ON gd.campaign_id = pm.post_id AND pm.meta_key = 'growfund_status'
                WHERE 1=1
            ";

            $args = [];

            // 2. Dynamically Append Filters
            if ( ! empty( $_POST['date_from'] ) ) {
                $query .= " AND gd.created_at >= %s";
                $args[] = sanitize_text_field( $_POST['date_from'] ) . ' 00:00:00';
            }

            if ( ! empty( $_POST['date_to'] ) ) {
                $query .= " AND gd.created_at <= %s";
                $args[] = sanitize_text_field( $_POST['date_to'] ) . ' 23:59:59';
            }

            if ( ! empty( $_POST['gf_status'] ) ) {
                $query .= " AND gd.status = %s";
                $args[] = sanitize_text_field( $_POST['gf_status'] );
            }

            if ( ! empty( $_POST['gf_payment_status'] ) ) {
                $query .= " AND gd.payment_status = %s";
                $args[] = sanitize_text_field( $_POST['gf_payment_status'] );
            }

            if ( ! empty( $_POST['campaign_status'] ) ) {
                $query .= " AND pm.meta_value = %s";
                $args[] = sanitize_text_field( $_POST['campaign_status'] );
            }

            $query .= " ORDER BY gd.created_at DESC";

            // 3. Execute Query Safely
            if ( ! empty( $args ) ) {
                $results = $wpdb->get_results( $wpdb->prepare( $query, $args ) );
            } else {
                $results = $wpdb->get_results( $query );
            }

            // Clear output buffer to prevent broken CSV formatting
            if ( ob_get_length() ) {
                ob_clean();
            }

            // Set headers for CSV download
            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=donations_export_' . date( 'Y-m-d' ) . '.csv' );

            // Open PHP output stream
            $output = fopen( 'php://output', 'w' );

            // 4. Write CSV Headers
            fputcsv( $output, [
                'Campaign ID',
                'Order ID',
                'Campaign Name',
                'Campaign Status',
                'Donor Name',
                'Donor Type',
                'Date',
                'Growfund Status',
                'Growfund Payment Status',
                'WC Status',
                'Total Paid (USD)',
                'Original Amount',
                'Currency',
                'Total Donation Amount (USD)',
                'Net Amount (USD)',
                'Gateway Fee (USD)',
                'Platform Fee (USD)',
                'Total Processing Fees (USD)',
                'Platform Tip (USD)',
                'Net Tip (USD)',
                'Payment Gateway',
                'Origin'
            ] );

            // 5. Loop through the rows and write data
            if ( ! empty( $results ) ) {
                foreach ( $results as $row ) {

                    // --- Parse Donor Name ---
                    $donor_name = 'Guest';
                    if ( ! empty( $row->user_info ) ) {
                        $user_data = json_decode( $row->user_info, true );
                        if ( isset( $user_data['first_name'] ) ) {
                            $donor_name = trim( $user_data['first_name'] . ' ' . ( $user_data['last_name'] ?? '' ) );
                        }
                    }

                    // --- Parse Donor Type ---
                    $donor_type = ( ! empty( $row->customer_id ) && $row->customer_id > 0 ) ? 'Registered User' : 'Guest';

                    // --- Parse Payment Gateway (JSON to Label) ---
                    $payment_gateway = 'Unknown';
                    if ( ! empty( $row->payment_method ) ) {
                        $pm_data = json_decode( $row->payment_method, true );
                        if ( is_array( $pm_data ) && isset( $pm_data['label'] ) ) {
                            $payment_gateway = $pm_data['label'];
                        }
                    }

                    // --- Parse Math & Fees ---
                    $donation_amount = $row->amount / 100;
                    $gateway_fee     = $row->gateway_fee / 100;
                    $platform_fee    = $row->platform_fee / 100;
                    $tip_amount      = $row->tip_amount / 100;
                    
                    // Applied User's Equations
                    $campaign_fees  = $row->processing_fee / 100; 
                    $net_amount     = $donation_amount - $campaign_fees;
                    $net_tip_amount = $tip_amount - $gateway_fee;
                    $total_fees     = $gateway_fee + $platform_fee;
                    $total_paid     = $donation_amount + $tip_amount;

                    // --- Format Strings & Statuses ---
                    $campaign_name   = get_the_title( $row->campaign_id );
                    $campaign_status = ! empty( $row->campaign_status ) ? ucfirst( $row->campaign_status ) : 'Unknown';
                    $gf_status       = ucfirst( $row->gf_status );
                    $gf_payment      = ucfirst( $row->payment_status );
                    
                    // WooCommerce stores statuses like 'wc-completed', strip the 'wc-' for a cleaner CSV
                    $wc_status       = ! empty( $row->wc_status ) ? ucfirst( str_replace( 'wc-', '', $row->wc_status ) ) : 'Unknown';
                    
                    // Separate Original Amount and Currency
                    $original_amount   = number_format( (float) $row->original_total, 2, '.', '' );
                    $original_currency = $row->original_currency;
                    
                    // --- Parse Origin ---
                    $origin = 'Website';
                    if ( ! empty( $row->user_agent ) ) {
                        if ( preg_match( '/iPhone|iPad|Android|Mobile/i', $row->user_agent ) ) {
                            $origin = 'Mobile Web';
                        }
                        if ( preg_match( '/Samafale|Dart|okhttp/i', $row->user_agent ) ) {
                            $origin = 'Mobile App';
                        }
                    }

                    // Write Row
                    fputcsv( $output, [
                        $row->campaign_id,
                        $row->order_id,
                        $campaign_name,
                        $campaign_status,
                        $donor_name,
                        $donor_type,
                        $row->created_at,
                        $gf_status,
                        $gf_payment,
                        $wc_status,
                        number_format( $total_paid, 2, '.', '' ),
                        $original_amount,
                        $original_currency,
                        number_format( $donation_amount, 2, '.', '' ),
                        number_format( $net_amount, 2, '.', '' ),
                        number_format( $gateway_fee, 2, '.', '' ),
                        number_format( $platform_fee, 2, '.', '' ),
                        number_format( $total_fees, 2, '.', '' ),
                        number_format( $tip_amount, 2, '.', '' ),
                        number_format( $net_tip_amount, 2, '.', '' ),
                        $payment_gateway,
                        $origin
                    ] );
                }
            }

            fclose( $output );
            exit; 
        }
    }
}

// Initialize the class
GFCM_Export_Donations::init();