# ages/shipping-gateway

Unified PHP library for shipping carrier integrations — **GLS**, **PPL**, **Czech Post**, **Gebrüder Weiss**, **One by Allegro**, **DPD**.  
Single entry point, config-driven credentials and pickup address, compatible with Nette + Nextras.

---

## API Documentation

| Dopravce | Odkaz |
|---|---|
| GLS | https://api.mygls.hu/index_cz.html |
| PPL | https://ppl-cpl-api.apidog.io/ |
| Česká pošta | https://www.postaonline.cz/dokumentaceapi/b2b/ |
| Gebrüder Weiss | https://developer.my.gw-world.com/#/apis |
| One by Allegro | https://api.onedelivery.tech/parcels/swagger.html |
| DPD (GeoAPI) | https://geoapi.dpd.cz/v1/swagger/ (tracking https://geoapi.dpd.cz/v2/swagger/) |

---

## Requirements

- PHP 8.4+
- Nette Utils `^4.0`
- Guzzle `^7.9`
- mPDF `^8.3`

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
        # company: 'my-company-code'   # volitelne, pouziva se jen pro GW tracking URL
    )

    glsConfig: Ages\ShippingGateway\Gls\Config\GlsConfig(
        username: 'user@example.com'
        password: secret
        clientNumber: 12345678
        pickupAddress: @pickupAddress
        # url: https://api.test.mygls.cz/ParcelService.svc/json/   # test endpoint
        # timeout: 300.0   # sekundy, výchozí 120
    )

    pplConfig: Ages\ShippingGateway\Ppl\Config\PplConfig(
        clientId: CLIENT_ID
        clientSecret: CLIENT_SECRET
        pickupAddress: @pickupAddress
        # url: https://api-dev.dhl.com/ecs/ppl/myapi2/             # test endpoint
        # widgetApiKey: ak_xxxxxxxx                                # mapový widget výdejních míst (klient.ppl.cz/widgetadmin)
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
        ssccPrefix: '22260000'          # číselný prefix pro generování SSCC kódů
        # product: GW_PRO_LINE
        # incoterm: DAP
        # importIncoterm: EXW              # dodací podmínka pro import (svoz od dodavatele)
        # goodsDescription: Goods
        # logoPath: %wwwDir%/img/logo-gw.png   # čtvercové logo na etiketu (volitelné)
    )

    oneByAllegroConfig: Ages\ShippingGateway\OneByAllegro\Config\OneByAllegroConfig(
        username: USERNAME                 # přístupy ze smlouvy s One by Allegro
        password: PASSWORD
        pickupAddress: @pickupAddress      # musí odpovídat svozovému místu nastavenému u One by Allegro
        # url: https://api.onedelivery.tech.allegrosandbox.pl   # sandbox
        # labelFormat: Ages\ShippingGateway\OneByAllegro\Values\LabelFormat::PdfA4   # výchozí PdfA6
        # contentDescription: 'Krmivo pro psy'
        # defaultLength: 30                # cm, když Parcel nemá dimensions (API rozměry vyžaduje)
        # defaultWidth: 20
        # defaultHeight: 15
    )

    dpdConfig: Ages\ShippingGateway\Dpd\Config\DpdConfig(
        apiKey: API_KEY                    # klíč GeoAPI účtu (hlavička x-api-key)
        customerDsw: '12345678901'         # zákaznické číslo DPD (DSW)
        pickupAddress: @pickupAddress
        # senderAddressId: 123456          # it4emId registrované adresy z GET /me – odesílatel + svozy (pickup orders)
        # url: https://geoapi-test.dpd.cz  # test
        # labelFormat: Ages\ShippingGateway\Dpd\Values\LabelFormat::PdfA4   # výchozí PdfA6, A4 = 4 štítky na stránku
        # notification: false              # Predict (SMS/e-mail příjemci), výchozí zapnuto
        # insureValue: true                # pojištění na ShipmentValue, výchozí vypnuto
    )

    - Ages\ShippingGateway\ShippingGateway
```

> **One by Allegro** doručuje jen do **CZ a SK**, odesílatel musí být v CZ. Bez `parcelShopCode` jde zásilka kurýrem (`TO_DOOR`),
> s `parcelShopCode` (= `id` výdejního místa, např. `OXBOX32992`) do One Boxu / One Pointu – typ místa určí API.
> Telefon se převádí na `+420…` / `+421…`, ulice bez `houseNumber` se rozdělí podle čísla na konci („Hlavní 42/5“ → `Hlavní`, `42/5`).

> **DPD** zakládá zásilky typu `Standard` pod `customerDsw`. `parcelShopCode` = ID výdejního místa (např. `CZ10190`, služba `pickupPoint`),
> dobírka se posílá v haléřích s platbou `CashOrCard` a variabilním symbolem (jen číslice, max 10). Firma jde do `name1`, osoba do `name2` a kontaktu.
> Ulice a telefon se upravují stejně jako u One by Allegro. `senderAddressId` a DSW najdete přes `$gateway->dpd()->getMe()`.

> **Certifikát České pošty** (`postsignum-bundle.pem`) je třeba umístit ručně do projektu.  
> Cesta se nastavuje přes `certificatePath` — nikdy se nekopíruje do balíčku.

> **Gebrüder Weiss — `clientId` / `clientSecret`** odpovídají hodnotám **Consumer Key** a **Consumer Secret** z portálu `developer.my.gw-world.com` (záložka vaší aplikace po subscribe na API).  
> Je třeba mít subscribe na obě API: `TRANSPORT_ORDER_CREATE` i `TRACKNTRACE`.

> **Gebrüder Weiss — `ssccPrefix`** je povinný číselný řetězec (doporučeno 6–10 číslic) sloužící jako základ pro automatické generování SSCC kódů (18 číslic dle GS1).  
> Podpora jej sdělí nebo si zvolte vlastní firemní prefix, např. `22260000`.  
> Volitelný `logoPath` přijímá absolutní cestu k obrázku — ten se zobrazí v levém horním rohu štítku ve čtvercové oblasti cca 24 × 24 mm.

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
    note: 'Poznámka pro přepravu',
    // Pro výdejní místa:
    // parcelShopCode: 'B69623',
    // parcelShopZip: '69623', // u České pošty explicitně PSČ provozovny
);

/** @var \Ages\ShippingGateway\ShippingGateway $gateway */
$labels = $gateway->createShipment(Carrier::Gls, $request);

foreach ($labels as $label) {
    echo $label->trackingNumber;             // číslo zásilky
    file_put_contents('label.pdf', $label->labelPdf); // raw PDF bytes
}
```

Dostupné hodnoty `Carrier`: `Carrier::Gls`, `Carrier::Ppl`, `Carrier::CzechPost`, `Carrier::GebruderWeiss`, `Carrier::OneByAllegro`, `Carrier::Dpd`.

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

// S rozměry — hodnoty v cm; u Gebrüder Weiss se odesílají jako metry (automatická konverze ÷ 100)
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

> **Více balíků — GLS / PPL / Czech Post / DPD:** API vrátí jeden kombinovaný PDF soubor pro všechny balíky.  
> Každý `ShipmentLabel` má svůj `trackingNumber`, `labelPdf` je u všech shodné (kombinovaný tisk).
>
> **PPL — produkt podle země a výdejního místa:** příjemce ve stejné zemi jako `pickupAddress` → tuzemský produkt
> (`PRIV`/`PRID` na adresu, `SMAR`/`SMAD` na výdejní místo), jinak mezinárodní (`CONN`/`COND` na adresu,
> `SMEU`/`SMED` na výdejní místo; druhý kód vždy s dobírkou). Produkty musí být aktivní ve smlouvě (`GET codelist/product`).
> U exportu se adresy posílají bez diakritiky (CPL přijímá jen latinku). Export s dobírkou nesmí být vícekusová sada,
> každý balík je proto samostatná zásilka (`referenceId` = `reference-1`, `-2` …) s poměrnou částí dobírky, zbytek po dělení
> dostane poslední balík. Měna dobírky se bere z `CashOnDelivery::currency` (pro SK `EUR`), z variabilního symbolu se berou jen číslice.
>
> **Více balíků — One by Allegro:** jedna zásilka (`shipmentId`) s více balíky; každý `ShipmentLabel` nese `shipmentId`
> (tracking i veřejné sledování jdou přes něj) a stejné PDF se štítky všech balíků. Pokud se zásilka vytvoří, ale štítek se
> nepodaří stáhnout, výjimka obsahuje `shipmentId` – štítek lze dotáhnout přes `$gateway->oneByAllegro()->getLabels([$id])`.
>
> **Více balíků — Gebrüder Weiss:** každý balík dostane vlastní SSCC kód v etiketě a samostatné PDF etiket.  
> `ShipmentLabel[]` má tolik prvků, kolik je zásilek — pro tracking mají všechny stejný `trackingNumber` (`reference` / `orderId`), `labelPdf` je u prvního prvku a SSCC zůstává součástí etikety.

---

### Import — svoz od dodavatele (jen Gebrüder Weiss)

Dopravce zboží vyzvedne u dodavatele (`shipper`) a doveze ho na `pickupAddress` z konfigurace; dopravu platí náš `customerId`.
GBW bez čárového kódu u každého kusu zakázku nepřijme, import proto vrací štítky se SSCC stejně jako export
(PDF u prvního balíku, odesílatel = dodavatel, příjemce = `pickupAddress`, datum = den vyzvednutí).
PDF pošlete dodavateli, který štítky nalepí na zboží; zásilku pak sledujete přes `reference`.

```php
use Ages\ShippingGateway\Common\Shipment\ImportRequest;

$labels = $gateway->createImport(Carrier::GebruderWeiss, new ImportRequest(
    reference: 'IMP-2026-001',
    shipper: new RecipientAddress(
        firstName: 'Max',
        lastName: 'Mustermann',
        street: 'Industriestraße',
        houseNumber: '10',
        city: 'Wien',
        zip: '1010',
        country: 'AT',
        phone: '+43 1 234',
        email: 'expedice@dodavatel.at',
        company: 'Dodavatel GmbH',
    ),
    parcels: [new Parcel(weight: 320.0, type: ParcelType::PalletEur, dimensions: new Dimensions(120, 80, 150))],
    collectionDate: new \DateTimeImmutable('2026-11-05'), // nesmí být v minulosti
    note: 'Vyzvednout na rampě 3',
));
```

> Posílá se `collectionDate`, incoterm `importIncoterm` (výchozí `EXW`, místo = město dodavatele) a balíky se SSCC, váhou a rozměry jako u exportu.
> Existuje-li u GBW objednávka se stejnou referencí, vyhodí `ShipmentAlreadyExistsException` se štítky (stejná SSCC).
> Kontakt (`contacts`) GBW přijímá jen u příjemce, u dodavatele se proto neposílá – jeho telefon jde na začátek poznámky (`Tel. … – poznámka`, celkem max. 100 znaků).

---

### Unified tracking

```php
$tracking = $gateway->tracking(Carrier::Gls, '1234567890');
$tracking = $gateway->tracking(Carrier::Ppl, 'KEA12345678');
$tracking = $gateway->tracking(Carrier::CzechPost, 'DR123456789CZ');
$tracking = $gateway->tracking(Carrier::GebruderWeiss, 'ORDER-2026-001'); // reference zásilky / orderId
$tracking = $gateway->tracking(Carrier::OneByAllegro, 'SHIP12345');         // shipmentId
$tracking = $gateway->tracking(Carrier::Dpd, '13815045502659');             // číslo balíku (14 číslic)

$trackingUrl = $gateway->trackingUrl(Carrier::Gls, '1234567890');
$trackingUrl = $gateway->trackingUrl(Carrier::Ppl, 'KEA12345678');
$trackingUrl = $gateway->trackingUrl(Carrier::CzechPost, 'DR123456789CZ');
$trackingUrl = $gateway->trackingUrl(Carrier::GebruderWeiss, 'ORDER-2026-001');

// GW public tracking deep-link needs pickupAddress.company in config.
// PPL public tracking URL uses ?shipmentId={trackingNumber}.

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

> `trackingUrl()` vrací veřejnou stránku sledování dopravce.  
> U GLS a České pošty je URL předvyplněná číslem zásilky, u PPL a Gebrüder Weiss vede na jejich oficiální tracking stránku.

> **Gebrüder Weiss:** tracking probíhá přes reference/orderId vrácené v `$label->trackingNumber`.  
> API vrací aktuální stav zásilky; `null` znamená, že zásilka ještě není v systému GBW (zpoždění po odeslání objednávky).

---

### Widget výdejních míst

```php
$apiKey = $gateway->pickupPointWidgetKey(Carrier::Ppl); // 'ak_…' pro <ppl-access-point-widget api-key="…">
$gateway->pickupPointWidgetKey(Carrier::Gls);           // null — widget klíč nepotřebuje
```

> PPL widget 2.0 (https://ppl-widget2.apidog.io/) vyžaduje API klíč z https://klient.ppl.cz/widgetadmin s whitelistem domén.  
> Klíč je veřejný (vypisuje se do HTML), nastavuje se jako `widgetApiKey` v `PplConfig`. Chybí-li, metoda vyhodí `ConfigException`.

---

### One by Allegro — výdejní místa a svozy

```php
$api = $gateway->oneByAllegro();

$points = $api->getPoints('CZ');           // všechna místa (BOX / PUDO) – id, name, type, status, address, openingHours …

$dates  = $api->getPickupProposals();      // dostupné termíny svozu ['2026-11-10', …]
$pickup = $api->createPickup([$shipmentId], $dates[0] ?? null); // ['pickupId' => …, 'pickupDate' => DateTimeImmutable]
$api->deletePickup($pickup['pickupId']);

$detail = $api->getShipment($shipmentId);  // ShipmentDetailResponse
```

> Stavy zásilky: `DOR` = doručeno, `USZ` / `ZAG` = poškozeno / ztraceno (`damaged`). Číselník: https://api.onedelivery.tech/parcels/statuses.csv

### DPD — svozy a nastavení účtu

```php
$dpd = $gateway->dpd();

$me = $dpd->getMe();                       // zákazníci (DSW) a registrované adresy (it4emId)
$dpd->getShippingServices();               // dostupné kombinace služeb

$orders = $dpd->createPickupOrder(new \DateTimeImmutable('tomorrow'), 'Rampa 2'); // kurýr na senderAddressId
$dpd->getPickupOrder($orders[0]['id']);
$dpd->cancelPickupOrder($orders[0]['id']);

$dpd->getLabels(['13815045502659']);       // znovu stáhnout štítky (PDF)
```

> Tracking jde přes GeoAPI v2, `null` = DPD balík zatím nezná (HTTP 404). Doručení se pozná podle kódu `13` / `23`
> nebo podle popisu („Doručeno“, „Delivered“) – GeoAPI číselník stavů nepublikuje. Váhu tracking nevrací (`weight` = 0).

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

## HTTP vrstva a chyby

Všichni dopravci posílají požadavky přes `Common\Http\HttpClient`:

- **Timeouty** – výchozí `timeout` 30 s, `connect_timeout` 10 s. Výjimky: DPD 60 s, Česká pošta 15 s
  (+ `verify` = `certificatePath`), GLS 120 s (`GlsApi::DefaultTimeoutSeconds`, přepíše `GlsConfig` parametr `timeout`).
- **Chyby** – vše je `ShippingException`. Chyba spojení / timeout: `"<Dopravce> <METODA> <cesta> request error: …"`
  (Guzzle výjimka v `getPrevious()`). HTTP ≥ 400: `HttpStatusException` s HTTP kódem v `getCode()`, zprávou
  `"<Dopravce> <METODA> <cesta> HTTP <kód>: <chyba z těla>"` a celým tělem v `getResponseBody()`.
  `CzechPostException` je potomek `ShippingException` a dál nese `getExtra()`.
- **Tokeny** – PPL (OAuth, 30 min), Gebrüder Weiss (OAuth, token pro každý scope) a One by Allegro si token drží
  v `TokenCache`: obnoví ho 60 s před vypršením (`expires_in`; když chybí, po 25 min) a při odpovědi 401 jednou
  získají nový token a požadavek zopakují. Dlouho běžící procesy (cron s hromadným trackingem) tak nespadnou na 401.

Vlastní Guzzle klienta (testy s `MockHandler`, proxy, logování) lze podstrčit – volitelný druhý parametr
konstruktoru každého `*Api` / `*ShipmentHandler`, nebo pro všechny dopravce najednou:

```php
$gateway->setHttpClient(new GuzzleHttp\Client(['handler' => $handlerStack]));
```

`http_errors` a timeouty nastavuje `HttpClient` u každého požadavku, takže na nastavení podstrčeného klienta nezáleží.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

Testy (`tests/`) běží jen proti `GuzzleHttp\Handler\MockHandler`, ostrá API nevolají.

## Architecture overview

```
src/
├── ShippingGateway.php               ← facade: tracking() + createShipment()
├── Common/
│   ├── Carrier.php                   ← enum: Gls | Ppl | CzechPost | GebruderWeiss
│   ├── CarrierInterface.php
│   ├── ShipmentHandlerInterface.php  ← createShipment(ShipmentRequest): ShipmentLabel[]
│   ├── AddressFormatter.php          ← ulice/číslo domu, telefon v mezinárodním tvaru
│   ├── ParcelTrackingInterface.php
│   ├── ParcelStatusInterface.php
│   ├── PickupAddress.php
│   ├── ShippingException.php
│   ├── Http/
│   │   ├── HttpClient.php            ← společný obal nad Guzzle: base URL, hlavičky, timeouty, chyby, Bearer + retry po 401
│   │   ├── HttpStatusException.php   ← HTTP ≥ 400, kód v getCode(), tělo v getResponseBody()
│   │   ├── TokenCache.php            ← tokeny s expirací (PPL, GW podle scope, One by Allegro)
│   │   └── AccessToken.php
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
    ├── Handler/GebruderWeissShipmentHandler.php
    ├── Label/GebruderWeissLabelGenerator.php  ← mPDF etiketa 100×150 mm
    └── Tracking/
        ├── GbwParcelTracking.php
        └── GbwParcelStatus.php
├── Dpd/
│   ├── Config/DpdConfig.php
│   ├── DpdApi.php                    ← x-api-key, shipments, labels, tracking (v2), me, pickup orders
│   ├── Handler/DpdShipmentHandler.php
│   ├── Tracking/
│   └── Values/
└── OneByAllegro/
    ├── Config/OneByAllegroConfig.php
    ├── OneByAllegroApi.php           ← auth token, shipments, labels, tracking, points, pickups
    ├── AddressFormatter.php          ← ulice/číslo, telefon, jména dle validace API
    ├── Handler/OneByAllegroShipmentHandler.php
    ├── Tracking/
    └── Values/
```

**Co je v balíčku:** HTTP komunikace, entity, config, unified tracking, unified shipment creation.  
**Co patří do projektu:** mapování Invoice → ShipmentRequest, ukládání zásilek do DB, Storage.

---

## License

Private package — Ages s.r.o.
