<?php

namespace Ages\ShippingGateway\Gls\Entity;

use Ages\ShippingGateway\Common\AbstractEntity;
use Ages\ShippingGateway\Gls\Values\ServiceCode;

class ServiceEntity extends AbstractEntity
{
    /**
     * @var ServiceCode[]
     */
    public array $services = [];
    private string $psdReference;
    private string $codVariableSymbol;
    private string $codCurrencyIso;
    private float $codPriceTax;

    final private function __construct()
    {
    }

    public static function of(ServiceCode ...$services): self
    {
        $entity = new static();
        foreach ($services as $service) {
            $entity->services[] = $service;
        }
        return $entity;
    }

    public function addServicePSD(string $psdReference): void
    {
        $this->psdReference = $psdReference;
        $this->services[] = ServiceCode::ParcelShopDelivery;
    }

    public function addServiceCOD(float $priceTax, string $variableSymbol, string $currencyIso): void
    {
        $this->services[] = ServiceCode::CashOnDelivery;
        $this->codPriceTax = $priceTax;
        $this->codVariableSymbol = $variableSymbol;
        $this->codCurrencyIso = $currencyIso;
    }

    public function getPsdId(): string
    {
        return $this->psdReference;
    }

    public function getCodVS(): string
    {
        return $this->codVariableSymbol;
    }

    public function getCodCurrency(): string
    {
        return $this->codCurrencyIso;
    }

    public function getCodPrice(): float
    {
        return $this->codPriceTax;
    }

    public function toArray(): array
    {
        $s = [];
        foreach ($this->services as $service) {
            if ($service === ServiceCode::ParcelShopDelivery) {
                $s[] = [
                    'Code' => $service->value,
                    'PSDParameter' => [
                        'StringValue' => $this->psdReference
                    ]
                ];
            } else {
                $s[] = ['Code' => $service->value];
            }
        }
        return $s;
    }

}
