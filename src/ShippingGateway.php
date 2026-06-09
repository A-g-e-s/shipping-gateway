<?php

namespace Ages\ShippingGateway;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\Common\ConfigException;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\CzechPost\Config\CzechPostConfig;
use Ages\ShippingGateway\CzechPost\Handler\CzechPostShipmentHandler;
use Ages\ShippingGateway\GebruderWeiss\Config\GebruderWeissConfig;
use Ages\ShippingGateway\GebruderWeiss\Handler\GebruderWeissShipmentHandler;
use Ages\ShippingGateway\Gls\Config\GlsConfig;
use Ages\ShippingGateway\Gls\Handler\GlsShipmentHandler;
use Ages\ShippingGateway\PersonalCollection\Config\PersonalCollectionConfig;
use Ages\ShippingGateway\PersonalCollection\Handler\PersonalCollectionShipmentHandler;
use Ages\ShippingGateway\Ppl\Config\PplConfig;
use Ages\ShippingGateway\Ppl\Handler\PplShipmentHandler;

class ShippingGateway
{
    private ?GlsShipmentHandler $glsHandler = null;
    private ?PplShipmentHandler $pplHandler = null;
    private ?CzechPostShipmentHandler $czechPostHandler = null;
    private ?GebruderWeissShipmentHandler $gwHandler = null;
    private ?PersonalCollectionShipmentHandler $personalCollectionHandler = null;

    public function __construct(
        private readonly ?GlsConfig                  $glsConfig = null,
        private readonly ?PplConfig                  $pplConfig = null,
        private readonly ?CzechPostConfig            $czechPostConfig = null,
        private readonly ?GebruderWeissConfig        $gwConfig = null,
        private readonly ?PersonalCollectionConfig   $personalCollectionConfig = null,
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
            };
            return $handler->createShipment($request);
        } catch (ShippingException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ShippingException($e->getMessage(), 0, $e);
        }
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
}
