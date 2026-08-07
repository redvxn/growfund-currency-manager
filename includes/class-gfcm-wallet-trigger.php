<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GFCM Wallet Trigger
 * Automatically fires the WalletTransactionSyncService in the background
 * immediately after a successful donation.
 */
class GFCM_Wallet_Trigger {

    public static function init() {
        // 1. Listen for donation status updates
        add_action( 'gfcm_donation_status_updated', [ __CLASS__, 'schedule_wallet_sync' ], 10, 4 );

        // 2. The background worker hook
        add_action( 'gfcm_background_wallet_sync', [ __CLASS__, 'execute_wallet_sync' ] );
    }

    /**
     * Intercepts a completed or pending donation and queues the sync job.
     */
    public static function schedule_wallet_sync( $contribution_id, $campaign_id, $order, $gf_status ) {
        // Run this when a donation is successfully completed OR pending (e.g. bank transfer)
        if ( $gf_status === 'completed' || $gf_status === 'pending' ) {
            if ( function_exists('as_schedule_single_action') ) {
                // Schedule it to run immediately in the background so checkout is instant
                as_schedule_single_action( time(), 'gfcm_background_wallet_sync', [], 'growfund' );
            }
        }
    }

    /**
     * The actual background task that runs the sync script.
     */
    public static function execute_wallet_sync() {
        // Make sure the GrowfundPro class actually exists before calling it
        if ( class_exists( '\GrowfundPro\Services\WalletTransactionSyncService' ) ) {
            try {
                $sync_service = new \GrowfundPro\Services\WalletTransactionSyncService();
                
                // Call the public sync() method (which routes to sync_donations())
                $sync_service->sync();
                
            } catch ( \Exception $e ) {
                // If something goes wrong, log it so it doesn't crash the server
                error_log( 'GFCM Wallet Sync Error: ' . $e->getMessage() );
            }
        }
    }
}

GFCM_Wallet_Trigger::init();