<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Ppl\Handler;

use Ages\ShippingGateway\Common\Carrier;
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
use Mpdf\Mpdf;

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
        if (!isset($status->items) || !is_array($status->items) || $status->items === []) {
            throw new ShippingException('PPL: batch response does not contain any shipments');
        }

        $trackingNumbers = [];
        foreach ($this->collectShipmentItems($status->items) as $item) {
            $trackingNumbers[] = (string) ($item->shipmentNumber ?? $fallbackRef);
        }

        if ($trackingNumbers === []) {
            throw new ShippingException('PPL: shipment numbers not found in batch response');
        }

        return $trackingNumbers;
    }

    private function extractLabelPdf(\stdClass $status): string
    {
        $labelPdfs = [];
        foreach ($this->extractLabelUrls($status) as $labelUrl) {
            $labelPdfs[] = $this->waitForLabel($labelUrl);
        }

        if ($labelPdfs === []) {
            throw new ShippingException('PPL: label URL not found in batch response');
        }

        if (count($labelPdfs) === 1) {
            return $labelPdfs[0];
        }

        return $this->mergeLabelPdfs($labelPdfs);
    }

    /**
     * @param array<int, \stdClass> $items
     * @return array<int, \stdClass>
     */
    private function collectShipmentItems(array $items): array
    {
        $collected = [];

        foreach ($items as $item) {
            if (!$item instanceof \stdClass) {
                continue;
            }

            $collected[] = $item;

            if (isset($item->relatedItems) && is_array($item->relatedItems)) {
                array_push($collected, ...$this->collectShipmentItems($item->relatedItems));
            }
        }

        return $collected;
    }

    /**
     * @return string[]
     */
    private function extractLabelUrls(\stdClass $status): array
    {
        if (!isset($status->items) || !is_array($status->items) || $status->items === []) {
            return [];
        }

        $urls = [];
        foreach ($this->collectShipmentItems($status->items) as $item) {
            if (isset($item->labelUrl) && is_string($item->labelUrl) && $item->labelUrl !== '') {
                $urls[] = $item->labelUrl;
            }
        }

        if ($urls === [] && isset($status->completeLabel?->labelUrls) && is_array($status->completeLabel->labelUrls)) {
            foreach ($status->completeLabel->labelUrls as $labelUrl) {
                if (is_string($labelUrl) && $labelUrl !== '') {
                    $urls[] = $labelUrl;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function waitForLabel(string $labelUrl): string
    {
        $lastException = null;

        for ($i = 0; $i < self::LabelPollAttempts; $i++) {
            try {
                return $this->getLabel($labelUrl);
            } catch (ShippingException $e) {
                $lastException = $e;
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }

            usleep(self::PollDelayMicroseconds);
        }

        if ($lastException instanceof ShippingException) {
            throw $lastException;
        }

        throw new ShippingException('PPL: label timeout for URL ' . $labelUrl);
    }

    /**
     * @param string[] $labelPdfs
     */
    private function mergeLabelPdfs(array $labelPdfs): string
    {
        $pdf = new Mpdf();
        $temporaryFiles = [];

        try {
            foreach ($labelPdfs as $labelPdf) {
                $temporaryFile = tempnam(sys_get_temp_dir(), 'ppl_');
                if ($temporaryFile === false) {
                    throw new ShippingException('PPL: failed to create temporary file for label merge');
                }

                file_put_contents($temporaryFile, $labelPdf);
                $temporaryFiles[] = $temporaryFile;

                $pageCount = $pdf->setSourceFile($temporaryFile);
                for ($page = 1; $page <= $pageCount; $page++) {
                    $template = $pdf->importPage($page);
                    $pdf->AddPage();
                    $pdf->useTemplate($template, 0, 0, null, null, true);
                }
            }

            return $pdf->Output('', 'S');
        } finally {
            foreach ($temporaryFiles as $temporaryFile) {
                if (is_file($temporaryFile)) {
                    @unlink($temporaryFile);
                }
            }
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
