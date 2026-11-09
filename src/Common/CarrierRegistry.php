<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common;

use GuzzleHttp\ClientInterface;

/**
 * Mapa Carrier → handler. Handler se vytvoří až při prvním použití (lazy) z dodaného configu;
 * dopravce bez configu je registrovaný jako nenakonfigurovaný (get() hodí ConfigException).
 */
final class CarrierRegistry
{
    /** @var array<string, \Closure(?ClientInterface): ShipmentHandlerInterface> */
    private array $factories = [];

    /** @var array<string, string> carrier => název chybějícího configu */
    private array $missing = [];

    /** @var array<string, ShipmentHandlerInterface> */
    private array $handlers = [];

    private ?ClientInterface $httpClient = null;

    /**
     * @template TConfig of object
     * @param TConfig|null $config null = dopravce není nakonfigurovaný
     * @param \Closure(TConfig, ?ClientInterface): ShipmentHandlerInterface $factory
     */
    public function register(Carrier $carrier, ?object $config, string $configName, \Closure $factory): void
    {
        unset($this->factories[$carrier->value], $this->missing[$carrier->value], $this->handlers[$carrier->value]);

        if ($config === null) {
            $this->missing[$carrier->value] = $configName;
            return;
        }
        $this->factories[$carrier->value] = static fn (?ClientInterface $httpClient): ShipmentHandlerInterface => $factory($config, $httpClient);
    }

    public function isConfigured(Carrier $carrier): bool
    {
        return isset($this->factories[$carrier->value]);
    }

    /**
     * @throws ConfigException dopravce nemá config (nebo není registrovaný)
     */
    public function get(Carrier $carrier): ShipmentHandlerInterface
    {
        if (isset($this->handlers[$carrier->value])) {
            return $this->handlers[$carrier->value];
        }

        $factory = $this->factories[$carrier->value] ?? null;
        if ($factory === null) {
            throw new ConfigException(isset($this->missing[$carrier->value])
                ? $this->missing[$carrier->value] . ' not configured'
                : 'Carrier ' . $carrier->name . ' is not registered');
        }

        $handler = $factory($this->httpClient);
        if ($handler->getCarrier() !== $carrier) {
            throw new ConfigException(sprintf('Handler %s is registered for %s, but serves %s', $handler::class, $carrier->name, $handler->getCarrier()->name));
        }
        return $this->handlers[$carrier->value] = $handler;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     * @throws ConfigException
     */
    public function getAs(Carrier $carrier, string $class): object
    {
        $handler = $this->get($carrier);
        if (!$handler instanceof $class) {
            throw new ConfigException(sprintf('Handler for %s is %s, expected %s', $carrier->name, $handler::class, $class));
        }
        return $handler;
    }

    /**
     * Guzzle klient pro handlery vytvořené od teď – už vytvořené se zahodí.
     */
    public function setHttpClient(?ClientInterface $httpClient): void
    {
        $this->httpClient = $httpClient;
        $this->handlers = [];
    }
}
