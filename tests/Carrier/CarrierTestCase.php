<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\Carrier;

use Ages\ShippingGateway\Common\ParcelStatusInterface;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\PickupAddress;
use Ages\ShippingGateway\Common\Shipment\CashOnDelivery;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\RecipientAddress;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\Common\Shipment\ShipmentValue;
use Ages\ShippingGateway\Common\Tracking\ParcelTracking;
use Ages\ShippingGateway\Tests\MockHttp;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Společné pomůcky testů obsahu požadavků dopravců (ostré API se nevolá – odpovědi z MockHandler).
 */
abstract class CarrierTestCase extends TestCase
{
    protected const string Pdf = "%PDF-1.4 label";

    protected static function pickup(string $country = 'CZ'): PickupAddress
    {
        return new PickupAddress('Wolfio s.r.o.', 'Hlavní', 'Praha', '11000', $country, '+420777000000', 'info@example.com', '12');
    }

    protected static function json(mixed $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    protected static function recipient(
        string $country = 'CZ',
        string $street = 'Dlouhá 5',
        ?string $houseNumber = null,
        string $phone = '777123456',
        string $firstName = 'Jan',
        string $lastName = 'Novák',
        string $city = 'Brno',
    ): RecipientAddress {
        return new RecipientAddress($firstName, $lastName, $street, $city, '602 00', $country, $phone, 'jan@example.com', houseNumber: $houseNumber);
    }

    /**
     * @param Parcel[]|null $parcels
     */
    protected static function request(
        ?RecipientAddress $recipient = null,
        ?array $parcels = null,
        ?float $cod = null,
        ?string $parcelShopCode = null,
        string $reference = 'OBJ123',
        float $value = 1500.0,
    ): ShipmentRequest {
        return new ShipmentRequest(
            $reference,
            $recipient ?? self::recipient(),
            $parcels ?? [new Parcel(2.5)],
            new ShipmentValue($value),
            $cod !== null ? new CashOnDelivery($cod, 'VS-2024/77') : null,
            'Poznámka',
            $parcelShopCode,
        );
    }

    /**
     * JSON tělo odeslaného požadavku jako pole.
     *
     * @return array<mixed>
     */
    protected static function body(MockHttp $mock, int $index): array
    {
        $data = json_decode((string) $mock->request($index)->getBody(), true);
        self::assertIsArray($data, 'Request #' . $index . ' body is not JSON');
        return $data;
    }

    /**
     * Hodnota na cestě „a.b.0.c“ v dekódovaném JSON (chybějící klíč = selhání testu).
     */
    protected static function at(mixed $data, string $path): mixed
    {
        foreach ($path === '' ? [] : explode('.', $path) as $key) {
            self::assertIsArray($data, 'Not an array before "' . $key . '" in ' . $path);
            self::assertArrayHasKey($key, $data, $path);
            $data = $data[$key];
        }
        return $data;
    }

    /**
     * Pole na cestě „a.b.0“ v dekódovaném JSON.
     *
     * @return array<mixed>
     */
    protected static function arr(mixed $data, string $path = ''): array
    {
        $value = self::at($data, $path);
        self::assertIsArray($value, $path);
        return $value;
    }

    /**
     * Pro každou položku seznamu na cestě $path hodnota na cestě $field.
     *
     * @return list<mixed>
     */
    protected static function pluck(mixed $data, string $path, string $field): array
    {
        return array_values(array_map(static fn (mixed $item): mixed => self::at($item, $field), self::arr($data, $path)));
    }

    /**
     * @return list<array{string, bool, bool, ?string}> [statusCode, delivered, damaged, customInfo] stavů v pořadí
     */
    protected static function statuses(ParcelTrackingInterface $tracking): array
    {
        self::assertInstanceOf(ParcelTracking::class, $tracking);
        return array_map(
            static fn (ParcelStatusInterface $s): array => [$s->statusCode, $s->delivered, $s->damaged, $s->customInfo],
            array_values($tracking->parcelStatuses),
        );
    }
}
