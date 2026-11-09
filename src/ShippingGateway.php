<?php

namespace Ages\ShippingGateway;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\Shipment\ImportRequest;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\Common\ConfigException;
use Ages\ShippingGateway\Common\ShipmentAlreadyExistsException;
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

class ShippingGateway
{
    private ?GlsShipmentHandler $glsHandler = null;
    private ?PplShipmentHandler $pplHandler = null;
    private ?CzechPostShipmentHandler $czechPostHandler = null;
    private ?GebruderWeissShipmentHandler $gwHandler = null;
    private ?PersonalCollectionShipmentHandler $personalCollectionHandler = null;
    private ?SupplierDeliveryShipmentHandler $supplierDeliveryHandler = null;
    private ?OneByAllegroShipmentHandler $oneByAllegroHandler = null;
    private ?DpdShipmentHandler $dpdHandler = null;

    public function __construct(
        private readonly ?GlsConfig                  $glsConfig = null,
        private readonly ?PplConfig                  $pplConfig = null,
        private readonly ?CzechPostConfig            $czechPostConfig = null,
        private readonly ?GebruderWeissConfig        $gwConfig = null,
        private readonly ?PersonalCollectionConfig   $personalCollectionConfig = null,
        private readonly ?SupplierDeliveryConfig     $supplierDeliveryConfig = null,
        private readonly ?OneByAllegroConfig         $oneByAllegroConfig = null,
        private readonly ?DpdConfig                  $dpdConfig = null,
    )
    {
    }

    /**
     * @throws ShippingException
     */
    public function tracking(Carrier $carrier, string $consignmentId, ?\DateTimeInterface $createdAt = null): ?ParcelTrackingInterface
    {
        try {
            return match ($carrier) {
                Carrier::Gls                => $this->glsShipmentHandler()->getParcelTracking($consignmentId),
                Carrier::Ppl                => $this->pplShipmentHandler()->getParcelTracking($consignmentId),
                Carrier::CzechPost          => $this->czechPostShipmentHandler()->getParcelTracking($consignmentId),
                Carrier::GebruderWeiss      => $this->gwShipmentHandler()->getParcelTracking($consignmentId, $createdAt),
                Carrier::PersonalCollection => null,
                Carrier::SupplierDelivery   => null,
                Carrier::OneByAllegro       => $this->oneByAllegroShipmentHandler()->getParcelTracking($consignmentId),
                Carrier::Dpd                => $this->dpdShipmentHandler()->getParcelTracking($consignmentId),
            };
        } catch (ShippingException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ShippingException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws ShippingException
     */
    public function trackingUrl(Carrier $carrier, string $consignmentId): string
    {
        try {
            return match ($carrier) {
                Carrier::Gls                => $this->glsShipmentHandler()->getTrackingUrl($consignmentId),
                Carrier::Ppl                => $this->pplShipmentHandler()->getTrackingUrl($consignmentId),
                Carrier::CzechPost          => $this->czechPostShipmentHandler()->getTrackingUrl($consignmentId),
                Carrier::GebruderWeiss      => $this->gwShipmentHandler()->getTrackingUrl($consignmentId),
                Carrier::PersonalCollection => '',
                Carrier::SupplierDelivery   => '',
                Carrier::OneByAllegro       => $this->oneByAllegroShipmentHandler()->getTrackingUrl($consignmentId),
                Carrier::Dpd                => $this->dpdShipmentHandler()->getTrackingUrl($consignmentId),
            };
        } catch (ShippingException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ShippingException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return ShipmentLabel[]
     * @throws ShippingException
     */
    public function createShipment(Carrier $carrier, ShipmentRequest $request): array
    {
        try {
            $handler = match ($carrier) {
                Carrier::Gls                => $this->glsShipmentHandler(),
                Carrier::Ppl                => $this->pplShipmentHandler(),
                Carrier::CzechPost          => $this->czechPostShipmentHandler(),
                Carrier::GebruderWeiss      => $this->gwShipmentHandler(),
                Carrier::PersonalCollection => $this->personalCollectionShipmentHandler(),
                Carrier::SupplierDelivery   => $this->supplierDeliveryShipmentHandler(),
                Carrier::OneByAllegro       => $this->oneByAllegroShipmentHandler(),
                Carrier::Dpd                => $this->dpdShipmentHandler(),
            };
            return $handler->createShipment($request);
        } catch (ShippingException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ShippingException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Objedná svoz od dodavatele na pickupAddress dopravce. Podporuje jen Gebrüder Weiss.
     * Vrací štítky (PDF u prvního balíku), které dodavatel nalepí na zboží před vyzvednutím.
     *
     * @return ShipmentLabel[]
     * @throws ShipmentAlreadyExistsException objednávka se stejnou referencí už u dopravce existuje (nese štítky)
     * @throws ShippingException
     */
    public function createImport(Carrier $carrier, ImportRequest $request): array
    {
        try {
            return match ($carrier) {
                Carrier::GebruderWeiss => $this->gwShipmentHandler()->createImport($request),
                default                => throw new ShippingException('Import is not supported for carrier ' . $carrier->name),
            };
        } catch (ShippingException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ShippingException($e->getMessage(), 0, $e);
        }
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

    private function glsShipmentHandler(): GlsShipmentHandler
    {
        if ($this->glsConfig === null) throw new ConfigException('GlsConfig not configured');
        return $this->glsHandler ??= new GlsShipmentHandler($this->glsConfig);
    }

    private function pplShipmentHandler(): PplShipmentHandler
    {
        if ($this->pplConfig === null) throw new ConfigException('PplConfig not configured');
        return $this->pplHandler ??= new PplShipmentHandler($this->pplConfig);
    }

    private function czechPostShipmentHandler(): CzechPostShipmentHandler
    {
        if ($this->czechPostConfig === null) throw new ConfigException('CzechPostConfig not configured');
        return $this->czechPostHandler ??= new CzechPostShipmentHandler($this->czechPostConfig);
    }

    private function gwShipmentHandler(): GebruderWeissShipmentHandler
    {
        if ($this->gwConfig === null) throw new ConfigException('GebruderWeissConfig not configured');
        return $this->gwHandler ??= new GebruderWeissShipmentHandler($this->gwConfig);
    }

    private function personalCollectionShipmentHandler(): PersonalCollectionShipmentHandler
    {
        if ($this->personalCollectionConfig === null) throw new ConfigException('PersonalCollectionConfig not configured');
        return $this->personalCollectionHandler ??= new PersonalCollectionShipmentHandler($this->personalCollectionConfig);
    }

    private function supplierDeliveryShipmentHandler(): SupplierDeliveryShipmentHandler
    {
        if ($this->supplierDeliveryConfig === null) throw new ConfigException('SupplierDeliveryConfig not configured');
        return $this->supplierDeliveryHandler ??= new SupplierDeliveryShipmentHandler($this->supplierDeliveryConfig);
    }

    /**
     * Přímý přístup k One by Allegro API – výdejní místa, svozy (pickups), detail zásilky.
     *
     * @throws ConfigException
     */
    public function oneByAllegro(): OneByAllegroShipmentHandler
    {
        return $this->oneByAllegroShipmentHandler();
    }

    private function oneByAllegroShipmentHandler(): OneByAllegroShipmentHandler
    {
        if ($this->oneByAllegroConfig === null) throw new ConfigException('OneByAllegroConfig not configured');
        return $this->oneByAllegroHandler ??= new OneByAllegroShipmentHandler($this->oneByAllegroConfig);
    }

    /**
     * Přímý přístup k DPD GeoAPI – svozy (pickup orders), zákazníci a adresy (GET /me), služby.
     *
     * @throws ConfigException
     */
    public function dpd(): DpdShipmentHandler
    {
        return $this->dpdShipmentHandler();
    }

    private function dpdShipmentHandler(): DpdShipmentHandler
    {
        if ($this->dpdConfig === null) throw new ConfigException('DpdConfig not configured');
        return $this->dpdHandler ??= new DpdShipmentHandler($this->dpdConfig);
    }
}
