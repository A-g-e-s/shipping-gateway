<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Http;

use Ages\ShippingGateway\Common\ShippingException;

/**
 * Dopravce odpověděl HTTP kódem ≥ 400. Kód je v getCode(), celé tělo odpovědi v getResponseBody().
 */
class HttpStatusException extends ShippingException
{
    public function __construct(
        string $message,
        int $statusCode,
        private readonly string $responseBody = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->getCode();
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }
}
