<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Ppl\Values;

enum ShipmentState: string
{
    case None = 'Bez stavu';
    case Undelivered = 'Nedoručeno';
    case Delivered = 'Doručeno';
    case PickedUpFromSender = 'Vyzvednuto u odesílatele';
    case DeliveredToPickupPoint = 'Doručeno do výdejního místa';
    case OutForDelivery = 'Na cestě k doručení';
    case NotDelivered = 'Nepodařilo se doručit';
    case CodPaidDate = 'Dobírka zaplacena';
    case BackToSender = 'Vráceno odesílateli';
    case Rejected = 'Odmítnuto příjemcem';
    case DataShipment = 'Zásilka zaregistrována';
    case Active = 'Aktivní';
    case Canceled = 'Zrušeno';
    case Dormant = 'Neaktivní';
}
