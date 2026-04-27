<?php

namespace Ages\ShippingGateway\Common;

interface ParcelTrackingInterface
{
    public function getParcelNumber(): string;

    public function getDelivered(): bool;

    public function getDeliveredDate(): ?\DateTimeImmutable;

    public function getDamaged(): bool;

    public function getWeight(): float;

    /**
     * @return ParcelStatusInterface[]
     */
    public function getParcelStatuses(): array;

    public function getDeliveryCountryCode(): string;
}
