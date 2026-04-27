<?php

namespace Ages\ShippingGateway\Gls\Entity;

abstract class AbstractEntity
{
    /**
     * @return array<mixed>
     */
    abstract public function toArray(): array;
}
