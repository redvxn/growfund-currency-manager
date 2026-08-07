jQuery(document).ready(function ($) {
    
    // --- 1. GRAB PREFILL DATA FROM PHP ---
    let prefillAmt = fude_checkout_params.prefill_amt ? parseFloat(fude_checkout_params.prefill_amt) : 0;
    let prefillTip = fude_checkout_params.prefill_tip ? parseFloat(fude_checkout_params.prefill_tip) : 0;
    
    // Grab existing cart session so we don't accidentally wipe it out!
    let sessionAmt = fude_checkout_params.session_amt ? parseFloat(fude_checkout_params.session_amt) : 0;
    let sessionTip = fude_checkout_params.session_tip ? parseFloat(fude_checkout_params.session_tip) : 0;

    // --- 2. INITIALIZE VARIABLES (FIX: Default is now 0!) ---
    // Hierarchy: 1. Mobile Prefill -> 2. Existing Session -> 3. Default to 0
    let currentDonation = prefillAmt > 0 ? prefillAmt : (sessionAmt > 0 ? sessionAmt : 0); 
    let currentTip = prefillTip > 0 ? prefillTip : (sessionTip > 0 ? sessionTip : 0);

    // --- 3. POPULATE INPUT FIELDS ON LOAD ---
    if (currentDonation > 0) {
        $('#custom-donation-amount').val(currentDonation);
        $('.donation-btn').removeClass('active');
        $(`.donation-btn[data-amount="${currentDonation}"]`).addClass('active');
    }
    
    if (currentTip > 0) {
        $('#custom-tip-amount').val(currentTip.toFixed(2));
        $('.tip-btn').removeClass('active');
    }

    // --- HELPER: Visual Currency Formatter (Forces SOS to SLSH) ---
    function getCleanSymbol(rawSymbol) {
        let clean = rawSymbol ? rawSymbol.trim() : '$';
        return clean === 'SOS' ? 'SLSH ' : clean;
    }

    // --- NEW: Reusable UI Updater for Buttons and Stats ---
    function updateCurrencyUI(value, exchangeRate) {
        let rawSymbol = value === 'USD' ? '$' : value;
        let displaySymbol = getCleanSymbol(rawSymbol) === '$' ? '$' : getCleanSymbol(rawSymbol) + ' ';

        // 1. Update Raised Amount
        let statEl = $('.fude-stat-value');
        let rawRaisedUsd = parseFloat(statEl.attr('data-raw-usd')) || 0;
        
        if (rawRaisedUsd >= 0) {
            let convertedRaised = value === 'USD' ? (rawRaisedUsd * exchangeRate).toFixed(2) : Math.round(rawRaisedUsd * exchangeRate);
            let formattedRaised = '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">' + displaySymbol + '</span>' + convertedRaised + '</bdi></span>';
            statEl.html(formattedRaised);
        }

        // 2. Update Donation Buttons
        $('.donation-btn').each(function() {
            let rawBtnUsd = parseFloat($(this).attr('data-raw-usd')) || 0;
            
            if (rawBtnUsd > 0) {
                let convertedBtn = value === 'USD' ? (rawBtnUsd * exchangeRate).toFixed(0) : Math.round(rawBtnUsd * exchangeRate);
                $(this).text(displaySymbol + convertedBtn);
                $(this).attr('data-amount', convertedBtn); 
                $(this).data('amount', convertedBtn); 
            }
        });
    }

    // --- State Evaluator for Tips ---
    function evaluateFormState() {
        if (currentDonation > 0) {
            $('.tip-btn').removeClass('gfcm-disabled');
            $('#custom-tip-amount').prop('readonly', false).removeClass('gfcm-disabled');
            $('#gfcm-tip-inline-error').slideUp(200, function(){ $(this).remove(); });
        } else {
            $('.tip-btn').addClass('gfcm-disabled').removeClass('active');
            $('#custom-tip-amount').prop('readonly', true).addClass('gfcm-disabled').val('');
            currentTip = 0;
        }
    }

    // --- SILENT BACKEND SYNC ---
    let syncTimeout;
    function silentBackendSync() {
        clearTimeout(syncTimeout);
        syncTimeout = setTimeout(function() {
            $.ajax({
                type: 'POST',
                url: fude_checkout_params.ajax_url,
                data: {
                    action: 'gfcm_update_totals',
                    amount: currentDonation,
                    tip: currentTip,
                    nonce: fude_checkout_params.nonce
                }
            });
        }, 300); 
    }

    // --- Instant Local UI Math Engine ---
    function calculateInstantSummary() {
        // Fetch and clean the symbol so SOS becomes SLSH in the table!
        let rawSymbol = $('#payment-custom-wrap').attr('data-symbol') || '$';
        let symbol = getCleanSymbol(rawSymbol);
        
        // Add a space after the symbol if it's not a dollar sign to make it look nice
        let displaySymbol = symbol === '$' ? '$' : symbol + ' ';

        let convertedDonation = parseFloat(currentDonation) || 0;
        let convertedTip = parseFloat(currentTip) || 0;
        let convertedTotal = convertedDonation + convertedTip;

        let formatPrice = function(amount) {
            return '<bdi><span class="woocommerce-Price-currencySymbol">' + displaySymbol + '</span>' + amount.toFixed(2) + '</bdi>';
        };

        $('table.woocommerce-checkout-review-order-table tbody .cart_item').each(function() {
            let rowText = $(this).find('.product-name').text().toLowerCase();
            if (rowText.includes('tip')) {
                $(this).find('.amount').html(formatPrice(convertedTip));
            } else {
                $(this).find('.amount').html(formatPrice(convertedDonation));
            }
        });

        $('.cart-subtotal .amount').html(formatPrice(convertedTotal));
        $('.order-total .amount').html(formatPrice(convertedTotal));
    }

    // --- INITIALIZE UI & BACKEND ---
    evaluateFormState();
    
    // NEW: Force the buttons and stats to apply the SLSH override on page load!
    let initialCurrency = $('#payment-custom-wrap').attr('data-symbol') || 'USD';
    let initialRate = parseFloat($('#payment-custom-wrap').attr('data-rate')) || 1;
    updateCurrencyUI(initialCurrency, initialRate);
    
    calculateInstantSummary(); 
    
    // Only fire background sync on load if there's actual data to save
    if (currentDonation > 0 || currentTip > 0) {
        silentBackendSync();       
    }

    // --- CLEAR MOBILE SESSION ---
    if (prefillAmt > 0 || prefillTip > 0) {
        $.post(fude_checkout_params.ajax_url, {
            action: 'gfcm_clear_mobile_session',
            nonce: fude_checkout_params.nonce
        });
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    // --- Helper: Auto-Recalculate Tip ---
    function recalculateActiveTip() {
        let $activeTipBtn = $('.tip-btn.active');
        if ($activeTipBtn.length > 0) {
            let percentValue = parseFloat($activeTipBtn.data('tip-percent'));
            if (!isNaN(percentValue)) {
                currentTip = (percentValue / 100) * currentDonation;
                $('#custom-tip-amount').val(currentTip.toFixed(2)); 
            }
        }
    }

    // --- Donation Amount Logic ---
    $('.donation-btn').on('click', function (e) {
        e.preventDefault();
        currentDonation = parseFloat($(this).data('amount'));
        
        $('.donation-btn').removeClass('active');
        $(this).addClass('active');
        
        $('#custom-donation-amount').val(currentDonation);

        evaluateFormState();
        recalculateActiveTip(); 
        calculateInstantSummary(); 
        silentBackendSync(); 
    });

    $('#custom-donation-amount').on('input change', function () {
        $('.donation-btn').removeClass('active');
        currentDonation = parseFloat($(this).val()) || 0;

        evaluateFormState();
        recalculateActiveTip(); 
        calculateInstantSummary(); 
        silentBackendSync(); 
    });

    // --- Tip Amount Logic ---
    $('.tip-btn').on('click', function (e) {
        e.preventDefault();
        if ($(this).hasClass('gfcm-disabled')) return;

        $('.tip-btn').removeClass('active');
        $(this).addClass('active');

        let percentValue = parseFloat($(this).data('tip-percent'));

        if (!isNaN(percentValue)) {
            currentTip = (percentValue / 100) * currentDonation;
        } else {
            currentTip = parseFloat($(this).data('amount')) || 0;
        }
        
        $('#custom-tip-amount').val(currentTip.toFixed(2));

        calculateInstantSummary(); 
        silentBackendSync(); 
    });

    $('#custom-tip-amount').on('input change', function () {
        if ($(this).hasClass('gfcm-disabled')) return;
        $('.tip-btn').removeClass('active');
        currentTip = parseFloat($(this).val()) || 0;

        calculateInstantSummary(); 
        silentBackendSync(); 
    });

    // --- Custom Dropdown & FAST Currency Switcher ---
    const selectContainer = $('.fude-select-container');
    const selectTrigger = $('.fude-select-trigger');
    const selectOptions = $('.fude-select-option');
    const hiddenInput = $('#custom-currency-dropdown');

    selectTrigger.on('click', function (e) {
        e.stopPropagation();
        selectContainer.toggleClass('open');
    });

    $(document).on('click', function () {
        selectContainer.removeClass('open');
    });

    selectOptions.on('click', function (e) {
        e.stopPropagation();

        let value = $(this).data('value');
        let content = $(this).html();
        
        let exchangeRate = parseFloat($(this).attr('data-rate')) || 1;

        $('.fude-select-selected').html(content);
        selectOptions.removeClass('selected');
        $(this).addClass('selected');
        selectContainer.removeClass('open');

        if (hiddenInput.val() !== value) {
            hiddenInput.val(value);

            // Update the tracker attributes
            $('#payment-custom-wrap').attr('data-rate', exchangeRate).attr('data-symbol', value);

            // 1. Call our new reusable function to update buttons and stats!
            updateCurrencyUI(value, exchangeRate);

            // 2. Reset UI states for the new currency
            $('.donation-btn').removeClass('active');
            $('#custom-donation-amount').val('');
            currentDonation = 0;
            currentTip = 0;
            
            evaluateFormState();
            calculateInstantSummary(); 

            // 3. Silent backend sync
            $.post(fude_checkout_params.ajax_url, {
                action: 'gfcm_set_currency',
                currency: value,
                nonce: fude_checkout_params.nonce
            }, function () {
                $(document.body).trigger('update_checkout');
            });
        }
    });

    // --- BACKEND REBUILD & LOAD LOCK ON SUBMIT ---
    $('form.checkout').on('submit', function(e) {
        let $form = $(this);
        if ($form.hasClass('gfcm-processing')) return true; 

        e.preventDefault(); 
        let $submitBtn = $('#place_order');
        let originalText = $submitBtn.text() || $submitBtn.val();

        $form.addClass('gfcm-processing');
        $submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing Donation...');

        $.ajax({
            type: 'POST',
            url: fude_checkout_params.ajax_url,
            data: {
                action: 'gfcm_update_totals',
                amount: currentDonation,
                tip: currentTip,
                nonce: fude_checkout_params.nonce
            },
            success: function() {
                setTimeout(function() {
                    $submitBtn.prop('disabled', false).html(originalText);
                    $form.submit(); 
                }, 200);
            }
        });
    });

    // --- Relocate Place Order Section ---
    function movePlaceOrder() {
        let $paymentPlaceOrder = $('.woocommerce-checkout-payment .place-order');
        
        if ($paymentPlaceOrder.length > 0) {
            // 1. Delete the PHP dummy button so they don't stack
            $('.gfcm-dummy-wrapper').remove(); 
            
            // 2. Remove any old injected buttons (from previous AJAX refreshes)
            $('.checkout-right-col > .place-order').remove();
            
            // 3. Drop the REAL, secure WooCommerce button into place!
            $('.checkout-right-col').append($paymentPlaceOrder);
        }
    }
    movePlaceOrder();

    // --- Turnstile Re-initialization ---
    function renderTurnstileWidget() {
        if (typeof turnstile !== 'undefined') {
            $('.cf-turnstile').each(function() {
                $(this).empty(); 
                turnstile.render(this, {
                    sitekey: $(this).data('sitekey') || '0x4AAAAAAC9i2cIl62vvwghG',
                    theme: $(this).data('theme') || 'light'
                });
            });
        }
    }
    renderTurnstileWidget();

    // --- WooCommerce Gateway Refresh Listeners ---
    $(document.body).on('updated_checkout', function () {
        movePlaceOrder();
        renderTurnstileWidget();
        calculateInstantSummary(); 
    });
});