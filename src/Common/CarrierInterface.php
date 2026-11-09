<?php

namespace Ages\ShippingGateway\Common;

interface CarrierInterface
{
    /**
     * @param \DateTimeInterface|null $createdAt datum založení zásilky – dopravci, kteří zásilku hledají podle
     *                                           reference v časovém okně (Gebrüder Weiss), ho použijí; ostatní ho ignorují
     * @return ParcelTrackingInterface|null null = dopravce zásilku nezná nebo sledování nepodporuje
     */
    public function getParcelTracking(string $consignmentId, ?\DateTimeInterface $createdAt = null): ?ParcelTrackingInterface;

    /**
     * Veřejná stránka sledování zásilky, '' = dopravce ji nemá.
     */
    public function getTrackingUrl(string $consignmentId): string;
}
