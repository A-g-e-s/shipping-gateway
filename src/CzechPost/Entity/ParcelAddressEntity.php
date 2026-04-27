<?php

namespace Ages\ShippingGateway\CzechPost\Entity;

use Ages\ShippingGateway\CzechPost\Entity\Values\ParcelAddressSubject;

class ParcelAddressEntity extends AbstractEntity
{
    private ?string $recordID;
    private ?string $firstName;
    private ?string $surname;
    private ?string $company;
    private ?string $aditionAddress;
    private ParcelAddressSubject $subject;
    private ?string $mobilNumber;
    private ?string $phoneNumber;
    private ?string $emailAddress;
    private AddressEntity $address;

    final private function __construct()
    {
    }

    public static function of(
        string|ParcelAddressSubject $subject,
        AddressEntity $address,
        string $firstName,
        string $surname,
        ?string $company,
        string $mobilNumber,
        string $emailAddress,
        ?string $aditionAddress = null,
        ?string $phoneNumber = null,
        ?string $recordID = null,
    ): self {
        $entity = new static();
        $entity->recordID = $recordID;
        $entity->subject = ($subject instanceof ParcelAddressSubject) ? $subject : ParcelAddressSubject::from($subject);
        $entity->address = $address;
        $entity->firstName = $firstName;
        $entity->surname = $surname;
        $entity->company = $company;
        $entity->aditionAddress = $aditionAddress;
        $entity->mobilNumber = $mobilNumber;
        $entity->phoneNumber = $phoneNumber;
        $entity->emailAddress = $emailAddress;
        return $entity;
    }

    public function toArray(): array
    {
        $e = [
            'subject' => $this->subject->value,
            'address' => $this->address->toArray(),
        ];
        if ($this->recordID !== null) { $e['recordID'] = $this->recordID; }
        if ($this->firstName !== null) { $e['firstName'] = $this->firstName; }
        if ($this->surname !== null) { $e['surname'] = $this->surname; }
        if ($this->company !== null) { $e['company'] = $this->company; }
        if ($this->mobilNumber !== null) { $e['mobilNumber'] = $this->mobilNumber; }
        if ($this->phoneNumber !== null) { $e['phoneNumber'] = $this->phoneNumber; }
        if ($this->emailAddress !== null) { $e['emailAddress'] = $this->emailAddress; }
        if ($this->aditionAddress !== null) { $e['aditionAddress'] = $this->aditionAddress; }
        return $e;
    }
}
