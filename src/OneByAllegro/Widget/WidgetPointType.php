<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\OneByAllegro\Widget;

/**
 * Typ výdejního místa ve widgetu (parametr fixedType).
 */
enum WidgetPointType: string
{
    /** One Box – výdejní box */
    case Box = 'Box';
    /** One Point – výdejní místo s obsluhou */
    case Point = 'Point';
}
