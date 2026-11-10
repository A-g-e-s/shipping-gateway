<?php

namespace Ages\ShippingGateway;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\CarrierRegistry;
use Ages\ShippingGateway\Common\ImportHandlerInterface;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\Shipment\ImportRequest;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\Common\ConfigException;
use Ages\ShippingGateway\Common\ShipmentAlreadyExistsException;
use Ages\ShippingGateway\Common\ShipmentHandlerInterface;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\CzechPost\Config\CzechPostConfig;
use Ages\ShippingGateway\CzechPost\Handler\CzechPostShipmentHandler;
use Ages\ShippingGateway\Dpd\Config\DpdConfig;
use Ages\ShippingGateway\Dpd\Handler\DpdShipmentHandler;
use Ages\ShippingGateway\GebruderWeiss\Config\GebruderWeissConfig;
use Ages\ShippingGateway\GebruderWeiss\Handler\GebruderWeissShipmentHandler;
use Ages\ShippingGateway\Gls\Config\GlsConfig;
use Ages\ShippingGateway\Gls\Handler\GlsShipmentHandler;
use Ages\ShippingGateway\OneByAllegro\Config\OneByAllegroConfig;
use Ages\ShippingGateway\OneByAllegro\Handler\OneByAllegroShipmentHandler;
use Ages\ShippingGateway\PersonalCollection\Config\PersonalCollectionConfig;
use Ages\ShippingGateway\PersonalCollection\Handler\PersonalCollectionShipmentHandler;
use Ages\ShippingGateway\Ppl\Config\PplConfig;
use Ages\ShippingGateway\Ppl\Handler\PplShipmentHandler;
use Ages\ShippingGateway\SupplierDelivery\Config\SupplierDeliveryConfig;
use Ages\ShippingGateway\SupplierDelivery\Handler\SupplierDeliveryShipmentHandler;
use Ages\ShippingGateway\Toptrans\Config\ToptransConfig;
use Ages\ShippingGateway\Toptrans\Handler\ToptransShipmentHandler;
use GuzzleHttp\ClientInterface;

class ShippingGateway
{
    private readonly CarrierRegistry $carriers;

    /**
     * Pojmenované configy kvůli neonu (ShippingGateway(gwConfig: @gwConfig)); dopravce bez configu
     * je registrovaný, ale volání na něj hodí ConfigException.
     */
    public function __construct(
        ?GlsConfig                $glsConfig = null,
        private readonly ?PplConfig $pplConfig = null,
        ?CzechPostConfig          $czechPostConfig = null,
        ?GebruderWeissConfig      $gwConfig = null,
        ?PersonalCollectionConfig $personalCollectionConfig = null,
        ?SupplierDeliveryConfig   $supplierDeliveryConfig = null,
        ?OneByAllegroConfig       $oneByAllegroConfig = null,
        ?DpdConfig                $dpdConfig = null,
        ?ToptransConfig           $toptransConfig = null,
    )
    {
        // Jediné místo registrace dopravců: Carrier → config → handler (vytvoří se až při prvním použití).
        $c = $this->carriers = new CarrierRegistry();
        $c->register(Carrier::Gls, $glsConfig, 'GlsConfig',
            static fn (GlsConfig $config, ?ClientInterface $http) => new GlsShipmentHandler($config, $http));
        $c->register(Carrier::Ppl, $pplConfig, 'PplConfig',
            static fn (PplConfig $config, ?ClientInterface $http) => new PplShipmentHandler($config, $http));
        $c->register(Carrier::CzechPost, $czechPostConfig, 'CzechPostConfig',
            static fn (CzechPostConfig $config, ?ClientInterface $http) => new CzechPostShipmentHandler($config, $http));
        $c->register(Carrier::GebruderWeiss, $gwConfig, 'GebruderWeissConfig',
            static fn (GebruderWeissConfig $config, ?ClientInterface $http) => new GebruderWeissShipmentHandler($config, $http));
        $c->register(Carrier::PersonalCollection, $personalCollectionConfig, 'PersonalCollectionConfig',
            static fn (PersonalCollectionConfig $config) => new PersonalCollectionShipmentHandler($config));
        $c->register(Carrier::SupplierDelivery, $supplierDeliveryConfig, 'SupplierDeliveryConfig',
            static fn (SupplierDeliveryConfig $config) => new SupplierDeliveryShipmentHandler($config));
        $c->register(Carrier::OneByAllegro, $oneByAllegroConfig, 'OneByAllegroConfig',
            static fn (OneByAllegroConfig $config, ?ClientInterface $http) => new OneByAllegroShipmentHandler($config, $http));
        $c->register(Carrier::Dpd, $dpdConfig, 'DpdConfig',
            static fn (DpdConfig $config, ?ClientInterface $http) => new DpdShipmentHandler($config, $http));
        $c->register(Carrier::Toptrans, $toptransConfig, 'ToptransConfig',
            static fn (ToptransConfig $config, ?ClientInterface $http) => new ToptransShipmentHandler($config, $http));
    }

    /**
     * Vlastní Guzzle klient pro všechny dopravce (testy s MockHandler, proxy, logování).
     * Nastavit před prvním voláním dopravce – už vytvořené handlery se zahodí, ale reference na ně
     * (oneByAllegro(), dpd(), toptrans()) si drží původního klienta.
     * Timeouty a http_errors nastavuje společná vrstva Common\Http\HttpClient u každého požadavku.
     */
    public function setHttpClient(?ClientInterface $httpClient): void
    {
        $this->carriers->setHttpClient($httpClient);
    }

    /**
     * Handler dopravce (např. pro funkce mimo společné API). Hodí ConfigException, když dopravce nemá config.
     *
     * @throws ConfigException
     */
    public function handler(Carrier $carrier): ShipmentHandlerInterface
    {
        return $this->carriers->get($carrier);
    }

    public function isConfigured(Carrier $carrier): bool
    {
        return $this->carriers->isConfigured($carrier);
    }

    /**
     * @param \DateTimeInterface|null $createdAt datum založení zásilky (Gebrüder Weiss podle něj hledá referenci)
     * @return ParcelTrackingInterface|null null = dopravce zásilku nezná nebo tracking nemá (osobní odběr, dodavatel)
     * @throws ShippingException
     */
    public function tracking(Carrier $carrier, string $consignmentId, ?\DateTimeInterface $createdAt = null): ?ParcelTrackingInterface
    {
        return $this->call(fn () => $this->carriers->get($carrier)->getParcelTracking($consignmentId, $createdAt));
    }

    /**
     * @throws ShippingException
     */
    public function trackingUrl(Carrier $carrier, string $consignmentId): string
    {
        return $this->call(fn () => $this->carriers->get($carrier)->getTrackingUrl($consignmentId));
    }

    /**
     * @return ShipmentLabel[]
     * @throws ShippingException
     */
    public function createShipment(Carrier $carrier, ShipmentRequest $request): array
    {
        return $this->call(fn () => $this->carriers->get($carrier)->createShipment($request));
    }

    /**
     * Objedná svoz od dodavatele na pickupAddress dopravce (handler s ImportHandlerInterface – Gebrüder Weiss).
     * Vrací štítky (PDF u prvního balíku), které dodavatel nalepí na zboží před vyzvednutím.
     *
     * @return ShipmentLabel[]
     * @throws ShipmentAlreadyExistsException objednávka se stejnou referencí už u dopravce existuje (nese štítky)
     * @throws ShippingException
     */
    public function createImport(Carrier $carrier, ImportRequest $request): array
    {
        return $this->call(function () use ($carrier, $request): array {
            $handler = $this->carriers->get($carrier);
            if (!$handler instanceof ImportHandlerInterface) {
                throw new ShippingException('Import is not supported for carrier ' . $carrier->name);
            }
            return $handler->createImport($request);
        });
    }

    /**
     * API klíč mapového widgetu výdejních míst. Null pro dopravce, jejichž widget klíč nepotřebuje.
     *
     * @throws ConfigException
     */
    public function pickupPointWidgetKey(Carrier $carrier): ?string
    {
        return match ($carrier) {
            Carrier::Ppl => $this->pplConfig->widgetApiKey ?? throw new ConfigException('PplConfig widgetApiKey not configured'),
            default      => null,
        };
    }

    /**
     * Přímý přístup k One by Allegro API – výdejní místa, svozy (pickups), detail zásilky.
     *
     * @throws ConfigException
     */
    public function oneByAllegro(): OneByAllegroShipmentHandler
    {
        return $this->carriers->getAs(Carrier::OneByAllegro, OneByAllegroShipmentHandler::class);
    }

    /**
     * Přímý přístup k DPD GeoAPI – svozy (pickup orders), zákazníci a adresy (GET /me), služby.
     *
     * @throws ConfigException
     */
    public function dpd(): DpdShipmentHandler
    {
        return $this->carriers->getAs(Carrier::Dpd, DpdShipmentHandler::class);
    }

    /**
     * Přímý přístup k Toptrans API – výpočet ceny, archiv dávek, tisk dokumentů (zasilatelský příkaz, soupiska,
     * štítky Zebra, POD), číselníky.
     *
     * @throws ConfigException
     */
    public function toptrans(): ToptransShipmentHandler
    {
        return $this->carriers->getAs(Carrier::Toptrans, ToptransShipmentHandler::class);
    }

    /**
     * Chyby mimo ShippingException (Guzzle, TypeError, InvalidArgument…) zabalí do ShippingException.
     *
     * @template T
     * @param \Closure(): T $operation
     * @return T
     * @throws ShippingException
     */
    private function call(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (ShippingException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ShippingException($e->getMessage(), 0, $e);
        }
    }
}
