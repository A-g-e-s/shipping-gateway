<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Toptrans\Config;

use Ages\ShippingGateway\Common\ConfigException;
use Ages\ShippingGateway\Common\PickupAddress;
use Ages\ShippingGateway\Toptrans\Values\LabelFormat;
use Ages\ShippingGateway\Toptrans\Values\PackType;
use Ages\ShippingGateway\Toptrans\Values\Term;

readonly class ToptransConfig
{
    /** Tvar „předčíslí-číslo/kód banky“, předčíslí nepovinné. */
    private const string BankAccountPattern = '~^\s*(?:(\d{1,6})-)?(\d{2,10})\s*/\s*(\d{4})\s*$~';

    /**
     * @param string $username přihlašovací jméno do zákaznické aplikace ZP2 (Basic auth)
     * @param string $password heslo do ZP2
     * @param PickupAddress|null $pickupAddress nakládka jinde než na adrese objednavatele (loading_select=2);
     *                                          null = adresa objednavatele z registrace u Toptransu
     * @param string $url API je jen produkční (testovací prostředí Toptrans nezveřejňuje)
     * @param Term $term základní přepravní služba (Standard, Top Privat…); výdejní místo → vždy osobní odběr
     * @param PackType $packagePack obal pro balíky (Package / PackageOversize), palety mají vlastní obal
     * @param string|null $packDescription popis obsahu kusu (string 100), null = reference balíku nebo nic
     * @param bool $smsAdvice SMS avizace vykládky příjemci (aviso_sms)
     * @param bool $phoneAdvice telefonická avizace vykládky řidičem (discharge_aviso)
     * @param bool $declareValue posílat hodnotu zásilky (order_value) – podle smlouvy může jít o zpoplatněné připojištění
     * @param string|null $codBankAccount účet pro dobírky ve tvaru „19-2000145399/0800“; null = účet z registrace
     * @param string|null $codIban IBAN pro dobírky (hlavně EUR na Slovensko)
     * @param bool $attachDocuments k PDF se štítky přidat zasilatelský příkaz a soupisku z order/send
     * @param string $trackingUrl veřejná stránka sledování, doplní se číslo přepravního listu
     */
    public function __construct(
        public string         $username,
        public string         $password,
        public ?PickupAddress $pickupAddress = null,
        public string         $url = 'https://zp.toptrans.cz/api',
        public Term           $term = Term::Standard,
        public PackType       $packagePack = PackType::Parcel,
        public ?string        $packDescription = null,
        public LabelFormat    $labelFormat = LabelFormat::A4,
        public bool           $smsAdvice = true,
        public bool           $phoneAdvice = false,
        public bool           $declareValue = false,
        public ?string        $codBankAccount = null,
        public ?string        $codIban = null,
        public ?string        $codSwift = null,
        public bool           $attachDocuments = false,
        public string         $trackingUrl = 'https://www.toptrans.cz/preprava/cs/sledovani-zasilky?order_id=',
    ) {
        if ($codBankAccount !== null && preg_match(self::BankAccountPattern, $codBankAccount) !== 1) {
            throw new ConfigException('ToptransConfig: codBankAccount must look like "19-2000145399/0800", got ' . $codBankAccount);
        }
    }

    /**
     * @return array{prefix: string, number: string, bank: string}|null
     */
    public function codBankAccountParts(): ?array
    {
        if ($this->codBankAccount === null || preg_match(self::BankAccountPattern, $this->codBankAccount, $m) !== 1) {
            return null;
        }

        return ['prefix' => $m[1], 'number' => $m[2], 'bank' => $m[3]];
    }
}
