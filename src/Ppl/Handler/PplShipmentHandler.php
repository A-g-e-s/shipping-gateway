<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Ppl\Handler;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\Data;
use Ages\ShippingGateway\Common\Label\PdfMerger;
use Ages\ShippingGateway\Common\ShipmentHandlerInterface;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\Ppl\Entity\AddressEntity;
use Ages\ShippingGateway\Ppl\Entity\CashOnDeliveryEntity;
use Ages\ShippingGateway\Ppl\Entity\ParcelEntity;
use Ages\ShippingGateway\Ppl\Entity\SpecificDeliveryEntity;
use Ages\ShippingGateway\Ppl\PplApi;
use Ages\ShippingGateway\Ppl\Values\Product;

class PplShipmentHandler extends PplApi implements ShipmentHandlerInterface
{
    private const int BatchPollAttempts = 30;
    private const int LabelPollAttempts = 30;
    private const int PollDelayMicroseconds = 1_000_000;

    public function getCarrier(): Carrier
    {
        return Carrier::Ppl;
    }

    public function createShipment(ShipmentRequest $request): array
    {
        foreach ($request->parcels as $parcel) {
            if ($parcel->type->isPallet()) {
                throw new \InvalidArgumentException('PPL does not support pallet shipments');
            }
        }

        $labels = [];
        $batchId = $this->createBatch(...$this->buildEntities($request));
        $status = $this->waitForBatch($batchId);
        $labelPdf = $this->extractLabelPdf($status);

        foreach ($this->extractTrackingNumbers($status, $request->reference) as $trackingNumber) {
            $labels[] = new ShipmentLabel(Carrier::Ppl, $trackingNumber, $labelPdf);
        }

        return $labels;
    }

    /**
     * Zásilka do jiné země než odesílatel jde mezinárodním produktem (Connect / Smart Europe) s adresami bez diakritiky.
     * Export s dobírkou nesmí být vícekusová sada – každý balík je pak samostatná zásilka s poměrnou částí dobírky.
     *
     * @return ParcelEntity[]
     */
    protected function buildEntities(ShipmentRequest $request): array
    {
        $international = strtoupper(trim($request->recipient->country)) !== strtoupper(trim($this->config->pickupAddress->country));
        $pickupPoint = $request->parcelShopCode !== null && trim($request->parcelShopCode) !== '';
        $hasCod = $request->cod !== null && $request->cod->amount > 0;
        $product = Product::resolve($international, $pickupPoint, $hasCod);

        $pickup = $this->getPickupAddress();
        $delivery = $this->buildDelivery($request);
        if ($international) {
            $pickup = $pickup->withAscii();
            $delivery = $delivery->withAscii();
        }
        $specific = SpecificDeliveryEntity::of($pickupPoint ? trim((string) $request->parcelShopCode) : null);
        $count = count($request->parcels);

        if (!$international || !$hasCod || $count === 1) {
            return [ParcelEntity::of($request->reference, $count, $request->note, $pickup, $delivery, $specific, $this->buildCod($request), $product)];
        }

        $entities = [];
        foreach (self::splitAmount($request->cod->amount, $count) as $i => $amount) {
            $entities[] = ParcelEntity::of(
                $request->reference,
                1,
                $request->note,
                $pickup,
                $delivery,
                $specific,
                $this->buildCod($request, $amount),
                $product,
                $request->reference . '-' . ($i + 1),
            );
        }
        return $entities;
    }

    /**
     * Rozdělí částku na $parts dílů v haléřích/centech, zbytek po dělení dostane poslední díl.
     *
     * @return float[]
     */
    private static function splitAmount(float $amount, int $parts): array
    {
        $cents = (int) round($amount * 100);
        $base = intdiv($cents, $parts);
        $amounts = array_fill(0, $parts, $base / 100);
        $amounts[$parts - 1] = ($cents - $base * ($parts - 1)) / 100;
        return $amounts;
    }

    private function waitForBatch(string $batchId): \stdClass
    {
        for ($i = 0; $i < self::BatchPollAttempts; $i++) {
            $status = $this->getStatus($batchId);
            if ($status !== null) {
                return $status;
            }
            usleep(self::PollDelayMicroseconds);
        }
        throw new ShippingException('PPL: batch processing timeout for batch ' . $batchId);
    }

    /**
     * @return string[]
     */
    private function extractTrackingNumbers(\stdClass $status, string $fallbackRef): array
    {
        $items = $this->collectShipmentItems(Data::objects($status, 'items'));
        if ($items === []) {
            throw new ShippingException('PPL: batch response does not contain any shipments');
        }

        return array_map(static fn (\stdClass $item): string => Data::string($item, 'shipmentNumber', $fallbackRef), $items);
    }

    private function extractLabelPdf(\stdClass $status): string
    {
        $labelPdfs = array_map($this->waitForLabel(...), $this->extractLabelUrls($status));
        if ($labelPdfs === []) {
            throw new ShippingException('PPL: label URL not found in batch response');
        }

        return PdfMerger::merge($labelPdfs);
    }

    /**
     * Zásilky dávky včetně souvisejících (relatedItems – další kusy vícekusové sady).
     *
     * @param \stdClass[] $items
     * @return \stdClass[]
     */
    private function collectShipmentItems(array $items): array
    {
        $collected = [];
        foreach ($items as $item) {
            $collected[] = $item;
            array_push($collected, ...$this->collectShipmentItems(Data::objects($item, 'relatedItems')));
        }
        return $collected;
    }

    /**
     * Štítky jednotlivých zásilek; když chybí, štítek celé dávky (completeLabel).
     *
     * @return string[]
     */
    private function extractLabelUrls(\stdClass $status): array
    {
        $urls = [];
        foreach ($this->collectShipmentItems(Data::objects($status, 'items')) as $item) {
            $url = Data::nonEmptyString($item, 'labelUrl');
            if ($url !== null) {
                $urls[] = $url;
            }
        }

        if ($urls === []) {
            $urls = array_filter(Data::array($status, ['completeLabel', 'labelUrls']), static fn (mixed $url): bool => is_string($url) && $url !== '');
        }

        return array_values(array_unique($urls));
    }

    /**
     * Štítek bývá chvíli po dokončení dávky ještě nepřipravený (404) – zkouší se znovu, jiná chyba končí hned.
     */
    private function waitForLabel(string $labelUrl): string
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->getLabel($labelUrl);
            } catch (ShippingException $e) {
                if ($e->getCode() !== 404 || $attempt >= self::LabelPollAttempts) {
                    throw $e;
                }
            }
            usleep(self::PollDelayMicroseconds);
        }
    }

    private function buildDelivery(ShipmentRequest $request): AddressEntity
    {
        $r = $request->recipient;
        return AddressEntity::of(
            $r->fullName(),
            $r->streetWithNumber(),
            $r->city,
            $r->zip,
            $r->country,
            null,
            $r->company,
            $r->phone,
            $r->email,
        );
    }

    /**
     * @param float|null $amount část dobírky u rozdělené exportní zásilky; null = celá částka
     */
    private function buildCod(ShipmentRequest $request, ?float $amount = null): ?CashOnDeliveryEntity
    {
        if ($request->cod === null || $request->cod->amount <= 0) {
            return null;
        }
        return CashOnDeliveryEntity::of(
            $amount ?? $request->cod->amount,
            // CPL přijímá ve variabilním symbolu jen číslice
            (string) preg_replace('~\D+~', '', $request->cod->variableSymbol),
            strtoupper($request->cod->currency),
        );
    }
}
