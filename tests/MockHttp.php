<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle klient nad MockHandler, který si pamatuje odeslané požadavky a jejich volby.
 */
final class MockHttp
{
    public readonly MockHandler $handler;
    public readonly Client $client;

    /** @var \ArrayObject<int, mixed> */
    private \ArrayObject $history;

    /**
     * @param list<mixed> $queue odpovědi / výjimky
     */
    public function __construct(array $queue = [])
    {
        // ArrayObject je sdílený objekt – middleware do něj zapisuje i přes referenci na lokální proměnnou
        $this->history = $history = new \ArrayObject();
        $this->handler = new MockHandler($queue);
        $stack = HandlerStack::create($this->handler);
        $stack->push(Middleware::history($history));
        $this->client = new Client(['handler' => $stack]);
    }

    public function request(int $index): RequestInterface
    {
        $request = $this->entry($index)['request'] ?? null;
        if (!$request instanceof RequestInterface) {
            throw new \LogicException('No request #' . $index);
        }
        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(int $index): array
    {
        $options = $this->entry($index)['options'] ?? null;
        if (!is_array($options)) {
            throw new \LogicException('No request #' . $index);
        }
        /** @var array<string, mixed> $options Guzzle volby požadavku */
        return $options;
    }

    /**
     * @return array<mixed>
     */
    private function entry(int $index): array
    {
        $entry = $this->history[$index] ?? null;
        return is_array($entry) ? $entry : [];
    }

    public function count(): int
    {
        return count($this->history);
    }
}
