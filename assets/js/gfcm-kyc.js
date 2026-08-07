jQuery(document).ready(function ($) {
    let currentStep = 1;
    let totalSteps = 5; 
    let fundraiserType = 'myself';

    function getExpectedRecipientName() {
        let type = $('input[name="gfcm_kyc_type"]:checked').val();
        if (type === 'myself') {
            return $('#gfcm_kyc_contact_name').val() || $('#gfcm_registered_name').val();
        } else if (type === 'organization') {
            return $('#gfcm_kyc_org_name').val() || "[Organization Name]";
        } else if (type === 'someone_else') {
            let recipient = $('#gfcm_kyc_recipient_type').val();
            if (recipient === 'fundraiser') {
                return $('#gfcm_kyc_contact_name').val() || $('#gfcm_registered_name').val();
            } else {
                return $('#gfcm_kyc_beneficiary_name').val() || "[Beneficiary Name]";
            }
        }
        return '';
    }

    function updateUI() {
        fundraiserType = $('input[name="gfcm_kyc_type"]:checked').val();
        totalSteps = 5;
        
        $('.kyc-myself-fields, .kyc-someone-fields, .kyc-org-fields, .kyc-withdrawal-fields, .kyc-personal-contact, .kyc-org-contact, .kyc-personal-currency, .kyc-org-currency').hide();

        if (fundraiserType === 'myself') {
            $('.kyc-personal-contact, .kyc-myself-fields, .kyc-withdrawal-fields, .kyc-personal-currency').show();
            $('.kyc-personal-currency').css('display', 'flex'); 
        } else if (fundraiserType === 'someone_else') {
            $('.kyc-personal-contact, .kyc-someone-fields, .kyc-withdrawal-fields, .kyc-personal-currency').show();
            $('.kyc-personal-currency').css('display', 'flex'); 
        } else if (fundraiserType === 'organization') {
            $('.kyc-org-contact, .kyc-org-fields, .kyc-withdrawal-fields, .kyc-org-currency').show();
            $('.kyc-org-currency').css('display', 'flex'); 
        }

        let payoutMethod = $('input[name="gfcm_kyc_payout_method"]:checked').val();
        if (payoutMethod === 'bank') {
            $('#gfcm_bank_fields').show();
            $('#gfcm_mobile_money_fields').hide();
        } else {
            $('#gfcm_mobile_money_fields').show();
            $('#gfcm_bank_fields').hide();
        }

        // Dynamic Prefix Logic for Dropdown
        let provider = $('#gfcm_kyc_mobile_provider').val() || 'Edahab';
        let prefix = (provider === 'Mpessa') ? '254' : '252';
        $('#gfcm_mobile_prefix').text(prefix);

        let expectedName = getExpectedRecipientName();
        $('#kyc-match-warning').text(`ℹ️ Account Name must strictly match: ${expectedName}`);

        $('.gfcm-step').removeClass('active');
        $(`.gfcm-step[data-step="${currentStep}"]`).addClass('active');

        let progress = (currentStep / totalSteps) * 100;
        $('#gfcm-progress-bar').css('width', `${progress}%`);
        
        let titles = ["Let's Get Started", "Contact Information", "Verification", "Payout Details", "Final Setup"];
        $('#gfcm-step-title').text(titles[currentStep - 1]);
        $('#gfcm-step-counter').text(`Step ${currentStep} of ${totalSteps}`);

        if (currentStep === totalSteps) {
            $('#gfcm-consent-wrapper').show();
            $('#gfcm-next-btn').text('Submit Registration');
        } else {
            $('#gfcm-consent-wrapper').hide();
            $('#gfcm-next-btn').text('Continue');
        }

        $('#gfcm-prev-btn').toggle(currentStep > 1);
    }

    function validateStep(step) {
        let isValid = true;
        let $activeStep = $(`.gfcm-step[data-step="${step}"]`);

        $activeStep.find('.gfcm-error-msg').remove();

        // Update loop to include input[type="tel"]
        $activeStep.find('input[type="text"], input[type="tel"], input[type="file"], select, textarea').each(function() {
            let $el = $(this);

            if (!$el.is(':visible')) return;
            if ($el.is('input[type="file"]')) return; // Files are optional
            if ($el.attr('name') === 'gfcm_kyc_org_reg_number') return; // Optional

            let fieldValid = true;

            if ($el.is('input[type="text"], input[type="tel"], select, textarea')) {
                if ($el.val().trim() === '') fieldValid = false;
            }

            if (!fieldValid) {
                isValid = false;
                $el.css('border-color', '#d9534f');
                $('<span class="gfcm-error-msg">This field is required.</span>').insertAfter($el);
            }
        });

        return isValid;
    }

    $(document).on('input change', 'input, select, textarea', function() {
        $(this).css('border-color', 'var(--gfcm-border)');
        $(this).next('.gfcm-error-msg').remove();
        if ($(this).attr('id') === 'gfcm_kyc_consent') {
            $('#gfcm-consent-error').remove();
        }
    });

    $('input[name="gfcm_kyc_type"], input[name="gfcm_kyc_payout_method"], select[name="gfcm_kyc_mobile_provider"], select[name="gfcm_kyc_recipient_type"], input[name="gfcm_kyc_org_name"], input[name="gfcm_kyc_beneficiary_name"], input[name="gfcm_kyc_contact_name"]').on('change input', updateUI);

    $('#gfcm-next-btn').on('click', function () {
        if (!validateStep(currentStep)) {
            return;
        }

        if (currentStep === 4) {
            let payoutMethod = $('input[name="gfcm_kyc_payout_method"]:checked').val();
            
            // Name Matching Validation
            let expectedName = getExpectedRecipientName().toLowerCase().trim();
            let $nameInput = (payoutMethod === 'mobile_money') ? $('#gfcm_kyc_mobile_name') : $('#gfcm_kyc_bank_account_name');
            let enteredName = $nameInput.val().toLowerCase().trim();

            if (fundraiserType !== 'organization') {
                if (!enteredName || expectedName !== enteredName) {
                    $nameInput.css('border-color', '#d9534f');
                    $nameInput.next('.gfcm-error-msg').remove(); 
                    $(`<span class="gfcm-error-msg">Account Name must exactly match: <strong>${getExpectedRecipientName()}</strong></span>`).insertAfter($nameInput);
                    return; 
                }
            }
        }

        if (currentStep < totalSteps) {
            currentStep++;
            updateUI();
        } else {
            $('#gfcm-consent-error').remove();
            if (!$('#gfcm_kyc_consent').is(':checked')) {
                $('<div class="gfcm-error-msg" id="gfcm-consent-error">Please accept the Terms & Conditions and Privacy Policy to register.</div>').appendTo('#gfcm-consent-wrapper');
                return;
            }
            submitForm();
        }
    });

    $('#gfcm-prev-btn').on('click', function () {
        if (currentStep > 1) {
            currentStep--;
            updateUI();
        }
    });

    function submitForm() {
        // Concatenate prefix and number seamlessly into the hidden field
        let payoutMethod = $('input[name="gfcm_kyc_payout_method"]:checked').val();
        if (payoutMethod === 'mobile_money') {
            let finalMobile = $('#gfcm_mobile_prefix').text() + $('#gfcm_kyc_mobile_number_visible').val().trim();
            $('#gfcm_kyc_mobile_number_hidden').val(finalMobile);
        }

        let formData = new FormData($('#gfcm-kyc-form')[0]);
        formData.append('action', 'gfcm_submit_kyc');
        formData.append('nonce', gfcm_kyc_params.nonce);

        $('#gfcm-next-btn').prop('disabled', true).text('Processing...');

        $.ajax({
            url: gfcm_kyc_params.ajax_url,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function (response) {
                if (response.success) {
                    window.location.href = response.data.redirect;
                } else {
                    alert('Server Error: ' + response.data.message);
                    $('#gfcm-next-btn').prop('disabled', false).text('Submit Registration');
                }
            }
        });
    }

    updateUI();
});