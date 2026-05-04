# ages/shipping-gateway

Unified PHP library for shipping carrier integrations — **GLS**, **PPL**, **Czech Post**, **Gebrüder Weiss**.  
Single entry point, config-driven credentials and pickup address, compatible with Nette + Nextras.

---

## API Documentation

| Dopravce | Odkaz |
|---|---|
| GLS | https://api.mygls.hu/index_cz.html |
| PPL | https://ppl-cpl-api.apidog.io/ |
| Česká pošta | https://www.postaonline.cz/dokumentaceapi/b2b/ |
| Gebrüder Weiss | https://developer.my.gw-world.com/#/apis |

---

## Requirements

- PHP 8.4+
- Nette Utils `^4.0`
- Guzzle `^7.9`
- Tracy `^2.8`

---

## Installation

```bash
composer require ages/shipping-gateway
```

Or add a path repository for local development:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../shipping-gateway"
        }
    ],
    "require": {
        "ages/shipping-gateway": "*"
    }
}
```

---

## Configuration (Nette / neon)

All credentials and pickup address are configured in `config.neon` — nothing is hardcoded.

```neon
services:
    pickupAddress: Ages\ShippingGateway\Common\PickupAddress(
        name: 'MyShop s.r.o.'
        street: Ulice
        city: Město
        zip: '12345'
        country: CZ
        phone: '+420 123 456 789'
        email: 'info@myshop.cz'
        houseNumber: '10'
    )

    glsConfig: Ages\ShippingGateway\Gls\Config\GlsConfig(
        username: 'user@example.com'
        password: secret
        clientNumber: 12345678
        pickupAddress: @pickupAddress
        # url: https://api.test.mygls.cz/ParcelService.svc/json/   # test endpoint
    )

    pplConfig: Ages\ShippingGateway\Ppl\Config\PplConfig(
        clientId: CLIENT_ID
        clientSecret: CLIENT_SECRET
        pickupAddress: @pickupAddress
        # url: https://api-dev.dhl.com/ecs/ppl/myapi2/             # test endpoint
    )

    czechPostConfig: Ages\ShippingGateway\CzechPost\Config\CzechPostConfig(
        apiToken: api-token-here
        secretKey: secret-key-here
        idContract: 123456789
        customerId: L12345
        postCode: '53307'
        locationNumber: 1
        certificatePath: %wwwDir%/cert/postsignum-bundle.pem
        # packageLimit: 5
    )

    gwConfig: Ages\ShippingGateway\GebruderWeiss\Config\GebruderWeissConfig(
        clientId: CLIENT_ID
        clientSecret: CLIENT_SECRET
        customerId: 12345
        branchCode: PRG
        pickupAddress: @pickupAddress
        # product: GW_PRO_LINE
        # incoterm: DAP
        # goodsDescription: Goods
    )

    - Ages\ShippingGateway\ShippingGateway
```

> **Certifikát České pošty** (`postsignum-bundle.pem`) je třeba umístit ručně do projektu.  
> Cesta se nastavuje přes `certificatePath` — nikdy se nekopíruje do balíčku.

---

## Usage

### Unified shipment creation

Předáš `ShipmentRequest`, označíš dopravce a dostaneš `ShipmentLabel[]` — jeden štítek per balík.

```php
use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\Shipment\CashOnDelivery;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\RecipientAddress;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\Common\Shipment\ShipmentValue;

$request = new ShipmentRequest(
    reference: '2025-00123',
    recipient: RecipientAddress::fromFullName(
        name: 'Jan Novák',
        street: 'Hlavní',
        houseNumber: '42',
        city: 'Praha',
        zip: '10000',
        country: 'CZ',
        phone: '+420600123456',
        email: 'jan@example.com',
    ),
    parcels: [
        new Parcel(weight: 2.5),
        new Parcel(weight: 1.8),
    ],
    value: new ShipmentValue(amount: 1490.0),
    cod: new CashOnDelivery(amount: 1490.0, variableSymbol: '2025001'),
);

/** @var \Ages\ShippingGateway\ShippingGateway $gateway */
$labels = $gateway->createShipment(Carrier::Gls, $request);

foreach ($labels as $label) {
    echo $label->trackingNumber;             // číslo zásilky
    file_put_contents('label.pdf', $label->labelPdf); // raw PDF bytes
}
```

Dostupné hodnoty `Carrier`: `Carrier::Gls`, `Carrier::Ppl`, `Carrier::CzechPost`, `Carrier::GebruderWeiss`.

---

#### RecipientAddress

```php
// Ze jména — rozdělí podle první mezery (Jan / Novák)
RecipientAddress::fromFullName('Jan Novák', $street, $city, $zip, $country, $phone, $email);

// Nebo přímo s firstName / lastName
new RecipientAddress(
    firstName: 'Jan',
    lastName: 'Novák',
    street: 'Hlavní',
    city: 'Praha',
    zip: '10000',
    country: 'CZ',
    phone: '+420600123456',
    email: 'jan@example.com',
    company: 'Firma s.r.o.',      // volitelné
    houseNumber: '42',            // volitelné
    type: RecipientType::Company, // Person (výchozí) nebo Company
);
```

---

#### Parcel — typy zásilek

```php
use Ages\ShippingGateway\Common\Shipment\Dimensions;
use Ages\ShippingGateway\Common\Shipment\ParcelType;

new Parcel(weight: 2.5);                                         // standardní balík
new Parcel(weight: 15.0, type: ParcelType::PackageOversize);     // neskladný / atypický
new Parcel(weight: 80.0, type: ParcelType::PalletEur);           // EUR paleta (GebrüderWeiss)
new Parcel(weight: 60.0, type: ParcelType::PalletOneWay);        // jednorázová paleta
new Parcel(weight: 50.0, type: ParcelType::PalletHalf);          // půl paleta
new Parcel(weight: 40.0, type: ParcelType::PalletCustom);        // vlastní paleta

// S rozměry (cm)
new Parcel(weight: 5.0, dimensions: new Dimensions(60, 40, 30));
```

> Palety jsou podporovány **pouze přes Gebrüder Weiss**.  
> GLS a PPL paletové typy nepodporují — handler vyhodí `InvalidArgumentException`.  
> Česká pošta podporuje `Package` a `PackageOversize` (neskladná zásilka, služba 10), palety nepodporuje.

---

#### ShipmentLabel — výstup

```php
$label->carrier;        // Carrier::Gls | Carrier::Ppl | Carrier::CzechPost
$label->trackingNumber; // číslo zásilky pro sledování
$label->labelPdf;       // raw PDF bytes — ulož nebo pošli do prohlížeče

// Uložení štítku
file_put_contents('/path/to/' . $label->trackingNumber . '.pdf', $label->labelPdf);

// Sledování zásilky (po doručení)
$tracking = $gateway->tracking($label->carrier, $label->trackingNumber);
```

> **Více balíků:** API vrátí jeden kombinovaný PDF soubor pro všechny balíky.  
> Každý `ShipmentLabel` dostane svůj `trackingNumber`, ale `labelPdf` je u všech shodné (kombinovaný tisk).

---

### Unified tracking

```php
$tracking = $gateway->tracking(Carrier::Gls, '1234567890');
$tracking = $gateway->tracking(Carrier::Ppl, 'KEA12345678');
$tracking = $gateway->tracking(Carrier::CzechPost, 'DR123456789CZ');

if ($tracking !== null) {
    $tracking->getDelivered();           // bool
    $tracking->getDeliveredDate();       // ?DateTimeImmutable
    $tracking->getWeight();              // float (kg)
    $tracking->getParcelNumber();        // string
    $tracking->getDeliveryCountryCode(); // string (ISO)

    foreach ($tracking->getParcelStatuses() as $status) {
        $status->getStatusDate();        // ?DateTimeImmutable
        $status->getStatusDescription(); // string
        $status->getCustomInfo();        // ?string
        $status->getDelivered();         // bool
        $status->getDamaged();           // bool
    }
}
```

> Gebrüder Weiss nepodporuje tracking — metoda vrátí `null`.

---

---

## Extending in your project

Balíček obsahuje API vrstvu (HTTP komunikace, entity, config) a unified shipment handlery.  
Aplikační logika — mapování objednávky/faktury na zásilku, ukládání do DB — patří do projektu.

### GLS — příklad rozšíření

```php
namespace App\Api\Gls;

use Ages\ShippingGateway\Gls\GlsApi;
use Ages\ShippingGateway\Gls\Entity\ParcelEntity;
use Ages\ShippingGateway\Gls\Entity\ServiceEntity;

class Gls extends GlsApi
{
    const string Carrier = 'GLS';
    const string TrackUrl = 'https://gls-group.eu/CZ/cs/sledovani-zasilek/?match=';

    public function createConsignmentFromInvoice(Invoice $invoice): ?Consignment
    {
        $services = ServiceEntity::of();
        if ($invoice->cashOnDelivery) {
            $services->addServiceCOD($invoice->priceTax, $invoice->variableSymbol, $invoice->currencyIso);
        }

        $parcel = ParcelEntity::of(
            strval($this->config->clientNumber),
            $invoice->code,
            $invoice->packageQty,
            $this->getPickupAddress(),  // z configu
            $deliveryAddress,
            $services,
        );

        $data = $this->printLabels($parcel);
        // $data->PrintLabelsInfoList[0]->ParcelNumber  ← tracking number
        // implode(array_map('chr', $data->Labels))     ← PDF bytes
    }
}
```

### PPL — příklad rozšíření

```php
namespace App\Api\Ppl;

use Ages\ShippingGateway\Ppl\PplApi;

class Ppl extends PplApi
{
    public function createConsignmentFromInvoice(Invoice $invoice): ?Consignment
    {
        $parcel = ParcelEntity::of(
            $invoice->code,
            $invoice->packageQty,
            $this->getPickupAddress(),
            $deliveryAddress,
            SpecificDeliveryEntity::of($psdCode),
            $cod,
        );

        $batchId = $this->createBatch($parcel);
        $status  = $this->getStatus($batchId);   // poll dokud není Complete
        // $status->items[0]->shipmentNumber      ← tracking number
        // $status->completeLabel->labelUrls[0]   ← URL štítku → getLabel($url)
    }
}
```

### Czech Post — příklad rozšíření

```php
namespace App\Api\CzechPost;

use Ages\ShippingGateway\CzechPost\CzechPostApi;

class CzechPost extends CzechPostApi
{
    public function createConsignmentFromInvoice(Invoice $invoice): ?Consignment
    {
        $header = $this->prepareParcelServiceHeader(); // z configu

        $res = $this->parcelService($header, $consignmentEntity->toArray(), $multipart);

        // $res['responseHeader']['resultHeader']['responseCode'] === 1  ← úspěch
        // $res['responseHeader']['resultParcelData'][n]['parcelCode']   ← tracking number
        // $res['responseHeader']['responsePrintParams']['file']         ← base64 PDF
    }
}
```

---

## Architecture overview

```
src/
├── ShippingGateway.php               ← facade: tracking() + createShipment()
├── Common/
│   ├── Carrier.php                   ← enum: Gls | Ppl | CzechPost | GebruderWeiss
│   ├── CarrierInterface.php
│   ├── ShipmentHandlerInterface.php  ← createShipment(ShipmentRequest): ShipmentLabel[]
│   ├── ParcelTrackingInterface.php
│   ├── ParcelStatusInterface.php
│   ├── PickupAddress.php
│   ├── ShippingException.php
│   └── Shipment/
│       ├── ShipmentRequest.php       ← vstupní DTO
│       ├── ShipmentLabel.php         ← výstupní DTO (carrier, trackingNumber, labelPdf)
│       ├── RecipientAddress.php      ← fromFullName() factory
│       ├── Parcel.php                ← weight, type, dimensions
│       ├── Dimensions.php
│       ├── ShipmentValue.php
│       ├── CashOnDelivery.php
│       ├── ParcelType.php            ← Package | PackageOversize | PalletEur | ...
│       └── RecipientType.php         ← Person | Company
├── Gls/
│   ├── Config/GlsConfig.php
│   ├── GlsApi.php
│   ├── Handler/GlsShipmentHandler.php
│   └── Entity/ Values/
├── Ppl/
│   ├── Config/PplConfig.php
│   ├── PplApi.php
│   ├── Handler/PplShipmentHandler.php
│   └── Entity/ Values/
├── CzechPost/
│   ├── Config/CzechPostConfig.php
│   ├── CzechPostApi.php
│   ├── CzechPostException.php
│   ├── Handler/CzechPostShipmentHandler.php
│   └── Entity/ Values/
└── GebruderWeiss/
    ├── Config/GebruderWeissConfig.php
    ├── GebruderWeissApi.php
    └── Handler/GebruderWeissShipmentHandler.php
```

**Co je v balíčku:** HTTP komunikace, entity, config, unified tracking, unified shipment creation.  
**Co patří do projektu:** mapování Invoice → ShipmentRequest, ukládání zásilek do DB, Storage.

---

## License

Private package — Ages s.r.o.
