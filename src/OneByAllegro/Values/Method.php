<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\OneByAllegro\Values;

enum Method: string
{
    case AuthToken = '/parcels/auth/token';
    case Shipments = '/parcels/shipments';
    case Labels = '/parcels/shipments/labels';
    case Tracking = '/parcels/tracking';
    case Points = '/parcels/points';
    case Pickups = '/parcels/pickups';
    case PickupProposals = '/parcels/pickups/proposals';
}
