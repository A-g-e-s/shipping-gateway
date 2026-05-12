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
        $pickup = $this->getPickupAddress();
        $delivery = $this->buildDelivery($request);
        $cod = $this->buildCod($request);
        $specific = SpecificDeliveryEntity::of($request->parcelShopCode);
        $count = count($request->parcels);
        $entity = ParcelEntity::of($request->reference, $count, $request->note, $pickup, $delivery, $specific, $cod);

        $batchId = $this->createBatch($entity);
        $status = $this->waitForBatch($batchId);
        $labelPdf = $this->extractLabelPdf($status);

        foreach ($this->extractTrackingNumbers($status, $request->reference) as $trackingNumber) {
            $labels[] = new ShipmentLabel(Carrier::Ppl, $trackingNumber, $labelPdf);
        }

        return $labels;
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

    private function buildCod(ShipmentRequest $request): ?CashOnDeliveryEntity
    {
        if ($request->cod === null) {
            return null;
        }
        return CashOnDeliveryEntity::of(
            $request->cod->amount,
            $request->cod->variableSymbol,
            $request->cod->currency,
        );
    }
}
