<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common;

use Ages\ShippingGateway\Common\Shipment\ImportRequest;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;

/**
 * Handler, který umí objednat svoz od dodavatele k nám (ShippingGateway::createImport).
 */
interface ImportHandlerInterface
{
    /**
     * @return ShipmentLabel[]
     * @throws ShipmentAlreadyExistsException objednávka se stejnou referencí už u dopravce existuje (nese štítky)
     * @throws ShippingException
     */
    public function createImport(ImportRequest $request): array;
}
