<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\GebruderWeiss\Handler;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\ShipmentHandlerInterface;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;

class GebruderWeissShipmentHandler implements ShipmentHandlerInterface
{
    public function getCarrier(): Carrier
    {
        return Carrier::GebruderWeiss;
    }

    public function createShipment(ShipmentRequest $request): array
    {
        throw new \RuntimeException('Gebrüder Weiss integration is not yet implemented');
    }
}
