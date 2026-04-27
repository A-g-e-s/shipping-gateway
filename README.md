# ages/shipping-gateway

Unified PHP library for shipping carrier integrations — **GLS**, **PPL**, **Czech Post**.  
Single entry point, config-driven credentials and pickup address, compatible with Nette + Nextras.

---

## Requirements

- PHP 8.4+
- Nette Utils `^4.0`
- Nextras DBAL
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
        name: MyShop s.r.o.
        street: Ulice
        city: Město
        zip: '12345'
        country: CZ
        phone: '+420 123 456 789'
        email: info@myshop.cz
        houseNumber: '10'        # volitelné — GLS posílá číslo popisné zvlášť
    )

    glsConfig: Ages\ShippingGateway\Gls\Config\GlsConfig(
        username: user@example.com
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
        # packageLimit: 5                                           # výchozí hodnota
    )

    Ages\ShippingGateway\ShippingGateway(
        glsConfig: @glsConfig
        pplConfig: @pplConfig
        czechPostConfig: @czechPostConfig
    )
```

> **Certifikát České pošty** (`postsignum-bundle.pem`) je třeba umístit ručně do projektu.  
> Cesta se nastavuje přes `certificatePath` — nikdy se nekopíruje do balíčku.

---

## Usage

### Unified tracking

```php
use Ages\ShippingGateway\ShippingGateway;

/** @var ShippingGateway $gateway */

$tracking = $gateway->tracking('GLS', '1234567890');
$tracking = $gateway->tracking('PPL', 'KEA12345678');
$tracking = $gateway->tracking('CzechPost', 'DR123456789CZ');

if ($tracking !== null) {
    $tracking->getDelivered();          // bool
    $tracking->getDeliveredDate();      // ?DateTimeImmutable
    $tracking->getDamaged();            // bool
    $tracking->getWeight();             // float (kg)
    $tracking->getParcelNumber();       // string
    $tracking->getDeliveryCountryCode(); // string (ISO)

    foreach ($tracking->getParcelStatuses() as $status) {
        $status->getStatusDate();        // ?DateTimeImmutable
        $status->getStatusDescription(); // string
        $status->getCustomInfo();        // ?string (human-readable popis)
        $status->getDelivered();         // bool
        $status->getDamaged();           // bool
    }
}
```

Podporované hodnoty pro `$carrier`: `gls`, `ppl`, `czechpost`, `česká pošta`, `cp`.

---

### Direct API access

Pokud potřebuješ přímý přístup na metody konkrétního dopravce:

```php
$gateway->gls();        // Ages\ShippingGateway\Gls\GlsApi
$gateway->ppl();        // Ages\ShippingGateway\Ppl\PplApi
$gateway->czechPost();  // Ages\ShippingGateway\CzechPost\CzechPostApi
```

---

## Extending in your project

Balíček obsahuje pouze API vrstvu (HTTP komunikace, entity, config).  
Aplikační logika — mapování objednávky/faktury na zásilku, ukládání do DB, generování PDF — patří do projektu.

### GLS — příklad rozšíření

```php
namespace App\Api\Gls;

use Ages\ShippingGateway\Gls\GlsApi;
use Ages\ShippingGateway\Gls\Entity\ParcelEntity;
use Ages\ShippingGateway\Gls\Entity\ServiceEntity;
use App\Model\Orm\Consignment\Consignment;
use App\Model\Orm\Consignment\ConsignmentRepository;
use App\Model\Orm\Invoice\Invoice;
use App\Components\Storage\Storage;
use Tracy\Debugger;

class Gls extends GlsApi
{
    const string Carrier = 'GLS';
    const string TransportCode = 'D03';
    const string TrackUrl = 'https://gls-group.eu/CZ/cs/sledovani-zasilek/?match=';

    public function __construct(
        private readonly ConsignmentRepository $repository,
        private readonly Storage $storage,
        \Ages\ShippingGateway\Gls\Config\GlsConfig $config,
    ) {
        parent::__construct($config);
    }

    public function createConsignmentFromInvoice(Invoice $invoice): ?Consignment
    {
        try {
            $services = ServiceEntity::of();

            // pickupAddress pochází z configu přes $this->getPickupAddress()
            $pickupAddress = $this->getPickupAddress();

            $deliveryAddress = \Ages\ShippingGateway\Gls\Entity\AddressEntity::of(
                $invoice->delivery->company ?? $invoice->delivery->name,
                $invoice->delivery->street,
                $invoice->delivery->city,
                $invoice->delivery->zip,
                $invoice->delivery->countryIso,
                contactName: $invoice->delivery->name,
                contactPhone: $invoice->delivery->phone,
                contactEmail: $invoice->customer->email,
            );

            if ($invoice->cashOnDelivery) {
                $services->addServiceCOD($invoice->priceTax, $invoice->variableSymbol, $invoice->currencyIso);
            }
            if ($invoice->parcelShopDelivery) {
                $services->addServicePSD($invoice->chosenPsd->psdId);
            }

            $parcel = ParcelEntity::of(
                strval($this->config->clientNumber),
                $invoice->code,
                $invoice->packageQty,
                $pickupAddress,
                $deliveryAddress,
                $services,
            );

            $consignment = $this->persistConsignment($invoice);
            $data = $this->printLabels($parcel);

            // ... uložení štítku, persist do DB ...

            return $consignment;
        } catch (\Exception $e) {
            Debugger::log($e);
            return null;
        }
    }
}
```

### PPL — příklad rozšíření

```php
namespace App\Api\Ppl;

use Ages\ShippingGateway\Ppl\PplApi;
use Ages\ShippingGateway\Ppl\Entity\ParcelEntity;
use Ages\ShippingGateway\Ppl\Entity\CashOnDeliveryEntity;
use Ages\ShippingGateway\Ppl\Entity\SpecificDeliveryEntity;

class Ppl extends PplApi
{
    const string Carrier = 'PPL';
    const string TransportCode = 'D04';
    const string TrackUrl = 'https://www.ppl.cz/vyhledat-zasilku?shipmentId=';

    public function createConsignmentFromInvoice(Invoice $invoice): ?Consignment
    {
        // pickupAddress z configu
        $pickupAddress = $this->getPickupAddress();

        $deliveryAddress = \Ages\ShippingGateway\Ppl\Entity\AddressEntity::of(
            $invoice->delivery->company ?? $invoice->delivery->name,
            $invoice->delivery->street,
            $invoice->delivery->city,
            $invoice->delivery->zip,
            $invoice->delivery->countryIso,
            contactPhone: $invoice->delivery->phone,
            contactEmail: $invoice->customer->email,
        );

        $cod = $invoice->cashOnDelivery
            ? CashOnDeliveryEntity::of($invoice->priceTax, $invoice->variableSymbol, $invoice->currencyIso)
            : null;

        $specificDelivery = SpecificDeliveryEntity::of(
            $invoice->parcelShopDelivery ? $invoice->chosenPsd->psdId : null
        );

        $parcel = ParcelEntity::of(
            $invoice->code,
            $invoice->packageQty,
            $pickupAddress,
            $deliveryAddress,
            $specificDelivery,
            $cod,
        );

        $batchId = $this->createBatch($parcel);
        // ... getStatus(), getLabel(), persist ...
    }
}
```

### Czech Post — příklad rozšíření

```php
namespace App\Api\CzechPost;

use Ages\ShippingGateway\CzechPost\CzechPostApi;
use Ages\ShippingGateway\CzechPost\ErrorCodes;

class CzechPost extends CzechPostApi
{
    const string Carrier = 'Česká pošta';
    const string TransportCode = 'D02';

    public function createConsignmentFromInvoice(Invoice $invoice): ?Consignment
    {
        // prepareParcelServiceHeader() je protected metoda z CzechPostApi
        // — automaticky použije customerId, postCode, locationNumber z configu
        $header = $this->prepareParcelServiceHeader();

        // ... sestavení ConsignmentEntity, ParcelParamsEntity, atd. ...

        $res = $this->parcelService($header, $consignmentEntity->toArray(), $multipart);

        if (isset($res['responseHeader']['resultHeader']['responseCode'])) {
            $code = $res['responseHeader']['resultHeader']['responseCode'];
            if ($code !== 1) {
                $error = ErrorCodes::getErrorMsg($code);
                // ...
            }
        }
    }
}
```

---

## Architecture overview

```
src/
├── ShippingGateway.php          ← jediný vstupní bod (facade)
├── Common/
│   ├── CarrierInterface.php     ← getParcelTracking()
│   ├── ParcelTrackingInterface.php
│   ├── ParcelStatusInterface.php
│   ├── PickupAddress.php        ← sdílené DTO pro adresu odesílatele
│   └── ShippingException.php
├── Gls/
│   ├── Config/GlsConfig.php
│   ├── GlsApi.php               ← HTTP komunikace + getParcelTracking()
│   └── Entity/ Values/
├── Ppl/
│   ├── Config/PplConfig.php
│   ├── PplApi.php               ← OAuth2 + HTTP komunikace + getParcelTracking()
│   └── Entity/ Values/
└── CzechPost/
    ├── Config/CzechPostConfig.php
    ├── CzechPostApi.php         ← HMAC-SHA256 auth + getParcelTracking()
    ├── CzechPostException.php
    ├── ErrorCodes.php
    └── Entity/ Values/
```

**Co je v balíčku:** HTTP komunikace, entity, config, unified tracking.  
**Co patří do projektu:** mapování Invoice → zásilka, ukládání Consignment do DB, generování PDF štítků, Storage.

---

## License

Private package — Ages s.r.o.
