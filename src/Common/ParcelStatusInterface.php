<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common;

interface ParcelStatusInterface
{
    public ?string $depotCity { get; }
    public ?string $depotCode { get; }
    public string $statusCode { get; }
    public ?\DateTimeImmutable $statusDate { get; }
    public string $statusDescription { get; }
    public ?string $statusInfo { get; }
    public ?string $customInfo { get; }
    public bool $delivered { get; }
    public bool $damaged { get; }
}
