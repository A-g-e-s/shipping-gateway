<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Ppl\Tracking;

use Ages\ShippingGateway\Common\Data;
use Ages\ShippingGateway\Common\Tracking\DeliveredDate;
use Ages\ShippingGateway\Common\Tracking\ParcelStatus;
use Ages\ShippingGateway\Common\Tracking\ParcelTracking;
use Ages\ShippingGateway\Ppl\Values\ShipmentState;

/**
 * Zásilka z GET shipment → společné tracking entity. Doručeno je podle shipmentState (s datem
 * deliveryFeature.delivDate) a podle události s kódem „Delivered“. Poškození PPL v událostech nehlásí.
 */
final class PplTrackingMapper
{
    private const string DeliveredEventCode = 'Delivered';

    private function __construct()
    {
    }

    public static function tracking(\stdClass $shipment): ParcelTracking
    {
        $tracking = new ParcelTracking(
            parcelNumber: Data::string($shipment, 'shipmentNumber'),
            deliveryCountryCode: Data::string($shipment, ['recipient', 'country']),
            weight: Data::float($shipment, ['shipmentWeightInfo', 'weight']),
            deliveryZipCode: Data::string($shipment, ['recipient', 'zipCode']),
            clientReference: Data::string($shipment, ['externalNumbers', 0, 'externalNumber']),
            deliveredDatePolicy: DeliveredDate::LastAdded,
        );

        if (self::isDelivered(Data::string($shipment, 'shipmentState'))) {
            $tracking->markDelivered(Data::date(Data::get($shipment, ['deliveryFeature', 'delivDate'])));
        }

        // PPL vrací události od nejnovější
        $depot = Data::string($shipment, 'depot');
        foreach (array_reverse(Data::objects($shipment, ['trackAndTrace', 'events'])) as $event) {
            $tracking->addStatus(self::status($event, $depot));
        }

        return $tracking;
    }

    public static function status(\stdClass $event, string $depot = ''): ParcelStatus
    {
        $code = Data::string($event, 'code');
        $delivered = $code === self::DeliveredEventCode;

        return new ParcelStatus(
            statusCode: $code,
            statusDescription: Data::string($event, 'name'),
            statusDate: Data::date(Data::get($event, 'eventDate')),
            depotCity: '',
            depotCode: $depot,
            customInfo: $delivered ? 'Doručeno' : null,
            delivered: $delivered,
        );
    }

    /**
     * shipmentState může obsahovat víc stavů oddělených čárkou.
     */
    private static function isDelivered(string $states): bool
    {
        return in_array(ShipmentState::Delivered->name, array_map('trim', explode(',', $states)), true);
    }
}
