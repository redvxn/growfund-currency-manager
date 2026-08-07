<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GFCM Emails Handler
 * Extracts all email scheduling logic out of the core checkout file.
 */
class GFCM_Emails {

    public static function init() {
        // Hook into the custom actions we will place in class-gfcm-core.php
        add_action( 'gfcm_donation_created', [ __CLASS__, 'queue_creation_emails' ], 10, 3 );
        add_action( 'gfcm_donation_status_updated', [ __CLASS__, 'queue_status_emails' ], 10, 5 );

        // GLOBAL HOOKS TO REPLACE BROKEN NATIVE EVENT DISPATCHERS
        add_action( 'user_register', [ __CLASS__, 'queue_user_registration_emails' ], 999, 1 );
        
        // Hooks for Growfund's custom campaign status postmeta updates
        add_action( 'added_post_meta', [ __CLASS__, 'queue_campaign_meta_status_emails' ], 10, 4 );
        add_action( 'updated_post_meta', [ __CLASS__, 'queue_campaign_meta_status_emails' ], 10, 4 );
    }

    /**
     * Helper to grab the Fundraiser ID safely
     */
    private static function get_fundraiser_id( $campaign_id ) {
        return (int) get_post_meta( $campaign_id, 'growfund_fundraiser_id', true );
    }

    /**
     * Fired when "Donate Now" is clicked and the order is initially generated
     */
    public static function queue_creation_emails( $contribution_id, $campaign_id, $order ) {
        if ( ! function_exists('as_schedule_single_action') ) return;

        // 1. Admin New Donation
        // FIXED: Restored 'receiver_user_id' => 0. Growfund strictly requires this magic number for admin routing.
        as_schedule_single_action( time(), 'growfund_scheduled_emails', [
            [
                'class' => '\Growfund\Mails\NewDonationMail',
                'args'  => [
                    'content_key'      => 'admin_email_new_donation',
                    'donation_id'      => $contribution_id,
                    'receiver_user_id' => 0,
                ]
            ]
        ], 'growfund');

        // 2. Fundraiser New Donation
        $fundraiser_id = self::get_fundraiser_id( $campaign_id );
        if ( $fundraiser_id ) {
            as_schedule_single_action( time(), 'growfund_scheduled_emails', [
                [
                    'class' => '\Growfund\Mails\NewDonationMail',
                    'args'  => [
                        'content_key'      => 'fundraiser_email_new_donation',
                        'donation_id'      => $contribution_id,
                        'receiver_user_id' => $fundraiser_id,
                    ]
                ]
            ], 'growfund');
        }
        
        // 3. Offline Donation Instructions (If using Direct Bank Transfer / BACS)
        if ( $order && $order->get_payment_method() === 'bacs' ) {
             as_schedule_single_action( time(), 'growfund_scheduled_emails', [
                [
                    'class' => '\Growfund\Mails\Donor\OfflineDonationInstructionMail',
                    'args'  => [
                        'donation_id' => $contribution_id,
                    ]
                ]
            ], 'growfund');
        }
    }

    /**
     * Fired when the WooCommerce order status updates (Processing, Completed, Failed, Cancelled)
     */
    public static function queue_status_emails( $contribution_id, $campaign_id, $order, $gf_status, $wc_status ) {
        if ( ! function_exists('as_schedule_single_action') ) return;

        $fundraiser_id = self::get_fundraiser_id( $campaign_id );

        // SUCCESS EMAILS
        if ( $gf_status === 'completed' ) {
            
            // Donor Receipt
            as_schedule_single_action( time(), 'growfund_scheduled_emails', [
                [
                    'class' => '\Growfund\Mails\Donor\DonationReceiptMail',
                    'args'  => [
                        'donation_id' => $contribution_id
                    ]
                ]
            ], 'growfund');

            // Fundraiser Amount Charged
            if ( $fundraiser_id && class_exists('\GrowfundPro\Mails\Fundraiser\DonationAmountChargedMail') ) {
                as_schedule_single_action( time(), 'growfund_scheduled_emails', [
                    [
                        'class' => '\GrowfundPro\Mails\Fundraiser\DonationAmountChargedMail',
                        'args'  => [
                            'donation_id'      => $contribution_id,
                            'receiver_user_id' => $fundraiser_id
                        ]
                    ]
                ], 'growfund');
            }

        // FAILED EMAILS
        } elseif ( $gf_status === 'failed' ) {
            // Donor Failed Notice
            as_schedule_single_action( time(), 'growfund_scheduled_emails', [
                [
                    'class' => '\Growfund\Mails\Donor\DonationFailedMail',
                    'args'  => [
                        'donation_id' => $contribution_id
                    ]
                ]
            ], 'growfund');

        // CANCELLED EMAILS
        } elseif ( $gf_status === 'cancelled' ) {
            // Fundraiser Cancelled Notice
            if ( $fundraiser_id && class_exists('\GrowfundPro\Mails\Fundraiser\DonationCancelledMail') ) {
                as_schedule_single_action( time(), 'growfund_scheduled_emails', [
                    [
                        'class' => '\GrowfundPro\Mails\Fundraiser\DonationCancelledMail',
                        'args'  => [
                            'donation_id'      => $contribution_id,
                            'receiver_user_id' => $fundraiser_id
                        ]
                    ]
                ], 'growfund');
            }
        }
    }

    /**
     * Fired when a new user registers in WordPress
     */
    public static function queue_user_registration_emails( $user_id ) {
        if ( ! function_exists('as_schedule_single_action') ) return;

        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $roles = (array) $user->roles;
        
        if ( empty($roles) && isset($_POST['role']) ) {
            $roles[] = sanitize_text_field($_POST['role']);
        }

        // Strictly define our roles
        $is_donor = in_array( 'growfund_donor', $roles );

        // 2. Queue Admin Notification 
        as_schedule_single_action( time(), 'growfund_scheduled_emails', [
            [
                'class' => '\Growfund\Mails\NewUserMail',
                'args'  => [
                    'content_key'      => 'admin_email_new_user_registration',
                    'user_id'          => $user_id,
                    'receiver_user_id' => 0,
                ]
            ]
        ], 'growfund');

        // 3. Queue User Welcome Email (Strictly Donor Only)
        // Fundraiser emails are disabled, and we explicitly verify the donor role to prevent
        // admins, subscribers, or SEO managers from getting this email.
        if ( $is_donor ) {
            as_schedule_single_action( time(), 'growfund_scheduled_emails', [
                [
                    'class' => '\Growfund\Mails\NewUserMail',
                    'args'  => [
                        'content_key'      => 'donor_email_new_donor_registration', 
                        'user_id'          => $user_id,
                        'receiver_user_id' => $user_id,
                    ]
                ]
            ], 'growfund');
        }

        /**
        // 4. Email Verification Engine
        $verification_class = '';
        if ( class_exists('\Growfund\Mails\EmailVerificationMail') ) {
            $verification_class = '\Growfund\Mails\EmailVerificationMail';
        } elseif ( class_exists('\Growfund\Mails\User\EmailVerificationMail') ) {
            $verification_class = '\Growfund\Mails\User\EmailVerificationMail';
        } elseif ( class_exists('\Growfund\Mails\VerificationMail') ) {
            $verification_class = '\Growfund\Mails\VerificationMail';
        }

        if ( ! empty( $verification_class ) ) {
            $token = wp_generate_password( 32, false );
            update_user_meta( $user_id, '_growfund_email_verification_token', $token );
            update_user_meta( $user_id, 'growfund_email_verified', 0 ); 

            as_schedule_single_action( time(), 'growfund_scheduled_emails', [
                [
                    'class' => $verification_class,
                    'args'  => [
                        'user_id' => $user_id,
                        'token'   => $token
                    ]
                ]
            ], 'growfund');
        }*/
    }

    /**
     * Fired when a campaign's 'growfund_status' postmeta changes
     */
    public static function queue_campaign_meta_status_emails( $meta_id, $post_id, $meta_key, $meta_value ) {
        if ( $meta_key !== 'growfund_status' ) return;
        if ( ! function_exists('as_schedule_single_action') ) return;

        $new_status = sanitize_text_field( $meta_value );
        
        $sent_flag = '_gfcm_emailed_status_' . $new_status;
        if ( get_post_meta( $post_id, $sent_flag, true ) ) return;

        $fundraiser_id = self::get_fundraiser_id( $post_id );

        // Campaign Submitted for Review (Pending)
        if ( $new_status === 'pending' ) {
            if ( class_exists('\GrowfundPro\Mails\Admin\NewCampaignSubmittedForReviewMail') ) {
                as_schedule_single_action( time(), 'growfund_scheduled_emails', [
                    [
                        'class' => '\GrowfundPro\Mails\Admin\NewCampaignSubmittedForReviewMail',
                        'args'  => [
                            'campaign_id'      => $post_id,
                            'receiver_user_id' => 0,
                        ]
                    ]
                ], 'growfund');
            }
        }

        // Campaign Approved (Published)
        if ( $new_status === 'published' ) {
            if ( $fundraiser_id && class_exists('\GrowfundPro\Mails\Fundraiser\CampaignApprovedMail') ) {
                as_schedule_single_action( time(), 'growfund_scheduled_emails', [
                    [
                        'class' => '\GrowfundPro\Mails\Fundraiser\CampaignApprovedMail',
                        'args'  => [
                            'campaign_id'      => $post_id,
                            'receiver_user_id' => $fundraiser_id,
                        ]
                    ]
                ], 'growfund');
            }
        }

        // Campaign Declined (Declined)
        if ( $new_status === 'declined' ) {
            if ( $fundraiser_id && class_exists('\GrowfundPro\Mails\Fundraiser\CampaignDeclinedMail') ) {
                as_schedule_single_action( time(), 'growfund_scheduled_emails', [
                    [
                        'class' => '\GrowfundPro\Mails\Fundraiser\CampaignDeclinedMail',
                        'args'  => [
                            'campaign_id' => $post_id,
                        ]
                    ]
                ], 'growfund');
            }
        }

        update_post_meta( $post_id, $sent_flag, 'yes' );
    }
}

GFCM_Emails::init();