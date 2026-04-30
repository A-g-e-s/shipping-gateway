<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common;

interface ParcelTrackingInterface
{
    public string $parcelNumber { get; }
    public string $deliveryCountryCode { get; }
    public bool $delivered { get; }
    public ?\DateTimeImmutable $deliveredDate { get; }
    public bool $damaged { get; }
    public float $weight { get; }

    /** @var ParcelStatusInterface[] */
    public array $parcelStatuses { get; }
}
