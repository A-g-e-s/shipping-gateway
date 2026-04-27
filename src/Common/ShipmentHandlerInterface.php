<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common;

use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;

interface ShipmentHandlerInterface
{
    /**
     * @return ShipmentLabel[]
     */
    public function createShipment(ShipmentRequest $request): array;

    public function getCarrierCode(): string;
}
