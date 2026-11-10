<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Toptrans;

use Ages\ShippingGateway\Common\CarrierInterface;
use Ages\ShippingGateway\Common\Data;
use Ages\ShippingGateway\Common\Http\HttpClient;
use Ages\ShippingGateway\Common\Http\HttpStatusException;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\Toptrans\Config\ToptransConfig;
use Ages\ShippingGateway\Toptrans\Tracking\ToptransTrackingMapper;
use Ages\ShippingGateway\Toptrans\Values\Method;
use GuzzleHttp\ClientInterface;

/**
 * Toptrans – API zákaznické aplikace ZP2: https://zp.toptrans.cz/docs/api.html, entity https://zp.toptrans.cz/docs/data.html
 *
 * Všechny metody jsou POST s JSON tělem a odpovídají obálkou {status: ok|error, data, errors}
 * (chyba může přijít i s HTTP 200). Tiskové metody vrací přímo PDF.
 */
class ToptransApi implements CarrierInterface
{
    private const int TimeoutSeconds = 60;
    /** order/archive vrátí nejvýš 100 dávek. */
    public const int ArchiveLimit = 100;
    /** order/search přijme nejvýš 50 hodnot v jednom parametru. */
    public const int SearchLimit = 50;
    /** Pozice štítku na archu A4 2 × 7 (0–13). */
    private const int LastLabelPosition = 13;
    private const string StatusOk = 'ok';

    private readonly HttpClient $http;

    public function __construct(protected readonly ToptransConfig $config, ?ClientInterface $httpClient = null)
    {
        $this->http = new HttpClient('Toptrans', $this->config->url, [], [
            'timeout' => self::TimeoutSeconds,
            'auth' => [$this->config->username, $this->config->password],
        ], $httpClient, self::formatError(...));
    }

    /**
     * Uloží neodeslanou objednávku (přísný režim – při jakékoli chybě se neuloží).
     *
     * @param array<string, mixed> $order objednávka dle dokumentace datových entit
     * @return int ID objednávky v ZP (není to číslo přepravního listu)
     */
    public function saveOrder(array $order): int
    {
        $data = $this->call(Method::OrderSave, $order + ['strict' => 1]);
        $id = Data::get($data, 'id') ?? $data;
        if (!is_numeric($id)) {
            throw new ShippingException('Toptrans: order/save did not return order id');
        }

        return (int) $id;
    }

    /**
     * Odešle objednávky do systému Toptransu – tím je přeprava závazně objednaná (svoz z nakládky).
     * Bez ID by API odeslalo všechny neodeslané objednávky účtu, proto jsou ID povinná.
     *
     * @param int[] $orderIds
     * @param int $position pozice prvního štítku na archu A4 (0–13, po sloupcích)
     * @return array<mixed> numOk, numErrors, orderListObj (ID dávky), files (base64 PDF) …
     */
    public function sendOrders(array $orderIds, int $position = 0): array
    {
        if ($orderIds === []) {
            throw new \InvalidArgumentException('Toptrans: order/send needs order ids (empty list would send all unsent orders)');
        }
        if ($position < 0 || $position > self::LastLabelPosition) {
            throw new \InvalidArgumentException('Toptrans: label position must be 0–' . self::LastLabelPosition);
        }

        return Data::array($this->call(Method::OrderSend, [
            'ids' => array_values($orderIds),
            'options' => ['position' => $position],
        ]), []);
    }

    /**
     * Smaže neodeslanou objednávku.
     */
    public function deleteOrder(int $orderId): void
    {
        $this->call(Method::OrderDelete, ['id' => $orderId]);
    }

    /**
     * @return array<mixed> kompletní data neodeslaných objednávek
     */
    public function listUnsentOrders(): array
    {
        return Data::array($this->call(Method::OrderList), []);
    }

    /**
     * Výpočet ceny přepravy (loading_address_city/zip, discharge_address_city/zip, kg nebo m3, num_epals, oversize…).
     *
     * @param array<string, mixed> $input
     * @return array<mixed> PRICE, CURRENCY_ID, KG, M3, DISCOUNT …
     */
    public function price(array $input): array
    {
        return Data::array($this->call(Method::OrderPrice, $input), []);
    }

    /**
     * Archiv odeslaných dávek. Jen dateFrom = jen ten den, oba = období včetně krajních dnů.
     *
     * @return list<array<mixed>> dávky: id, timestamp, order_ids, order_numbers, item_numbers, labels …
     */
    public function archive(?\DateTimeInterface $dateFrom = null, ?\DateTimeInterface $dateTo = null, int $limit = self::ArchiveLimit): array
    {
        $input = ['limit' => max(1, min(self::ArchiveLimit, $limit))];
        if ($dateFrom !== null) {
            $input['dateFrom'] = $dateFrom->format('Y-m-d');
        }
        if ($dateTo !== null) {
            $input['dateTo'] = $dateTo->format('Y-m-d');
        }

        return Data::arrays($this->call(Method::OrderArchive, $input), []);
    }

    /**
     * Dokument odeslané dávky jako PDF – PrintList (zasilatelský příkaz), PrintRoll (soupiska),
     * PrintLabels (A4, s pozicí), PrintZebraLabels (70 × 90 mm), PrintAdr.
     */
    public function printBatch(Method $document, int $batchId, ?int $position = null): string
    {
        $allowed = [Method::PrintList, Method::PrintRoll, Method::PrintLabels, Method::PrintZebraLabels, Method::PrintAdr];
        if (!in_array($document, $allowed, true)) {
            throw new \InvalidArgumentException('Toptrans: ' . $document->value . ' is not a batch document');
        }

        $input = ['id' => $batchId];
        if ($position !== null && $document === Method::PrintLabels) {
            $input['position'] = $position;
        }

        return $this->callPdf($document, $input);
    }

    /**
     * Potvrzení o doručení (POD) podle čísla přepravního listu.
     */
    public function printProofOfDelivery(string $orderNumber): string
    {
        return $this->callPdf(Method::PrintPod, ['orderNumber' => $orderNumber]);
    }

    /**
     * Stav zásilek. Každý seznam max. 50 hodnot.
     *
     * @param string[] $orderNumbers čísla přepravních listů
     * @param string[] $itemNumbers čísla kusů (štítků)
     * @param string[] $labels označení (naše reference)
     * @return list<array<mixed>> orderNumber, loadingDate, itemFrom, itemTo, label, deliveryBranch, deliveryDriver, deliveryDate, status
     */
    public function search(array $orderNumbers = [], array $itemNumbers = [], array $labels = []): array
    {
        $input = array_filter([
            'orderNumber' => self::searchList($orderNumbers),
            'itemNumber' => self::searchList($itemNumbers),
            'label' => self::searchList($labels),
        ], static fn (string $value): bool => $value !== '');
        if ($input === []) {
            throw new \InvalidArgumentException('Toptrans: order/search needs order number, item number or label');
        }

        return Data::arrays($this->call(Method::OrderSearch, $input), []);
    }

    /**
     * Veřejný číselník (pack, term, currency, comfort, comfort-loading, freight-cash, delivery-notes, returnable-pack).
     *
     * @return array<mixed> ID → název
     */
    public function register(string $name): array
    {
        return Data::array($this->call(Method::Register, null, rawurlencode($name)), []);
    }

    /**
     * @param string $consignmentId číslo přepravního listu (orderNumber)
     */
    public function getParcelTracking(string $consignmentId, ?\DateTimeInterface $createdAt = null): ?ParcelTrackingInterface
    {
        $consignmentId = trim($consignmentId);
        foreach ($this->search([$consignmentId]) as $record) {
            if (Data::string($record, 'orderNumber') === $consignmentId) {
                return ToptransTrackingMapper::tracking($consignmentId, $record);
            }
        }

        return null;
    }

    public function getTrackingUrl(string $consignmentId): string
    {
        return $this->config->trackingUrl . rawurlencode($consignmentId);
    }

    /**
     * @param array<string, mixed>|null $input
     * @return mixed atribut data z obálky odpovědi
     * @throws ShippingException status=error nebo neplatná odpověď
     * @throws HttpStatusException HTTP ≥ 400 (např. 401 – špatné přihlašovací údaje)
     */
    protected function call(Method $method, ?array $input = null, string $suffix = ''): mixed
    {
        $path = $this->path($method, $suffix);
        $envelope = $this->http->decodeJson($this->send($path, $input), 'POST', $path);
        self::assertOk($envelope, $method);

        return Data::get($envelope, 'data');
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function callPdf(Method $method, array $input): string
    {
        $path = $this->path($method);
        $body = $this->send($path, $input, 'application/pdf');
        if (str_starts_with($body, '%PDF')) {
            return $body;
        }

        $envelope = json_decode($body, true);
        if (is_array($envelope)) {
            self::assertOk($envelope, $method);
        }
        throw new ShippingException('Toptrans ' . $method->value . ': response is not a PDF: ' . HttpClient::sample($body));
    }

    /**
     * @param array<mixed> $envelope
     */
    private static function assertOk(array $envelope, Method $method): void
    {
        if (Data::string($envelope, 'status') === self::StatusOk) {
            return;
        }

        $errors = self::errorMessages($envelope);
        throw new ShippingException('Toptrans ' . $method->value . ': ' . ($errors !== [] ? implode('; ', $errors) : 'request failed'));
    }

    /**
     * @param array<string, mixed>|null $input
     */
    private function send(string $path, ?array $input, string $accept = 'application/json'): string
    {
        return $this->http->send('POST', $path, [
            'headers' => ['Accept' => $accept, 'Content-Type' => 'application/json'],
            // metody bez vstupu čekají prázdné tělo
            'body' => $input !== null ? json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : '',
        ]);
    }

    private function path(Method $method, string $suffix = ''): string
    {
        return $suffix !== '' ? rtrim($method->path(), '/') . '/' . $suffix : $method->path();
    }

    /**
     * @param string[] $values
     */
    private static function searchList(array $values): string
    {
        $values = array_values(array_filter(array_map('trim', $values), static fn (string $v): bool => $v !== ''));
        if (count($values) > self::SearchLimit) {
            throw new \InvalidArgumentException('Toptrans: order/search accepts at most ' . self::SearchLimit . ' values');
        }

        return implode(',', $values);
    }

    /**
     * Chybové hlášky – errors chodí jako seznam i jako objekt {"0": "…"}.
     *
     * @param array<mixed> $envelope
     * @return string[]
     */
    private static function errorMessages(array $envelope): array
    {
        $messages = [];
        foreach (Data::array($envelope, 'errors') as $key => $error) {
            if (is_scalar($error)) {
                $messages[] = (string) $error;
            } elseif (is_array($error)) {
                $text = Data::nonEmptyString($error, 'message') ?? (string) json_encode($error, JSON_UNESCAPED_UNICODE);
                $messages[] = (is_string($key) ? $key . ': ' : '') . $text;
            }
        }

        return array_values(array_filter($messages, static fn (string $m): bool => trim($m) !== ''));
    }

    private static function formatError(string $body): string
    {
        $envelope = json_decode($body, true);
        if (is_array($envelope)) {
            $errors = self::errorMessages($envelope);
            if ($errors !== []) {
                return implode('; ', $errors);
            }
        }

        return HttpClient::sample($body);
    }
}
