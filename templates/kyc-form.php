<?php
$uid = isset($_GET['uid']) ? intval($_GET['uid']) : 0;
$token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

if ( ! $uid || ! $token || get_user_meta( $uid, '_gfcm_kyc_token', true ) !== $token ) {
    echo '<div class="gfcm-notice error">Invalid or expired session. Please log in.</div>';
    return;
}

$user = get_userdata($uid);
$registered_name = trim($user->first_name . ' ' . $user->last_name);
?>

<div class="gfcm-kyc-container">
    <div class="gfcm-kyc-header">
        <h2>Start Your Fundraising Campaign</h2>
        <p>Complete your identity verification to raise funds for a cause you care about. It's easy to get started.</p>
    </div>

    <div class="gfcm-kyc-card">
        <div class="gfcm-kyc-progress-header">
            <h3 id="gfcm-step-title">Let's Get Started</h3>
            <span id="gfcm-step-counter">Step 1 of 5</span>
        </div>
        <div class="gfcm-progress-track">
            <div class="gfcm-progress-bar" id="gfcm-progress-bar" style="width: 20%;"></div>
        </div>

        <form id="gfcm-kyc-form" enctype="multipart/form-data">
            <input type="hidden" name="uid" value="<?php echo esc_attr($uid); ?>">
            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
            <input type="hidden" id="gfcm_registered_name" value="<?php echo esc_attr($registered_name); ?>">

            <div class="gfcm-step active" data-step="1">
                <h4>Who are you raising funds for?</h4>
                <p class="gfcm-text-muted">Tell us who you are raising funds for.</p>
                
                <label class="gfcm-radio-card">
                    <input type="radio" name="gfcm_kyc_type" value="myself" checked>
                    <div class="gfcm-radio-content">
                        <span class="gfcm-icon-circle">👤</span>
                        <div>
                            <strong>Myself</strong>
                            <p>Raising funds for your own needs.</p>
                        </div>
                    </div>
                </label>

                <label class="gfcm-radio-card">
                    <input type="radio" name="gfcm_kyc_type" value="someone_else">
                    <div class="gfcm-radio-content">
                        <span class="gfcm-icon-circle">👥</span>
                        <div>
                            <strong>Someone else</strong>
                            <p>Raise funds for another person or family.</p>
                        </div>
                    </div>
                </label>

                <label class="gfcm-radio-card">
                    <input type="radio" name="gfcm_kyc_type" value="organization">
                    <div class="gfcm-radio-content">
                        <span class="gfcm-icon-circle">🏢</span>
                        <div>
                            <strong>Organization</strong>
                            <p>Raise funds for a charity, NGO, or community project.</p>
                        </div>
                    </div>
                </label>
            </div>

            <div class="gfcm-step" data-step="2">
                <div class="kyc-personal-contact">
                    <h4>Contact Information</h4>
                    <label for="gfcm_kyc_contact_name">Full Name <span class="gfcm-required">*</span></label>
                    <input type="text" name="gfcm_kyc_contact_name" id="gfcm_kyc_contact_name" placeholder="E.g. Hebel Hebel" value="<?php echo esc_attr($registered_name); ?>">
                    
                    <label for="gfcm_kyc_contact_phone">Phone Number <span class="gfcm-required">*</span></label>
                    <input type="tel" name="gfcm_kyc_contact_phone" id="gfcm_kyc_contact_phone" placeholder="E.g. 252XXXXXXXXX" oninput="this.value = this.value.replace(/[^0-9+]/g, '')">
                    
                    <label for="gfcm_kyc_contact_address">Address <span class="gfcm-required">*</span></label>
                    <input type="text" name="gfcm_kyc_contact_address" id="gfcm_kyc_contact_address" placeholder="E.g. Wado ama Street">
                    
                    <label for="gfcm_kyc_contact_city">City <span class="gfcm-required">*</span></label>
                    <input type="text" name="gfcm_kyc_contact_city" id="gfcm_kyc_contact_city" placeholder="E.g. Magaalo ama City">
                </div>

                <div class="kyc-org-contact" style="display:none;">
                    <h4>Representative Contact Information</h4>
                    <label for="gfcm_kyc_rep_name">Representative Full Name <span class="gfcm-required">*</span></label>
                    <input type="text" name="gfcm_kyc_rep_name" id="gfcm_kyc_rep_name" placeholder="E.g. Hebel Hebel">
                    
                    <label for="gfcm_kyc_rep_role">Representative Organization Role <span class="gfcm-required">*</span></label>
                    <input type="text" name="gfcm_kyc_rep_role" id="gfcm_kyc_rep_role" placeholder="E.g. Director">
                    
                    <label for="gfcm_kyc_rep_phone">Representative Phone Number <span class="gfcm-required">*</span></label>
                    <input type="tel" name="gfcm_kyc_rep_phone" id="gfcm_kyc_rep_phone" placeholder="E.g. 252XXXXXXXXX" oninput="this.value = this.value.replace(/[^0-9+]/g, '')">
                    
                    <label for="gfcm_kyc_rep_address">Representative Address <span class="gfcm-required">*</span></label>
                    <input type="text" name="gfcm_kyc_rep_address" id="gfcm_kyc_rep_address" placeholder="E.g. Wado ama Street">
                    
                    <label for="gfcm_kyc_rep_city">Representative City <span class="gfcm-required">*</span></label>
                    <input type="text" name="gfcm_kyc_rep_city" id="gfcm_kyc_rep_city" placeholder="E.g. Magaalo ama City">
                    
                    <label>Your Passport / ID Number <span class="gfcm-required">*</span></label>
                    <input type="text" name="gfcm_kyc_fundraiser_id_number" placeholder="Enter your ID Number">
                    <label> Upload Your ID</label>
                    <input type="file" name="gfcm_kyc_fundraiser_id" accept=".jpg,.jpeg,.png,.pdf">
                    
                    <div style="margin-top: 25px; padding-top: 25px; border-top: 1px solid var(--gfcm-border);">
                        <h4>Authorized Person Verification</h4>
                        
                        <label>Authorized Person Passport / ID Number <span class="gfcm-required">*</span></label>
                        <input type="text" name="gfcm_kyc_auth_id_number" placeholder="Enter ID Number">
                        <label>Authorized Person ID Upload (Optional)</label>
                        <input type="file" name="gfcm_kyc_auth_id" accept=".jpg,.jpeg,.png,.pdf">
                    </div>
                </div>
            </div>

            <div class="gfcm-step" data-step="3">
                <div class="kyc-myself-fields">
                    <h4>Identity Verification</h4>
                    <label>Passport / ID Number <span class="gfcm-required">*</span></label>
                    <input type="text" name="gfcm_kyc_id_number" placeholder="Enter ID Number">
                    <label>Upload National ID / Passport (Optional)</label>
                    <input type="file" name="gfcm_kyc_id_upload" accept=".jpg,.jpeg,.png,.pdf">
                </div>

                <div class="kyc-someone-fields" style="display:none;">
                    <h4>Beneficiary Information</h4>
                    <label for="gfcm_kyc_beneficiary_name">Beneficiary Full Name <span class="gfcm-required">*</span></label>
                    <input type="text" name="gfcm_kyc_beneficiary_name" id="gfcm_kyc_beneficiary_name" placeholder="E.g. Hebel Hebel">
                    
                    <label for="gfcm_kyc_beneficiary_relation">Relationship to Beneficiary <span class="gfcm-required">*</span></label>
                    <input type="text" name="gfcm_kyc_beneficiary_relation" id="gfcm_kyc_beneficiary_relation" placeholder="E.g. Isku Reer ama Family">
                    
                    <label for="gfcm_kyc_beneficiary_contact">Beneficiary Contact <span class="gfcm-required">*</span></label>
                    <input type="tel" name="gfcm_kyc_beneficiary_contact" id="gfcm_kyc_beneficiary_contact" placeholder="E.g. 252XXXXXXXXX" oninput="this.value = this.value.replace(/[^0-9+]/g, '')">
                </div>

                <div class="kyc-org-fields" style="display:none;">
                    <h4>Organization Verification</h4>
                    <label for="gfcm_kyc_org_name">Organization Name <span class="gfcm-required">*</span></label>
                    <input type="text" name="gfcm_kyc_org_name" id="gfcm_kyc_org_name" placeholder="E.g. Magaca Organization ka">

                    <label for="gfcm_kyc_org_type">Organization Type <span class="gfcm-required">*</span></label>
                    <select name="gfcm_kyc_org_type" id="gfcm_kyc_org_type">
                        <option value="">Select an organization type...</option>
                        <option value="NGO">NGO (Non-Governmental Organization)</option>
                        <option value="Non-Profit">Non-Profit Organization</option>
                        <option value="Charity">Registered Charity</option>
                        <option value="Community_Group">Community Group</option>
                        <option value="Corporate">Corporate / Business</option>
                        <option value="Other">Other</option>
                    </select>
                    
                    <label for="gfcm_kyc_org_reg_number">Registration Number (Optional)</label>
                    <input type="text" name="gfcm_kyc_org_reg_number" id="gfcm_kyc_org_reg_number" placeholder="E.g. 123456789">
                    
                    <label for="gfcm_kyc_org_website">Website / Social Links <span class="gfcm-required">*</span></label>
                    <input type="text" name="gfcm_kyc_org_website" id="gfcm_kyc_org_website" placeholder="E.g. www.website.org">
                    
                    <label>Upload Registration Certificate (Optional)</label>
                    <input type="file" name="gfcm_kyc_org_cert" accept=".jpg,.jpeg,.png,.pdf">
                </div>
            </div>

            <div class="gfcm-step" data-step="4">
                <div class="kyc-withdrawal-fields">
                    <h4>Withdrawal Setup</h4>
                    <div class="kyc-someone-fields" style="display:none; margin-bottom:15px;">
                        <label for="gfcm_kyc_recipient_type">Choose recipient of funds:</label>
                        <select name="gfcm_kyc_recipient_type" id="gfcm_kyc_recipient_type">
                            <option value="fundraiser">Myself (Fundraiser)</option>
                            <option value="beneficiary">The Beneficiary</option>
                        </select>
                    </div>

                    <label>Choose Payout Method:</label>
                    <div style="display:flex; gap:10px; margin-bottom:15px;">
                        <label class="gfcm-radio-card" style="flex:1; margin-bottom:0;">
                            <input type="radio" name="gfcm_kyc_payout_method" value="mobile_money" checked>
                            <div class="gfcm-radio-content" style="padding:12px; justify-content:center; border-width:1px;">
                                <strong style="font-size:14px; margin:0;">📱 Mobile Money</strong>
                            </div>
                        </label>
                        <label class="gfcm-radio-card" style="flex:1; margin-bottom:0;">
                            <input type="radio" name="gfcm_kyc_payout_method" value="bank">
                            <div class="gfcm-radio-content" style="padding:12px; justify-content:center; border-width:1px;">
                                <strong style="font-size:14px; margin:0;">🏦 Bank Transfer</strong>
                            </div>
                        </label>
                    </div>

                    <div id="gfcm_mobile_money_fields">
                        <label for="gfcm_kyc_mobile_provider">Select Mobile Money Provider <span class="gfcm-required">*</span></label>
                        <select name="gfcm_kyc_mobile_provider" id="gfcm_kyc_mobile_provider">
                            <option value="Edahab">eDahab</option>
                            <option value="Zaad">Zaad</option>
                            <option value="EVC">EVC Plus</option>
                            <option value="Sahal">Sahal</option>
                            <option value="Mpessa">M-Pesa</option>
                        </select>

                        <label for="gfcm_kyc_mobile_name">Mobile Money Account Name <span class="gfcm-required">*</span></label>
                        <input type="text" name="gfcm_kyc_mobile_name" id="gfcm_kyc_mobile_name" placeholder="E.g. Hebel Hebel">

                        <label for="gfcm_kyc_mobile_number_visible">Mobile Money Account Number <span class="gfcm-required">*</span></label>
                        <div style="display:flex; border: 1px solid var(--gfcm-border); border-radius: 6px; overflow: hidden; margin-bottom: 15px; background: #fff;">
                            <span id="gfcm_mobile_prefix" style="background: #f1f1f1; padding: 12px 15px; border-right: 1px solid var(--gfcm-border); font-weight: 600; color: #555;">252</span>
                            <input type="tel" id="gfcm_kyc_mobile_number_visible" placeholder="XXXXXXX" style="border: none; margin: 0; flex: 1; border-radius: 0; box-shadow: none; outline: none; padding: 12px 15px;" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        </div>
                        <input type="hidden" name="gfcm_kyc_mobile_number" id="gfcm_kyc_mobile_number_hidden">
                    </div>
                    
                    <div id="gfcm_bank_fields" style="display:none;">
                        <label for="gfcm_kyc_bank_name">Bank Name <span class="gfcm-required">*</span></label>
                        <input type="text" name="gfcm_kyc_bank_name" id="gfcm_kyc_bank_name" placeholder="E.g. Dahabshiil International Bank">

                        <label for="gfcm_kyc_bank_account_name">Account Holder Name <span class="gfcm-required">*</span></label>
                        <input type="text" name="gfcm_kyc_bank_account_name" id="gfcm_kyc_bank_account_name" placeholder="E.g. Hebel Hebel">

                        <label for="gfcm_kyc_bank_account_number">Account Number <span class="gfcm-required">*</span></label>
                        <input type="text" name="gfcm_kyc_bank_account_number" id="gfcm_kyc_bank_account_number" placeholder="E.g. 123456789">
                    </div>
                    
                    <div class="gfcm-alert-box" id="kyc-match-warning" style="color:#d9534f; background:#fdf0f0; border:1px solid #f2dede;">
                        ℹ️ Account Name must match exactly.
                    </div>
                </div>
            </div>

            <div class="gfcm-step" data-step="5">
                <h4>Preferred Payout Currency</h4>
                
                <div class="kyc-personal-currency" style="display:flex; gap:10px; margin-bottom:15px;">
                    <label class="gfcm-radio-card" style="flex:1; margin-bottom:0;">
                        <input type="radio" name="gfcm_kyc_payout_currency" value="USD" checked>
                        <div class="gfcm-radio-content" style="padding:10px; justify-content:center; border-width:1px;">
                            <strong style="font-size:14px; margin:0;">🇺🇸 USD</strong>
                        </div>
                    </label>
                    <label class="gfcm-radio-card" style="flex:1; margin-bottom:0;">
                        <input type="radio" name="gfcm_kyc_payout_currency" value="SLSH">
                        <div class="gfcm-radio-content" style="padding:10px; justify-content:center; border-width:1px;">
                            <strong style="font-size:14px; margin:0; display:flex; align-items:center; gap:6px;">
                                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/4/4d/Flag_of_Somaliland.svg/330px-Flag_of_Somaliland.svg.png" alt="Somaliland Flag" style="width: 20px; height: auto; border-radius: 2px;"> SLSH
                            </strong>
                        </div>
                    </label>
                    <label class="gfcm-radio-card" style="flex:1; margin-bottom:0;">
                        <input type="radio" name="gfcm_kyc_payout_currency" value="KES">
                        <div class="gfcm-radio-content" style="padding:10px; justify-content:center; border-width:1px;">
                            <strong style="font-size:14px; margin:0;">🇰🇪 KES</strong>
                        </div>
                    </label>
                </div>

                <div class="kyc-org-currency" style="display:none; gap:10px; margin-bottom:15px;">
                    <label class="gfcm-radio-card" style="flex:1; margin-bottom:0;">
                        <input type="radio" name="gfcm_kyc_payout_currency_org" value="USD" checked>
                        <div class="gfcm-radio-content" style="padding:10px; justify-content:center; border-width:1px;">
                            <strong style="font-size:14px; margin:0;">🇺🇸 USD</strong>
                        </div>
                    </label>
                    <label class="gfcm-radio-card" style="flex:1; margin-bottom:0;">
                        <input type="radio" name="gfcm_kyc_payout_currency_org" value="SLSH">
                        <div class="gfcm-radio-content" style="padding:10px; justify-content:center; border-width:1px;">
                            <strong style="font-size:14px; margin:0; display:flex; align-items:center; gap:6px;">
                                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/4/4d/Flag_of_Somaliland.svg/330px-Flag_of_Somaliland.svg.png" alt="Somaliland Flag" style="width: 20px; height: auto; border-radius: 2px;"> SLSH
                            </strong>
                        </div>
                    </label>
                    <label class="gfcm-radio-card" style="flex:1; margin-bottom:0;">
                        <input type="radio" name="gfcm_kyc_payout_currency_org" value="KES">
                        <div class="gfcm-radio-content" style="padding:10px; justify-content:center; border-width:1px;">
                            <strong style="font-size:14px; margin:0;">🇰🇪 KES</strong>
                        </div>
                    </label>
                </div>
            </div>

            <div id="gfcm-consent-wrapper" style="display:none; margin-top:25px; padding-top:20px; border-top:1px solid var(--gfcm-border);">
                <label style="display:flex; align-items:flex-start; gap:10px; font-weight:normal; cursor:pointer;">
                    <input type="checkbox" name="gfcm_kyc_consent" id="gfcm_kyc_consent" style="margin-top:4px;">
                    <span style="font-size:13px; color:var(--gfcm-text-muted);">
                        I have read and agree to the website <a href="/terms-and-conditions" target="_blank" style="color:var(--gfcm-primary); text-decoration:underline;">Terms and Conditions</a> and <a href="/privacy-policy" target="_blank" style="color:var(--gfcm-primary); text-decoration:underline;">Privacy Policy</a>.
                    </span>
                </label>
            </div>
            
            <div class="cf-turnstile" data-sitekey="0x4AAAAAAC9i2cIl62vvwghG" data-theme="light" style="margin-bottom: 15px;"></div>

            <div class="gfcm-kyc-actions">
                <button type="button" id="gfcm-prev-btn" style="display:none;">Back</button>
                <button type="button" id="gfcm-next-btn" class="gfcm-btn-primary">Continue</button>
            </div>
        </form>
    </div>
</div>