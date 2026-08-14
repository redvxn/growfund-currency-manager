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

}
