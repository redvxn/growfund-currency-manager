<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'GFCM_PLUGIN_DIR' ) ) {
    define( 'GFCM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'GFCM_PLUGIN_URL' ) ) {
    define( 'GFCM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

add_action( 'admin_init', 'gfcm_extend_database_schema' );
function gfcm_extend_database_schema() {
    global $wpdb;
    $donations_table = $wpdb->prefix . 'growfund_donations';
    
    if($wpdb->get_var("SHOW TABLES LIKE '$donations_table'") == $donations_table) {
        $column_check = $wpdb->get_results("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '$donations_table' AND column_name = 'gateway_fee'");
        if (empty($column_check)) {
            $wpdb->query("ALTER TABLE $donations_table 
                ADD gateway_fee BIGINT DEFAULT 0 NOT NULL,
                ADD platform_fee BIGINT DEFAULT 0 NOT NULL,
                ADD tip_amount BIGINT DEFAULT 0 NOT NULL
            ");
        }
    }
    
    // FIX: Add the missing 'meta' column caught in the debug.log
    $activities_table = $wpdb->prefix . 'growfund_activities';
    if($wpdb->get_var("SHOW TABLES LIKE '$activities_table'") == $activities_table) {
        $meta_check = $wpdb->get_results("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '$activities_table' AND column_name = 'meta'");
        if (empty($meta_check)) {
            $wpdb->query("ALTER TABLE $activities_table ADD meta TEXT NULL");
        }
    }
}

// ==========================================
// 1. ENQUEUE ASSETS & OVERRIDE TEMPLATE
// ==========================================
add_action( 'wp_enqueue_scripts', 'gfcm_enqueue_checkout_assets' );
function gfcm_enqueue_checkout_assets() {
    if ( is_checkout() ) {
        wp_enqueue_style( 'gfcm-checkout-css', GFCM_PLUGIN_URL . 'assets/css/checkout-style.css', array(), '2.0' );
        wp_enqueue_script( 'gfcm-checkout-js', GFCM_PLUGIN_URL . 'assets/js/checkout-script.js', array('jquery', 'wc-checkout'), '2.0', true );
        
        // Grab custom currencies from the database
        $custom_currencies = get_option( 'gfcm_custom_currencies', array() );
        
        // 1. Fetch from Usermeta
        $prefill_amt = '';
        $prefill_tip = '';
        
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $prefill_amt = get_user_meta( $user_id, 'gfcm_mobile_prefill_amt', true );
            $prefill_tip = get_user_meta( $user_id, 'gfcm_mobile_prefill_tip', true );
        } else if ( isset( WC()->session ) ) {
            // Guest fallback
            $customer_id = WC()->session->get_customer_id();
            $prefill_amt = get_transient( 'gfcm_guest_amt_' . $customer_id );
            $prefill_tip = get_transient( 'gfcm_guest_tip_' . $customer_id );
        }

        // 2. Pass everything to Javascript
        wp_localize_script( 'gfcm-checkout-js', 'fude_checkout_params', array(
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'custom_checkout_nonce' ),
            'currencies'  => $custom_currencies,
            'prefill_amt' => $prefill_amt,
            'prefill_tip' => $prefill_tip
        ) );
    }
}

// ==========================================
// FORCE MOBILE PREFILLS INTO WOOCOMMERCE SESSION
// ==========================================
add_action( 'template_redirect', 'gfcm_apply_mobile_prefill_to_session', 9 );

function gfcm_apply_mobile_prefill_to_session() {
    // Only run this on the checkout page (and ignore the 'Thank You' receipt page)
    if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
        
        $prefill_amt = 0;
        $prefill_tip = 0;
        
        // 1. Hunt down the mobile app values from the database
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $prefill_amt = floatval( get_user_meta( $user_id, 'gfcm_mobile_prefill_amt', true ) );
            $prefill_tip = floatval( get_user_meta( $user_id, 'gfcm_mobile_prefill_tip', true ) );
        } else if ( isset( WC()->session ) ) {
            $customer_id = WC()->session->get_customer_id();
            $prefill_amt = floatval( get_transient( 'gfcm_guest_amt_' . $customer_id ) );
            $prefill_tip = floatval( get_transient( 'gfcm_guest_tip_' . $customer_id ) );
        }

        // 2. If mobile values exist, force them directly into the WooCommerce Session!
        if ( $prefill_amt > 0 || $prefill_tip > 0 ) {
            if ( isset( WC()->session ) ) {
                if ( $prefill_amt > 0 ) {
                    WC()->session->set( 'custom_donation_amount', $prefill_amt );
                }
                if ( $prefill_tip > 0 ) {
                    WC()->session->set( 'custom_platform_tip', $prefill_tip );
                }
                
                // Force WooCommerce to recalculate the totals immediately 
                // so the server is ready before the JavaScript even loads!
                if ( isset( WC()->cart ) ) {
                    WC()->cart->calculate_totals();
                }
            }
        }
    }
}

add_filter( 'woocommerce_locate_template', 'gfcm_override_checkout_template', 999, 3 );
function gfcm_override_checkout_template( $template, $template_name, $template_path ) {
    if ( 'checkout/form-checkout.php' === $template_name ) {
        $plugin_template = GFCM_PLUGIN_DIR . 'templates/form-checkout.php';
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }
    }
    return $template;
}

// ==========================================
// 2. BACKEND: DYNAMIC CURRENCY BUILDER
// ==========================================
add_action( 'admin_menu', 'gfcm_currency_menu' );
function gfcm_currency_menu() {
    add_options_page( 'Growfund Currencies', 'Growfund Currencies', 'manage_options', 'gfcm-currencies', 'gfcm_currency_page' );
}

add_action( 'admin_enqueue_scripts', 'gfcm_admin_scripts' );
function gfcm_admin_scripts( $hook ) {
    if ( 'settings_page_gfcm-currencies' === $hook ) {
        wp_enqueue_media(); 
    }
}

function gfcm_currency_page() {
    if ( isset( $_POST['gfcm_save_currencies'] ) && isset( $_POST['gfcm_currencies'] ) ) {
        $clean_currencies = array();
        foreach ( $_POST['gfcm_currencies'] as $curr ) {
            if ( ! empty( $curr['code'] ) && ! empty( $curr['rate'] ) ) {
                $clean_currencies[ strtoupper( sanitize_text_field( $curr['code'] ) ) ] = array(
                    'name' => sanitize_text_field( $curr['name'] ),
                    'code' => strtoupper( sanitize_text_field( $curr['code'] ) ),
                    'rate' => floatval( $curr['rate'] ),
                    'flag' => sanitize_url( $curr['flag'] )
                );
            }
        }
        update_option( 'gfcm_custom_currencies', $clean_currencies );
        echo '<div class="updated"><p>Currencies Successfully Saved!</p></div>';
    }

    $currencies = get_option( 'gfcm_custom_currencies', array() );
    ?>
    <div class="wrap">
        <h2>Growfund Currency Manager</h2>
        <p>Add custom currencies here. <strong>Base Rate: 1 USD.</strong> (e.g., If 1 USD = 8500 SLSH, enter 8500). USD is always active by default.</p>
        <form method="post">
            <table class="widefat striped" id="gfcm-currency-table" style="max-width: 900px; margin-bottom: 20px;">
                <thead>
                    <tr><th>Flag Image</th><th>Currency Code</th><th>Currency Name</th><th>Exchange Rate</th><th>Action</th></tr>
                </thead>
                <tbody id="gfcm-currency-body">
                    <?php 
                    $row = 0;
                    if ( ! empty( $currencies ) ) :
                        foreach ( $currencies as $curr ) : ?>
                            <tr data-row="<?php echo $row; ?>">
                                <td>
                                    <input type="hidden" name="gfcm_currencies[<?php echo $row; ?>][flag]" class="flag-url" value="<?php echo esc_attr( $curr['flag'] ); ?>" />
                                    <img src="<?php echo esc_url( $curr['flag'] ); ?>" class="flag-preview" style="max-width: 40px; height: auto; display: <?php echo $curr['flag'] ? 'block' : 'none'; ?>; margin-bottom: 5px; border-radius:50%; aspect-ratio: 1/1; object-fit: cover;" />
                                    <button type="button" class="button gfcm-upload-flag">Choose Flag</button>
                                </td>
                                <td><input type="text" name="gfcm_currencies[<?php echo $row; ?>][code]" value="<?php echo esc_attr( $curr['code'] ); ?>" required /></td>
                                <td><input type="text" name="gfcm_currencies[<?php echo $row; ?>][name]" value="<?php echo esc_attr( $curr['name'] ); ?>" required /></td>
                                <td><input type="number" step="0.01" name="gfcm_currencies[<?php echo $row; ?>][rate]" value="<?php echo esc_attr( $curr['rate'] ); ?>" required /></td>
                                <td><button type="button" class="button gfcm-remove-row" style="color: red;">Remove</button></td>
                            </tr>
                        <?php $row++; endforeach; 
                    endif; ?>
                </tbody>
            </table>
            <button type="button" class="button button-secondary" id="gfcm-add-row" style="margin-bottom: 20px;">+ Add Currency</button><br>
            <input type="submit" name="gfcm_save_currencies" class="button-primary" value="Save Currencies" />
        </form>
    </div>
    <script>
    jQuery(document).ready(function($){
        let rowCount = <?php echo $row; ?>;
        $('#gfcm-add-row').on('click', function(){
            let html = `<tr data-row="${rowCount}">
                <td><input type="hidden" name="gfcm_currencies[${rowCount}][flag]" class="flag-url" /><img src="" class="flag-preview" style="max-width: 40px; height: auto; display: none; margin-bottom: 5px; border-radius:50%; aspect-ratio: 1/1; object-fit: cover;" /><button type="button" class="button gfcm-upload-flag">Choose Flag</button></td>
                <td><input type="text" name="gfcm_currencies[${rowCount}][code]" placeholder="e.g. SLSH" required /></td>
                <td><input type="text" name="gfcm_currencies[${rowCount}][name]" placeholder="e.g. Somaliland Shilling" required /></td>
                <td><input type="number" step="0.01" name="gfcm_currencies[${rowCount}][rate]" placeholder="8500" required /></td>
                <td><button type="button" class="button gfcm-remove-row" style="color: red;">Remove</button></td>
            </tr>`;
            $('#gfcm-currency-body').append(html);
            rowCount++;
        });
        $(document).on('click', '.gfcm-remove-row', function(){ $(this).closest('tr').remove(); });
        $(document).on('click', '.gfcm-upload-flag', function(e){
            e.preventDefault();
            let button = $(this);
            let custom_uploader = wp.media({title: 'Choose Flag', button: { text: 'Use this Flag' }, multiple: false})
            .on('select', function() {
                let attachment = custom_uploader.state().get('selection').first().toJSON();
                button.siblings('.flag-url').val(attachment.url);
                button.siblings('.flag-preview').attr('src', attachment.url).show();
            }).open();
        });
    });
    </script>
    <?php
}



// ==========================================
// NEW: GROWFUND EXCHANGE & PLATFORM FEES PAGE
// ==========================================
add_action( 'admin_menu', 'gfcm_fees_menu' );
function gfcm_fees_menu() {
    add_options_page( 
        'Growfund Exchange and Platform Fees',
        'Growfund Fees',                      
        'manage_options', 
        'gfcm-fees',                          
        'gfcm_fees_page' 
    );
}

function gfcm_fees_page() {
    if ( isset( $_POST['gfcm_save_sifalo_rates'] ) && isset( $_POST['gfcm_sifalo_rates'] ) ) {
        update_option( 'gfcm_sifalo_rates', $_POST['gfcm_sifalo_rates'] );
        echo '<div class="updated"><p>Exchange and Platform Fees Successfully Saved!</p></div>';
    }

    $sifalo_rates = get_option('gfcm_sifalo_rates', array());
    ?>
    <div class="wrap">
        <h2>Growfund Exchange and Platform Fees</h2>
        <p>Set the gateway and platform fee percentages for each payment method below. These fees will be deducted automatically to calculate the Net Donation in the dashboard.</p>
        <form method="post">
            <table class="widefat striped" style="max-width: 650px; margin-bottom: 20px;">
                <thead>
                    <tr>
                        <th>Payment Method</th>
                        <th>Gateway Fee (%)</th>
                        <th>Platform Fee (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $payment_methods = [
                        'zes_pay'            => 'ZES Pay (ZAAD, EVC, SAHAL)', 
                        'edahab_pay'         => 'Sifalo - eDahab', 
                        'premier_wallet_pay' => 'Sifalo - Premier Wallet', 
                        'card_pay'           => 'Sifalo - Cards',
                        'bacs'               => 'Direct Bank Transfer'
                    ];
                    foreach ($payment_methods as $key => $label): 
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($label); ?></strong></td>
                        <td>
                            <input type="number" step="0.01" name="gfcm_sifalo_rates[<?php echo $key; ?>][gateway]" value="<?php echo esc_attr($sifalo_rates[$key]['gateway'] ?? '0'); ?>" style="width: 120px;" required />
                        </td>
                        <td>
                            <input type="number" step="0.01" name="gfcm_sifalo_rates[<?php echo $key; ?>][platform]" value="<?php echo esc_attr($sifalo_rates[$key]['platform'] ?? '0'); ?>" style="width: 120px;" required />
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <input type="submit" name="gfcm_save_sifalo_rates" class="button-primary" value="Save Fee Rates" />
        </form>
    </div>
    <?php
}

// ==========================================
// 3. WOOCOMMERCE INJECTION & AJAX
// ==========================================

add_filter( 'woocommerce_currencies', 'gfcm_register_currencies' );
function gfcm_register_currencies( $currencies ) {
    $custom_currencies = get_option( 'gfcm_custom_currencies', array() );
    foreach ( $custom_currencies as $code => $data ) { $currencies[$code] = $data['name']; }
    return $currencies;
}

add_filter( 'woocommerce_currency_symbol', 'gfcm_register_symbols', 10, 2 );
function gfcm_register_symbols( $currency_symbol, $currency ) {
    $custom_currencies = get_option( 'gfcm_custom_currencies', array() );
    if ( isset( $custom_currencies[$currency] ) ) { return $currency . ' '; }
    return $currency_symbol;
}

add_filter( 'woocommerce_currency', 'gfcm_override_checkout_currency' );
function gfcm_override_checkout_currency( $currency ) {
    if ( isset( WC()->session ) ) {
        $selected = WC()->session->get( 'gfcm_selected_currency' );
        if ( $selected ) return $selected;
    }
    return $currency;
}

// THE TIP PRODUCT GENERATOR
function gfcm_get_tip_product_id() {
    $tip_id = get_option('gfcm_tip_product_id');
    if ( $tip_id && get_post_type($tip_id) === 'product' ) {
        $product = wc_get_product($tip_id);
        if ( $product && $product->get_name() !== 'Platform Tip' ) {
            $product->set_name('Platform Tip');
            $product->save();
        }
        return $tip_id;
    }
    $product = new WC_Product_Simple();
    $product->set_name('Platform Tip');
    $product->set_status('publish');
    $product->set_catalog_visibility('hidden');
    $product->set_virtual(true);
    $product->set_sold_individually(true);
    $product->set_regular_price('0');
    $product_id = $product->save();
    update_option('gfcm_tip_product_id', $product_id);
    return $product_id;
}

add_action( 'wp_ajax_gfcm_set_currency', 'gfcm_set_currency_ajax' );
add_action( 'wp_ajax_nopriv_gfcm_set_currency', 'gfcm_set_currency_ajax' );
function gfcm_set_currency_ajax() {
    $currency = isset( $_POST['currency'] ) ? sanitize_text_field( $_POST['currency'] ) : 'USD';
    WC()->session->set( 'gfcm_selected_currency', $currency );
    WC()->session->set( 'custom_donation_amount', 0 ); 
    WC()->session->set( 'custom_platform_tip', 0 );
    
    if ( isset( WC()->cart ) ) {
        WC()->cart->calculate_totals(); 
    }
    wp_send_json_success();
}

add_action( 'wp_ajax_gfcm_update_totals', 'gfcm_update_totals_ajax' );
add_action( 'wp_ajax_nopriv_gfcm_update_totals', 'gfcm_update_totals_ajax' );
function gfcm_update_totals_ajax() {
    check_ajax_referer( 'custom_checkout_nonce', 'nonce' );
    if ( isset( $_POST['amount'] ) ) {
        WC()->session->set( 'custom_donation_amount', floatval( $_POST['amount'] ) );
    }
    
    // UPDATED: No longer removes the tip product, just adjusts its value
    if ( isset( $_POST['tip'] ) ) {
        $tip = floatval( $_POST['tip'] );
        WC()->session->set( 'custom_platform_tip', $tip );
        $tip_product_id = gfcm_get_tip_product_id();
        $tip_found = false;
        
        if ( isset( WC()->cart ) ) {
            foreach ( WC()->cart->get_cart() as $key => $item ) {
                if ( $item['product_id'] == $tip_product_id ) {
                    $tip_found = true;
                }
            }
            if ( ! $tip_found ) {
                WC()->cart->add_to_cart( $tip_product_id, 1 );
            }
        }
    }
    
    if ( isset( WC()->cart ) ) { WC()->cart->calculate_totals(); }
    wp_send_json_success();
}

// ==========================================
// 4. WOOCOMMERCE CART LOGIC
// ==========================================

add_action( 'woocommerce_before_calculate_totals', 'gfcm_custom_cart_prices', 999, 1 ); 
function gfcm_custom_cart_prices( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    $donation_amount = WC()->session->get( 'custom_donation_amount' );
    $tip_amount      = WC()->session->get( 'custom_platform_tip' );
    $tip_product_id  = gfcm_get_tip_product_id();

    foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
        if ( $cart_item['product_id'] == $tip_product_id ) {
            $cart_item['data']->set_price( $tip_amount > 0 ? $tip_amount : 0 );
        } else {
            $cart_item['data']->set_price( $donation_amount > 0 ? $donation_amount : 0 );
        }
    }
}

add_filter( 'woocommerce_checkout_redirect_empty_cart', '__return_false' );
add_filter( 'woocommerce_cart_needs_payment', '__return_true', 99 );

add_filter( 'woocommerce_checkout_fields', 'gfcm_safe_simplify_fields', 9999 );
function gfcm_safe_simplify_fields( $fields ) {
    if ( ! isset( $fields['billing'] ) ) $fields['billing'] = array();
    $fields['billing']['billing_first_name'] = array('required' => true);
    $fields['billing']['billing_last_name']  = array('required' => true);
    $fields['billing']['billing_email']      = array('required' => true);
    $fields['billing']['billing_city']       = array('required' => false);
    $fields['billing']['billing_country']    = array('required' => false);

    $fields_to_hide = array( 'billing_company', 'billing_address_1', 'billing_address_2', 'billing_state', 'billing_postcode', 'billing_phone' );
    foreach ( $fields_to_hide as $field ) {
        if ( isset( $fields['billing'][$field] ) ) $fields['billing'][$field]['required'] = false; 
    }
    return $fields;
}

add_filter( 'gettext', 'gfcm_translate_woocommerce_strings', 20, 3 );
function gfcm_translate_woocommerce_strings( $translated_text, $text, $domain ) {
    if ( $text === 'Billing details' || $text === 'Billing Details' ) return 'Donor information';
    if ( $text === 'Product' ) return 'Campaign name';
    return $translated_text;
}

add_filter( 'woocommerce_cart_item_name', 'gfcm_dynamic_campaign_name_in_cart', 10, 3 );
function gfcm_dynamic_campaign_name_in_cart( $name, $cart_item, $cart_item_key ) {
    if ( isset($cart_item['product_id']) && $cart_item['product_id'] == gfcm_get_tip_product_id() ) {
        return 'Platform Tip'; 
    }
    $campaign_id = WC()->session->get( 'custom_fude_campaign_id' );
    if ( $campaign_id ) {
        $title = get_the_title( $campaign_id );
        if ( ! empty( $title ) ) return esc_html( $title );
    }
    return $name;
}

add_action( 'wp', 'gfcm_remove_default_payment_gateway_location' );
function gfcm_remove_default_payment_gateway_location() {
    if ( is_checkout() ) remove_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );
}

add_action( 'template_redirect', 'gfcm_force_clear_tip_on_load', 5 );
function gfcm_force_clear_tip_on_load() {
    if ( is_checkout() && ! is_wc_endpoint_url() && empty( $_POST ) && ! wp_doing_ajax() ) {
        if ( isset( WC()->session ) ) WC()->session->set( 'custom_platform_tip', 0 );
    }
}

// ==========================================
// RENAME 'SUBTOTAL' COLUMN TO 'AMOUNT' (JS OVERRIDE)
// ==========================================
add_action( 'wp_footer', 'gfcm_rename_subtotal_column_js', 99 );
function gfcm_rename_subtotal_column_js() {
    // Only run this on the actual checkout page
    if ( is_checkout() && ! is_wc_endpoint_url() ) {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                function renameSubtotalColumn() {
                    // Surgically targets only the header column, ignoring the footer row
                    $('.woocommerce-checkout-review-order-table thead th.product-total').text('Amount');
                }
                
                // Run on initial page load
                renameSubtotalColumn();
                
                // Run every time WooCommerce recalculates and redraws the table via AJAX
                $(document.body).on('updated_checkout', function() {
                    renameSubtotalColumn();
                });
            });
        </script>
        <?php
    }
}

// ==========================================
// RENAME THE CHECKOUT SUBMIT BUTTON
// ==========================================
add_filter( 'woocommerce_order_button_text', 'gfcm_custom_checkout_button_text' );
function gfcm_custom_checkout_button_text() {
    return __( 'Donate Now', 'woocommerce' );
}

// ==========================================
// 5. THE URL CATCHER & JIT DB INSERTION
// ==========================================
add_action( 'template_redirect', 'gfcm_catch_url_and_populate_cart', 9 );
function gfcm_catch_url_and_populate_cart() {
    if ( is_checkout() && isset( $_GET['campaign_id'] ) ) {
        $campaign_id = intval( $_GET['campaign_id'] );
        $growfund_internal_product_id = 661; // NATIVE PRODUCT FOR CHECKOUT
        
        WC()->session->set( 'custom_fude_campaign_id', $campaign_id );
        if ( isset( WC()->session ) && ! WC()->session->has_session() ) {
            WC()->session->set_customer_session_cookie( true );
        }

        if ( isset( WC()->cart ) ) {
            WC()->cart->empty_cart();
            // Pre-load BOTH the generic donation product AND the Tip product!
            WC()->cart->add_to_cart( $growfund_internal_product_id, 1 );
            
            if ( function_exists('gfcm_get_tip_product_id') ) {
                WC()->cart->add_to_cart( gfcm_get_tip_product_id(), 1 ); 
            }
        }
        
        if ( isset( WC()->session ) ) {
            WC()->session->save_data();
        }
        
        // Use standard redirect, but with a fallback to the native checkout url
        $checkout_url = wc_get_checkout_url();
        if ( empty($checkout_url) ) {
            $checkout_url = home_url( '/checkout/' );
        }
        
        wp_redirect( $checkout_url );
        exit;
    }
}

add_action( 'woocommerce_add_to_cart', 'gfcm_force_session_on_growfund_add', 10, 6 );
function gfcm_force_session_on_growfund_add( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
    $product = wc_get_product( $product_id );
    if ( $product && $product->get_slug() === 'growfund-internal' ) {
        if ( isset( WC()->session ) && ! WC()->session->has_session() ) {
            WC()->session->set_customer_session_cookie( true );
            WC()->session->save_data();
        }
    }
}

add_action( 'template_redirect', 'gfcm_force_skip_cart_page', 10 );
function gfcm_force_skip_cart_page() {
    if ( is_cart() && ! WC()->cart->is_empty() ) {
        wp_redirect( wc_get_checkout_url() );
        exit;
    }
}

// JUST-IN-TIME (JIT) DATABASE INSERTION
add_action( 'woocommerce_checkout_create_order', 'gfcm_create_donation_record_on_checkout', 10, 2 );
function gfcm_create_donation_record_on_checkout( $order, $data ) {
    $campaign_id = WC()->session->get( 'custom_fude_campaign_id' );
    if ( ! $campaign_id ) return;

    global $wpdb;
    $donations_table = $wpdb->prefix . 'growfund_donations';
    $funds_table     = $wpdb->prefix . 'growfund_funds';
    $uid             = wp_generate_uuid4();

    $fund_id = $wpdb->get_var( $wpdb->prepare( "SELECT fund_id FROM $donations_table WHERE campaign_id = %d AND fund_id IS NOT NULL LIMIT 1", $campaign_id ) );
    if ( ! $fund_id ) $fund_id = $wpdb->get_var( "SELECT id FROM $funds_table LIMIT 1" );

    $first_name = $order->get_billing_first_name();
    $last_name  = $order->get_billing_last_name();
    $email      = $order->get_billing_email();
    $user_id    = $order->get_customer_id() ?: null; // Get the user ID from the order

    $wpdb->insert(
        $donations_table,
        array(
            'uid'            => $uid,
            'campaign_id'    => $campaign_id,
            'fund_id'        => $fund_id,
            'amount'         => 0, 
            'payment_engine' => 'woocommerce',
            'payment_status' => 'pending',
            'status'         => 'pending',
            'user_id'        => $user_id,
            'email'          => $email, 
            'user_info'      => wp_json_encode(array(
                'id'              => (string) $user_id, // Cast to string to match Growfund's UserDTO requirements
                'first_name'      => $first_name, 
                'last_name'       => $last_name, 
                'email'           => $email, 
                'billing_address' => array(
                    'city'    => $order->get_billing_city(),
                    'country' => $order->get_billing_country()
                )
            )),
            'created_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' )
        ),
        array( '%s', '%d', '%d', '%f', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
    );

    $contribution_id = $wpdb->insert_id;

    if ( $contribution_id ) {
        $prefix_key = function_exists('growfund_with_prefix') ? growfund_with_prefix('contribution_id') : '_growfund_contribution_id';
        $order->update_meta_data( $prefix_key, $contribution_id );
        $order->update_meta_data( 'contribution_id', $contribution_id );
        $order->update_meta_data( '_fude_custom_campaign_id', $campaign_id );
        $order->update_meta_data( '_gfcm_uid', $uid ); 

        // 3. Attach the ID to the Line Items (Safely skipping the Platform Tip)
        $tip_product_id = gfcm_get_tip_product_id();
        foreach ( $order->get_items() as $item ) {
            if ( $item->get_product_id() == $tip_product_id ) {
                $item->set_name('Platform Tip');
            } else {
                $item->add_meta_data( $prefix_key, $contribution_id, true );
                $item->add_meta_data( 'contribution_id', $contribution_id, true );
                $item->add_meta_data( 'Donation For', get_the_title( $campaign_id ), true );
            }
        }

        // --- NEW: TRIGGER CUSTOM CREATION EMAILS HOOK ---
        do_action( 'gfcm_donation_created', $contribution_id, $campaign_id, $order );
    }
}

// ==========================================
// THE DUAL-LAYER REDIRECT INTERCEPTOR
// ==========================================
add_filter( 'woocommerce_get_return_url', 'gfcm_force_campaign_return_url', 9999, 2 );
function gfcm_force_campaign_return_url( $return_url, $order ) {
    if ( ! $order ) return $return_url;
    
    $campaign_id = $order->get_meta( '_fude_custom_campaign_id' );
    if ( ! $campaign_id ) $campaign_id = WC()->session->get( 'custom_fude_campaign_id' );
    
    if ( $campaign_id ) {
        $campaign_url = get_permalink( $campaign_id );
        
        // Grab the UID securely generated during checkout
        $uid = $order->get_meta( '_gfcm_uid' );

        // Fallback for older orders
        if ( ! $uid ) {
            $contribution_id = $order->get_meta( '_growfund_contribution_id' );
            if ( ! $contribution_id ) $contribution_id = $order->get_meta( 'contribution_id' );

            if ( ! $contribution_id ) {
                foreach ( $order->get_items() as $item ) {
                    $contribution_id = $item->get_meta( '_growfund_contribution_id' );
                    if ( $contribution_id ) break;
                    $contribution_id = $item->get_meta( 'contribution_id' );
                    if ( $contribution_id ) break;
                }
            }

            if ( $contribution_id ) {
                global $wpdb;
                $donations_table = $wpdb->prefix . 'growfund_donations';
                $uid = $wpdb->get_var( $wpdb->prepare( "SELECT uid FROM {$donations_table} WHERE id = %d", $contribution_id ) );
            }
        }

        if ( $uid ) {
            $campaign_url = rtrim($campaign_url, '/'); 
            return $campaign_url . '/?payment=success&uid=' . $uid . '&oid=' . $order->get_id() . '#campaign';
        }
    }
    return $return_url;
}

add_action( 'template_redirect', 'gfcm_catch_order_received_redirect', 1 );
function gfcm_catch_order_received_redirect() {
    if ( is_wc_endpoint_url( 'order-received' ) || isset($_GET['order-received']) ) {
        global $wp;
        $order_id = isset($wp->query_vars['order-received']) ? intval($wp->query_vars['order-received']) : 0;
        if (!$order_id && isset($_GET['order-received'])) $order_id = intval($_GET['order-received']);
        
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $campaign_id = $order->get_meta( '_fude_custom_campaign_id' );
                if ( ! $campaign_id ) $campaign_id = WC()->session->get( 'custom_fude_campaign_id' );

                // Grab the UID securely generated during checkout
                $uid = $order->get_meta( '_gfcm_uid' );

                // Fallback for older orders
                if ( ! $uid ) {
                    $contribution_id = $order->get_meta( '_growfund_contribution_id' );
                    if ( ! $contribution_id ) {
                        foreach ( $order->get_items() as $item ) {
                            $contribution_id = $item->get_meta( '_growfund_contribution_id' );
                            if ( $contribution_id ) break;
                        }
                    }

                    if ( $contribution_id ) {
                        global $wpdb;
                        $donations_table = $wpdb->prefix . 'growfund_donations';
                        $uid = $wpdb->get_var( $wpdb->prepare( "SELECT uid FROM {$donations_table} WHERE id = %d", $contribution_id ) );
                    }
                }

                if ( $campaign_id && $uid ) {
                    $campaign_url = rtrim(get_permalink( $campaign_id ), '/'); 
                    wp_safe_redirect( $campaign_url . '/?payment=success&uid=' . $uid . '&oid=' . $order->get_id() . '#campaign' );
                    exit;
                }
            }
        }
    }
}

// ==========================================
// 6. ANONYMOUS CHECKBOX
// ==========================================
add_action( 'woocommerce_checkout_before_terms_and_conditions', 'gfcm_add_anonymous_donation_checkbox', 10 );
function gfcm_add_anonymous_donation_checkbox() {
    echo '<div class="fude-anonymous-wrapper">';
    woocommerce_form_field( 'growfund_is_anonymous', array(
        'type'          => 'checkbox',
        'class'         => array('form-row custom-anonymous-checkbox'),
        'label_class'   => array('woocommerce-form__label woocommerce-form__label-for-checkbox checkbox'),
        'input_class'   => array('woocommerce-form__input woocommerce-form__input-checkbox input-checkbox'),
        'required'      => false,
        'label'         => __('Don’t display my name publicly on the fundraiser.', 'fude-child'),
    ), WC()->checkout()->get_value( 'growfund_is_anonymous' ) );
    echo '</div>';
}

add_action( 'woocommerce_checkout_create_order', 'gfcm_save_anonymous_checkbox_to_order', 10, 2 );
function gfcm_save_anonymous_checkbox_to_order( $order, $data ) {
    if ( isset( $_POST['growfund_is_anonymous'] ) && ! empty( $_POST['growfund_is_anonymous'] ) ) {
        $order->update_meta_data( 'growfund_is_anonymous', 1 );
        $order->update_meta_data( 'Anonymous Donation', 'Yes' );
    } else {
        $order->update_meta_data( 'growfund_is_anonymous', 0 );
        $order->update_meta_data( 'Anonymous Donation', 'No' );
    }
}

// ==========================================
// 7. DIRECT GROWFUND DATABASE OVERWRITE & DONOR LOGGING
// ==========================================
add_action( 'woocommerce_checkout_order_processed', 'gfcm_correct_growfund_database', 99, 1 );
add_action( 'woocommerce_payment_complete', 'gfcm_correct_growfund_database', 99, 1 );
add_action( 'woocommerce_order_status_processing', 'gfcm_correct_growfund_database', 99, 1 );
add_action( 'woocommerce_order_status_completed', 'gfcm_correct_growfund_database', 99, 1 );
add_action( 'woocommerce_order_status_cancelled', 'gfcm_correct_growfund_database', 99, 1 );
add_action( 'woocommerce_order_status_failed', 'gfcm_correct_growfund_database', 99, 1 );

function gfcm_correct_growfund_database( $order_id ) {
    if ( ! $order_id ) return;
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $contribution_id = $order->get_meta( '_growfund_contribution_id' );
    if ( ! $contribution_id ) {
        foreach ( $order->get_items() as $item ) {
            $contribution_id = $item->get_meta( '_growfund_contribution_id' );
            if ( $contribution_id ) break;
            $contribution_id = $item->get_meta( 'contribution_id' );
            if ( $contribution_id ) break;
        }
    }

    if ( $contribution_id ) {
        $currency = $order->get_currency();
        $order_total = $order->get_total(); 
        if ($order_total <= 0) return;

        // STATUS TRANSLATOR
        $wc_status = $order->get_status();
        $gf_status = 'pending'; 
        $gf_payment_status = 'pending';

        if ( in_array( $wc_status, array('processing', 'completed') ) ) {
            $gf_status = 'completed';
            $gf_payment_status = 'paid';
        } elseif ( $wc_status === 'cancelled' ) {
            $gf_status = 'cancelled';
            $gf_payment_status = 'cancelled';
        } elseif ( $wc_status === 'failed' ) {
            $gf_status = 'failed';
            $gf_payment_status = 'failed';
        }

        $tip_product_id = gfcm_get_tip_product_id();
        $donation_total_local = 0;
        $tip_total_local = 0;

        foreach ( $order->get_items() as $item ) {
            if ( $item->get_product_id() == $tip_product_id ) {
                $tip_total_local += $item->get_total();
            } else {
                $donation_total_local += $item->get_total();
            }
        }

        $exchange_rate = 1;
        if ( $currency !== 'USD' ) {
            $custom_currencies = get_option( 'gfcm_custom_currencies', array() );
            if ( isset( $custom_currencies[$currency] ) ) {
                $exchange_rate = floatval( $custom_currencies[$currency]['rate'] );
            }
        }

        $usd_order_total = ($exchange_rate > 0 && $exchange_rate != 1) ? ($order_total / $exchange_rate) : $order_total;
        $usd_donation    = ($exchange_rate > 0 && $exchange_rate != 1) ? ($donation_total_local / $exchange_rate) : $donation_total_local;
        $usd_tip         = ($exchange_rate > 0 && $exchange_rate != 1) ? ($tip_total_local / $exchange_rate) : $tip_total_local;

        $order_cents    = intval( round( $usd_order_total * 100 ) );
        $donation_cents = intval( round( $usd_donation * 100 ) );
        $tip_cents      = intval( round( $usd_tip * 100 ) );

        $real_user_info = wp_json_encode(array(
            'id'              => (string) $order->get_customer_id(), // Cast to string for Growfund's UserDTO
            'first_name'      => $order->get_billing_first_name(),
            'last_name'       => $order->get_billing_last_name(),
            'email'           => $order->get_billing_email(),
            'billing_address' => array( 
                'city'    => $order->get_billing_city(), 
                'country' => $order->get_billing_country() 
            )
        ));

        $transaction_id = $order->get_transaction_id();
        if ( empty($transaction_id) ) $transaction_id = (string) $order->get_id();

        $wc_pm_slug = $order->get_payment_method();
        $pm_json = wp_json_encode(array(
            'name'        => $wc_pm_slug,
            'label'       => $order->get_payment_method_title(),
            'logo'        => null,
            'type'        => 'online-payment',
            'instruction' => null
        ));

        $is_anonymous = $order->get_meta('growfund_is_anonymous') ? 1 : 0;

        $sifalo_rates = get_option('gfcm_sifalo_rates', array());
        $gateway_pct  = isset($sifalo_rates[$wc_pm_slug]['gateway']) ? floatval($sifalo_rates[$wc_pm_slug]['gateway']) : 0;
        $platform_pct = isset($sifalo_rates[$wc_pm_slug]['platform']) ? floatval($sifalo_rates[$wc_pm_slug]['platform']) : 0;

        // --- THE MATH FIX ---
        $gateway_fee_donation = intval( round( $donation_cents * ( $gateway_pct / 100 ) ) );
        $gateway_fee_tip      = intval( round( $tip_cents * ( $gateway_pct / 100 ) ) );
        
        $total_gateway_fee    = $gateway_fee_donation + $gateway_fee_tip; // Grand Total for Display
        $platform_fee         = intval( round( $donation_cents * ( $platform_pct / 100 ) ) );
        
        $net_tip_amount = $tip_cents - $gateway_fee_tip;
        if ($net_tip_amount < 0) $net_tip_amount = 0; 
        
        $total_campaign_fees = $gateway_fee_donation + $platform_fee; // Only what hits the Campaign

        global $wpdb;
        $donations_table = $wpdb->prefix . 'growfund_donations';
        $activities_table = $wpdb->prefix . 'growfund_activities';

        $gate_usd = number_format($total_gateway_fee / 100, 2);
        $plat_usd = number_format($platform_fee / 100, 2);
        $tip_usd  = number_format($net_tip_amount / 100, 2);
        $fee_string = " | Total Gateway Fee: $$gate_usd | Platform Fee: $$plat_usd | Net Tip: $$tip_usd";

        // Grab email safely
        $billing_email = $order->get_billing_email();

        // UPDATE DATABASE
        $wpdb->query($wpdb->prepare("
            UPDATE {$donations_table} 
            SET amount = %d, 
                user_info = %s,
                email = %s, 
                gateway_fee = %d, 
                platform_fee = %d,
                tip_amount = %d,
                processing_fee = %d,
                payment_status = %s,
                status = %s,
                payment_method = %s,
                transaction_id = %s,
                is_anonymous = %d,
                notes = CONCAT(IFNULL(notes, ''), %s)
            WHERE id = %d
        ", 
        $donation_cents, 
        $real_user_info, 
        $billing_email, 
        $total_gateway_fee, 
        $platform_fee, 
        $net_tip_amount,       
        $total_campaign_fees, 
        $gf_payment_status, 
        $gf_status, 
        $pm_json,
        $transaction_id,
        $is_anonymous,
        $fee_string, 
        $contribution_id));

        $campaign_id = $order->get_meta('_fude_custom_campaign_id');
        if ( ! $campaign_id ) {
            $campaign_id = $wpdb->get_var($wpdb->prepare("SELECT campaign_id FROM {$donations_table} WHERE id = %d", $contribution_id));
        }

        $activity_id = $order->get_meta('_gfcm_activity_id');
        $meta_data = wp_json_encode(array(
            'donation_amount' => $donation_cents,
            'donation_id'     => $contribution_id
        ));

        if ( $activity_id ) {
            $wpdb->update($activities_table, array('meta' => $meta_data), array('id' => $activity_id));
        } elseif ( $gf_status === 'completed' ) {
            $user_id = $order->get_customer_id() ?: null;
            $wpdb->insert(
                $activities_table,
                array(
                    'type'        => 'donation-created',
                    'campaign_id' => $campaign_id,
                    'user_id'     => $user_id,
                    'meta'        => $meta_data,
                    'status'      => 1,
                    'created_by'  => $user_id ?: 1,
                    'created_at'  => current_time('mysql')
                ),
                array('%s', '%d', '%d', '%s', '%d', '%d', '%s')
            );
            $order->update_meta_data('_gfcm_activity_id', $wpdb->insert_id);
            $order->save();
        }

        // --- NEW: TRIGGER CUSTOM STATUS EMAILS HOOK ---
        do_action( 'gfcm_donation_status_updated', $contribution_id, $campaign_id, $order, $gf_status, $wc_status );
    }
}

// ==========================================
// 8. CUSTOM PAYMENT SUCCESS POPUP (Replaces Native React Modal)
// ==========================================
add_action( 'wp_footer', 'gfcm_custom_success_popup' );
function gfcm_custom_success_popup() {
    if ( !isset($_GET['payment']) || $_GET['payment'] !== 'success' || empty($_GET['uid']) ) return;

    global $wpdb;
    $uid = sanitize_text_field( $_GET['uid'] );
    $donations_table = $wpdb->prefix . 'growfund_donations';
    $donation = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$donations_table} WHERE uid = %s", $uid ) );

    if ( ! $donation ) return;

    $ref_number = !empty($donation->id) ? $donation->id : (!empty($donation->ID) ? $donation->ID : '');

    // Bulletproof lookup: Uses the EXACT order_id passed in the URL to avoid fallback errors
    $order_id = isset($_GET['oid']) ? intval($_GET['oid']) : 0;
    $order = $order_id ? wc_get_order( $order_id ) : null;

    $pdf_link = site_url( '/public/#donations/' . esc_attr($uid) . '/receipt' );

    if ( $order ) {
        $currency       = $order->get_currency();
        $payment_method = $order->get_payment_method_title();
        $payment_time   = $order->get_date_created()->date_i18n( 'M j, Y, h:i A' );
        $sender_name    = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        
        $exchange_rate = 1;
        if ( $currency !== 'USD' ) {
            $custom_currencies = get_option( 'gfcm_custom_currencies', array() );
            if ( isset( $custom_currencies[$currency] ) ) {
                $exchange_rate = floatval( $custom_currencies[$currency]['rate'] );
            }
        }

        $tip_product_id = gfcm_get_tip_product_id();
        $donation_val   = 0;
        $tip_val        = 0;

        foreach ( $order->get_items() as $item ) {
            if ( $item->get_product_id() == $tip_product_id ) {
                $tip_val += $item->get_total();
            } else {
                $donation_val += $item->get_total();
            }
        }
        $total_val = $order->get_total();

        // Dual-Currency Formatter 
        $format_dual_currency = function($local_val) use ($currency, $exchange_rate) {
            $local_str = wc_price( $local_val, array( 'currency' => $currency ) );
            if ( $currency !== 'USD' && $exchange_rate > 0 && $exchange_rate != 1 ) {
                $usd_val = $local_val / $exchange_rate;
                $usd_str = wp_strip_all_tags( wc_price( $usd_val, array( 'currency' => 'USD' ) ) );
                return $local_str . ' <span style="font-size:12px; font-weight:normal; color:#888; margin-left:6px;">(' . $usd_str . ')</span>';
            }
            return $local_str;
        };

        $donation_formatted = $format_dual_currency($donation_val);
        $tip_formatted      = $format_dual_currency($tip_val);
        $total_formatted    = $format_dual_currency($total_val);

    } else {
        $payment_method = 'Online Payment';
        $user_info      = json_decode( $donation->user_info, true );
        $sender_name    = isset($user_info['first_name']) ? $user_info['first_name'] . ' ' . $user_info['last_name'] : 'Guest';
        $payment_time   = date_i18n( 'M j, Y, h:i A', strtotime( $donation->created_at ) );
        
        $donation_val = $donation->amount / 100;
        $tip_val = 0;
        if ( preg_match('/Tip:\s\$([0-9.]+)/', $donation->notes, $matches) ) {
            $tip_val = floatval($matches[1]);
        }
        $total_val = $donation_val + $tip_val;

        $donation_formatted = '$' . number_format( $donation_val, 2 );
        $tip_formatted      = '$' . number_format( $tip_val, 2 );
        $total_formatted    = '$' . number_format( $total_val, 2 );
    }

    ?>
    <style>
        #growfund-root .growfund-modal,
        #growfund-root .growfund-modal-overlay,
        div[class*="growfund-modal"] {
            display: none !important;
            opacity: 0 !important;
            visibility: hidden !important;
        }

        .gfcm-popup-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 999999;
            display: flex; align-items: center; justify-content: center;
            opacity: 0; animation: gfcmFadeIn 0.3s forwards; backdrop-filter: blur(2px);
        }

        .gfcm-popup-card {
            background: #ffffff; width: 100%; max-width: 440px; border-radius: 12px 12px 0 0;
            position: relative; padding: 40px 30px 25px; text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2); transform: translateY(20px);
            animation: gfcmSlideUp 0.3s forwards 0.1s; font-family: inherit; margin: 20px;
        }

        .gfcm-popup-card::after {
            content: ""; position: absolute; left: 0; right: 0; bottom: -10px; height: 10px;
            background-size: 20px 20px; background-image: radial-gradient(circle at 10px 0, #ffffff 10px, transparent 11px);
        }

        .gfcm-popup-icon {
            position: absolute; top: -25px; left: 50%; transform: translateX(-50%);
            width: 50px; height: 50px; background: #20c997; border-radius: 50%;
            border: 4px solid #ffffff; display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 10px rgba(32,201,151,0.3);
        }

        .gfcm-popup-icon svg { width: 22px; height: 22px; fill: none; stroke: #ffffff; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }
        .gfcm-popup-card h2 { font-size: 22px; font-weight: 700; color: #2b2b2b; margin: 10px 0 5px; }
        .gfcm-popup-card p { font-size: 13px; color: #666666; margin: 0 0 20px; }

        .gfcm-popup-breakdown { background: #f9f9f9; border-radius: 8px; padding: 15px 20px; margin-bottom: 25px; }
        .gfcm-breakdown-row { display: flex; justify-content: space-between; align-items: center; font-size: 14px; color: #555555; margin-bottom: 8px; text-align: left; }
        .gfcm-breakdown-row:last-child { margin-bottom: 0; }
        
        .gfcm-breakdown-row strong { color: #2b2b2b; font-size: 13px; text-align: right; padding-left: 10px; }
        .gfcm-breakdown-total { border-top: 1px dashed #e0e0e0; padding-top: 10px; margin-top: 10px; font-size: 14px; font-weight: 700; color: #2b2b2b; align-items: flex-end; }
        .gfcm-breakdown-total span:last-child { font-size: 14px; color: #20c997; text-align: right; }

        .gfcm-popup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px 15px; text-align: left; margin: 0 0 30px; padding: 20px 0; border-top: 1px solid #eaeaea; border-bottom: 1px solid #eaeaea; }
        .gfcm-popup-grid div { display: flex; flex-direction: column; }
        .gfcm-popup-grid small { font-size: 11px; color: #999999; margin-bottom: 3px; }
        .gfcm-popup-grid b { font-size: 13px; color: #333333; font-weight: 600; word-break: break-word; }

        .gfcm-popup-action { margin-top: 20px; }
        .gfcm-popup-action a { display: inline-flex; align-items: center; gap: 8px; color: #555555; text-decoration: none; font-size: 14px; font-weight: 600; transition: color 0.2s; }
        .gfcm-popup-action a:hover { color: #20c997; }
        .gfcm-popup-action svg { width: 16px; height: 16px; fill: currentColor; }

        @keyframes gfcmFadeIn { to { opacity: 1; } }
        @keyframes gfcmSlideUp { to { transform: translateY(0); opacity: 1; } }
    </style>

    <div class="gfcm-popup-overlay" id="gfcm-success-overlay">
        <div class="gfcm-popup-card">
            <div class="gfcm-popup-icon"><svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"></path></svg></div>
            <h2>Payment Success!</h2>
            <p>Your payment has been successfully done.</p>
            
            <div class="gfcm-popup-breakdown">
                <div class="gfcm-breakdown-row">
                    <span>Donation amount</span>
                    <strong><?php echo wp_kses_post( $donation_formatted ); ?></strong>
                </div>
                <div class="gfcm-breakdown-row">
                    <span>Platform Tip</span>
                    <strong><?php echo wp_kses_post( $tip_formatted ); ?></strong>
                </div>
                <div class="gfcm-breakdown-row gfcm-breakdown-total">
                    <span>Total Amount</span>
                    <span><?php echo wp_kses_post( $total_formatted ); ?></span>
                </div>
            </div>
            
            <div class="gfcm-popup-grid">
                <div><small>Ref Number</small><b>#<?php echo esc_html( $ref_number ); ?></b></div>
                <div><small>Payment Time</small><b><?php echo esc_html( $payment_time ); ?></b></div>
                <div><small>Payment Method</small><b><?php echo esc_html( $payment_method ); ?></b></div>
                <div><small>Sender Name</small><b><?php echo esc_html( $sender_name ); ?></b></div>
            </div>
            
            <div class="gfcm-popup-action">
                <a href="<?php echo esc_url( $pdf_link ); ?>" target="_blank" id="gfcm-pdf-link">
                    <svg viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"></path></svg>
                    Get PDF Receipt
                </a>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            var overlay = document.getElementById('gfcm-success-overlay');
            if (overlay) {
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) {
                        overlay.style.display = 'none';
                        var newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                        window.history.replaceState({path:newUrl}, '', newUrl);
                    }
                });
            }
        });
    </script>
    <?php
}

// ==========================================
// 10. CUSTOM WOOCOMMERCE ORDERS TABLE COLUMNS
// ==========================================

add_filter( 'manage_edit-shop_order_columns', 'gfcm_add_custom_wc_order_columns', 20 );
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'gfcm_add_custom_wc_order_columns', 20 );
function gfcm_add_custom_wc_order_columns( $columns ) {
    $new_columns = array();
    foreach ( $columns as $key => $name ) {
        $new_columns[$key] = $name;
        if ( 'order_total' === $key ) {
            $new_columns['original_donation'] = 'Original Amount';
            $new_columns['gfcm_donation']     = 'Donation Amount'; 
            $new_columns['gfcm_net']          = 'Net Amount';
            $new_columns['gfcm_gateway']      = 'Gateway Fee';
            $new_columns['gfcm_platform']     = 'Platform Fee';
            $new_columns['gfcm_fees']         = 'Processing Fees';
            $new_columns['gfcm_tip']          = 'Platform Tip';
        }
    }
    return $new_columns;
}

add_action( 'manage_shop_order_posts_custom_column', 'gfcm_render_custom_wc_order_columns_legacy', 10, 2 );
function gfcm_render_custom_wc_order_columns_legacy( $column, $post_id ) {
    $order = wc_get_order( $post_id );
    gfcm_render_custom_wc_columns_html( $column, $order );
}

add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'gfcm_render_custom_wc_order_columns_hpos', 10, 2 );
function gfcm_render_custom_wc_order_columns_hpos( $column, $order ) {
    gfcm_render_custom_wc_columns_html( $column, $order );
}

function gfcm_render_custom_wc_columns_html( $column, $order ) {
    if ( ! $order ) return;

    if ( 'original_donation' === $column ) {
        $currency = $order->get_currency();
        if ( $currency !== 'USD' ) {
            echo wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $currency ) ) );
        } else {
            echo '<span style="color:#999;">-</span>'; 
        }
        return;
    }

    $gfcm_columns = array('gfcm_donation', 'gfcm_net', 'gfcm_gateway', 'gfcm_platform', 'gfcm_fees', 'gfcm_tip');
    
    if ( in_array( $column, $gfcm_columns ) ) {
        $contribution_id = $order->get_meta( '_growfund_contribution_id' );
        if ( ! $contribution_id ) {
            foreach ( $order->get_items() as $item ) {
                $contribution_id = $item->get_meta( '_growfund_contribution_id' );
                if ( $contribution_id ) break;
                $contribution_id = $item->get_meta( 'contribution_id' );
                if ( $contribution_id ) break;
            }
        }

        if ( $contribution_id ) {
            global $wpdb;
            $donations_table = $wpdb->prefix . 'growfund_donations';
            
            // FETCH PROCESSING FEE DIRECTLY
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT amount, gateway_fee, platform_fee, tip_amount, processing_fee FROM {$donations_table} WHERE id = %d", 
                $contribution_id
            ));
            
            if ( $row ) {
                $gateway_usd    = $row->gateway_fee / 100;
                $platform_usd   = $row->platform_fee / 100;
                $processing_usd = $row->processing_fee / 100; 
                $tip_usd        = $row->tip_amount / 100;
                $amount_usd     = $row->amount / 100;
                
                // Net USD calculation uses ONLY the Campaign's Processing Fee
                $net_usd = $amount_usd - $processing_usd;

                // NEW: Platform Tip minus Gateway Fee calculation
                // Only subtract the gateway fee if a tip was provided. max() prevents negative numbers.
                $adjusted_tip_usd = ( $tip_usd > 0 ) ? max( 0, $tip_usd - $gateway_usd ) : 0;

                if ( 'gfcm_donation' === $column ) {
                    echo wp_kses_post( wc_price( $amount_usd, array( 'currency' => 'USD' ) ) );
                } elseif ( 'gfcm_net' === $column ) {
                    echo '<strong style="color: #00a32a;">' . wp_kses_post( wc_price( $net_usd, array( 'currency' => 'USD' ) ) ) . '</strong>';
                } elseif ( 'gfcm_gateway' === $column ) {
                    echo wp_kses_post( wc_price( $gateway_usd, array( 'currency' => 'USD' ) ) );
                } elseif ( 'gfcm_platform' === $column ) {
                    echo wp_kses_post( wc_price( $platform_usd, array( 'currency' => 'USD' ) ) );
                } elseif ( 'gfcm_fees' === $column ) {
                    echo wp_kses_post( wc_price( $processing_usd, array( 'currency' => 'USD' ) ) );
                } elseif ( 'gfcm_tip' === $column ) {
                    // Display the adjusted tip instead of the raw tip
                    echo wp_kses_post( wc_price( $adjusted_tip_usd, array( 'currency' => 'USD' ) ) );
                }
                return;
            }
        }
        echo '<span style="color:#999;">' . wc_price(0, array('currency' => 'USD')) . '</span>';
    }
}

// ==========================================
// 11. FORCE 'TOTAL' COLUMN TO DISPLAY USD
// ==========================================
add_filter( 'woocommerce_get_formatted_order_total', 'gfcm_display_usd_total_in_admin', 10, 4 );
function gfcm_display_usd_total_in_admin( $formatted_total, $order, $tax_display, $display_refunded ) {
    if ( ! is_admin() ) return $formatted_total;
    
    $currency = $order->get_currency();
    if ( $currency === 'USD' ) return $formatted_total;

    $custom_currencies = get_option( 'gfcm_custom_currencies', array() );
    $exchange_rate = 1;
    if ( isset( $custom_currencies[$currency] ) ) {
        $exchange_rate = floatval( $custom_currencies[$currency]['rate'] );
    }

    if ( $exchange_rate > 0 && $exchange_rate != 1 ) {
        $total = $order->get_total();
        $usd_total = $total / $exchange_rate;
        return wc_price( $usd_total, array( 'currency' => 'USD' ) );
    }
    return $formatted_total;
}

// ==========================================
// 13. CUSTOM CHECKBOX & PRIVACY POLICY TEXT
// ==========================================
add_filter( 'woocommerce_get_privacy_policy_text', 'gfcm_custom_privacy_policy_text' );
function gfcm_custom_privacy_policy_text( $text ) {
    return ''; 
}

add_filter( 'woocommerce_get_terms_and_conditions_checkbox_text', 'gfcm_custom_terms_and_conditions_text' );
function gfcm_custom_terms_and_conditions_text( $text ) {
    $site_name = get_bloginfo( 'name' );
    $terms_url = wc_get_page_permalink( 'terms' );
    return sprintf(
        'I confirm that my donation is voluntary and non-refundable, and I agree to %s’s <a href="%s" target="_blank" style="text-decoration: underline;">Terms & Conditions</a>.',
        esc_html( $site_name ),
        esc_url( $terms_url )
    );
}

// ==========================================
// 14. DASHBOARD: AJAX DOM INJECTION (React-Safe Columns)
// ==========================================

add_action('wp_ajax_gfcm_get_row_fees', 'gfcm_get_row_fees_ajax');
add_action('wp_ajax_nopriv_gfcm_get_row_fees', 'gfcm_get_row_fees_ajax'); 
function gfcm_get_row_fees_ajax() {
    $user = wp_get_current_user();
    $is_admin = current_user_can('manage_options') || in_array('administrator', (array) $user->roles);
    $is_fundraiser = in_array('growfund_fundraiser', (array) $user->roles);

    if (!$is_admin && !$is_fundraiser) {
        wp_send_json_error(array('message' => 'Unauthorized'));
        return;
    }

    if (!isset($_POST['ids']) || !is_array($_POST['ids'])) {
        wp_send_json_error(array('message' => 'No IDs provided'));
        return;
    }

    global $wpdb;
    $donations_table = $wpdb->prefix . 'growfund_donations';
    $ids = array_map('intval', $_POST['ids']);
    $id_list = implode(',', $ids);

    // FETCH PROCESSING FEE
    $results = $wpdb->get_results("SELECT id, amount, gateway_fee, platform_fee, tip_amount, processing_fee FROM {$donations_table} WHERE id IN ($id_list)");

    $response = array();
    foreach ($results as $row) {
        // Safe Net Math
        $net_amount = $row->amount - $row->processing_fee;

        $response[$row->id] = array(
            'gross'    => '$' . number_format($row->amount / 100, 2),
            'net'      => '$' . number_format($net_amount / 100, 2),
            'gateway'  => '$' . number_format($row->gateway_fee / 100, 2),
            'platform' => '$' . number_format($row->platform_fee / 100, 2),
            'total'    => '$' . number_format($row->processing_fee / 100, 2),
            'tip'      => '$' . number_format($row->tip_amount / 100, 2)
        );
    }
    wp_send_json_success($response);
}

// ... The Javascript block below remains exactly the same! ...

add_action('admin_print_scripts', 'gfcm_force_draw_html_columns_ajax', 100);
add_action('wp_footer', 'gfcm_force_draw_html_columns_ajax', 100);
function gfcm_force_draw_html_columns_ajax() {
    $user = wp_get_current_user();
    $is_admin = current_user_can('manage_options') || in_array('administrator', (array) $user->roles);
    $is_fundraiser = in_array('growfund_fundraiser', (array) $user->roles);

    if (!$is_admin && !$is_fundraiser) return;

    if ( is_admin() ) {
        if ( !function_exists('get_current_screen') ) return;
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'growfund' ) === false ) return;
    }

    $isAdminJs = $is_admin ? 'true' : 'false';
    ?>
    <script type="text/javascript">
        window.gfcmFeeData = window.gfcmFeeData || {};
        window.gfcmFetching = window.gfcmFetching || false;
        const gfcmAjaxUrl = "<?php echo admin_url('admin-ajax.php'); ?>";
        const gfcmIsAdmin = <?php echo $isAdminJs; ?>;

        // --- LAYER 1 PROTECTION: FETCH INTERCEPTOR ---
        // Instantly strips our custom columns the millisecond a filter is applied, 
        // ensuring React has a clean, native table to rebuild without getting confused.
        const gfcmOriginalFetch = window.fetch;
        window.fetch = async function(...args) {
            const url = args[0] && typeof args[0] === 'string' ? args[0] : (args[0] && args[0].url ? args[0].url : '');
            
            if (url.indexOf('/growfund/v1/donations') !== -1) {
                document.querySelectorAll('.gfcm-custom-col').forEach(el => el.remove());
                document.querySelectorAll('tr').forEach(tr => tr.removeAttribute('data-gfcm-loaded'));
            }

            const response = await gfcmOriginalFetch.apply(this, args);
            
            if (url.indexOf('/growfund/v1/donations') !== -1) {
                response.clone().json().then(data => {
                    if (data && data.items) {
                        data.items.forEach(item => {
                            if (item.id && item.gfcm_fees) window.gfcmFeeData[item.id] = item.gfcm_fees;
                        });
                    }
                }).catch(e => {});
            }
            return response;
        };

        function gfcmDrawDonationColumns() {
            if (window.location.hash.indexOf('/donations') === -1) return;

            const table = document.querySelector('table');
            if (!table) return;

            table.style.width = 'max-content';
            table.style.minWidth = '100%';
            if (table.parentElement) table.parentElement.style.overflowX = 'auto';

            const thead = table.querySelector('thead tr');
            if (!thead) return;

            // --- LAYER 2 PROTECTION: DOM SANITY CHECK ---
            // If React somehow shuffled our columns to the left or middle of the table,
            // this detects the shift, destroys the columns, and triggers a clean rebuild.
            let headerCustomCols = Array.from(thead.querySelectorAll('.gfcm-injected-headers'));
            if (headerCustomCols.length > 0 && !thead.lastElementChild.classList.contains('gfcm-injected-headers')) {
                headerCustomCols.forEach(col => col.remove());
            }

            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                let customCols = Array.from(row.querySelectorAll('.gfcm-custom-col'));
                if (customCols.length > 0 && !row.lastElementChild.classList.contains('gfcm-custom-col')) {
                    customCols.forEach(col => col.remove());
                    row.removeAttribute('data-gfcm-loaded');
                }
            });

            // ---------------------------------------------
            // SAFE INJECTION LOGIC (Always appends to the far end)
            // ---------------------------------------------
            if (gfcmIsAdmin && !thead.querySelector('.gfcm-injected-headers')) {
                const feeHeaders = ['Net Amount', 'Gateway Fee', 'Platform Fee', 'Tip'].map(text => 
                    `<th class="gfcm-custom-col gfcm-injected-headers growfund-h-10 growfund-px-2 growfund-text-left growfund-align-middle growfund-typo-small growfund-font-medium growfund-text-fg-muted" style="width: 120px; min-width: 120px;"><div class="growfund-flex growfund-items-center growfund-gap-2 hover:growfund-no-underline growfund-px-0 growfund-typo-tiny growfund-text-fg-secondary">${text}</div></th>`
                ).join('');
                thead.insertAdjacentHTML('beforeend', feeHeaders);
            }

            let missingIds = [];

            rows.forEach(row => {
                let idCell = Array.from(row.children).find(td => td.innerText.trim().startsWith('#'));
                if (!idCell) return;
                let id = idCell.innerText.trim().replace('#', '');

                if (gfcmIsAdmin && !row.querySelector('.gfcm-net-col')) {
                    const feeCells = ['net', 'gateway', 'platform', 'tip'].map(key => 
                        `<td class="gfcm-custom-col gfcm-net-col growfund-p-2 growfund-align-middle growfund-typo-small growfund-text-fg-primary growfund-typo-tiny group-hover/row:growfund-bg-background-surface-secondary" style="width: 120px; min-width: 120px;"><div><span class="growfund-font-medium growfund-text-fg-primary gfcm-val-${key}">...</span></div></td>`
                    ).join('');
                    row.insertAdjacentHTML('beforeend', feeCells);
                    row.removeAttribute('data-gfcm-loaded');
                }

                if (window.gfcmFeeData[id] && !row.getAttribute('data-gfcm-loaded')) {
                    let fees = window.gfcmFeeData[id];
                    
                    if (gfcmIsAdmin) {
                        let netEl = row.querySelector('.gfcm-val-net'); 
                        if (netEl) { netEl.innerText = fees.net; netEl.style.color = '#00a32a'; }
                        
                        let gwEl = row.querySelector('.gfcm-val-gateway'); if (gwEl) gwEl.innerText = fees.gateway;
                        let plEl = row.querySelector('.gfcm-val-platform'); if (plEl) plEl.innerText = fees.platform;
                        let tipEl = row.querySelector('.gfcm-val-tip'); if (tipEl) tipEl.innerText = fees.tip;
                    }
                    
                    row.setAttribute('data-gfcm-loaded', 'true');
                } else if (!window.gfcmFeeData[id]) {
                    if (!missingIds.includes(id)) missingIds.push(id);
                }
            });

            if (missingIds.length > 0 && !window.gfcmFetching) {
                window.gfcmFetching = true;
                jQuery.post(gfcmAjaxUrl, {
                    action: 'gfcm_get_row_fees',
                    ids: missingIds
                }, function(response) {
                    if (response.success) {
                        Object.keys(response.data).forEach(id => {
                            window.gfcmFeeData[id] = response.data[id];
                        });
                    }
                    window.gfcmFetching = false;
                }).fail(() => { window.gfcmFetching = false; });
            }
        }

        setInterval(gfcmDrawDonationColumns, 150);
        window.addEventListener('hashchange', function() { window.gfcmFeeData = {}; });
    </script>
    <?php
}


// ==========================================
// 15. ASYNC WOOCOMMERCE PRODUCT SWAP ENGINE (CRON & MANUAL)
// ==========================================

function gfcm_get_or_create_campaign_product( $campaign_id ) {
    $product_id = get_post_meta( $campaign_id, '_gfcm_linked_product_id', true );

    if ( $product_id && 'product' === get_post_type( $product_id ) ) {
        $current_product = wc_get_product( $product_id );
        $campaign_title = get_the_title( $campaign_id );
        if ( $current_product && $current_product->get_name() !== $campaign_title ) {
            $current_product->set_name( $campaign_title );
            $current_product->save();
        }
        return $product_id;
    }

    $campaign_title = get_the_title( $campaign_id );
    $product = new WC_Product_Simple();
    $product->set_name( $campaign_title );
    $product->set_status( 'publish' );
    $product->set_catalog_visibility( 'hidden' ); 
    $product->set_virtual( true );
    $product->set_sold_individually( false );
    $product->set_regular_price( '0' ); 
    $product->add_meta_data( '_is_gfcm_donation_product', 'yes', true );
    
    $product_id = $product->save();
    update_post_meta( $campaign_id, '_gfcm_linked_product_id', $product_id );

    return $product_id;
}

add_action( 'woocommerce_checkout_order_processed', 'gfcm_ensure_campaign_product_exists_early', 10, 1 );
function gfcm_ensure_campaign_product_exists_early( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    
    $campaign_id = $order->get_meta( '_fude_custom_campaign_id' );
    if ( ! $campaign_id && isset( WC()->session ) ) {
        $campaign_id = WC()->session->get( 'custom_fude_campaign_id' );
    }

    if ( $campaign_id && get_post_status( $campaign_id ) ) {
        gfcm_get_or_create_campaign_product( $campaign_id );
    }
}

function gfcm_perform_order_product_swap( $order_id, $force = false ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return false;

    if ( ! $force && $order->get_meta( '_gfcm_product_swapped' ) === 'yes' ) {
        return false;
    }

    if ( ! $order->has_status( 'completed' ) ) {
        return false;
    }

    $campaign_id = $order->get_meta( '_fude_custom_campaign_id' );

    if ( ! $campaign_id ) {
        $contribution_id = $order->get_meta( '_growfund_contribution_id' );
        if ( ! $contribution_id ) {
            foreach ( $order->get_items() as $item ) {
                $contribution_id = $item->get_meta( '_growfund_contribution_id' );
                if ( $contribution_id ) break;
                $contribution_id = $item->get_meta( 'contribution_id' );
                if ( $contribution_id ) break;
            }
        }

        if ( $contribution_id ) {
            global $wpdb;
            $donations_table = $wpdb->prefix . 'growfund_donations';
            $campaign_id = $wpdb->get_var( $wpdb->prepare( "SELECT campaign_id FROM {$donations_table} WHERE id = %d", $contribution_id ) );
            
            if ( $campaign_id ) {
                $order->update_meta_data( '_fude_custom_campaign_id', $campaign_id );
            }
        }
    }

    $fallback_to_generic = false;
    $dynamic_product_id = false;
    $generic_product_id = 661; 

    if ( ! $campaign_id || ! get_post_status( $campaign_id ) ) {
        $fallback_to_generic = true;
    } else {
        $dynamic_product_id = gfcm_get_or_create_campaign_product( $campaign_id );
        if ( ! $dynamic_product_id ) {
            $fallback_to_generic = true; 
        }
    }

    $items_changed = false;
    $tip_product_id = gfcm_get_tip_product_id();

    foreach ( $order->get_items() as $item_id => $item ) {
        if ( $item->get_product_id() == $tip_product_id ) continue;

        $is_donation_item = ( 
            $item->get_product_id() == $generic_product_id || 
            $item->meta_exists('_growfund_contribution_id') || 
            $item->meta_exists('contribution_id') || 
            $item->meta_exists('_is_gfcm_donation_product')
        );

        if ( $is_donation_item ) {
            if ( $fallback_to_generic ) {
                if ( $item->get_product_id() != $generic_product_id ) {
                    $generic_product = wc_get_product( $generic_product_id );
                    $item->set_product_id( $generic_product_id );
                    $item->set_name( $generic_product ? $generic_product->get_name() : 'Growfund Internal' );
                    $item->save();
                    $items_changed = true;
                }
            } else if ( $dynamic_product_id ) {
                if ( $item->get_product_id() != $dynamic_product_id ) {
                    $item->set_product_id( $dynamic_product_id );
                    $item->set_name( get_the_title( $campaign_id ) );
                    $item->save(); 
                    $items_changed = true;
                }
            }
        }
    }
    
    if ( $items_changed ) {
        if ( $fallback_to_generic ) {
            $order->add_order_note( 'GFCM Accounting: Fallback applied. Assigned generic product.' );
        } else {
            $order->add_order_note( 'GFCM Accounting: Automatically swapped generic product for Campaign Product.' );
        }
    }

    $order->update_meta_data( '_gfcm_product_swapped', 'yes' );
    $order->save();

    return $items_changed;
}

if ( ! wp_next_scheduled( 'gfcm_daily_product_swap_cron' ) ) {
    wp_schedule_event( time(), 'daily', 'gfcm_daily_product_swap_cron' );
}

add_action( 'gfcm_daily_product_swap_cron', 'gfcm_run_daily_product_swap' );
function gfcm_run_daily_product_swap() {
    $args = array(
        'limit'        => -1,
        'status'       => 'completed', 
        'date_created' => '>' . ( time() - 48 * 3600 ),
        'meta_query'   => array(
            array(
                'key'     => '_gfcm_product_swapped',
                'compare' => 'NOT EXISTS'
            )
        )
    );
    $orders = wc_get_orders( $args );

    foreach ( $orders as $order ) {
        gfcm_perform_order_product_swap( $order->get_id(), false );
    }
}

add_filter( 'bulk_actions-edit-shop_order', 'gfcm_register_bulk_swap_action' );
add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'gfcm_register_bulk_swap_action' );
function gfcm_register_bulk_swap_action( $bulk_actions ) {
    $bulk_actions['gfcm_swap_products'] = 'Force Swap to Campaign Products (QuickBooks)';
    return $bulk_actions;
}

add_filter( 'handle_bulk_actions-edit-shop_order', 'gfcm_handle_bulk_swap_action', 10, 3 );
add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'gfcm_handle_bulk_swap_action', 10, 3 );
function gfcm_handle_bulk_swap_action( $redirect_to, $doaction, $post_ids ) {
    if ( $doaction !== 'gfcm_swap_products' ) {
        return $redirect_to;
    }

    $swapped_count = 0;
    foreach ( $post_ids as $order_id ) {
        if ( gfcm_perform_order_product_swap( $order_id, true ) ) {
            $swapped_count++;
        }
    }

    $redirect_to = add_query_arg( 'gfcm_swapped_count', $swapped_count, $redirect_to );
    return $redirect_to;
}

add_action( 'admin_notices', 'gfcm_bulk_swap_admin_notice' );
function gfcm_bulk_swap_admin_notice() {
    if ( ! empty( $_REQUEST['gfcm_swapped_count'] ) ) {
        $count = intval( $_REQUEST['gfcm_swapped_count'] );
        printf( 
            '<div id="message" class="updated notice is-dismissible"><p>Forced <strong>%d</strong> order(s) through the Campaign Product Swap engine.</p></div>', 
            $count 
        );
    }
}

// ==========================================
// 16. EMERGENCY FIX: ALLOW GUESTS TO VIEW DONOR LIST
// ==========================================
// CHANGED: Hooked to 'wp_loaded' at priority 999 to guarantee GrowFund has already registered its hooks
add_action('wp_loaded', 'gfcm_fix_missing_guest_donor_list_hook', 999);
function gfcm_fix_missing_guest_donor_list_hook() {
    global $wp_filter;
    
    // Check if the logged-in AJAX hook exists natively in GrowFund
    if ( isset( $wp_filter['wp_ajax_growfund_ajax_donor_list'] ) ) {
        
        // Loop through the native logged-in functions and duplicate them for guests
        foreach ( $wp_filter['wp_ajax_growfund_ajax_donor_list']->callbacks as $priority => $callbacks ) {
            foreach ( $callbacks as $id => $callback ) {
                add_action( 'wp_ajax_nopriv_growfund_ajax_donor_list', $callback['function'], $priority, $callback['accepted_args'] );
            }
        }
    }
}

// ==========================================
// 17. Images Auto Alt text file name + custom text
// ==========================================

add_action('add_attachment', 'hiilbox_auto_alt_text');
function hiilbox_auto_alt_text($attachment_id) {
    // Check if the uploaded file is an image
    if (wp_attachment_is_image($attachment_id)) {
        
        // Get the file name without the extension
        $file_title = get_the_title($attachment_id);
        
        // Clean up the file name (replace hyphens and underscores with spaces)
        $clean_title = str_replace(array('-', '_'), ' ', $file_title);
        
        // Capitalize the first letter of each word for readability
        $clean_title = ucwords($clean_title); 
        
        // DEFINE YOUR CUSTOM TEXT HERE
        $custom_text = " - Hiilbox | Trusted Somali Crowdfunding & Fundraising";
        
        // Combine them together
        $final_alt_text = $clean_title . $custom_text;
        
        // Update the image's Alt Text field in WordPress
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($final_alt_text));
    }
}

// ==========================================
// 18. HIDE ADMIN BAR FOR SPECIFIC GROWFUND ROLES
// ==========================================
add_filter( 'show_admin_bar', 'gfcm_hide_admin_bar_for_growfund_roles' );
function gfcm_hide_admin_bar_for_growfund_roles( $show ) {
    // If the user isn't logged in, do nothing
    if ( ! is_user_logged_in() ) {
        return $show;
    }

    // Get the current user's data
    $user = wp_get_current_user();

    // Define the roles that should NOT see the admin bar
    $hidden_roles = array( 'growfund_fundraiser', 'growfund_donor' );

    // Check if the user's role array intersects with our hidden roles list
    if ( ! empty( array_intersect( $hidden_roles, (array) $user->roles ) ) ) {
        return false; // Hide the bar
    }

    // Otherwise, leave the default setting (e.g., Administrators will still see it)
    return $show;
}

// ==========================================
// MOBILE APP CHECKOUT HANDOFF API
// ==========================================

// 1. Create the REST API Endpoint for the Mobile App
add_action( 'rest_api_init', function() {
    register_rest_route( 'gfcm/v1', '/mobile-handoff', array(
        'methods'             => 'POST',
        'callback'            => 'gfcm_mobile_handoff_endpoint',
        'permission_callback' => '__return_true', // Open for the app to hit
    ) );
} );


function gfcm_mobile_handoff_endpoint( $request ) {

    $params = $request->get_json_params();

    // ==========================================
    // BASIC INPUT
    // ==========================================

    $campaign_id = isset( $params['campaign_id'] )
        ? absint( $params['campaign_id'] )
        : 0;

    $amount = isset( $params['amount'] )
        ? floatval( $params['amount'] )
        : 0;

    $tip_amount = isset( $params['tip_amount'] )
        ? max( 0, floatval( $params['tip_amount'] ) )
        : 0;

    $payment_method = isset( $params['payment_method'] )
        ? sanitize_text_field( $params['payment_method'] )
        : '';

    // ==========================================
    // DONOR INFORMATION
    // ==========================================

    $first_name = isset( $params['first_name'] )
        ? sanitize_text_field( $params['first_name'] )
        : '';

    $last_name = isset( $params['last_name'] )
        ? sanitize_text_field( $params['last_name'] )
        : '';

    $email = isset( $params['email'] )
        ? sanitize_email( $params['email'] )
        : '';

    $address = isset( $params['address'] )
        ? sanitize_text_field( $params['address'] )
        : '';

    $address_2 = isset( $params['address_2'] )
        ? sanitize_text_field( $params['address_2'] )
        : '';

    $city = isset( $params['city'] )
        ? sanitize_text_field( $params['city'] )
        : '';

    $state = isset( $params['state'] )
        ? sanitize_text_field( $params['state'] )
        : '';

    $zip_code = isset( $params['zip_code'] )
        ? sanitize_text_field( $params['zip_code'] )
        : '';

    $country = isset( $params['country'] )
        ? strtoupper( sanitize_text_field( $params['country'] ) )
        : '';

    $user_id = isset( $params['user_id'] )
        ? absint( $params['user_id'] )
        : 0;

    $is_anonymous = ! empty( $params['is_anonymous'] );

    // ==========================================
    // VALIDATION
    // ==========================================

    if ( ! $campaign_id ) {
        return new WP_Error(
            'missing_campaign',
            'Campaign ID is required.',
            array( 'status' => 400 )
        );
    }

    if ( $amount <= 0 ) {
        return new WP_Error(
            'invalid_amount',
            'Donation amount must be greater than zero.',
            array( 'status' => 400 )
        );
    }

    if ( ! $payment_method ) {
        return new WP_Error(
            'missing_payment_method',
            'Payment method is required.',
            array( 'status' => 400 )
        );
    }

    if ( ! $first_name ) {
        return new WP_Error(
            'missing_first_name',
            'First name is required.',
            array( 'status' => 400 )
        );
    }

    if ( ! $last_name ) {
        return new WP_Error(
            'missing_last_name',
            'Last name is required.',
            array( 'status' => 400 )
        );
    }

    if ( ! is_email( $email ) ) {
        return new WP_Error(
            'invalid_email',
            'A valid email address is required.',
            array( 'status' => 400 )
        );
    }

    if ( ! $address ) {
        return new WP_Error(
            'missing_address',
            'Address is required.',
            array( 'status' => 400 )
        );
    }

    if ( ! $city ) {
        return new WP_Error(
            'missing_city',
            'City is required.',
            array( 'status' => 400 )
        );
    }

    if ( ! $zip_code ) {
        return new WP_Error(
            'missing_zip',
            'ZIP/postal code is required.',
            array( 'status' => 400 )
        );
    }

    if ( ! $country ) {
        return new WP_Error(
            'missing_country',
            'Country is required.',
            array( 'status' => 400 )
        );
    }

    if ( ! get_post_status( $campaign_id ) ) {
        return new WP_Error(
            'campaign_not_found',
            'Campaign could not be found.',
            array( 'status' => 404 )
        );
    }

    // ==========================================
    // INITIALIZE WOOCOMMERCE
    // ==========================================

    try {

        if (
            function_exists( 'wc_load_cart' ) &&
            (
                ! isset( WC()->cart ) ||
                ! WC()->cart
            )
        ) {
            wc_load_cart();
        }

        if ( ! isset( WC()->session ) || ! WC()->session ) {
            return new WP_Error(
                'session_unavailable',
                'WooCommerce session is unavailable.',
                array( 'status' => 500 )
            );
        }

        if ( ! WC()->session->has_session() ) {
            WC()->session->set_customer_session_cookie( true );
        }

        // ==========================================
        // SAVE GFCM SESSION VALUES
        // ==========================================

        WC()->session->set(
            'custom_fude_campaign_id',
            $campaign_id
        );

        WC()->session->set(
            'custom_donation_amount',
            $amount
        );

        WC()->session->set(
            'custom_platform_tip',
            $tip_amount
        );

        // ==========================================
        // SAVE CUSTOMER INFORMATION
        // ==========================================

        if ( isset( WC()->customer ) && WC()->customer ) {

            WC()->customer->set_billing_first_name( $first_name );
            WC()->customer->set_billing_last_name( $last_name );
            WC()->customer->set_billing_email( $email );
            WC()->customer->set_billing_address_1( $address );
            WC()->customer->set_billing_address_2( $address_2 );
            WC()->customer->set_billing_city( $city );
            WC()->customer->set_billing_state( $state );
            WC()->customer->set_billing_postcode( $zip_code );
            WC()->customer->set_billing_country( $country );

            WC()->customer->save();
        }

        // ==========================================
        // BUILD THE DONATION CART
        // ==========================================

        if ( ! isset( WC()->cart ) || ! WC()->cart ) {
            return new WP_Error(
                'cart_unavailable',
                'WooCommerce cart is unavailable.',
                array( 'status' => 500 )
            );
        }

        WC()->cart->empty_cart();

        // Existing Growfund internal donation product.
        $added = WC()->cart->add_to_cart( 661, 1 );

        if ( ! $added ) {
            return new WP_Error(
                'cart_add_failed',
                'Unable to create the donation item.',
                array( 'status' => 500 )
            );
        }

        // Add the existing GFCM Platform Tip product.
        if ( function_exists( 'gfcm_get_tip_product_id' ) ) {

            $tip_product_id = gfcm_get_tip_product_id();

            if ( $tip_product_id ) {
                WC()->cart->add_to_cart(
                    $tip_product_id,
                    1
                );
            }
        }

        // Apply donation + tip prices.
        WC()->cart->calculate_totals();

        // ==========================================
        // FIND THE PAYMENT GATEWAY
        // ==========================================

        $available_gateways =
            WC()->payment_gateways()->get_available_payment_gateways();

        if ( ! isset( $available_gateways[ $payment_method ] ) ) {

            return new WP_Error(
                'invalid_payment_method',
                'The selected payment method is not available.',
                array(
                    'status' => 400,
                    'gateway' => $payment_method,
                )
            );
        }

        $gateway = $available_gateways[ $payment_method ];

        if (
            ! $gateway->enabled ||
            ! $gateway->is_available()
        ) {
            return new WP_Error(
                'payment_method_unavailable',
                'The selected payment method is currently unavailable.',
                array( 'status' => 400 )
            );
        }

        // ==========================================
        // PREPARE WOOCOMMERCE ORDER DATA
        // ==========================================

        $posted_data = array(

            'billing_first_name' => $first_name,
            'billing_last_name'  => $last_name,
            'billing_company'    => '',

            'billing_country'    => $country,
            'billing_address_1'  => $address,
            'billing_address_2'  => $address_2,
            'billing_city'       => $city,
            'billing_state'      => $state,
            'billing_postcode'  => $zip_code,
            'billing_phone'      => '',

            'shipping_first_name' => $first_name,
            'shipping_last_name'  => $last_name,
            'shipping_company'    => '',
            'shipping_country'    => $country,
            'shipping_address_1'  => $address,
            'shipping_address_2'  => $address_2,
            'shipping_city'       => $city,
            'shipping_state'      => $state,
            'shipping_postcode'  => $zip_code,

            'payment_method' => $payment_method,

            'terms' => 1,
            'terms-field' => 1,

            'createaccount' => 0,

            'order_comments' => '',
        );

        WC()->session->set(
            'chosen_payment_method',
            $payment_method
        );

        WC()->session->save_data();

        // ==========================================
        // CREATE THE WOOCOMMERCE ORDER
        // ==========================================

        $checkout = WC()->checkout();

        $order_id = $checkout->create_order(
            $posted_data
        );

        if ( is_wp_error( $order_id ) ) {
            throw new Exception(
                $order_id->get_error_message()
            );
        }

        $order_id = absint( $order_id );

        if ( ! $order_id ) {
            throw new Exception(
                'WooCommerce failed to create the order.'
            );
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            throw new Exception(
                'Unable to load the newly created WooCommerce order.'
            );
        }

        // ==========================================
        // SAVE GFCM ORDER META
        // ==========================================

        $order->update_meta_data(
            '_fude_custom_campaign_id',
            $campaign_id
        );

        $order->update_meta_data(
            'growfund_is_anonymous',
            $is_anonymous ? 1 : 0
        );

        $order->update_meta_data(
            'Anonymous Donation',
            $is_anonymous ? 'Yes' : 'No'
        );

        $order->save();

        // ==========================================
        // DIRECTLY INVOKE THE PAYMENT GATEWAY
        // ==========================================

        WC()->session->set(
            'order_awaiting_payment',
            $order_id
        );

        WC()->session->save_data();

    
// ==========================================
// DIRECT GATEWAY PROCESSING
// ==========================================

// WooCommerce payment gateways often expect the
// normal checkout POST variables to exist.
//
// Because this request comes from our Next.js REST
// API instead of the native WooCommerce checkout,
// we must provide those values explicitly.

$gateway_post_data = array(
    'billing_first_name' => $first_name,
    'billing_last_name'  => $last_name,
    'billing_email'      => $email,

    'billing_country'    => $country,
    'billing_address_1'  => $address,
    'billing_address_2'  => $address_2,
    'billing_city'       => $city,
    'billing_state'      => $state,
    'billing_postcode'   => $zip_code,
    'billing_phone'      => '',

    'shipping_first_name' => $first_name,
    'shipping_last_name'  => $last_name,
    'shipping_country'    => $country,
    'shipping_address_1'  => $address,
    'shipping_address_2'  => $address_2,
    'shipping_city'       => $city,
    'shipping_state'      => $state,
    'shipping_postcode'   => $zip_code,

    'payment_method' => $payment_method,

    'terms'       => '1',
    'terms-field' => '1',

    'createaccount' => '0',

    'order_comments' => '',
);

// Make the request look like a normal WooCommerce
// checkout request to the payment gateway.
foreach ( $gateway_post_data as $key => $value ) {
    $_POST[ $key ] = $value;
    $_REQUEST[ $key ] = $value;
}

// Set the selected gateway in the WooCommerce session.
WC()->session->set(
    'chosen_payment_method',
    $payment_method
);

WC()->session->save_data();

// Make sure the order has the correct payment method.
$order->set_payment_method( $gateway );
$order->set_payment_method_title(
    $gateway->get_title()
);
$order->save();

// ==========================================
// PROCESS PAYMENT
// ==========================================

error_log(
    'GFCM CHECKOUT: BEFORE process_payment - gateway=' .
    $payment_method .
    ' order=' .
    $order_id
);

@set_time_limit(20);

$payment_result = $gateway->process_payment(
    $order_id
);

error_log(
    'GFCM CHECKOUT: AFTER process_payment - gateway=' .
    $payment_method .
    ' order=' .
    $order_id
);
// ==========================================
// NORMALIZE THE GATEWAY RESPONSE
// ==========================================

if ( is_object( $payment_result ) ) {
    $payment_result = (array) $payment_result;
}

if ( ! is_array( $payment_result ) ) {

    return new WP_Error(
        'gateway_invalid_response',
        'The selected payment gateway returned an invalid response.',
        array(
            'status'  => 500,
            'gateway' => $payment_method,
        )
    );
}

// Some gateways may return "success" with a redirect.
$result_status = isset(
    $payment_result['result']
)
    ? strtolower(
        (string) $payment_result['result']
    )
    : '';

// ==========================================
// SUCCESS
// ==========================================

if ( $result_status === 'success' ) {

    $redirect = '';

    if (
        isset(
            $payment_result['redirect']
        ) &&
        is_string(
            $payment_result['redirect']
        )
    ) {
        $redirect = esc_url_raw(
            $payment_result['redirect']
        );
    }

    WC()->session->set(
        'order_awaiting_payment',
        $order_id
    );

    WC()->session->save_data();

    // IMPORTANT:
    // We do NOT allow the old WordPress checkout
    // page to become the redirect.
    //
    // If the gateway returns an external payment
    // provider URL, that URL is allowed.
    if ( $redirect ) {

        $redirect_host = wp_parse_url(
            $redirect,
            PHP_URL_HOST
        );

        $redirect_path = wp_parse_url(
            $redirect,
            PHP_URL_PATH
        );

        $site_host = wp_parse_url(
            home_url(),
            PHP_URL_HOST
        );

        if (
            $redirect_host === $site_host &&
            (
                strpos(
                    $redirect_path,
                    '/checkout'
                ) === 0 ||
                strpos(
                    $redirect_path,
                    '/cart'
                ) === 0
            )
        ) {
            $redirect = '';
        }
    }

    WC()->cart->empty_cart();

    return rest_ensure_response(
        array(
            'success'  => true,
            'result'   => 'success',
            'order_id' => $order_id,
            'redirect' => $redirect,
        )
    );
}

// ==========================================
// GATEWAY DID NOT RETURN SUCCESS
// ==========================================

$message =
    'The selected payment gateway could not start the payment.';

if (
    isset(
        $payment_result['messages']
    )
) {

    $messages =
        $payment_result['messages'];

    if ( is_array( $messages ) ) {
        $messages = implode(
            ' ',
            $messages
        );
    }

    $messages =
        wp_strip_all_tags(
            (string) $messages
        );

    if ( $messages ) {
        $message = $messages;
    }
}

if (
    isset(
        $payment_result['message']
    ) &&
    $payment_result['message']
) {

    $message =
        wp_strip_all_tags(
            (string)
            $payment_result['message']
        );
}

return new WP_Error(
    'gateway_payment_failed',
    $message,
    array(
        'status'   => 402,
        'order_id' => $order_id,
        'gateway'  => $payment_method,
    )
);



            WC()->cart->empty_cart();

            return rest_ensure_response(
                array(
                    'success'  => true,
                    'result'   => 'success',
                    'order_id' => $order_id,
                    'redirect' => $redirect,
                )
            );
        }

        // ==========================================
        // PAYMENT FAILURE
        // ==========================================

        $message = 'The payment could not be processed.';

        if (
            isset( $payment_result['messages'] )
        ) {

            $messages =
                $payment_result['messages'];

            if ( is_array( $messages ) ) {
                $messages =
                    implode(
                        ' ',
                        $messages
                    );
            }

            $message =
                wp_strip_all_tags(
                    $messages
                );
        }

        return new WP_Error(
            'payment_failed',
            $message,
            array(
                'status'   => 402,
                'order_id' => $order_id,
            )
        );

    } catch ( Throwable $e ) {

        return new WP_Error(
            'checkout_processing_failed',
            $e->getMessage(),
            array(
                'status' => 500,
            )
        );
    }
}



// ==========================================
// CLEAR MOBILE SESSION AJAX
// ==========================================
add_action( 'wp_ajax_gfcm_clear_mobile_session', 'gfcm_clear_mobile_session_ajax' );
add_action( 'wp_ajax_nopriv_gfcm_clear_mobile_session', 'gfcm_clear_mobile_session_ajax' );
function gfcm_clear_mobile_session_ajax() {
    check_ajax_referer( 'custom_checkout_nonce', 'nonce' );
    
    if ( is_user_logged_in() ) {
        $user_id = get_current_user_id();
        delete_user_meta( $user_id, 'gfcm_mobile_prefill_amt' );
        delete_user_meta( $user_id, 'gfcm_mobile_prefill_tip' );
    } else if ( isset( WC()->session ) ) {
        $customer_id = WC()->session->get_customer_id();
        delete_transient( 'gfcm_guest_amt_' . $customer_id );
        delete_transient( 'gfcm_guest_tip_' . $customer_id );
    }
    
    wp_die();
}

// ==========================================
// DIRECT NEXT.JS CHECKOUT API
// ==========================================
//
// This endpoint is different from mobile-handoff.
//
// mobile-handoff:
//     Next.js -> WooCommerce checkout page
//
// process-checkout:
//     Next.js -> create WooCommerce order -> payment gateway
//
// The donor never visits /checkout/.
//

add_action( 'rest_api_init', function() {

    // ------------------------------------------
    // GET AVAILABLE PAYMENT GATEWAYS
    // ------------------------------------------
    register_rest_route( 'gfcm/v1', '/checkout-gateways', array(
        'methods'             => 'GET',
        'callback'            => 'gfcm_api_get_checkout_gateways',
        'permission_callback' => '__return_true',
    ) );

    // ------------------------------------------
    // PROCESS CHECKOUT DIRECTLY
    // ------------------------------------------
    register_rest_route( 'gfcm/v1', '/process-checkout', array(
        'methods'             => 'POST',
        'callback'            => 'gfcm_api_process_checkout',
        'permission_callback' => '__return_true',
    ) );

} );


// ==========================================
// GET AVAILABLE PAYMENT GATEWAYS
// ==========================================

function gfcm_api_get_checkout_gateways() {

    if ( ! function_exists( 'WC' ) || ! WC() ) {
        return new WP_Error(
            'woocommerce_unavailable',
            'WooCommerce is not available.',
            array(
                'status' => 503,
            )
        );
    }

    try {

        /*
         * Load WooCommerce cart/session when this is a
         * headless REST request.
         */
        if (
            function_exists( 'wc_load_cart' ) &&
            (
                ! isset( WC()->cart ) ||
                ! WC()->cart
            )
        ) {
            wc_load_cart();
        }

        /*
         * Get the registered WooCommerce payment gateways.
         *
         * IMPORTANT:
         * Do NOT call is_available() here.
         *
         * On a headless request there may not yet be a
         * billing country/customer context, which can cause
         * WooCommerce to incorrectly report a gateway as
         * unavailable even though the gateway is enabled.
         */
        $payment_gateways =
            WC()->payment_gateways()->payment_gateways();

        $gateways = array();

        /*
         * These are the actual HiilBox payment gateway IDs
         * already used by the existing GFCM code.
         */
        $allowed_gateways = array(
            'zes_pay',
            'edahab_pay',
            'premier_wallet_pay',
            'card_pay',
        );

        foreach ( $payment_gateways as $gateway_id => $gateway ) {

            if (
                ! in_array(
                    $gateway_id,
                    $allowed_gateways,
                    true
                )
            ) {
                continue;
            }

            /*
             * Only expose gateways that are enabled.
             */
            if (
                isset( $gateway->enabled ) &&
                $gateway->enabled !== 'yes'
            ) {
                continue;
            }

            $gateways[] = array(
                'id' => $gateway->id,

                'title' => wp_strip_all_tags(
                    $gateway->get_title()
                ),

                'description' => wp_strip_all_tags(
                    $gateway->get_description()
                ),

                'icon' => isset( $gateway->icon )
                    ? esc_url_raw(
                        $gateway->icon
                    )
                    : '',
            );
        }

        return rest_ensure_response(
            array(
                'success'  => true,
                'gateways' => $gateways,
            )
        );

    } catch ( Throwable $e ) {

        error_log(
            'GFCM GATEWAY LIST ERROR: ' .
            $e->getMessage()
        );

        return new WP_Error(
            'gateway_lookup_failed',
            $e->getMessage(),
            array(
                'status' => 500,
            )
        );
    }
}

// ==========================================
// PROCESS NEXT.JS CHECKOUT DIRECTLY
// ==========================================

function gfcm_api_process_checkout( $request ) {

    if ( ! function_exists( 'WC' ) || ! WC() ) {
        return new WP_Error(
            'woocommerce_unavailable',
            'WooCommerce is not available.',
            array( 'status' => 503 )
        );
    }

    $params = $request->get_json_params();

    // ------------------------------------------
    // BASIC INPUT
    // ------------------------------------------

    $campaign_id = isset( $params['campaign_id'] )
        ? absint( $params['campaign_id'] )
        : 0;

    $amount = isset( $params['amount'] )
        ? floatval( $params['amount'] )
        : 0;

    $tip_amount = isset( $params['tip_amount'] )
        ? max( 0, floatval( $params['tip_amount'] ) )
        : 0;

    $payment_method = isset( $params['payment_method'] )
        ? sanitize_text_field( $params['payment_method'] )
        : '';

    // ------------------------------------------
    // VALIDATE REQUIRED DATA
    // ------------------------------------------

    if ( ! $campaign_id ) {
        return new WP_Error(
            'missing_campaign_id',
            'Campaign ID is required.',
            array( 'status' => 400 )
        );
    }

    if ( $amount <= 0 ) {
        return new WP_Error(
            'invalid_amount',
            'Donation amount must be greater than zero.',
            array( 'status' => 400 )
        );
    }

    if ( empty( $payment_method ) ) {
        return new WP_Error(
            'missing_payment_method',
            'Payment method is required.',
            array( 'status' => 400 )
        );
    }

    if ( ! get_post_status( $campaign_id ) ) {
        return new WP_Error(
            'campaign_not_found',
            'The selected campaign could not be found.',
            array( 'status' => 404 )
        );
    }

    // ------------------------------------------
    // SANITIZE DONOR DETAILS
    // ------------------------------------------

    $first_name = isset( $params['first_name'] )
        ? sanitize_text_field( $params['first_name'] )
        : '';

    $last_name = isset( $params['last_name'] )
        ? sanitize_text_field( $params['last_name'] )
        : '';

    $email = isset( $params['email'] )
        ? sanitize_email( $params['email'] )
        : '';

    $address = isset( $params['address'] )
        ? sanitize_text_field( $params['address'] )
        : '';

    $address_2 = isset( $params['address_2'] )
        ? sanitize_text_field( $params['address_2'] )
        : '';

    $city = isset( $params['city'] )
        ? sanitize_text_field( $params['city'] )
        : '';

    $state = isset( $params['state'] )
        ? sanitize_text_field( $params['state'] )
        : '';

    $zip_code = isset( $params['zip_code'] )
        ? sanitize_text_field( $params['zip_code'] )
        : '';

    $country = isset( $params['country'] )
        ? strtoupper( sanitize_text_field( $params['country'] ) )
        : '';

    $is_anonymous = ! empty( $params['is_anonymous'] );

    // ------------------------------------------
    // VALIDATE DONOR DETAILS
    // ------------------------------------------

    if ( empty( $first_name ) ) {
        return new WP_Error(
            'missing_first_name',
            'First name is required.',
            array( 'status' => 400 )
        );
    }

    if ( empty( $last_name ) ) {
        return new WP_Error(
            'missing_last_name',
            'Last name is required.',
            array( 'status' => 400 )
        );
    }

    if ( ! is_email( $email ) ) {
        return new WP_Error(
            'invalid_email',
            'A valid email address is required.',
            array( 'status' => 400 )
        );
    }

    if ( empty( $address ) ) {
        return new WP_Error(
            'missing_address',
            'Address is required.',
            array( 'status' => 400 )
        );
    }

    if ( empty( $city ) ) {
        return new WP_Error(
            'missing_city',
            'City is required.',
            array( 'status' => 400 )
        );
    }

    if ( empty( $zip_code ) ) {
        return new WP_Error(
            'missing_zip',
            'ZIP/postal code is required.',
            array( 'status' => 400 )
        );
    }

    if ( empty( $country ) ) {
        return new WP_Error(
            'missing_country',
            'Country is required.',
            array( 'status' => 400 )
        );
    }

    try {

        // ------------------------------------------
        // INITIALIZE WOOCOMMERCE
        // ------------------------------------------

        if (
            function_exists( 'wc_load_cart' ) &&
            (
                ! isset( WC()->cart ) ||
                ! WC()->cart
            )
        ) {
            wc_load_cart();
        }

        if ( ! isset( WC()->session ) || ! WC()->session ) {
            return new WP_Error(
                'woocommerce_session_unavailable',
                'WooCommerce session is unavailable.',
                array( 'status' => 500 )
            );
        }

        if ( ! WC()->session->has_session() ) {
            WC()->session->set_customer_session_cookie( true );
        }

        // ------------------------------------------
        // SET THE SAME GFCM SESSION DATA USED BY
        // THE EXISTING WOOCOMMERCE CHECKOUT
        // ------------------------------------------

        WC()->session->set(
            'custom_fude_campaign_id',
            $campaign_id
        );

        WC()->session->set(
            'custom_donation_amount',
            $amount
        );

        WC()->session->set(
            'custom_platform_tip',
            $tip_amount
        );

        // ------------------------------------------
        // SET CUSTOMER DATA
        // ------------------------------------------

        if ( isset( WC()->customer ) && WC()->customer ) {

            WC()->customer->set_billing_first_name( $first_name );
            WC()->customer->set_billing_last_name( $last_name );
            WC()->customer->set_billing_email( $email );
            WC()->customer->set_billing_address_1( $address );
            WC()->customer->set_billing_address_2( $address_2 );
            WC()->customer->set_billing_city( $city );
            WC()->customer->set_billing_state( $state );
            WC()->customer->set_billing_postcode( $zip_code );
            WC()->customer->set_billing_country( $country );

            WC()->customer->save();
        }

        // ------------------------------------------
        // BUILD THE DONATION CART
        // ------------------------------------------

        if ( ! WC()->cart ) {
            return new WP_Error(
                'cart_unavailable',
                'WooCommerce cart is unavailable.',
                array( 'status' => 500 )
            );
        }

        WC()->cart->empty_cart();

        /*
         * 661 = Growfund internal donation product.
         *
         * Your existing GFCM code already uses this
         * product for the donation workflow.
         */
        $added = WC()->cart->add_to_cart( 661, 1 );

        if ( ! $added ) {
            return new WP_Error(
                'donation_product_failed',
                'Unable to create the donation cart item.',
                array( 'status' => 500 )
            );
        }

        /*
         * Keep the Platform Tip product in the cart.
         * Your existing gfcm_custom_cart_prices()
         * callback sets its actual price from the session.
         */
        if ( function_exists( 'gfcm_get_tip_product_id' ) ) {

            $tip_product_id = gfcm_get_tip_product_id();

            if ( $tip_product_id ) {
                WC()->cart->add_to_cart(
                    $tip_product_id,
                    1
                );
            }
        }

        // Force the custom donation / tip prices.
        WC()->cart->calculate_totals();

        // ------------------------------------------
        // VERIFY PAYMENT GATEWAY
        // ------------------------------------------

        $available_gateways =
            WC()->payment_gateways()->get_available_payment_gateways();

        if (
            ! isset( $available_gateways[ $payment_method ] )
        ) {
            return new WP_Error(
                'invalid_payment_method',
                'The selected payment method is not available.',
                array( 'status' => 400 )
            );
        }

        $gateway = $available_gateways[ $payment_method ];

        if ( ! $gateway->enabled || ! $gateway->is_available() ) {
            return new WP_Error(
                'payment_method_unavailable',
                'The selected payment method is currently unavailable.',
                array( 'status' => 400 )
            );
        }

        // ------------------------------------------
        // PREPARE DATA FOR WC_CHECKOUT::create_order()
        // ------------------------------------------

        $posted_data = array(

            'billing_first_name' => $first_name,
            'billing_last_name'  => $last_name,
            'billing_company'    => '',
            'billing_country'    => $country,
            'billing_address_1'  => $address,
            'billing_address_2'  => $address_2,
            'billing_city'       => $city,
            'billing_state'      => $state,
            'billing_postcode'  => $zip_code,
            'billing_phone'      => '',

            'shipping_first_name' => $first_name,
            'shipping_last_name'  => $last_name,
            'shipping_company'    => '',
            'shipping_country'    => $country,
            'shipping_address_1'  => $address,
            'shipping_address_2'  => $address_2,
            'shipping_city'       => $city,
            'shipping_state'      => $state,
            'shipping_postcode'  => $zip_code,

            'payment_method' => $payment_method,

            'order_comments' => '',

            'terms' => 1,
            'terms-field' => 1,

            'createaccount' => 0,

            'shipping_method' => array(),

            'ship_to_different_address' => 0,
        );

        // ------------------------------------------
        // SAVE CUSTOMER PAYMENT METHOD SESSION
        // ------------------------------------------

        WC()->session->set(
            'chosen_payment_method',
            $payment_method
        );

        WC()->session->save_data();

        // ------------------------------------------
        // CREATE THE WOOCOMMERCE ORDER
        // ------------------------------------------

        $checkout = WC()->checkout();

        $order_id = $checkout->create_order(
            $posted_data
        );

        if ( is_wp_error( $order_id ) ) {
            throw new Exception(
                $order_id->get_error_message()
            );
        }

        $order_id = absint( $order_id );

        if ( ! $order_id ) {
            throw new Exception(
                'WooCommerce could not create the order.'
            );
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            throw new Exception(
                'WooCommerce order could not be loaded.'
            );
        }

        // ------------------------------------------
        // SAVE GFCM / ANONYMOUS DATA
        // ------------------------------------------

        $order->update_meta_data(
            '_fude_custom_campaign_id',
            $campaign_id
        );

        $order->update_meta_data(
            'growfund_is_anonymous',
            $is_anonymous ? 1 : 0
        );

        $order->update_meta_data(
            'Anonymous Donation',
            $is_anonymous ? 'Yes' : 'No'
        );

        $order->save();

        // ------------------------------------------
        // RUN THE SAME GROWFUND ORDER HOOKS USED
        // BY THE NORMAL WOOCOMMERCE CHECKOUT
        // ------------------------------------------

        do_action(
            'woocommerce_checkout_order_processed',
            $order_id,
            $posted_data,
            $order
        );
// ------------------------------------------
// PROCESS PAYMENT DIRECTLY
// ------------------------------------------

WC()->session->set(
    'order_awaiting_payment',
    $order_id
);

WC()->session->set(
    'chosen_payment_method',
    $payment_method
);

WC()->session->save_data();

// Make the gateway see the same checkout fields
// that it would receive from the normal WooCommerce checkout.
$_POST['billing_first_name'] = $first_name;
$_POST['billing_last_name']  = $last_name;
$_POST['billing_email']      = $email;

$_POST['billing_country']    = $country;
$_POST['billing_address_1']  = $address;
$_POST['billing_address_2']  = $address_2;
$_POST['billing_city']       = $city;
$_POST['billing_state']      = $state;
$_POST['billing_postcode']   = $zip_code;
$_POST['billing_phone']      = '';

$_POST['payment_method']     = $payment_method;

$_POST['terms']              = '1';
$_POST['terms-field']        = '1';
$_POST['createaccount']      = '0';
$_POST['order_comments']     = '';

foreach ( $_POST as $key => $value ) {
    $_REQUEST[ $key ] = $value;
}

// Make sure the order itself has the selected gateway.
$order->set_payment_method(
    $gateway
);

$order->set_payment_method_title(
    $gateway->get_title()
);

$order->save();

error_log(
    'GFCM HEADLESS BEFORE PAYMENT: gateway=' .
    $payment_method .
    ' order=' .
    $order_id
);

@set_time_limit(30);

$result = $gateway->process_payment(
    $order_id
);

error_log(
    'GFCM HEADLESS AFTER PAYMENT: gateway=' .
    $payment_method .
    ' order=' .
    $order_id .
    ' result=' .
    print_r( $result, true )
);

// Some gateways may return an object.
if ( is_object( $result ) ) {
    $result = (array) $result;
}

if ( ! is_array( $result ) ) {

    return new WP_Error(
        'invalid_gateway_response',
        'The payment gateway returned an invalid response.',
        array(
            'status'  => 500,
            'gateway' => $payment_method,
            'type'    => gettype( $result ),
        )
    );
}

        if (
            isset( $result['result'] ) &&
            $result['result'] === 'success'
        ) {

            $redirect = isset( $result['redirect'] )
                ? $result['redirect']
                : '';

            /*
             * IMPORTANT:
             *
             * A gateway redirect is allowed if it is an
             * external payment provider.
             *
             * But never send the donor to the WordPress
             * checkout page again.
             */
            if (
                $redirect &&
                strpos(
                    wp_parse_url( $redirect, PHP_URL_PATH ),
                    '/checkout/'
                ) === 0
            ) {

                return new WP_Error(
                    'invalid_gateway_redirect',
                    'The selected payment gateway attempted to redirect to the WordPress checkout page.',
                    array( 'status' => 500 )
                );
            }

            // WooCommerce will usually handle the payment
            // status itself inside process_payment().
            WC()->cart->empty_cart();

            return rest_ensure_response(
                array(
                    'success'   => true,
                    'result'    => 'success',
                    'order_id'  => $order_id,
                    'redirect'  => $redirect,
                )
            );
        }

        // ------------------------------------------
        // PAYMENT FAILED
        // ------------------------------------------

        $message = isset( $result['messages'] )
            ? wp_strip_all_tags(
                wp_kses_post(
                    is_array( $result['messages'] )
                        ? implode( ' ', $result['messages'] )
                        : $result['messages']
                )
            )
            : 'The payment gateway could not process the donation.';

        return new WP_Error(
            'payment_failed',
            $message,
            array(
                'status'   => 402,
                'order_id' => $order_id,
            )
        );

    } catch ( Throwable $e ) {

        return new WP_Error(
            'process_checkout_failed',
            $e->getMessage(),
            array( 'status' => 500 )
        );
    }
}


// ==========================================
// CLEAN UP SIFALO LABELS (CHECKOUT PAGE)
// ==========================================
add_filter( 'woocommerce_gateway_title', 'gfcm_clean_sifalo_labels', 999, 2 );
add_filter( 'woocommerce_gateway_description', 'gfcm_clean_sifalo_labels', 999, 2 );

function gfcm_clean_sifalo_labels( $text, $gateway_id ) {
    if ( empty( $text ) ) {
        return $text;
    }

    $sifalo_gateways = array( 'zes_pay', 'edahab_pay', 'premier_wallet_pay', 'card_pay' );
    
    if ( in_array( $gateway_id, $sifalo_gateways ) ) {
        // Decode hidden HTML entities
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        
        // Safely remove "Sifalo Pay", hyphens, and invisible Unicode spaces (\p{Z})
        $text = preg_replace( '/Sifalo Pay[\p{Z}\s\-]+/iu', '', $text );
        
        // Clean up the description text
        $text = preg_replace( '/Pay via[\p{Z}\s\-]+/iu', 'Pay via ', $text );
    }

    return $text;
}

// ==========================================
// CLEAN UP SIFALO INSTRUCTIONS (THANK YOU PAGE & EMAILS)
// ==========================================
add_filter( 'gettext', 'gfcm_clean_sifalo_instructions', 999, 3 );

function gfcm_clean_sifalo_instructions( $translated_text, $text, $domain ) {
    
    // Only run our heavy regex if the string actually contains the word "Sifalo"
    if ( stripos( $text, 'Sifalo' ) !== false || stripos( $translated_text, 'Sifalo' ) !== false ) {
        
        // Decode hidden HTML entities
        $translated_text = html_entity_decode( $translated_text, ENT_QUOTES, 'UTF-8' );
        
        // Safely remove "Sifalo Pay" and invisible Unicode spaces (\p{Z})
        $translated_text = preg_replace( '/Sifalo Pay[\p{Z}\s\-]+/iu', '', $translated_text );
        
        // Clean up the "Pay via" structure
        $translated_text = preg_replace( '/Pay via[\p{Z}\s\-]+/iu', 'Pay via ', $translated_text );
    }

    return $translated_text;
}

// ==========================================
// GTM & META PIXEL STANDARD E-COMMERCE TRACKING 
// ==========================================

// 1. Product Browsing: Single Campaign View
add_action( 'wp_footer', 'gfcm_track_campaign_view' );
function gfcm_track_campaign_view() {
    // Bulletproof check: Is it the campaign post type OR does the URL contain /campaigns/?
    $is_campaign = is_singular( 'campaign' ) || ( isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/campaigns/') !== false );
    
    if ( ! $is_campaign ) return;
    
    global $post;
    if ( ! $post ) return;
    
    $campaign_id   = $post->ID;
    $campaign_name = esc_js( $post->post_title );
    $currency      = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
    ?>
    <script>
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({
            'event': 'view_item',
            'ecommerce': {
                'currency': '<?php echo esc_js($currency); ?>',
                'value': 0, 
                'items': [{
                    'item_name': '<?php echo $campaign_name; ?>',
                    'item_id': '<?php echo $campaign_id; ?>',
                    'price': 0,
                    'quantity': 1
                }]
            }
        });
        if (typeof fbq === 'function') {
            fbq('track', 'ViewContent', { content_name: '<?php echo $campaign_name; ?>', content_ids: ['<?php echo $campaign_id; ?>'], content_type: 'product', value: 0, currency: '<?php echo esc_js($currency); ?>' });
        }
    </script>
    <?php
}

// 2. Cart Interactions: Add to Cart (Intercepts donation submission)
add_action( 'woocommerce_add_to_cart', 'gfcm_track_add_to_cart', 10, 6 );
function gfcm_track_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
    $campaign_id = isset( $cart_item_data['campaign_id'] ) ? $cart_item_data['campaign_id'] : (isset( $_REQUEST['campaign_id'] ) ? intval( $_REQUEST['campaign_id'] ) : 0);
    
    if ( $campaign_id ) {
        $item_name = get_the_title( $campaign_id );
        $item_id   = $campaign_id;
    } else {
        $product   = wc_get_product( $product_id );
        $item_name = $product ? $product->get_name() : 'Donation';
        $item_id   = $product_id;
    }

    $price = isset( $cart_item_data['data'] ) ? $cart_item_data['data']->get_price() : (isset( $_REQUEST['amount'] ) ? floatval( $_REQUEST['amount'] ) : 0);

    $track_data = [
        'name' => $item_name, 'id' => $item_id, 'price' => $price, 'quantity' => 1, 'currency' => get_woocommerce_currency()
    ];
    set_transient( 'gfcm_track_add_to_cart_' . get_current_user_id(), $track_data, 60 );
}

add_action( 'wp_footer', 'gfcm_output_add_to_cart_tracking' );
function gfcm_output_add_to_cart_tracking() {
    $track_data = get_transient( 'gfcm_track_add_to_cart_' . get_current_user_id() );
    if ( $track_data ) {
        delete_transient( 'gfcm_track_add_to_cart_' . get_current_user_id() );
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                'event': 'add_to_cart',
                'ecommerce': {
                    'currency': '<?php echo esc_js($track_data['currency']); ?>',
                    'value': <?php echo $track_data['price']; ?>,
                    'items': [{
                        'item_name': '<?php echo esc_js($track_data['name']); ?>',
                        'item_id': '<?php echo esc_js($track_data['id']); ?>',
                        'price': <?php echo $track_data['price']; ?>,
                        'quantity': 1
                    }]
                }
            });
            if (typeof fbq === 'function') {
                fbq('track', 'AddToCart', { content_name: '<?php echo esc_js($track_data['name']); ?>', content_ids: ['<?php echo esc_js($track_data['id']); ?>'], content_type: 'product', value: <?php echo $track_data['price']; ?>, currency: '<?php echo esc_js($track_data['currency']); ?>' });
            }
        </script>
        <?php
    }
}

// 3. Checkout Process: Begin Checkout
add_action( 'wp_footer', 'gfcm_track_begin_checkout_footer' );
function gfcm_track_begin_checkout_footer() {
    // Only fire if we are physically on the checkout page (and NOT on the order received page)
    if ( ! function_exists('is_checkout') || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
        return;
    }
    
    if ( WC()->cart->is_empty() ) return;

    $cart_total = WC()->cart->get_total('edit');
    $currency   = get_woocommerce_currency();
    $tip_product_id = function_exists('gfcm_get_tip_product_id') ? gfcm_get_tip_product_id() : 0;
    
    $items = [];
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        // Find campaign ID safely from cart meta
        $camp_id = isset($cart_item['campaign_id']) ? $cart_item['campaign_id'] : 0;
        
        $is_tip = ( $cart_item['product_id'] == $tip_product_id );
        
        $name   = $is_tip ? 'Platform Tip' : ($camp_id ? get_the_title($camp_id) : $cart_item['data']->get_name());
        $id     = $is_tip ? $cart_item['product_id'] : ($camp_id ?: $cart_item['product_id']);
        $price  = $cart_item['data']->get_price();
        $qty    = $cart_item['quantity'];
        
        $items[] = "{'item_name': '" . esc_js($name) . "', 'item_id': '" . esc_js($id) . "', 'price': " . $price . ", 'quantity': " . $qty . "}";
    }
    
    ?>
    <script>
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({
            'event': 'begin_checkout',
            'ecommerce': {
                'currency': '<?php echo esc_js($currency); ?>',
                'value': <?php echo $cart_total ? $cart_total : 0; ?>,
                'items': [<?php echo implode(',', $items); ?>]
            }
        });
        if (typeof fbq === 'function') {
            fbq('track', 'InitiateCheckout', { value: <?php echo $cart_total ? $cart_total : 0; ?>, currency: '<?php echo esc_js($currency); ?>' });
        }
    </script>
    <?php
}

// 4. Purchases: Order Completed (Triggered on your Custom Success Popup Redirect)
add_action( 'wp_footer', 'gfcm_track_custom_purchase_event' );
function gfcm_track_custom_purchase_event() {
    if ( ! isset( $_GET['payment'] ) || $_GET['payment'] !== 'success' || empty( $_GET['oid'] ) ) return;

    $order_id = intval( $_GET['oid'] );
    $order    = wc_get_order( $order_id );
    if ( ! $order ) return;

    if ( get_post_meta( $order_id, '_gfcm_tracked_purchase', true ) ) return;
    update_post_meta( $order_id, '_gfcm_tracked_purchase', true );

    $total    = $order->get_total();
    $currency = $order->get_currency();
    $tax      = $order->get_total_tax();
    $shipping = $order->get_shipping_total();

    $campaign_id = $order->get_meta( '_fude_custom_campaign_id' );
    $campaign_name = $campaign_id ? get_the_title( $campaign_id ) : 'Donation';
    $tip_product_id = function_exists('gfcm_get_tip_product_id') ? gfcm_get_tip_product_id() : 0;

    $items = [];
    foreach ( $order->get_items() as $item ) {
        $is_tip = ( $item->get_product_id() == $tip_product_id );
        $name   = $is_tip ? 'Platform Tip' : $campaign_name;
        $id     = $is_tip ? $item->get_product_id() : ($campaign_id ?: $item->get_product_id());
        $price  = $order->get_item_total( $item );
        $qty    = $item->get_quantity();
        $items[] = "{'item_name': '" . esc_js($name) . "', 'item_id': '" . esc_js($id) . "', 'price': " . $price . ", 'quantity': " . $qty . "}";
    }

    ?>
    <script>
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({
            'event': 'purchase',
            'ecommerce': {
                'transaction_id': '<?php echo esc_js( $order_id ); ?>',
                'value': <?php echo $total; ?>,
                'tax': <?php echo $tax; ?>,
                'shipping': <?php echo $shipping; ?>,
                'currency': '<?php echo esc_js( $currency ); ?>',
                'items': [<?php echo implode(',', $items); ?>]
            }
        });
        if (typeof fbq === 'function') {
            fbq('track', 'Purchase', { value: <?php echo $total; ?>, currency: '<?php echo esc_js( $currency ); ?>' });
        }
    </script>
    <?php
}
