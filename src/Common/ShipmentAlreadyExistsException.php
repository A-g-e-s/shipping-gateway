<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common;

use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;

/**
 * Dopravce už má zásilku se stejnou referencí (Gebrüder Weiss: HTTP 409).
 *
 * Když dopravce štítky negeneruje sám a dají se odvodit z reference (Gebrüder Weiss: SSCC z reference
 * a pořadí balíku), nese výjimka štítky, které odpovídají už existující zásilce – za předpokladu,
 * že balíky jsou stejné jako při prvním odeslání. Jestli je použít, rozhoduje volající.
 */
class ShipmentAlreadyExistsException extends ShippingException
{
    /**
     * @param ShipmentLabel[] $labels prázdné, když je dopravce odvodit neumí (např. import)
     */
    public function __construct(
        string $message,
        public readonly array $labels = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
