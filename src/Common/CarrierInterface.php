<?php

namespace Ages\ShippingGateway\Common;

interface CarrierInterface
{
    public function getParcelTracking(string $consignmentId): ?ParcelTrackingInterface;

    public function getTrackingUrl(string $consignmentId): string;
}
