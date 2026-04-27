<?php

namespace Ages\ShippingGateway;

use Ages\ShippingGateway\Common\CarrierInterface;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\CzechPost\Config\CzechPostConfig;
use Ages\ShippingGateway\CzechPost\CzechPostApi;
use Ages\ShippingGateway\Gls\Config\GlsConfig;
use Ages\ShippingGateway\Gls\GlsApi;
use Ages\ShippingGateway\Ppl\Config\PplConfig;
use Ages\ShippingGateway\Ppl\PplApi;

class ShippingGateway
{
    private ?GlsApi $glsApi = null;
    private ?PplApi $pplApi = null;
    private ?CzechPostApi $czechPostApi = null;

    public function __construct(
        private readonly ?GlsConfig $glsConfig = null,
        private readonly ?PplConfig $pplConfig = null,
        private readonly ?CzechPostConfig $czechPostConfig = null,
    ) {
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
            'czechpost', 'česká pošta', 'cp' => $this->czechPost(),
            default => throw new \InvalidArgumentException("Unknown carrier: $carrier"),
        };
        return $api->getParcelTracking($consignmentId);
    }
}
