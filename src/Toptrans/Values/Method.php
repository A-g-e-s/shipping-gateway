<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Toptrans\Values;

/**
 * Metody API ZP2 – cesta {url}/json/{entita}/{metoda}/.
 */
enum Method: string
{
    case OrderPrice = 'order/price';
    /** Uloží neodeslanou objednávku (zůstává jen v ZP). */
    case OrderSave = 'order/save';
    case OrderList = 'order/list';
    case OrderDelete = 'order/delete';
    /** Odešle objednávky do systému TOPIS (= závazná objednávka přepravy včetně nakládky). */
    case OrderSend = 'order/send';
    case OrderArchive = 'order/archive';
    case OrderSearch = 'order/search';
    case PrintUnsentLabels = 'order/print-unsent-labels';
    /** Zasilatelský příkaz dávky (PDF). */
    case PrintList = 'order/print-list';
    /** Soupiska dávky (PDF). */
    case PrintRoll = 'order/print-roll';
    /** Štítky dávky na A4 (2 × 7). */
    case PrintLabels = 'order/print-labels';
    /** Štítek pro tiskárnu Zebra 70 × 90 mm. */
    case PrintZebraLabels = 'order/print-zebra-labels';
    case PrintAdr = 'order/print-adr';
    /** Potvrzení o doručení (POD). */
    case PrintPod = 'order/print-pod';
    case PrintPersonalPickup = 'order/print-personal-pickup';
    /** Veřejné číselníky: register/{název}, bez autentizace. */
    case Register = 'register';

    public function path(string $format = 'json'): string
    {
        return $format . '/' . $this->value . '/';
    }
}
