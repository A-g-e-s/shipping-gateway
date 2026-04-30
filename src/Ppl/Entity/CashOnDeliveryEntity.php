<?php

namespace Ages\ShippingGateway\Ppl\Entity;

use Ages\ShippingGateway\Common\AbstractEntity;

class CashOnDeliveryEntity extends AbstractEntity
{
    private float $amount;
    private string $currency;
    private string $variableSymbol;

    final private function __construct()
    {
    }

    public static function of(float $amount, string $variableSymbol, string $currency): self
    {
        $entity = new static();
        $entity->amount = $amount;
        $entity->currency = $currency;
        $entity->variableSymbol = $variableSymbol;
        return $entity;
    }

    public function toArray(): array
    {
        return [
            'codPrice' => $this->amount,
            'codCurrency' => $this->currency,
            'codVarSym' => $this->variableSymbol
        ];
    }
}
