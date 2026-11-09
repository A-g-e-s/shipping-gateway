<?php

namespace Ages\ShippingGateway\Ppl\Entity;

use Ages\ShippingGateway\Common\AbstractEntity;
use Ages\ShippingGateway\Ppl\Values\Product;

class ParcelEntity extends AbstractEntity
{
    private string $clientReference;
    private string $referenceId;
    private int $count;
    private ?string $note;
    private AddressEntity $pickupAddress;
    private AddressEntity $deliveryAddress;
    private SpecificDeliveryEntity $specificDeliveryEntity;
    private ?CashOnDeliveryEntity $cashOnDeliveryEntity;
    private Product $product;

    final private function __construct()
    {
    }

    /**
     * @param Product|null $product null = tuzemský produkt podle výdejního místa a dobírky
     * @param string|null $referenceId jedinečná reference zásilky v dávce; null = $clientReference
     */
    public static function of(
        string $clientReference,
        int $count,
        ?string $note,
        AddressEntity $pickupAddress,
        AddressEntity $deliveryAddress,
        SpecificDeliveryEntity $specificDeliveryEntity,
        ?CashOnDeliveryEntity $cashOnDeliveryEntity,
        ?Product $product = null,
        ?string $referenceId = null,
    ): self {
        $entity = new static();
        $entity->clientReference = $clientReference;
        $entity->referenceId = $referenceId ?? $clientReference;
        $entity->count = $count;
        $entity->note = $note;
        $entity->pickupAddress = $pickupAddress;
        $entity->deliveryAddress = $deliveryAddress;
        $entity->specificDeliveryEntity = $specificDeliveryEntity;
        $entity->cashOnDeliveryEntity = $cashOnDeliveryEntity;
        $entity->product = $product ?? Product::resolve(false, $specificDeliveryEntity->pdsCode !== null, $cashOnDeliveryEntity !== null);
        return $entity;
    }

    /**
     * Celý požadavek POST shipment/batch s touto jednou zásilkou.
     */
    public function toArray(): array
    {
        return self::batch($this);
    }

    /**
     * Požadavek POST shipment/batch s více zásilkami (export s dobírkou rozdělený na samostatné balíky).
     *
     * @return array<string, mixed>
     */
    public static function batch(self ...$entities): array
    {
        return [
            'returnChannel' => new \stdClass(),
            'labelSettings' => [
                'format' => 'Pdf',
                'dpi' => 300,
                'completeLabelSettings' => [
                    'isCompleteLabelRequested' => true,
                    'pageSize' => 'default',
                    'position' => null
                ]
            ],
            'shipments' => array_map(static fn (self $entity): array => $entity->shipmentToArray(), array_values($entities)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function shipmentToArray(): array
    {
        $shipment = [
            'referenceId' => $this->referenceId,
            'productType' => $this->product->value,
            'cashOnDelivery' => $this->cashOnDeliveryEntity?->toArray(),
            'shipmentSet' => [
                'numberOfShipments' => $this->count,
                'shipmentSetItems' => null,
            ],
            'sender' => $this->pickupAddress->toArray(),
            'recipient' => $this->deliveryAddress->toArray(),
            'externalNumbers' => [
                [
                    'externalNumber' => $this->clientReference,
                    'code' => 'CUST'
                ]
            ]
        ];

        if ($this->note !== null) {
            $shipment['note'] = $this->note;
        }

        if ($this->specificDeliveryEntity->pdsCode !== null) {
            $shipment['specificDelivery'] = $this->specificDeliveryEntity->toArray();
        }
        return $shipment;
    }
}
