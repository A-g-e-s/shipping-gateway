<?php

namespace Ages\ShippingGateway\CzechPost\Entity;

use Ages\ShippingGateway\Common\AbstractEntity;
use Ages\ShippingGateway\CzechPost\Entity\Values\PrefixParcelCode;

class ParcelParamsEntity extends AbstractEntity
{
    private string $recordID;
    private ?string $parcelCode;
    private PrefixParcelCode $prefixParcelCode;
    private float $weight;
    private float $insuredValue;
    private ?float $amount = null;
    private ?string $currency = null;
    private ?string $vsVoucher = null;
    private ?int $sequenceParcel = null;
    private ?int $quantityParcel = null;
    private ?string $note;
    private ?string $notePrint;

    final private function __construct()
    {
    }

    public static function of(
        string $recordID,
        string|PrefixParcelCode $prefixParcelCode,
        null|float|int $weight,
        float $insuredValue,
        ?string $note = null,
        ?string $notePrint = null,
        ?string $parcelCode = null,
    ): self {
        $entity = new static();
        $entity->recordID = $recordID;
        $entity->parcelCode = $parcelCode;
        $entity->prefixParcelCode = ($prefixParcelCode instanceof PrefixParcelCode) ? $prefixParcelCode : PrefixParcelCode::from($prefixParcelCode);
        $entity->weight = round($weight ?? 1.0, 2, PHP_ROUND_HALF_UP);
        $entity->insuredValue = abs($insuredValue) < 100 ? 500 : abs($insuredValue);
        $entity->note = $note;
        $entity->notePrint = $notePrint;
        return $entity;
    }

    public function addCashOnDelivery(?float $amount = null, ?string $currency = null, ?string $vsVoucher = null): void
    {
        $this->amount = $amount;
        $this->currency = $currency;
        $this->vsVoucher = $vsVoucher;
    }

    public function addMultipartInfo(int $sequenceParcel, int $quantityParcel): void
    {
        $this->sequenceParcel = $sequenceParcel;
        $this->quantityParcel = $quantityParcel;
    }

    public function getRecordId(): string { return $this->recordID; }
    public function getWeight(): float { return $this->weight; }

    public function toArray(): array
    {
        $e = [
            'recordID' => $this->recordID,
            'prefixParcelCode' => $this->prefixParcelCode->value,
            'weight' => strval($this->weight),
            'insuredValue' => $this->insuredValue,
        ];
        if ($this->note !== null) { $e['note'] = $this->note; }
        if ($this->notePrint !== null) { $e['notePrint'] = $this->notePrint; }
        if ($this->parcelCode !== null) { $e['parcelCode'] = $this->parcelCode; }
        if ($this->sequenceParcel !== null) { $e['sequenceParcel'] = $this->sequenceParcel; }
        if ($this->quantityParcel !== null) { $e['quantityParcel'] = $this->quantityParcel; }
        if ($this->amount !== null && $this->currency !== null && $this->vsVoucher !== null) {
            $e = array_merge($e, [
                'amount' => $this->amount,
                'currency' => $this->currency,
                'vsVoucher' => $this->vsVoucher,
            ]);
        }
        return $e;
    }
}
