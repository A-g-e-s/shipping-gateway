<?php

namespace Ages\ShippingGateway\CzechPost\Entity;

abstract class AbstractEntity
{
    /**
     * @return array<mixed>
     */
    abstract public function toArray(): array;
}
