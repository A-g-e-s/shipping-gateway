<?php

namespace Ages\ShippingGateway;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\CzechPost\Config\CzechPostConfig;
use Ages\ShippingGateway\CzechPost\CzechPostApi;
use Ages\ShippingGateway\CzechPost\Handler\CzechPostShipmentHandler;
use Ages\ShippingGateway\GebruderWeiss\Handler\GebruderWeissShipmentHandler;
use Ages\ShippingGateway\Gls\Config\GlsConfig;
use Ages\ShippingGateway\Gls\GlsApi;
use Ages\ShippingGateway\Gls\Handler\GlsShipmentHandler;
use Ages\ShippingGateway\Ppl\Config\PplConfig;
use Ages\ShippingGateway\Ppl\Handler\PplShipmentHandler;
use Ages\ShippingGateway\Ppl\PplApi;

class ShippingGateway
{
    private ?GlsApi $glsApi = null;
    private ?PplApi $pplApi = null;
    private ?CzechPostApi $czechPostApi = null;

    private ?GlsShipmentHandler $glsHandler = null;
    private ?PplShipmentHandler $pplHandler = null;
    private ?CzechPostShipmentHandler $czechPostHandler = null;

    public function __construct(
        private readonly ?GlsConfig       $glsConfig = null,
        private readonly ?PplConfig       $pplConfig = null,
        private readonly ?CzechPostConfig $czechPostConfig = null,
    )
    {
    }

    public function gls(): GlsApi
    {
        assert($this->glsConfig !== null, 'GlsConfig not configured');
        return $this->glsApi ??= new GlsApi($this->glsConfig);
    }

    public function ppl(): PplApi
    {
        assert($this->pplConfig !== null, 'PplConfig not configured');
        return $this->pplApi ??= new PplApi($this->pplConfig);
    }

    public function czechPost(): CzechPostApi
    {
        assert($this->czechPostConfig !== null, 'CzechPostConfig not configured');
        return $this->czechPostApi ??= new CzechPostApi($this->czechPostConfig);
    }

    public function tracking(Carrier $carrier, string $consignmentId): ?ParcelTrackingInterface
    {
        $api = match ($carrier) {
            Carrier::Gls          => $this->gls(),
            Carrier::Ppl          => $this->ppl(),
            Carrier::CzechPost    => $this->czechPost(),
            Carrier::GebruderWeiss => throw new \LogicException('Gebrüder Weiss tracking is not supported'),
        };
        return $api->getParcelTracking($consignmentId);
    }

    /**
     * @return ShipmentLabel[]
     */
    public function createShipment(Carrier $carrier, ShipmentRequest $request): array
    {
        $handler = match ($carrier) {
            Carrier::Gls           => $this->glsShipmentHandler(),
            Carrier::Ppl           => $this->pplShipmentHandler(),
            Carrier::CzechPost     => $this->czechPostShipmentHandler(),
            Carrier::GebruderWeiss => new GebruderWeissShipmentHandler(),
        };
        return $handler->createShipment($request);
    }

    private function glsShipmentHandler(): GlsShipmentHandler
    {
        assert($this->glsConfig !== null, 'GlsConfig not configured');
        return $this->glsHandler ??= new GlsShipmentHandler($this->glsConfig);
    }

    private function pplShipmentHandler(): PplShipmentHandler
    {
        assert($this->pplConfig !== null, 'PplConfig not configured');
        return $this->pplHandler ??= new PplShipmentHandler($this->pplConfig);
    }

    private function czechPostShipmentHandler(): CzechPostShipmentHandler
    {
        assert($this->czechPostConfig !== null, 'CzechPostConfig not configured');
        return $this->czechPostHandler ??= new CzechPostShipmentHandler($this->czechPostConfig);
    }
}
