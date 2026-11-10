<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Toptrans\Handler;

use Ages\ShippingGateway\Common\AddressFormatter;
use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\Data;
use Ages\ShippingGateway\Common\Label\PdfMerger;
use Ages\ShippingGateway\Common\Label\PdfPageFitter;
use Ages\ShippingGateway\Common\PickupAddress;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\ParcelType;
use Ages\ShippingGateway\Common\Shipment\RecipientType;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\Common\ShipmentHandlerInterface;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\Toptrans\ToptransApi;
use Ages\ShippingGateway\Toptrans\Values\Currency;
use Ages\ShippingGateway\Toptrans\Values\LabelFormat;
use Ages\ShippingGateway\Toptrans\Values\Method;
use Ages\ShippingGateway\Toptrans\Values\PackType;
use Ages\ShippingGateway\Toptrans\Values\Term;

/**
 * Zásilka = jedna objednávka přepravy (jeden přepravní list), každý Parcel je jeden kus s vlastním obalem.
 * Postup: order/save → order/send (závazné objednání včetně nakládky) → štítky → číslo přepravního listu z archivu.
 */
class ToptransShipmentHandler extends ToptransApi implements ShipmentHandlerInterface
{
    /** Toptrans doručuje po ČR a SR; země se v API neuvádí, určí ji PSČ a obec. */
    public const array SupportedCountries = ['CZ', 'SK'];

    private const int NameLimit = 60;
    private const int LabelLimit = 40;
    private const int PackDescriptionLimit = 100;
    private const int NoteLimit = 2000;
    private const int VariableSymbolLimit = 20;
    private const int MaxPieces = 250;
    private const float MinWeight = 0.01;
    private const float MinVolume = 0.01;
    private const int CubicCentimetresPerCubicMetre = 1_000_000;
    private const string LabelFile = 'stitky';
    /** Dokumenty z order/send, které se s attachDocuments přidají za štítky. */
    private const array DocumentFiles = ['zasilatelsky-prikaz', 'soupiska'];

    public function getCarrier(): Carrier
    {
        return Carrier::Toptrans;
    }

    /**
     * Vrací jeden ShipmentLabel: číslo přepravního listu (orderNumber, podle něj jde tracking i veřejné sledování)
     * a PDF se štítky všech kusů.
     */
    public function createShipment(ShipmentRequest $request): array
    {
        $this->validate($request);

        $orderId = $this->saveOrder($this->buildOrder($request));

        try {
            $sent = $this->sendOrders([$orderId]);
            if (Data::float($sent, 'numOk') < 1 || Data::float($sent, 'numErrors') > 0) {
                throw new ShippingException('Toptrans: order ' . $orderId . ' was not sent: '
                    . (Data::nonEmptyString($sent, 'message') ?? 'numOk=' . Data::string($sent, 'numOk')));
            }
        } catch (\Throwable $e) {
            $this->deleteUnsent($orderId);
            throw $e;
        }

        $batchId = (int) Data::float($sent, 'orderListObj');
        $context = 'Toptrans: order ' . $orderId . ' was sent in batch ' . $batchId;
        if ($batchId <= 0) {
            throw new ShippingException($context . ', but the response has no batch id (orderListObj)');
        }

        try {
            $labelPdf = $this->labelPdf($sent, $batchId);
        } catch (ShippingException $e) {
            throw new ShippingException($context . ', but label download failed: ' . $e->getMessage(), 0, $e);
        }

        $orderNumber = $this->findOrderNumber($batchId, $orderId)
            ?? throw new ShippingException($context . ', but its order number was not found in order/archive');

        return [new ShipmentLabel(Carrier::Toptrans, $orderNumber, $labelPdf)];
    }

    /**
     * Číslo přepravního listu objednávky z archivu odeslaných dávek (včera–dnes kvůli odeslání kolem půlnoci).
     */
    public function findOrderNumber(int $batchId, int $orderId): ?string
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague'));
        foreach ($this->archive($today->modify('-1 day'), $today) as $batch) {
            if ((int) Data::float($batch, 'id') !== $batchId) {
                continue;
            }

            $orderIds = array_map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '', array_values(Data::array($batch, 'order_ids')));
            $orderNumbers = array_values(Data::array($batch, 'order_numbers'));
            $index = array_search((string) $orderId, $orderIds, true);
            if ($index === false && count($orderNumbers) === 1) {
                $index = 0;
            }
            $number = $index !== false ? ($orderNumbers[$index] ?? null) : null;

            return is_scalar($number) && (string) $number !== '' ? (string) $number : null;
        }

        return null;
    }

    /**
     * @return array<string, mixed> objednávka pro order/save
     */
    protected function buildOrder(ShipmentRequest $request): array
    {
        $pickupBranch = $request->parcelShopCode !== null ? trim($request->parcelShopCode) : '';
        $term = $pickupBranch !== '' ? Term::PersonalPickup : $this->config->term;

        $order = [
            'term_id' => $term->value,
            'label' => mb_substr($request->reference, 0, self::LabelLimit),
            'payer_select' => 1,
            'discharge' => $this->buildDischarge($request),
            'aviso_sms' => (int) $this->config->smsAdvice,
            'discharge_aviso' => (int) $this->config->phoneAdvice,
            'kg' => max(self::MinWeight, round(array_sum(array_map(static fn (Parcel $p): float => $p->weight, $request->parcels)), 2)),
            'packs' => array_map($this->buildPack(...), array_values($request->parcels)),
        ];

        if ($pickupBranch !== '') {
            $order['discharge_personal_branch_id'] = $pickupBranch;
        }

        if ($this->config->pickupAddress !== null) {
            $order['loading_select'] = 2;
            $order['loading'] = self::partner($this->config->pickupAddress);
        } else {
            $order['loading_select'] = 1;
        }

        if (array_any($request->parcels, static fn (Parcel $p): bool => $p->type === ParcelType::PackageOversize)) {
            $order['oversize'] = 1;
        }
        $eurPallets = count(array_filter($request->parcels, static fn (Parcel $p): bool => $p->type === ParcelType::PalletEur));
        if ($eurPallets > 0) {
            $order['num_epals'] = $eurPallets;
        }

        $volume = self::volume($request->parcels);
        if ($volume !== null) {
            $order['m3'] = $volume;
        }

        if ($request->note !== null) {
            $order['note_discharge'] = mb_substr($request->note, 0, self::NoteLimit);
        }

        if ($request->cod !== null && $request->cod->amount > 0) {
            $order += $this->buildCashOnDelivery($request);
        }

        if ($this->config->declareValue && $request->value->amount > 0) {
            $order['order_value'] = round($request->value->amount, 1);
            $order['order_value_currency_id'] = Currency::fromCode($request->value->currency)->value;
        }

        return $order;
    }

    private function validate(ShipmentRequest $request): void
    {
        if ($request->parcels === []) {
            throw new \InvalidArgumentException('Toptrans: shipment has no parcels');
        }
        if (count($request->parcels) > self::MaxPieces) {
            throw new \InvalidArgumentException('Toptrans: at most ' . self::MaxPieces . ' parcels per order');
        }
        foreach ($request->parcels as $parcel) {
            if (!in_array($parcel->type, Carrier::Toptrans->supportedParcelTypes(), true)) {
                throw new \InvalidArgumentException('Toptrans does not support parcel type ' . $parcel->type->name);
            }
        }

        $country = strtoupper(trim($request->recipient->country));
        if (!in_array($country, self::SupportedCountries, true)) {
            throw new \InvalidArgumentException('Toptrans delivers only to ' . implode(', ', self::SupportedCountries) . ', got ' . $country);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDischarge(ShipmentRequest $request): array
    {
        $r = $request->recipient;
        $person = $r->fullName();
        $company = $r->company !== null ? trim($r->company) : '';
        [$street, $houseNumber] = AddressFormatter::splitStreet($r->street, $r->houseNumber);

        return array_filter([
            'name' => mb_substr($company !== '' ? $company : $person, 0, self::NameLimit),
            'first_name' => $r->type === RecipientType::Company && $company === '' ? null : mb_substr(trim($r->firstName), 0, self::NameLimit),
            'last_name' => mb_substr(trim($r->lastName), 0, self::NameLimit),
            'phone' => AddressFormatter::phone($r->phone, $r->country),
            'email' => trim($r->email),
            'address' => self::address($street, $houseNumber, $r->zip, $r->city),
        ], static fn (string|array|null $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function partner(PickupAddress $p): array
    {
        [$street, $houseNumber] = AddressFormatter::splitStreet($p->street, $p->houseNumber);
        $name = $p->company !== null && trim($p->company) !== '' ? trim($p->company) : $p->name;

        return array_filter([
            'name' => mb_substr($name, 0, self::NameLimit),
            'phone' => AddressFormatter::phone($p->phone, $p->country),
            'email' => trim($p->email),
            'address' => self::address($street, $houseNumber, $p->zip, $p->city),
        ], static fn (string|array $value): bool => $value !== '' && $value !== []);
    }

    /**
     * @return array<string, string>
     */
    private static function address(string $street, string $houseNumber, string $zip, string $city): array
    {
        return array_filter([
            'city' => trim($city),
            'street' => $street,
            'house_num' => $houseNumber !== $street ? $houseNumber : '',
            'zip' => str_replace(' ', '', trim($zip)),
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPack(Parcel $parcel): array
    {
        $description = $this->config->packDescription ?? $parcel->reference;
        $pack = [
            'pack_id' => PackType::forParcelType($parcel->type, $this->config->packagePack)->value,
            'quantity' => 1,
        ];
        if ($description !== null && trim($description) !== '') {
            $pack['description'] = mb_substr(trim($description), 0, self::PackDescriptionLimit);
        }
        if ($parcel->dimensions !== null) {
            // rozměry v cm
            $pack['dimensions_d'] = round($parcel->dimensions->length, 1);
            $pack['dimensions_s'] = round($parcel->dimensions->width, 1);
            $pack['dimensions_v'] = round($parcel->dimensions->height, 1);
        }

        return $pack;
    }

    /**
     * Kubatura v m³, jen když mají rozměry všechny kusy.
     *
     * @param Parcel[] $parcels
     */
    private static function volume(array $parcels): ?float
    {
        $volume = 0.0;
        foreach ($parcels as $parcel) {
            $d = $parcel->dimensions;
            if ($d === null) {
                return null;
            }
            $volume += $d->length * $d->width * $d->height / self::CubicCentimetresPerCubicMetre;
        }

        return max(self::MinVolume, round($volume, 2));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCashOnDelivery(ShipmentRequest $request): array
    {
        $cod = $request->cod;
        if ($cod === null) {
            return [];
        }

        $data = [
            'cash_on_delivery_type' => 1,
            'cash_on_delivery_price' => round($cod->amount, 2),
            'cash_on_delivery_price_cur_id' => Currency::fromCode($cod->currency)->value,
        ];

        $account = $this->config->codBankAccountParts();
        if ($account !== null) {
            if ($account['prefix'] !== '') {
                $data['cash_on_delivery_account1'] = $account['prefix'];
            }
            $data['cash_on_delivery_account2'] = $account['number'];
            $data['cash_on_delivery_bank'] = $account['bank'];
        }
        if ($this->config->codIban !== null && trim($this->config->codIban) !== '') {
            $data['cash_on_delivery_iban'] = str_replace(' ', '', strtoupper(trim($this->config->codIban)));
        }
        if ($this->config->codSwift !== null && trim($this->config->codSwift) !== '') {
            $data['cash_on_delivery_swift'] = strtoupper(trim($this->config->codSwift));
        }

        $vs = substr((string) preg_replace('~\D+~', '', $cod->variableSymbol), 0, self::VariableSymbolLimit);
        if ($vs !== '') {
            $data['var_symbol'] = $vs;
        }

        return $data;
    }

    /**
     * @param array<mixed> $sent odpověď order/send
     */
    private function labelPdf(array $sent, int $batchId): string
    {
        $labels = match ($this->config->labelFormat) {
            LabelFormat::A4 => self::sentFile($sent, self::LabelFile) ?? $this->printBatch(Method::PrintLabels, $batchId),
            LabelFormat::Zebra => $this->printBatch(Method::PrintZebraLabels, $batchId),
            LabelFormat::Thermal100x150 => PdfPageFitter::fit([$this->printBatch(Method::PrintZebraLabels, $batchId)]),
        };
        // zasilatelský příkaz a soupiska jsou A4 – na termoštítek se nehodí, jdou přes printOrderDocuments()
        if (!$this->config->attachDocuments || $this->config->labelFormat !== LabelFormat::A4) {
            return $labels;
        }

        $pdfs = [$labels];
        foreach (self::DocumentFiles as $name) {
            $document = self::sentFile($sent, $name);
            if ($document !== null) {
                $pdfs[] = $document;
            }
        }

        return PdfMerger::merge($pdfs);
    }

    /**
     * PDF z files odpovědi order/send – klíč (stitky, soupiska…) nebo název souboru s tímto začátkem, data v base64.
     *
     * @param array<mixed> $sent
     */
    private static function sentFile(array $sent, string $name): ?string
    {
        foreach (Data::array($sent, 'files') as $key => $file) {
            $filename = Data::string($file, 'filename');
            if ($key !== $name && !str_starts_with($filename, $name)) {
                continue;
            }

            $pdf = base64_decode(Data::string($file, 'data'), true);
            if ($pdf === false || !str_starts_with($pdf, '%PDF')) {
                throw new ShippingException('Toptrans: file ' . $name . ' from order/send is not a base64 PDF');
            }
            return $pdf;
        }

        return null;
    }

    /**
     * Úklid po neúspěšném odeslání – neodeslaná objednávka by jinak zůstala v ZP. Odeslanou API smazat nedovolí.
     */
    private function deleteUnsent(int $orderId): void
    {
        try {
            $this->deleteOrder($orderId);
        } catch (\Throwable) {
            // původní chyba je důležitější
        }
    }
}
