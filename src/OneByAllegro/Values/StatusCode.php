<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\OneByAllegro\Values;

/**
 * Vybrané kódy stavů zásilky. Úplný číselník: https://api.onedelivery.tech/parcels/statuses.csv
 */
final class StatusCode
{
    public const string Delivered = 'DOR';
    public const string Damaged = 'USZ';
    public const string Lost = 'ZAG';

    /** Vráceno odesílateli. */
    public const array Returned = ['ZDN', 'ZDN_PN_APM', 'ZDN_PN_PUDO', 'ZDNP'];

    private function __construct()
    {
    }
}
