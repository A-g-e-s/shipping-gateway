<?php

namespace Ages\ShippingGateway\Ppl\Entity;

use Ages\ShippingGateway\Common\AbstractEntity;

class ParcelEntity extends AbstractEntity
{
    private string $clientReference;
    private int $count;
    private ?string $note;
    private AddressEntity $pickupAddress;
    private AddressEntity $deliveryAddress;
    private SpecificDeliveryEntity $specificDeliveryEntity;
    private ?CashOnDeliveryEntity $cashOnDeliveryEntity;

    final private function __construct()
    {
    }

    public static function of(
        string $clientReference,
        int $count,
        ?string $note,
        AddressEntity $pickupAddress,
        AddressEntity $deliveryAddress,
        SpecificDeliveryEntity $specificDeliveryEntity,
        ?CashOnDeliveryEntity $cashOnDeliveryEntity,
    ): self {
        $entity = new static();
        $entity->clientReference = $clientReference;
        $entity->count = $count;
        $entity->note = $note;
        $entity->pickupAddress = $pickupAddress;
        $entity->deliveryAddress = $deliveryAddress;
        $entity->specificDeliveryEntity = $specificDeliveryEntity;
        $entity->cashOnDeliveryEntity = $cashOnDeliveryEntity;
        return $entity;
    }

    public function toArray(): array
    {
        if ($this->specificDeliveryEntity->pdsCode === null) {
            $productType = $this->cashOnDeliveryEntity === null ? 'PRIV' : 'PRID';
        } else {
            $productType = $this->cashOnDeliveryEntity === null ? 'SMAR' : 'SMAD';
        }
        $data = [
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
            'shipments' => [
                [
                    'referenceId' => $this->clientReference,
                    'productType' => $productType,
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
                ]
            ]
        ];

        if ($this->note !== null) {
            $data['shipments'][0]['note'] = $this->note;
        }

        if ($this->specificDeliveryEntity->pdsCode !== null) {
            $data['shipments'][0]['specificDelivery'] = $this->specificDeliveryEntity->toArray();
        }
        return $data;
    }
}
