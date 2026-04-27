<?php

namespace Ages\ShippingGateway\Common;

interface ParcelStatusInterface
{
    public function getDepotCity(): ?string;

    public function getDepotCode(): ?string;

    public function getStatusCode(): string;

    public function getStatusDate(): ?\DateTimeImmutable;

    public function getStatusDescription(): string;

    public function getStatusInfo(): ?string;

    public function getCustomInfo(): ?string;

    public function getDelivered(): bool;

    public function getDamaged(): bool;
}
