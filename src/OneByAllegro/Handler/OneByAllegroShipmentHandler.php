<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\OneByAllegro\Handler;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\RecipientType;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\Common\ShipmentHandlerInterface;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\OneByAllegro\AddressFormatter;
use Ages\ShippingGateway\OneByAllegro\OneByAllegroApi;

class OneByAllegroShipmentHandler extends OneByAllegroApi implements ShipmentHandlerInterface
{
    private const array ReceiverCountries = ['CZ', 'SK'];
    private const int ReferenceLimit = 20;
    private const int LabelAttempts = 5;
    private const int LabelDelayMicroseconds = 1_000_000;

    public function getCarrier(): Carrier
    {
        return Carrier::OneByAllegro;
    }

    /**
     * Vytvoří zásilku a stáhne štítek. Všechny ShipmentLabel nesou shipmentId (tracking i veřejné sledování
     * jdou přes něj) a stejné kombinované PDF všech balíků.
     */
    public function createShipment(ShipmentRequest $request): array
    {
        if ($request->parcels === []) {
            throw new \InvalidArgumentException('One by Allegro: shipment has no parcels');
        }
        foreach ($request->parcels as $parcel) {
            if (!in_array($parcel->type, Carrier::OneByAllegro->supportedParcelTypes(), true)) {
                throw new \InvalidArgumentException('One by Allegro does not support parcel type ' . $parcel->type->name);
            }
        }

        $country = strtoupper($request->recipient->country);
        if (!in_array($country, self::ReceiverCountries, true)) {
            throw new \InvalidArgumentException('One by Allegro delivers only to CZ and SK, not ' . $country);
        }

        $created = $this->createShipmentRequest($this->buildShipment($request));
        $shipmentId = $created['shipmentId'];
        $labelPdf = $this->waitForLabel($shipmentId);

        $labels = [];
        foreach ($request->parcels as $_) {
            $labels[] = new ShipmentLabel(Carrier::OneByAllegro, $shipmentId, $labelPdf);
        }
        return $labels;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildShipment(ShipmentRequest $request): array
    {
        $isPoint = $request->parcelShopCode !== null && trim($request->parcelShopCode) !== '';
        $currency = strtoupper($request->value->currency);

        $shipment = [
            'sender' => $this->buildSender(),
            'receiver' => $this->buildReceiver($request, $isPoint),
            'packages' => array_map($this->buildPackage(...), $request->parcels),
            // bez výdejního místa = kurýr (One Courier); u místa určí typ (BOX / PUDO) API podle pointId
            'delivery' => array_filter([
                'service' => $this->config->service->value,
                'method' => $isPoint ? null : 'TO_DOOR',
            ]),
            'referenceNumber1' => mb_substr($request->reference, 0, self::ReferenceLimit),
        ];

        $description = AddressFormatter::contentDescription($this->config->contentDescription);
        if ($description !== '') {
            $shipment['contentDescription'] = $description;
        }

        if ($request->value->amount >= 1) {
            $shipment['totalValue'] = [
                'amount' => round($request->value->amount, 2),
                'currency' => $currency,
            ];
        }

        if ($request->cod !== null && $request->cod->amount > 0) {
            $shipment['cod'] = [
                'amount' => round($request->cod->amount, 2),
                'currency' => strtoupper($request->cod->currency),
            ];
            if ($request->cod->variableSymbol !== '') {
                $shipment['referenceNumber2'] = mb_substr($request->cod->variableSymbol, 0, self::ReferenceLimit);
            }
        }

        return $shipment;
    }

    /**
     * @return array<string, string>
     */
    private function buildSender(): array
    {
        $sender = $this->buildPickupAddress();
        $sender['companyName'] = mb_substr($sender['companyName'] ?? '', 0, 100);
        return array_filter($sender, static fn (string $value): bool => $value !== '');
    }

    /**
     * @return array<string, string>
     */
    private function buildReceiver(ShipmentRequest $request, bool $isPoint): array
    {
        $r = $request->recipient;
        [$street, $number] = AddressFormatter::splitStreet($r->street, $r->houseNumber);

        $company = $r->company !== null ? trim($r->company) : '';
        if ($company === '' && $r->type === RecipientType::Company) {
            $company = $r->fullName();
        }

        $receiver = [
            'firstName' => AddressFormatter::name($r->firstName),
            'lastName' => AddressFormatter::name($r->lastName),
            'companyName' => mb_substr($company, 0, 100),
            'phoneNumber' => AddressFormatter::phone($r->phone, $r->country),
            'email' => mb_strlen($r->email) <= 50 ? $r->email : '',
            'postcode' => $r->zip,
            'city' => mb_substr($r->city, 0, 50),
            'street' => $street,
            'streetNumber' => $number,
            'countryCode' => strtoupper($r->country),
        ];

        if ($isPoint) {
            $receiver['pointId'] = trim((string) $request->parcelShopCode);
        }

        return array_filter($receiver, static fn (string $value): bool => $value !== '');
    }

    /**
     * @return array<string, string>
     */
    private function buildPackage(Parcel $parcel): array
    {
        $d = $parcel->dimensions;
        return [
            'weight' => self::number($parcel->weight),
            'length' => self::number($d->length ?? $this->config->defaultLength),
            'width' => self::number($d->width ?? $this->config->defaultWidth),
            'height' => self::number($d->height ?? $this->config->defaultHeight),
        ];
    }

    private function waitForLabel(string $shipmentId): string
    {
        $last = null;
        for ($i = 0; $i < self::LabelAttempts; $i++) {
            try {
                return $this->getLabels([$shipmentId]);
            } catch (ShippingException $e) {
                $last = $e;
                // chyby vstupu se opakováním nespraví; 404 / 5xx může znamenat, že štítek ještě není připravený
                if ($e->getCode() >= 400 && $e->getCode() < 500 && $e->getCode() !== 404) {
                    break;
                }
            }
            usleep(self::LabelDelayMicroseconds);
        }

        throw new ShippingException(
            'One by Allegro: shipment ' . $shipmentId . ' was created, but label download failed: ' . $last->getMessage(),
            0,
            $last,
        );
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
