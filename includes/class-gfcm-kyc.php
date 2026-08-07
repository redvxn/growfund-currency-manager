<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ==========================================
// 1. FRONTEND: KYC INTERCEPT & SUBMISSION
// ==========================================

add_action( 'user_register', 'gfcm_intercept_new_fundraiser', 99, 1 );
function gfcm_intercept_new_fundraiser( $user_id ) {
    $user = get_userdata( $user_id );
    if ( in_array( 'growfund_fundraiser', (array) $user->roles ) ) {
        $token = wp_generate_password( 20, false );
        update_user_meta( $user_id, '_gfcm_kyc_token', $token );
        update_user_meta( $user_id, '_gfcm_kyc_status', 'pending' );
        set_transient( 'gfcm_kyc_redirect_' . $_SERVER['REMOTE_ADDR'], $user_id, 300 );
        
        // NEW: Instantly send the KYC reminder email upon registration
        gfcm_send_kyc_reminder_email( $user_id );
    }
}

add_filter( 'wp_redirect', 'gfcm_redirect_to_kyc_page', 99, 2 );
function gfcm_redirect_to_kyc_page( $location, $status ) {
    $user_id = get_transient( 'gfcm_kyc_redirect_' . $_SERVER['REMOTE_ADDR'] );
    if ( $user_id && strpos( $location, 'login' ) !== false ) {
        delete_transient( 'gfcm_kyc_redirect_' . $_SERVER['REMOTE_ADDR'] );
        $token = get_user_meta( $user_id, '_gfcm_kyc_token', true );
        $kyc_url = site_url( '/fundraiser-kyc/' ); 
        $location = add_query_arg( array( 'uid' => $user_id, 'token' => $token ), $kyc_url );
    }
    return $location;
}

add_shortcode( 'gfcm_fundraiser_kyc', 'gfcm_render_kyc_form' );
function gfcm_render_kyc_form() {
    ob_start();
    include GFCM_PLUGIN_DIR . 'templates/kyc-form.php';
    return ob_get_clean();
}

add_action( 'wp_ajax_gfcm_submit_kyc', 'gfcm_handle_kyc_submission' );
add_action( 'wp_ajax_nopriv_gfcm_submit_kyc', 'gfcm_handle_kyc_submission' );
function gfcm_handle_kyc_submission() {
    
    // 1. Check Cloudflare Turnstile Anti-Spam BEFORE doing anything else!
    $turnstile_response = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';

    if ( ! GFCM_Turnstile::verify_token( $turnstile_response ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed. Please confirm you are human and try again.' ) );
        wp_die();
    }
    
    check_ajax_referer( 'gfcm_kyc_nonce', 'nonce' );

    $user_id = isset( $_POST['uid'] ) ? intval( $_POST['uid'] ) : 0;
    $token   = isset( $_POST['token'] ) ? sanitize_text_field( $_POST['token'] ) : '';

    if ( ! $user_id || get_user_meta( $user_id, '_gfcm_kyc_token', true ) !== $token ) {
        wp_send_json_error( array( 'message' => 'Invalid or expired secure token.' ) );
    }

    require_once( ABSPATH . 'wp-admin/includes/file.php' );
    require_once( ABSPATH . 'wp-admin/includes/media.php' );
    require_once( ABSPATH . 'wp-admin/includes/image.php' );

    $upload_file = function( $file_key ) {
        if ( ! empty( $_FILES[$file_key]['name'] ) ) {
            $uploaded = media_handle_upload( $file_key, 0 );
            if ( ! is_wp_error( $uploaded ) ) {
                return wp_get_attachment_url( $uploaded );
            }
        }
        return '';
    };

    // Updated Meta Fields (Removed Countries)
    $meta_fields = array(
        'gfcm_kyc_type', 'gfcm_kyc_payout_method', 'gfcm_kyc_mobile_provider', 'gfcm_kyc_payout_currency', 
        'gfcm_kyc_payout_currency_org', 'gfcm_kyc_mobile_name', 'gfcm_kyc_mobile_number', 
        'gfcm_kyc_bank_name', 'gfcm_kyc_bank_account_name', 'gfcm_kyc_bank_account_number',
        'gfcm_kyc_beneficiary_name', 'gfcm_kyc_beneficiary_relation', 
        'gfcm_kyc_beneficiary_contact', 'gfcm_kyc_recipient_type', 'gfcm_kyc_org_name', 'gfcm_kyc_org_type',
        'gfcm_kyc_org_reg_number', 'gfcm_kyc_org_website', 'gfcm_kyc_contact_name', 'gfcm_kyc_contact_phone',
        'gfcm_kyc_contact_address', 'gfcm_kyc_contact_city', 'gfcm_kyc_rep_name',
        'gfcm_kyc_rep_role', 'gfcm_kyc_rep_phone', 'gfcm_kyc_rep_address', 'gfcm_kyc_rep_city',
        'gfcm_kyc_id_number', 'gfcm_kyc_fundraiser_id_number', 'gfcm_kyc_auth_id_number', 'gfcm_kyc_consent'
    );

    foreach ( $meta_fields as $field ) {
        if ( isset( $_POST[$field] ) ) {
            $val = $field === 'gfcm_kyc_consent' ? current_time('mysql') : sanitize_text_field( $_POST[$field] );
            update_user_meta( $user_id, $field, $val );
        }
    }

    $file_fields = array( 'gfcm_kyc_id_upload', 'gfcm_kyc_fundraiser_id', 'gfcm_kyc_org_cert', 'gfcm_kyc_auth_id' );
    foreach ( $file_fields as $file_field ) {
        $file_url = $upload_file( $file_field );
        if ( $file_url ) {
            update_user_meta( $user_id, $file_field, $file_url );
        }
    }

    update_user_meta( $user_id, '_gfcm_kyc_status', 'submitted' );
    update_user_meta( $user_id, '_gfcm_kyc_submitted_date', current_time('mysql') ); // NEW: Save submission date
    delete_user_meta( $user_id, '_gfcm_kyc_token' );

    wp_send_json_success( array( 'redirect' => site_url( '/login/?kyc=success' ) ) );
}


// Core Email Sender Function (Used by Registration Hook and Admin Manual Button)
function gfcm_send_kyc_reminder_email( $user_id ) {
    $user = get_userdata( $user_id );
    if ( ! $user ) return false;

    // Generate secure token link
    $token = get_user_meta( $user_id, '_gfcm_kyc_token', true );
    if ( ! $token ) {
        $token = wp_generate_password( 20, false );
        update_user_meta( $user_id, '_gfcm_kyc_token', $token );
    }
    $kyc_link = home_url('/fundraiser-kyc/?uid=' . $user_id . '&token=' . $token);

    if ( function_exists('WC') ) {
        $mailer = WC()->mailer();
        $subject = 'Action Required: Complete Your Identity Verification';
        $email_heading = 'Identity Verification';
        
        ob_start();
        if ( file_exists( GFCM_PLUGIN_DIR . 'templates/email-kyc-reminder.php' ) ) {
            include GFCM_PLUGIN_DIR . 'templates/email-kyc-reminder.php'; 
        } else {
            echo "<p>Hi {$user->first_name},</p><p>Please complete your KYC verification using this link: <a href='{$kyc_link}'>{$kyc_link}</a></p>";
        }
        $message = ob_get_clean();

        $mailer->send( $user->user_email, $subject, $message );
    }
    
    return true;
}


// ==========================================
// 2. BACKEND: ADMIN KYC REVIEW DASHBOARD
// ==========================================

add_action('admin_menu', 'gfcm_register_fundraisers_kyc_page');
function gfcm_register_fundraisers_kyc_page() {
    add_users_page( 'Fundraisers KYC', 'Fundraisers KYC', 'manage_options', 'gfcm-fundraisers-kyc', 'gfcm_render_fundraisers_kyc_page' );
}

function gfcm_render_fundraisers_kyc_page() {
    if ( ! current_user_can('manage_options') ) return;

    $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $base_url = admin_url('users.php?page=gfcm-fundraisers-kyc');

    echo '<div class="wrap">';

    // --- HANDLE MANUAL EMAIL REMINDER ---
    if ( $action === 'remind' && $user_id && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'remind_kyc_' . $user_id) ) {
        gfcm_send_kyc_reminder_email( $user_id );
        echo '<div class="notice notice-success is-dismissible"><p>KYC reminder email successfully sent to user.</p></div>';
        $action = 'list';
    }

    // --- HANDLE DELETE ACTION ---
    if ( $action === 'delete' && $user_id && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_kyc_' . $user_id) ) {
        $meta_fields = array(
            'gfcm_kyc_type', 'gfcm_kyc_payout_method', 'gfcm_kyc_mobile_provider', 'gfcm_kyc_payout_currency', 
            'gfcm_kyc_payout_currency_org', 'gfcm_kyc_mobile_name', 'gfcm_kyc_mobile_number', 
            'gfcm_kyc_bank_name', 'gfcm_kyc_bank_account_name', 'gfcm_kyc_bank_account_number',
            'gfcm_kyc_beneficiary_name', 'gfcm_kyc_beneficiary_relation', 'gfcm_kyc_beneficiary_contact', 
            'gfcm_kyc_recipient_type', 'gfcm_kyc_org_name', 'gfcm_kyc_org_type', 'gfcm_kyc_org_reg_number', 
            'gfcm_kyc_org_website', 'gfcm_kyc_contact_name', 'gfcm_kyc_contact_phone', 'gfcm_kyc_contact_address', 
            'gfcm_kyc_contact_city', 'gfcm_kyc_rep_name', 'gfcm_kyc_rep_role', 'gfcm_kyc_rep_phone', 
            'gfcm_kyc_rep_address', 'gfcm_kyc_rep_city', 'gfcm_kyc_id_number', 'gfcm_kyc_fundraiser_id_number', 
            'gfcm_kyc_auth_id_number', 'gfcm_kyc_consent', 'gfcm_kyc_id_upload', 'gfcm_kyc_fundraiser_id', 
            'gfcm_kyc_org_cert', 'gfcm_kyc_auth_id', '_gfcm_kyc_status', '_gfcm_kyc_token', '_gfcm_kyc_submitted_date'
        );
        foreach ($meta_fields as $field) {
            delete_user_meta($user_id, $field);
        }
        echo '<div class="notice notice-success is-dismissible"><p>KYC record completely deleted.</p></div>';
        $action = 'list'; // Fallback to list view
    }

    // --- HANDLE EDIT SAVE ACTION ---
    if ( $action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gfcm_kyc_edit_nonce']) && wp_verify_nonce($_POST['gfcm_kyc_edit_nonce'], 'save_kyc_' . $user_id) ) {
        $text_fields = array(
            'gfcm_kyc_type', 'gfcm_kyc_payout_method', 'gfcm_kyc_mobile_provider', 'gfcm_kyc_payout_currency', 
            'gfcm_kyc_payout_currency_org', 'gfcm_kyc_mobile_name', 'gfcm_kyc_mobile_number', 
            'gfcm_kyc_bank_name', 'gfcm_kyc_bank_account_name', 'gfcm_kyc_bank_account_number',
            'gfcm_kyc_beneficiary_name', 'gfcm_kyc_beneficiary_relation', 'gfcm_kyc_beneficiary_contact', 
            'gfcm_kyc_recipient_type', 'gfcm_kyc_org_name', 'gfcm_kyc_org_type', 'gfcm_kyc_org_reg_number', 
            'gfcm_kyc_org_website', 'gfcm_kyc_contact_name', 'gfcm_kyc_contact_phone', 'gfcm_kyc_contact_address', 
            'gfcm_kyc_contact_city', 'gfcm_kyc_rep_name', 'gfcm_kyc_rep_role', 'gfcm_kyc_rep_phone', 
            'gfcm_kyc_rep_address', 'gfcm_kyc_rep_city', 'gfcm_kyc_id_number', 'gfcm_kyc_fundraiser_id_number', 
            'gfcm_kyc_auth_id_number'
        );
        foreach ($text_fields as $field) {
            if (isset($_POST[$field])) {
                update_user_meta($user_id, $field, sanitize_text_field($_POST[$field]));
            }
        }
        if (isset($_POST['_gfcm_kyc_status'])) {
            update_user_meta($user_id, '_gfcm_kyc_status', sanitize_text_field($_POST['_gfcm_kyc_status']));
        }
        echo '<div class="notice notice-success is-dismissible"><p>KYC record updated successfully.</p></div>';
        $action = 'view'; // Switch back to view mode so they can see changes
    }

    // --- DETAILED VIEW / EDIT VIEW ---
    if ( ($action === 'view' || $action === 'edit') && $user_id ) {
        // [Existing detailed view code remains exactly the same here - omitted for brevity but include your original view block]
        $user = get_userdata($user_id);
        if (!$user) { echo '<p>User not found.</p></div>'; return; }

        $type = get_user_meta($user_id, 'gfcm_kyc_type', true);
        $type_label = '';
        if ($type === 'myself') $type_label = 'Personal (For Myself)';
        if ($type === 'someone_else') $type_label = 'Third-Party (Someone Else)';
        if ($type === 'organization') $type_label = 'Organization / NGO';

        echo '<h2>' . ($action === 'edit' ? 'Edit KYC: ' : 'Review KYC: ') . esc_html($user->first_name . ' ' . $user->last_name) . '</h2>';
        echo '<a href="' . esc_url($base_url) . '" class="button button-secondary" style="margin-bottom: 20px;">&larr; Back to List</a>';

        if ($action === 'edit') {
            echo '<form method="POST" action="">';
            wp_nonce_field('save_kyc_' . $user_id, 'gfcm_kyc_edit_nonce');
        }

        echo '<div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04); max-width: 800px;">';
        
        // 1. Profile Status
        echo '<h3>User Profile / Account</h3>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">Name</th><td>' . esc_html($user->first_name . ' ' . $user->last_name) . '</td></tr>';
        echo '<tr><th scope="row">Email</th><td>' . esc_html($user->user_email) . '</td></tr>';
        
        if ($action === 'edit') {
            $current_kyc = get_user_meta($user_id, '_gfcm_kyc_status', true);
            echo '<tr><th scope="row">Internal KYC Status</th><td>';
            echo '<select name="_gfcm_kyc_status">';
            echo '<option value="pending" ' . selected($current_kyc, 'pending', false) . '>Pending / Incomplete</option>';
            echo '<option value="submitted" ' . selected($current_kyc, 'submitted', false) . '>Submitted</option>';
            echo '</select></td></tr>';
        }

        $consent_date = get_user_meta($user_id, 'gfcm_kyc_consent', true);
        if ($consent_date) {
            echo '<tr><th scope="row">Legal Consent</th><td><span style="color:green;">Accepted on ' . esc_html($consent_date) . '</span></td></tr>';
        }
        $submitted_date = get_user_meta($user_id, '_gfcm_kyc_submitted_date', true);
        if ($submitted_date) {
            echo '<tr><th scope="row">Submitted Date</th><td><span style="color:#2271b1;font-weight:bold;">' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($submitted_date))) . '</span></td></tr>';
        }
        echo '</tbody></table>';

        // Helper function to render text inputs or standard display
        $output_row = function($label, $meta_key, $is_file = false) use ($user_id, $action) {
            $val = get_user_meta($user_id, $meta_key, true);
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
            
            if ($action === 'edit' && !$is_file) {
                echo '<input type="text" name="' . esc_attr($meta_key) . '" value="' . esc_attr($val) . '" class="regular-text">';
            } else {
                if (!$val) {
                    if ($is_file) echo '<em style="color:#999;">Not provided</em>';
                } elseif ($is_file) {
                    echo '<a href="' . esc_url($val) . '" target="_blank" class="button button-primary">View Document</a>';
                } else {
                    echo esc_html(str_replace('_', ' ', ucfirst($val))); 
                }
            }
            echo '</td></tr>';
        };

        // 2. Contact Info
        echo '<tr><td colspan="2"><hr></td></tr>';
        echo '<tr><th colspan="2" style="padding-bottom:0;"><h3 style="margin:0;">Contact Information</h3></th></tr>';
        echo '<table class="form-table" role="presentation"><tbody>';
        $output_row('Fundraiser Type', 'gfcm_kyc_type');
        if ( $type === 'organization' ) {
            $output_row('Representative Name', 'gfcm_kyc_rep_name');
            $output_row('Representative Role', 'gfcm_kyc_rep_role');
            $output_row('Phone Number', 'gfcm_kyc_rep_phone');
            $output_row('Address', 'gfcm_kyc_rep_address');
            $output_row('City', 'gfcm_kyc_rep_city');
        } else {
            $output_row('Full Name', 'gfcm_kyc_contact_name');
            $output_row('Phone Number', 'gfcm_kyc_contact_phone');
            $output_row('Address', 'gfcm_kyc_contact_address');
            $output_row('City', 'gfcm_kyc_contact_city');
        }
        echo '</tbody></table>';

        // 3. Verification Details
        echo '<tr><td colspan="2"><hr></td></tr>';
        echo '<tr><th colspan="2" style="padding-bottom:0;"><h3 style="margin:0;">Verification Details</h3></th></tr>';
        echo '<table class="form-table" role="presentation"><tbody>';
        if ( $type === 'myself' ) {
            $output_row('ID / Passport Number', 'gfcm_kyc_id_number');
            $output_row('National ID / Passport', 'gfcm_kyc_id_upload', true);
        } elseif ( $type === 'someone_else' ) {
            $output_row('Beneficiary Name', 'gfcm_kyc_beneficiary_name');
            $output_row('Relationship', 'gfcm_kyc_beneficiary_relation');
            $output_row('Beneficiary Contact', 'gfcm_kyc_beneficiary_contact');
            $output_row('Fundraiser ID / Passport Number', 'gfcm_kyc_fundraiser_id_number');
            $output_row('Fundraiser (Your) ID Upload', 'gfcm_kyc_fundraiser_id', true);
            $output_row('Funds Recipient', 'gfcm_kyc_recipient_type');
        } elseif ( $type === 'organization' ) {
            $output_row('Organization Name', 'gfcm_kyc_org_name');
            $output_row('Organization Type', 'gfcm_kyc_org_type');
            $output_row('Registration Number', 'gfcm_kyc_org_reg_number');
            $output_row('Website/Social', 'gfcm_kyc_org_website');
            $output_row('Registration Certificate', 'gfcm_kyc_org_cert', true);
            echo '<tr><td colspan="2"><hr></td></tr>';
            $output_row('Authorized Person ID / Passport', 'gfcm_kyc_auth_id_number');
            $output_row('Authorized Person ID Upload', 'gfcm_kyc_auth_id', true);
        }
        echo '</tbody></table>';
        
        // 4. Payout Setup
        echo '<tr><td colspan="2"><hr></td></tr>';
        echo '<tr><th colspan="2" style="padding-bottom:0;"><h3 style="margin:0;">Payout Setup</h3></th></tr>';
        echo '<table class="form-table" role="presentation"><tbody>';
        $payout_method = get_user_meta($user_id, 'gfcm_kyc_payout_method', true);
        $output_row('Payout Method', 'gfcm_kyc_payout_method');
        if ($payout_method === 'mobile_money') {
            $output_row('Mobile Provider', 'gfcm_kyc_mobile_provider');
            $output_row('Mobile Account Name', 'gfcm_kyc_mobile_name');
            $output_row('Mobile Account Number', 'gfcm_kyc_mobile_number');
        } else {
            $output_row('Bank Name', 'gfcm_kyc_bank_name');
            $output_row('Account Holder Name', 'gfcm_kyc_bank_account_name');
            $output_row('Account Number', 'gfcm_kyc_bank_account_number');
        }
        if ($type === 'organization') {
            $output_row('Preferred Currency', 'gfcm_kyc_payout_currency_org');
        } else {
            $output_row('Preferred Currency', 'gfcm_kyc_payout_currency');
        }
        echo '</tbody></table>';

        if ($action === 'edit') {
            echo '<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Save KYC Changes"></p>';
            echo '</div></form>';
        } else {
            echo '</div>';
        }
    } 
    
    // --- MASTER LIST VIEW ---
    else {
        echo '<h1 class="wp-heading-inline">Fundraisers KYC Dashboard</h1>';
        echo '<hr class="wp-header-end">';

        $type_filter = isset($_GET['kyc_filter']) ? sanitize_text_field($_GET['kyc_filter']) : '';
        $gf_status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';
        $kyc_status_filter = isset($_GET['kyc_status_filter']) ? sanitize_text_field($_GET['kyc_status_filter']) : '';
        
        // NEW FILTERS: From and To Dates
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to   = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';

        // Server-Side Filters Form
        echo '<form method="GET" style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">';
        echo '<input type="hidden" name="page" value="gfcm-fundraisers-kyc">';
        
        echo '<select name="kyc_filter">';
        echo '<option value="">All Types</option>';
        echo '<option value="myself" ' . selected($type_filter, 'myself', false) . '>For Myself</option>';
        echo '<option value="someone_else" ' . selected($type_filter, 'someone_else', false) . '>Someone Else</option>';
        echo '<option value="organization" ' . selected($type_filter, 'organization', false) . '>Organization</option>';
        echo '</select>';
        
        echo '<select name="kyc_status_filter">';
        echo '<option value="">All KYC Statuses</option>';
        echo '<option value="submitted" ' . selected($kyc_status_filter, 'submitted', false) . '>KYC Submitted</option>';
        echo '<option value="pending" ' . selected($kyc_status_filter, 'pending', false) . '>KYC Pending</option>';
        echo '</select>';

        echo '<select name="status_filter">';
        echo '<option value="">All GrowFund Statuses</option>';
        echo '<option value="active" ' . selected($gf_status_filter, 'active', false) . '>Active / Approved</option>';
        echo '<option value="inactive" ' . selected($gf_status_filter, 'inactive', false) . '>Inactive / Rejected</option>';
        echo '<option value="pending" ' . selected($gf_status_filter, 'pending', false) . '>Pending</option>';
        echo '</select>';

        // Date Range Inputs
        echo '<div style="display:flex; align-items:center; gap:5px;">';
        echo '<span style="font-weight:bold; color:#555;">From:</span>';
        echo '<input type="date" name="date_from" value="' . esc_attr($date_from) . '">';
        echo '<span style="font-weight:bold; color:#555; margin-left:5px;">To:</span>';
        echo '<input type="date" name="date_to" value="' . esc_attr($date_to) . '">';
        echo '</div>';
        
        echo '<input type="submit" class="button" value="Filter">';
        echo '<a href="' . esc_url(admin_url('users.php?page=gfcm-fundraisers-kyc')) . '" class="button button-secondary">Reset</a>';
        echo '</form>';

        // Query Users
        $args = array( 'role' => 'growfund_fundraiser' );
        $meta_query = array();
        
        if ( $type_filter ) $meta_query[] = array('key' => 'gfcm_kyc_type', 'value' => $type_filter, 'compare' => '=');
        if ( $kyc_status_filter ) $meta_query[] = array('key' => '_gfcm_kyc_status', 'value' => $kyc_status_filter, 'compare' => '=');
        
        // DATE RANGE QUERY LOGIC
        if ( $date_from || $date_to ) {
            $date_query = array('key' => '_gfcm_kyc_submitted_date', 'type' => 'DATETIME');
            
            if ( $date_from && $date_to ) {
                $date_query['value'] = array( $date_from . ' 00:00:00', $date_to . ' 23:59:59' );
                $date_query['compare'] = 'BETWEEN';
            } elseif ( $date_from ) {
                $date_query['value'] = $date_from . ' 00:00:00';
                $date_query['compare'] = '>=';
            } elseif ( $date_to ) {
                $date_query['value'] = $date_to . ' 23:59:59';
                $date_query['compare'] = '<=';
            }
            $meta_query[] = $date_query;
        }

        if ( $gf_status_filter ) {
            if ( $gf_status_filter === 'pending' ) {
                $meta_query[] = array(
                    'relation' => 'OR',
                    array('key' => 'growfund_status', 'compare' => 'NOT EXISTS'),
                    array('key' => 'growfund_status', 'value' => array('active', 'inactive'), 'compare' => 'NOT IN')
                );
            } else {
                $meta_query[] = array('key' => 'growfund_status', 'value' => $gf_status_filter, 'compare' => '=');
            }
        }
        
        if ( ! empty( $meta_query ) ) {
            if ( count($meta_query) > 1 && !isset($meta_query['relation']) ) $meta_query['relation'] = 'AND';
            $args['meta_query'] = $meta_query;
        }

        $user_query = new WP_User_Query( $args );
        $users = $user_query->get_results();

        // Data Table Wrapper
        echo '<div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:4px; box-shadow:0 1px 1px rgba(0,0,0,.04);">';
        echo '<table id="gfcm-kyc-datatable" class="wp-list-table widefat fixed striped table-view-list users" style="width:100%; border:none;">';
        echo '<thead><tr>';
        echo '<th scope="col" class="manage-column column-primary">Name</th>';
        echo '<th scope="col" class="manage-column">Email</th>';
        echo '<th scope="col" class="manage-column">Fundraising Type</th>';
        echo '<th scope="col" class="manage-column">KYC Status</th>';
        echo '<th scope="col" class="manage-column">Submitted</th>';
        echo '<th scope="col" class="manage-column">GrowFund Status</th>';
        echo '</tr></thead><tbody id="the-list">';

        if ( ! empty( $users ) ) {
            foreach ( $users as $user ) {
                $status = get_user_meta($user->ID, '_gfcm_kyc_status', true);
                $type = get_user_meta($user->ID, 'gfcm_kyc_type', true);
                $gf_status = get_user_meta($user->ID, 'growfund_status', true);
                $submitted_date = get_user_meta($user->ID, '_gfcm_kyc_submitted_date', true); 
                
                $status_text = $status === 'submitted' ? '<span style="color:green;font-weight:bold;">Submitted</span>' : '<span style="color:#999;">Pending/Incomplete</span>';
                
                $timestamp = $submitted_date ? strtotime($submitted_date) : 0;
                $formatted_date = $submitted_date ? date_i18n( get_option('date_format'), $timestamp ) : '<span style="color:#999;">-</span>';

                if ( $gf_status === 'active' ) {
                    $gf_status_text = '<span style="color:green;font-weight:bold;">Active</span>';
                } elseif ( $gf_status === 'inactive' ) {
                    $gf_status_text = '<span style="color:red;font-weight:bold;">Inactive</span>';
                } else {
                    $gf_status_text = '<span style="color:#e68a00;font-weight:bold;">Pending</span>';
                }

                $type_text = 'Unknown';
                if ($type === 'myself') $type_text = 'Personal';
                if ($type === 'someone_else') $type_text = 'Third-Party';
                if ($type === 'organization') $type_text = 'Organization';

                $view_url = add_query_arg( array('action' => 'view', 'user_id' => $user->ID), $base_url );
                $edit_url = add_query_arg( array('action' => 'edit', 'user_id' => $user->ID), $base_url );
                $del_url  = wp_nonce_url( add_query_arg( array('action' => 'delete', 'user_id' => $user->ID), $base_url ), 'delete_kyc_' . $user->ID );
                $remind_url = wp_nonce_url( add_query_arg( array('action' => 'remind', 'user_id' => $user->ID), $base_url ), 'remind_kyc_' . $user->ID );

                echo '<tr id="user-' . esc_attr($user->ID) . '">';
                
                echo '<td class="has-row-actions column-primary" data-colname="Name">';
                echo '<strong><a href="' . esc_url($view_url) . '">' . esc_html($user->first_name . ' ' . $user->last_name) . '</a></strong>';
                echo '<div class="row-actions" style="visibility:visible; opacity:1; position:relative; left:0; display:block; padding-top:5px;">'; 
                echo '<span class="view"><a href="' . esc_url($view_url) . '">View</a> | </span>';
                echo '<span class="edit"><a href="' . esc_url($edit_url) . '">Edit</a> | </span>';
                echo '<span class="remind"><a href="' . esc_url($remind_url) . '" style="color:#e68a00;">Send Reminder</a> | </span>';
                echo '<span class="delete"><a href="' . esc_url($del_url) . '" class="submitdelete" onclick="return confirm(\'Are you sure you want to permanently delete this KYC record?\');">Delete</a></span>';
                echo '</div>';
                echo '</td>';

                echo '<td data-colname="Email">' . esc_html($user->user_email) . '</td>';
                echo '<td data-colname="Fundraising Type">' . esc_html($type_text) . '</td>';
                echo '<td data-colname="KYC Status">' . $status_text . '</td>';
                echo '<td data-colname="Submitted" data-sort="' . esc_attr($timestamp) . '">' . $formatted_date . '</td>';
                echo '<td data-colname="GrowFund Status">' . $gf_status_text . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div>';

        // --- INJECT DATATABLES CSS & JS ---
        ?>
        <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
        <style>
            .dataTables_wrapper .dataTables_filter input { border: 1px solid #8c8f94; border-radius: 4px; padding: 4px 8px; margin-left: 8px; }
            .dataTables_wrapper .dataTables_length select { border: 1px solid #8c8f94; border-radius: 4px; padding: 0 24px 0 8px; }
            table.dataTable thead th, table.dataTable thead td { border-bottom: 1px solid #ccd0d4; padding: 10px 10px; }
            table.dataTable.no-footer { border-bottom: 1px solid #ccd0d4; }
            #gfcm-kyc-datatable_wrapper { margin-top: 15px; }
        </style>
        
        <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
        <script>
            jQuery(document).ready(function($) {
                $('#gfcm-kyc-datatable').DataTable({
                    "order": [[ 4, "desc" ]], 
                    "pageLength": 25,
                    "language": {
                        "search": "Search Users:"
                    },
                    "columnDefs": [
                        { "searchable": true, "targets": [0, 1] },
                        { "searchable": false, "targets": [2, 3, 4, 5] }
                    ]
                });
            });
        </script>
        <?php
    }
}


// ==========================================
// 3. FRONTEND: KYC REMINDER NOTICE FOR FUNDRAISERS
// ==========================================

add_action( 'wp_footer', 'gfcm_frontend_kyc_reminder_notice' );
function gfcm_frontend_kyc_reminder_notice() {
    // Only show on the frontend for logged-in users
    if ( ! is_user_logged_in() || is_admin() ) {
        return;
    }

    $current_user = wp_get_current_user();
    
    // Only show this to users with the Fundraiser role
    if ( ! in_array( 'growfund_fundraiser', (array) $current_user->roles ) ) {
        return;
    }

    // Check their KYC status and data
    $has_kyc_data = get_user_meta( $current_user->ID, 'gfcm_kyc_type', true );

    // Hide the notice if they have ANY existing KYC data 
    if ( ! empty( $has_kyc_data ) ) {
        return; 
    }

    // Grab their secure token (Generates a new one for older users who don't have one)
    $token = get_user_meta( $current_user->ID, '_gfcm_kyc_token', true );
    if ( ! $token ) {
        $token = wp_generate_password( 20, false );
        update_user_meta( $current_user->ID, '_gfcm_kyc_token', $token );
    }

    // Build the secure magic link to the frontend form
    $kyc_url = site_url( '/fundraiser-kyc/' );
    $secure_kyc_url = add_query_arg( array( 'uid' => $current_user->ID, 'token' => $token ), $kyc_url );

    // Display the Floating Frontend Banner
    ?>
    <div id="gfcm-kyc-frontend-notice" style="position: fixed; bottom: 20px; right: 20px; max-width: 380px; background: #ffffff; border-left: 6px solid #d63638; box-shadow: 0 5px 15px rgba(0,0,0,0.2); padding: 25px; border-radius: 8px; z-index: 999999; font-family: sans-serif;">
        <button type="button" onclick="document.getElementById('gfcm-kyc-frontend-notice').style.display='none'" style="position: absolute; top: 10px; right: 15px; background: none; border: none; font-size: 24px; cursor: pointer; color: #aaaaaa; padding: 0; line-height: 1;">&times;</button>
        <h4 style="margin: 0 0 10px; color: #222222; font-size: 17px;">⚠️ Identity Verification (KYC)</h4>
        <p style="margin: 0 0 20px; color: #555555; font-size: 14px; line-height: 1.5;">Before your campaigns can be approved and payouts issued, you must complete your verification profile.</p>
        <a href="<?php echo esc_url( $secure_kyc_url ); ?>" style="display: inline-block; background: #d63638; color: #ffffff; text-decoration: none; padding: 10px 18px; border-radius: 5px; font-weight: 600; font-size: 14px; transition: background 0.2s;">Complete KYC Now</a>
    </div>
    <?php
}



// Intercept login to check email verification and KYC status for fundraisers
add_filter( 'authenticate', 'gfcm_enforce_fundraiser_login_checks', 30, 3 );

function gfcm_enforce_fundraiser_login_checks( $user, $username, $password ) {
    
    // 1. Skip if login already failed or user object is invalid
    if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
        return $user;
    }

    // 2. Only apply these rules to the 'growfund_fundraiser' role
    if ( ! in_array( 'growfund_fundraiser', (array) $user->roles ) ) {
        return $user;
    }

    $user_id = $user->ID;

    // --- RULE 1: Email Verification Check ---
    $email_verified = get_user_meta( $user_id, 'growfund_email_verified', true );
    
    if ( empty( $email_verified ) || $email_verified === '0' ) {
        return new WP_Error( 'email_not_verified', 'Email verification required.' );
    }

    // --- RULE 2 & 3: KYC Status Checks ---
    $growfund_status = get_user_meta( $user_id, 'growfund_status', true );
    
    if ( empty( $growfund_status ) || $growfund_status !== 'active' ) {
        
        $kyc_status = get_user_meta( $user_id, '_gfcm_kyc_status', true );

        // Rule 2: Pending KYC (or key doesn't exist yet)
        if ( empty( $kyc_status ) || $kyc_status === 'pending' ) {
            return new WP_Error( 'kyc_pending', 'KYC information required.' );
        }

        // Rule 3: Submitted KYC (Awaiting manual review)
        if ( $kyc_status === 'submitted' ) {
            return new WP_Error( 'kyc_submitted', 'Account under review.' );
        }
    }

    // 3. If all checks pass, allow them to log in!
    return $user;
}



// ==========================================
// 1. AJAX PRE-FLIGHT VALIDATION ENGINE
// ==========================================
add_action( 'wp_ajax_nopriv_gfcm_pre_login_check', 'gfcm_pre_login_check_handler' );
add_action( 'wp_ajax_gfcm_pre_login_check', 'gfcm_pre_login_check_handler' );

function gfcm_pre_login_check_handler() {
    $username = isset($_POST['user_login']) ? sanitize_text_field($_POST['user_login']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    // 1. Basic empty field validation
    if ( empty( $username ) || empty( $password ) ) {
        wp_send_json([
            'show_popup' => true,
            'title'      => 'Fields Required',
            'message'    => 'Both the username/email and password fields are required.',
            'color'      => '#e74c3c',
            'icon'       => 'alert',
            'btn_text'   => 'Try Again'
        ]);
    }

    // 2. Find the user manually
    $user = get_user_by( 'email', $username );
    if ( ! $user ) {
        $user = get_user_by( 'login', $username );
    }

    // 3. Verify Credentials
    if ( ! $user || ! wp_check_password( $password, $user->data->user_pass, $user->ID ) ) {
        wp_send_json([
            'show_popup' => true,
            'title'      => 'Login Failed',
            'message'    => 'The credentials you entered are not correct.',
            'color'      => '#e74c3c',
            'icon'       => 'alert',
            'btn_text'   => 'Try Again'
        ]);
    }

    $user_id = $user->ID;
    
    // 4. Check if they are a fundraiser to apply Growfund rules
    global $wpdb;
    $capabilities  = get_user_meta( $user_id, $wpdb->get_blog_prefix() . 'capabilities', true );
    $is_fundraiser = in_array( 'growfund_fundraiser', (array) $user->roles ) || ( is_array( $capabilities ) && array_key_exists( 'growfund_fundraiser', $capabilities ) );

    if ( $is_fundraiser ) {
        // RULE 1: Email Verification
        $email_verified = get_user_meta( $user_id, 'growfund_email_verified', true );
        if ( empty( $email_verified ) || $email_verified === '0' ) {
            wp_send_json([
                'show_popup' => true,
                'title'      => 'Email Verification Required',
                'message'    => 'Check your email address and verify your account by clicking on the link.',
                'color'      => '#e74c3c',
                'icon'       => 'alert',
                'btn_text'   => 'I Understand'
            ]);
        }

        // RULE 2 & 3: KYC Checks
        $growfund_status = get_user_meta( $user_id, 'growfund_status', true );
        if ( empty( $growfund_status ) || $growfund_status !== 'active' ) {
            $kyc_status = get_user_meta( $user_id, '_gfcm_kyc_status', true );

            if ( empty( $kyc_status ) || $kyc_status === 'pending' ) {
                $token = get_user_meta( $user_id, '_gfcm_kyc_token', true );
                if ( ! $token ) {
                    $token = wp_generate_password( 20, false );
                    update_user_meta( $user_id, '_gfcm_kyc_token', $token );
                }
                $kyc_url = add_query_arg( array( 'uid' => $user_id, 'token' => $token ), site_url( '/fundraiser-kyc/' ) );

                wp_send_json([
                    'show_popup' => true,
                    'title'      => 'KYC Information Required',
                    'message'    => 'Before your campaigns can be approved and payouts issued, you must complete your verification profile.',
                    'color'      => '#d63638',
                    'icon'       => 'alert',
                    'btn_text'   => 'Complete Now',
                    'btn_url'    => $kyc_url
                ]);
            } elseif ( $kyc_status === 'submitted' ) {
                wp_send_json([
                    'show_popup' => true,
                    'title'      => 'Account Under Review',
                    'message'    => 'Please contact support using the chat tool at the bottom right.',
                    'color'      => '#3498db',
                    'icon'       => 'info',
                    'btn_text'   => 'I Understand'
                ]);
            }
        }
    }

    // 5. IF ALL CHECKS PASS -> Tell Javascript to submit the form natively to Growfund
    wp_send_json(['show_popup' => false]);
}

// ==========================================
// 2. INJECT THE POPUPS & SMART FORM HANDLER
// ==========================================
add_action( 'wp_footer', 'gfcm_inject_prelogin_interceptor' );
function gfcm_inject_prelogin_interceptor() {
    // Only inject on pages that might have the login form
    if ( is_admin() ) return;
    ?>
    <style>
        .gfcm-alert-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 999999; display: none; align-items: center; justify-content: center; opacity: 0; animation: gfcmFadeIn 0.3s forwards; backdrop-filter: blur(2px); }
        .gfcm-alert-card { background: #ffffff; width: 100%; max-width: 440px; border-radius: 12px 12px 0 0; position: relative; padding: 45px 30px 30px; text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.2); transform: translateY(20px); animation: gfcmSlideUp 0.3s forwards 0.1s; font-family: inherit; margin: 20px; }
        .gfcm-alert-card::after { content: ""; position: absolute; left: 0; right: 0; bottom: -10px; height: 10px; background-size: 20px 20px; background-image: radial-gradient(circle at 10px 0, #ffffff 10px, transparent 11px); }
        .gfcm-alert-icon { position: absolute; top: -25px; left: 50%; transform: translateX(-50%); width: 50px; height: 50px; border-radius: 50%; border: 4px solid #ffffff; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(0,0,0,0.15); transition: background-color 0.3s; }
        .gfcm-alert-icon svg { width: 24px; height: 24px; fill: none; stroke: #ffffff; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }
        .gfcm-alert-card h2 { font-size: 22px; font-weight: 700; color: #2b2b2b; margin: 10px 0 10px; }
        .gfcm-alert-card p { font-size: 15px; color: #555555; margin: 0 0 25px; line-height: 1.5; }
        .gfcm-alert-btn { display: inline-block; color: #fff !important; padding: 12px 25px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; text-decoration: none; transition: opacity 0.2s; }
        .gfcm-alert-btn:hover { opacity: 0.85; color: #fff !important; }
        .gfcm-btn-stack { display: flex; flex-direction: column; gap: 12px; margin-top: 20px; }
        @keyframes gfcmFadeIn { to { opacity: 1; } }
        @keyframes gfcmSlideUp { to { transform: translateY(0); opacity: 1; } }
    </style>

    <div class="gfcm-alert-overlay" id="gfcm-prelogin-overlay">
        <div class="gfcm-alert-card">
            <div class="gfcm-alert-icon" id="gfcm-prelogin-icon-wrap">
                <svg id="gfcm-icon-alert" style="display:none;" viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <svg id="gfcm-icon-info" style="display:none;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
            </div>
            <h2 id="gfcm-prelogin-title"></h2>
            <p id="gfcm-prelogin-message"></p>
            <a href="#" class="gfcm-alert-btn" id="gfcm-prelogin-action">I Understand</a>
        </div>
    </div>

    <div class="gfcm-alert-overlay" id="gfcm-register-choice-overlay">
        <div class="gfcm-alert-card" style="padding-bottom: 40px;">
            <div class="gfcm-alert-icon" style="background-color: #3498db;">
                <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
            </div>
            <h2>Create an Account</h2>
            <p>How would you like to register today?</p>
            <div class="gfcm-btn-stack">
                <a href="<?php echo site_url( '/donor-registration/' ); ?>" class="gfcm-alert-btn" style="background-color: #20c997;">Register as a Donor</a>
                <a href="<?php echo site_url( '/fundraiser-registration/' ); ?>" class="gfcm-alert-btn" style="background-color: #2b2b2b;">Register as a Fundraiser</a>
            </div>
        </div>
    </div>

    <script>
        jQuery(document).ready(function($) {
            var ajaxurl = "<?php echo admin_url('admin-ajax.php'); ?>";
            
            // --- 1. HANDLE LOGIN VALIDATION ---
            $('.growfund-login-form').on('submit', function(e) {
                var $form = $(this);
                
                if ($form.data('gfcm-cleared')) {
                    return true;
                }

                e.preventDefault(); 
                var $btn = $('#growfund_login_submit_button');
                var originalText = $btn.html();
                
                var email = $('#growfund_login_email').val();
                var password = $('#growfund_login_password').val();

                $btn.prop('disabled', true).html('Verifying...');

                $.ajax({
                    type: 'POST',
                    url: ajaxurl,
                    data: { action: 'gfcm_pre_login_check', user_login: email, password: password },
                    success: function(response) {
                        if (response && response.show_popup) {
                            $btn.prop('disabled', false).html(originalText);
                            
                            $('#gfcm-prelogin-title').html(response.title);
                            $('#gfcm-prelogin-message').html(response.message);
                            $('#gfcm-prelogin-icon-wrap, #gfcm-prelogin-action').css('background-color', response.color);
                            $('#gfcm-prelogin-action').html(response.btn_text || 'I Understand');

                            if (response.btn_url) {
                                $('#gfcm-prelogin-action').attr('href', response.btn_url).removeAttr('data-dismiss');
                            } else {
                                $('#gfcm-prelogin-action').attr('href', 'javascript:void(0);').attr('data-dismiss', 'true');
                            }

                            if (response.icon === 'info') {
                                $('#gfcm-icon-info').show();
                                $('#gfcm-icon-alert').hide();
                            } else {
                                $('#gfcm-icon-alert').show();
                                $('#gfcm-icon-info').hide();
                            }

                            $('#gfcm-prelogin-overlay').css('display', 'flex');
                        } else {
                            $btn.html('Logging in...');
                            var actionUrl = $form.attr('action');
                            if (actionUrl && !actionUrl.endsWith('/')) {
                                $form.attr('action', actionUrl + '/');
                            }
                            $form.data('gfcm-cleared', true).submit();
                        }
                    },
                    error: function() {
                        var actionUrl = $form.attr('action');
                        if (actionUrl && !actionUrl.endsWith('/')) {
                            $form.attr('action', actionUrl + '/');
                        }
                        $btn.prop('disabled', false).html(originalText);
                        $form.data('gfcm-cleared', true).submit();
                    }
                });
            });

            // --- 2. HANDLE SIGN UP BUTTON HIJACK ---
            $('.growfund-login-sign-link').on('click', function(e) {
                e.preventDefault(); // Stop it from going to the default donor link
                $('#gfcm-register-choice-overlay').css('display', 'flex'); // Show choice popup
            });

            // --- 3. OVERLAY CLOSE ACTIONS ---
            $('#gfcm-prelogin-action').on('click', function(e) {
                if ($(this).attr('data-dismiss') === 'true') {
                    e.preventDefault();
                    $('#gfcm-prelogin-overlay').css('display', 'none');
                }
            });

            $('#gfcm-prelogin-overlay, #gfcm-register-choice-overlay').on('click', function(e) {
                if (e.target === this) {
                    $(this).css('display', 'none');
                }
            });
        });
    </script>
    <?php
}