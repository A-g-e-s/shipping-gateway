<?php

namespace Ages\ShippingGateway\Ppl\Entity;

abstract class AbstractEntity
{
    /**
     * @return array<mixed>
     */
    abstract public function toArray(): array;
}
