<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. GUARANTEE THE CHECKOUT OBJECT EXISTS
$checkout = WC()->checkout();

// 2. Fetch Growfund Campaign Variables SAFELY
$campaign_id = isset( WC()->session ) ? WC()->session->get('custom_fude_campaign_id') : 0;

// ==========================================
// NEW: GET ACTIVE EXCHANGE RATE & SYMBOL SAFELY
// ==========================================
$active_currency = ( isset( WC()->session ) && WC()->session->get('gfcm_selected_currency') ) ? WC()->session->get('gfcm_selected_currency') : 'USD';
$currency_symbol = get_woocommerce_currency_symbol( $active_currency );
$exchange_rate = 1;

if ( $active_currency !== 'USD' ) {
    $custom_currencies = get_option( 'gfcm_custom_currencies', array() );
    if ( isset( $custom_currencies[$active_currency] ) ) {
        $exchange_rate = floatval( $custom_currencies[$active_currency]['rate'] );
    }
}

// Safe Math Guard (Prevents fatal division by zero errors)
$safe_rate = ($exchange_rate && $exchange_rate > 0) ? $exchange_rate : 1;

// 3. Fetch Predefined Amounts
$raw_amounts = array();
$predefined_amounts = array();

if ( $campaign_id ) {
    $all_meta = get_post_meta( $campaign_id );
    if ( ! empty( $all_meta ) ) {
        foreach ( $all_meta as $key => $values ) {
            $meta_val = maybe_unserialize( $values[0] );
            if ( is_string( $meta_val ) ) {
                $decoded = json_decode( $meta_val, true );
                if ( is_array( $decoded ) ) {
                    $meta_val = $decoded;
                }
            }
            if ( is_array( $meta_val ) && ! empty( $meta_val ) ) {
                $first_item = reset( $meta_val );
                if ( ( is_array( $first_item ) && isset( $first_item['amount'] ) ) || ( is_object( $first_item ) && isset( $first_item->amount ) ) ) {
                    $raw_amounts = $meta_val;
                    break; 
                }
            }
        }
    }
}

if ( ! empty( $raw_amounts ) ) {
    foreach ( $raw_amounts as $item ) {
        $amt = 0;
        if ( is_array( $item ) && isset( $item['amount'] ) ) {
            $amt = floatval( $item['amount'] );
        } elseif ( is_object( $item ) && isset( $item->amount ) ) {
            $amt = floatval( $item->amount );
        }
        
        if ( $amt > 0 ) {
            $amt_usd = ( $amt >= 100 && $amt % 10 == 0 ) ? $amt / 100 : $amt;
            // MULTIPLY BY EXCHANGE RATE
            $predefined_amounts[] = round( $amt_usd * $safe_rate );
        }
    }
}

// Fallback amounts if none exist
if ( empty( $predefined_amounts ) ) {
    $predefined_amounts = array( round(50*$safe_rate), round(100*$safe_rate), round(200*$safe_rate) ); 
}

// WooCommerce User Check
if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
    echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
    return;
}
?>

<form name="checkout" method="post" class="checkout woocommerce-checkout" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data">
    
    <div class="custom-unified-checkout">
    
        <div class="checkout-left-col">
            
            <?php wc_print_notices(); ?>
            
            <?php 
            if ( $campaign_id ) : 
                $campaign_title = get_the_title( $campaign_id );
                
                // Image Grabber
                $image_html = '';
                $image_id = get_post_thumbnail_id( $campaign_id );
                if ( ! $image_id ) {
                    $possible_meta_keys = array( 'images', '_images', 'campaign_images', 'growfund_images', '_growfund_images' );
                    foreach ( $possible_meta_keys as $key ) {
                        $meta_data = get_post_meta( $campaign_id, $key, true );
                        if ( ! empty( $meta_data ) ) {
                            $image_id = is_array( $meta_data ) ? $meta_data[0] : explode( ',', $meta_data )[0]; 
                            break; 
                        }
                    }
                }
                if ( ! $image_id ) {
                    $attachments = get_attached_media( 'image', $campaign_id );
                    if ( ! empty( $attachments ) ) {
                        $first_attachment = reset( $attachments );
                        $image_id = $first_attachment->ID;
                    }
                }
    
                if ( $image_id ) {
                    $image_html = wp_get_attachment_image( $image_id, 'large', false, array( 'class' => 'fude-campaign-img', 'style' => 'width:100%; height:auto; aspect-ratio:4/3; object-fit:cover; border-radius:6px;' ) );
                } else {
                    $image_html = '<div class="fude-campaign-img placeholder" style="background:#f0f0f0; width:100%; aspect-ratio:4/3; border-radius:6px; display:flex; align-items:center; justify-content:center; color:#999; font-size:14px; border:1px dashed #ccc;">Campaign Image</div>';
                }
    
                // Stats Query
                global $wpdb;
                $table_name = $wpdb->prefix . 'growfund_donations';
                $stats = $wpdb->get_row( $wpdb->prepare( "
                    SELECT COUNT(id) as total_donors, SUM(amount) as total_raised 
                    FROM {$table_name} 
                    WHERE campaign_id = %d AND status IN ('completed', 'COMPLETED') AND payment_status IN ('paid', 'PAID')
                ", $campaign_id ) );
    
                $total_donors = $stats ? intval( $stats->total_donors ) : 0;
                $total_raised_usd = $stats ? (intval( $stats->total_raised ) / 100) : 0;
                
                // MULTIPLY RAISED BY EXCHANGE RATE
                $total_raised_converted = $total_raised_usd * $safe_rate;
                $formatted_raised = wc_price( $total_raised_converted );
            ?>
            <div class="fude-campaign-summary-wrapper">
                <div class="fude-summary-left">
                    <?php echo $image_html; ?>
                </div>
                <div class="fude-summary-right">
                    <div>
                        <p class="fude-summery-description">Choose your donation amount for</p>
                        <h3 class="fude-summary-title"><?php echo esc_html( $campaign_title ); ?></h3>
                    </div>
                    <div>
                        <div class="fude-summary-stats">
                            <div class="fude-stat-box">
                                <span class="fude-stat-label">Raised:</span>
                                <!-- NEW: The data-raw-usd attribute allows JS to do instant math -->
                                <span class="fude-stat-value" data-raw-usd="<?php echo esc_attr($total_raised_usd); ?>"><?php echo $formatted_raised; ?></span>
                            </div>
                        </div>
    
                        <div class="gfcm-currency-selector-wrap" style="position: relative;">
                            <label style="font-weight: bold; margin-bottom: 5px; display: block;">Choose a donation currency</label>
                            
                            <?php 
                                // Build the data arrays AND include the exchange rate
                                $usd_flag = 'https://upload.wikimedia.org/wikipedia/en/a/a4/Flag_of_the_United_States.svg';
                                $custom_currencies = get_option( 'gfcm_custom_currencies', array() );
                                
                                $options = array(
                                    'USD' => array('name' => 'US Dollar', 'flag' => $usd_flag, 'rate' => 1)
                                );
                                foreach ( $custom_currencies as $code => $data ) {
                                    $options[$code] = array(
                                        'name' => $data['name'], 
                                        'flag' => !empty($data['flag']) ? $data['flag'] : '',
                                        'rate' => !empty($data['rate']) ? floatval($data['rate']) : 1
                                    );
                                }
                                
                                // Get active selection details
                                $active_flag = isset($options[$active_currency]) ? $options[$active_currency]['flag'] : $usd_flag;
                                $active_name = isset($options[$active_currency]) ? $options[$active_currency]['name'] : 'US Dollar';
                                
                                // NEW: Set the visual code for the CURRENTLY selected currency (When dropdown is closed)
                                $active_display_code = ($active_currency === 'SOS') ? 'SLSH' : $active_currency;
                            ?>
                
                            <div id="fude-custom-select" class="fude-select-container">
                                <div class="fude-select-trigger">
                                    <span class="fude-select-selected">
                                        <img src="<?php echo esc_url($active_flag); ?>" class="gfcm-dropdown-flag" />
                                        <span><?php echo esc_html($active_display_code . ' - ' . $active_name); ?></span>
                                    </span>
                                    <span class="fude-select-arrow">▼</span>
                                </div>
                                
                                <ul class="fude-select-options">
                                    <?php foreach ($options as $code => $data) : ?>
                                        <?php 
                                            // 1. Create the visual override for THIS specific list item
                                            $display_code = ($code === 'SOS') ? 'SLSH' : $code; 
                                        ?>
                                        <li data-value="<?php echo esc_attr($code); ?>" data-rate="<?php echo esc_attr($data['rate']); ?>" class="fude-select-option <?php echo ($code === $active_currency) ? 'selected' : ''; ?>">
                                            <img src="<?php echo esc_url($data['flag']); ?>" class="gfcm-dropdown-flag" />
                                            
                                            <span><?php echo esc_html($display_code . ' - ' . $data['name']); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            
                            <input type="hidden" id="custom-currency-dropdown" value="<?php echo esc_attr($active_currency); ?>" />
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
    
            <div class="custom-donation-selector">
                <h3>Enter your donation amount</h3>
                <div class="donation-grid" id="donation-amounts">
                    <?php 
                    foreach ($predefined_amounts as $amount) : 
                        $base_usd = round( floatval($amount) / $safe_rate, 2 );
                    ?>
                        <!-- NEW: data-raw-usd ensures the button math is accurate when switching -->
                        <button type="button" class="donation-btn" data-amount="<?php echo esc_attr(trim($amount)); ?>" data-raw-usd="<?php echo esc_attr($base_usd); ?>">
                            <?php echo esc_html( $currency_symbol . trim($amount) ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                
                <div class="custom-input-currency-wrapper" style="display: flex; gap: 10px;">
                    <input type="number" id="custom-donation-amount" placeholder="Custom Amount" min="1" step="1" />
                </div>
            </div>
    
            <hr>
    
            <div class="custom-tip-selector">
                <h3>Enter tip amount</h3>
                <div class="tip-grid">
                    <button type="button" class="tip-btn" data-tip-percent="2.5">2.5%</button>
                    <button type="button" class="tip-btn" data-tip-percent="5">5%</button>
                    <button type="button" class="tip-btn" data-tip-percent="10">10%</button>
                    <input type="number" id="custom-tip-amount" placeholder="Custom tip amount" min="0" step="1" />
                </div>
            </div>
    
            <hr>
    
            <div class="donor-information">
                <h3>Donor information</h3>
                <div class="woocommerce-billing-fields__field-wrapper" style="display: block !important;">
                    <?php 
                    woocommerce_form_field( 'billing_first_name', array(
                        'type'        => 'text',
                        'class'       => array('form-row-first'),
                        'label'       => __('First name', 'woocommerce'),
                        'placeholder' => __('E.g. Mohamoud', 'woocommerce'),
                        'required'    => true,
                    ), $checkout->get_value( 'billing_first_name' ) );
    
                    woocommerce_form_field( 'billing_last_name', array(
                        'type'        => 'text',
                        'class'       => array('form-row-last'),
                        'label'       => __('Last name', 'woocommerce'),
                        'placeholder' => __('E.g. Ali', 'woocommerce'),
                        'required'    => true,
                    ), $checkout->get_value( 'billing_last_name' ) );
    
                    woocommerce_form_field( 'billing_email', array(
                        'type'        => 'email',
                        'class'       => array('form-row-wide'),
                        'label'       => __('Email address', 'woocommerce'),
                        'placeholder' => __('E.g. ali@gmail.com', 'woocommerce'),
                        'required'    => true,
                    ), $checkout->get_value( 'billing_email' ) );
                    ?>
                </div>
            </div>
    
            <hr>
    
            <div class="payment-gateways-wrap">
                <h3>Payment gateways</h3>
                <div id="payment-custom-wrap" data-rate="<?php echo esc_attr($safe_rate); ?>" data-symbol="<?php echo esc_attr($currency_symbol); ?>">
                    <?php woocommerce_checkout_payment(); ?>
                </div>
            </div>
    
        </div>
    
        <div class="checkout-right-col">
            <h3 id="order_review_heading"><?php esc_html_e( 'Donation Summary', 'woocommerce' ); ?></h3>
            
            <?php do_action( 'woocommerce_checkout_before_order_review' ); ?>
            
            <div id="order_review" class="woocommerce-checkout-review-order">
                <?php do_action( 'woocommerce_checkout_order_review' ); ?>
            </div>
            
            <?php do_action( 'woocommerce_checkout_after_order_review' ); ?>
            <div class="form-row place-order gfcm-dummy-wrapper" style="opacity: 0.5; pointer-events: none; transition: opacity 0.3s ease;">
        
            <div class="fude-anonymous-wrapper">
                <p class="form-row form-row custom-anonymous-checkbox">
                    <span class="woocommerce-input-wrapper">
                        <label class="checkbox woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                            <input type="checkbox" disabled class="input-checkbox"> Don’t display my name publicly on the fundraiser.&nbsp;<span class="optional">(optional)</span>
                        </label>
                    </span>
                </p>
            </div>
    
            <div class="woocommerce-terms-and-conditions-wrapper">
                <p class="form-row validate-required">
                    <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                        <input type="checkbox" disabled class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox">
                        <span class="woocommerce-terms-and-conditions-checkbox-text">I confirm that my donation is voluntary and non-refundable, and I agree to Hiilbox’s Terms & Conditions.</span>&nbsp;<abbr class="required" title="required">*</abbr>
                    </label>
                </p>
            </div>
            
            <div style="height: 65px; margin-bottom: 15px; background: #f9f9f9; border: 1px dashed #ccc; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 13px; color: #888;">
                <span class="spinner-border spinner-border-sm" style="margin-right:8px;" role="status" aria-hidden="true"></span> Loading Security Check...
            </div>
    
            <button type="button" class="button alt" disabled style="cursor: not-allowed; width: 100%;">
                Loading Gateway...
            </button>
        </div>
        </div>
    </div>
</form>

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>