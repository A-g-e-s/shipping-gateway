<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Tracking;

/**
 * Odkud ParcelTracking::addStatus() bere datum doručení, když je doručených stavů víc.
 */
enum DeliveredDate
{
    /** Nejpozdější datum mezi doručenými stavy (stav bez data datum nepřepíše). */
    case Latest;

    /** Datum posledního přidaného doručeného stavu, i když je null (GLS, PPL, Česká pošta). */
    case LastAdded;
}
