<?php

namespace Ages\ShippingGateway\Gls\Entity;

use Ages\ShippingGateway\Gls\Values\ServiceCode;

class ParcelEntity extends AbstractEntity
{
    private string $clientNumber;
    private string $clientReference;
    private int $count;
    private ?string $content;
    private AddressEntity $pickupAddress;
    private AddressEntity $deliveryAddress;
    private ServiceEntity $services;

    final private function __construct()
    {
    }

    public static function of(
        string $clientNumber,
        string $clientReference,
        int $count,
        AddressEntity $pickupAddress,
        AddressEntity $deliveryAddress,
        ServiceEntity $serviceEntity,
        ?string $content = null,
    ): self {
        $entity = new static();
        $entity->clientNumber = $clientNumber;
        $entity->clientReference = $clientReference;
        $entity->count = $count;
        $entity->content = $content;
        $entity->pickupAddress = $pickupAddress;
        $entity->deliveryAddress = $deliveryAddress;
        $entity->services = $serviceEntity;
        return $entity;
    }

    public function toArray(): array
    {
        $e = [
            'ClientNumber' => $this->clientNumber,
            'ClientReference' => $this->clientReference,
            'Count' => $this->count,
            'Content' => $this->content,
            'PickupAddress' => $this->pickupAddress->toArray(),
            'DeliveryAddress' => $this->deliveryAddress->toArray(),
        ];
        foreach ($this->services->getServices() as $service) {
            if ($service === ServiceCode::CashOnDelivery) {
                $e['CODAmount'] = $this->services->getCodPrice();
                $e['CODReference'] = $this->services->getCodVS();
                $e['CODCurrency'] = $this->services->getCodCurrency();
            }
        }
        $e['ServiceList'] = $this->services->toArray();
        return $e;
    }
}
