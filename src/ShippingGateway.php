<?php

namespace Ages\ShippingGateway;

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

    public function tracking(string $carrier, string $consignmentId): ?ParcelTrackingInterface
    {
        $api = match (strtolower($carrier)) {
            'gls' => $this->gls(),
            'ppl' => $this->ppl(),
            'czechpost', 'česká pošta', 'cp', 'balikovna', 'balíkovna' => $this->czechPost(),
            default => throw new \InvalidArgumentException("Unknown carrier: $carrier"),
        };
        return $api->getParcelTracking($consignmentId);
    }

    /**
     * @return ShipmentLabel[]
     */
    public function createShipment(string $carrier, ShipmentRequest $request): array
    {
        $handler = match (strtolower($carrier)) {
            'gls'                                                        => $this->glsShipmentHandler(),
            'ppl'                                                        => $this->pplShipmentHandler(),
            'czechpost', 'česká pošta', 'cp', 'balikovna', 'balíkovna' => $this->czechPostShipmentHandler(),
            'gbw', 'gebruderweiss', 'gebrüderweiss'                     => new GebruderWeissShipmentHandler(),
            default => throw new \InvalidArgumentException("Unknown carrier: $carrier"),
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
