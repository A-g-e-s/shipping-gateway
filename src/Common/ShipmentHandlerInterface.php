<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common;

use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;

/**
 * Handler dopravce registrovaný v ShippingGateway: zakládá zásilky a umí tracking (CarrierInterface).
 */
interface ShipmentHandlerInterface extends CarrierInterface
{
    /**
     * @return ShipmentLabel[]
     */
    public function createShipment(ShipmentRequest $request): array;

    public function getCarrier(): Carrier;
}
