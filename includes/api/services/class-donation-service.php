<?php

/**
 * Donation Service - Handles donation-related business logic
 */

use Growfund\Services\DonationService;
use Growfund\DTO\Donation\DonationFilterParamsDTO;
use Growfund\DTO\PaginatedCollectionDTO;
use Growfund\DTO\Donation\DonationDTO;


if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_Donation_Service { 

    protected $campaign_service;

    public function __construct() {
        $this->service = new DonationService();
    }

    /**
     * Get paginated donations based on filters 
     */

    public function get_paginated_donations(DonationFilterParamsDTO $params)
    {
        $query = $this->service->get_query($params);

        $donations = $query->paginate(
            $params->page,
            $params->limit
        );

        foreach ($data['results'] as $key => $donation) {
            $data['results'][$key] = $this->service->prepare_donation_dto($donation);
        }

        $paginated_collection_dto = PaginatedCollectionDTO::from_array($data);

        return $paginated_collection_dto;


    }

    /**
     * Get Currencies
     * 
     * @return array|WP_Error
     */

    public function all_currencies() 
    {
        $currencies = get_option('gfcm_custom_currencies', array());

        if (empty($currencies)) {
            return new WP_Error(
                'Failed to retrieve currencies',
                'No currencies found in the system.',
                [
                    'status' => 404,
                ]
            );
        }

        return $currencies;
    }

    /**
     * Get Gateway & Platform rates
     * 
     * @return array|WP_Error
     */

    public function all_gateway_and_platform_rates() 
    {
        $gprates = get_option('gfcm_sifalo_rates', array());

        if (empty($gprates)) {
            return new WP_Error(
                'Failed to retrieve gateway &platform rates',
                'No gateway or platform rates found in the system.',
                [
                    'status' => 404,
                ]
            );
        }

        return $gprates;
    }

}
