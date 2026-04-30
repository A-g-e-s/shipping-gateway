<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common;

abstract class AbstractEntity
{
    /**
     * @return array<mixed>
     */
    abstract public function toArray(): array;
}
