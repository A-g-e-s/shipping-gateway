<?php

namespace Ages\ShippingGateway\Common;

interface CarrierInterface
{
    public function getParcelTracking(string $consignmentId): ?ParcelTrackingInterface;
}
