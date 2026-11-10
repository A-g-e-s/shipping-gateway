<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\OneByAllegro\Widget;

/**
 * Adresa mapového widgetu One by Allegro pro výběr výdejního místa (One Point / One Box) v košíku.
 * Dokumentace: https://onedelivery.cz/integrace/widget – widget nepotřebuje API klíč.
 *
 * Vybrané místo widget vrací jako `selectedID` (= pointId pro vytvoření zásilky, ShipmentRequest::$parcelShopCode)
 * a `selectedName`. V režimu iframe přes postMessage – v JS ověř `event.origin === OneWidget::Origin`.
 */
final class OneWidget
{
    public const string Origin = 'https://widget.wedo.cz';
    public const string BaseUrl = self::Origin . '/v5/widget-content';

    /** Typy boxů, které jde vypnout (disableBoxType). */
    public const array BoxTypes = ['oxbox', 'alzabox', 'onebox'];
    /** Typy výdejních míst, které jde vypnout (disablePointType). */
    public const array PointTypes = ['sazka'];
    public const array Countries = ['cz', 'sk'];

    private function __construct()
    {
    }

    /**
     * Widget vložený do objednávky v elementu <iframe>, výsledek přijde přes window.postMessage.
     *
     * @param string[] $disableBoxTypes  hodnoty z BoxTypes
     * @param string[] $disablePointTypes hodnoty z PointTypes
     * @param string|null $country cz / sk – omezí mapu na zemi (např. podle země doručení)
     * @param bool $cancelMessage zavírací tlačítko pošle zprávu {"widgetClosed": true}
     */
    public static function iframeUrl(
        ?WidgetPointType $fixedType = null,
        ?string $country = null,
        array $disableBoxTypes = [],
        array $disablePointTypes = [],
        bool $cancelMessage = false,
    ): string {
        return self::build(['selectMode' => 'postMessage'], $fixedType, $country, $disableBoxTypes, $disablePointTypes, $cancelMessage);
    }

    /**
     * Widget přes přesměrování – po výběru vrátí zákazníka na $selectTarget, při chybě na $cancelUrl.
     * Widget ID místa jen přilepí na konec $selectTarget, adresa proto musí končit parametrem, např. `…?pointId=`.
     *
     * @param string[] $disableBoxTypes  hodnoty z BoxTypes
     * @param string[] $disablePointTypes hodnoty z PointTypes
     */
    public static function redirectUrl(
        string $selectTarget,
        string $cancelUrl,
        ?WidgetPointType $fixedType = null,
        ?string $country = null,
        array $disableBoxTypes = [],
        array $disablePointTypes = [],
        bool $cancelMessage = false,
    ): string {
        foreach (['selectTarget' => $selectTarget, 'cancelUrl' => $cancelUrl] as $name => $url) {
            if (preg_match('~^https?://~i', $url) !== 1) {
                throw new \InvalidArgumentException('One widget: ' . $name . ' must be an absolute http(s) URL');
            }
        }

        return self::build(
            ['selectMode' => 'redirect', 'selectTarget' => $selectTarget, 'cancelUrl' => $cancelUrl],
            $fixedType,
            $country,
            $disableBoxTypes,
            $disablePointTypes,
            $cancelMessage,
        );
    }

    /**
     * @param array<string, string> $params
     * @param string[] $disableBoxTypes
     * @param string[] $disablePointTypes
     */
    private static function build(
        array $params,
        ?WidgetPointType $fixedType,
        ?string $country,
        array $disableBoxTypes,
        array $disablePointTypes,
        bool $cancelMessage,
    ): string {
        if ($cancelMessage) {
            $params['cancelMessage'] = 'true';
        }
        if ($fixedType !== null) {
            $params['fixedType'] = $fixedType->value;
        }
        if ($disableBoxTypes !== []) {
            $params['disableBoxType'] = self::list($disableBoxTypes, self::BoxTypes, 'disableBoxType');
        }
        if ($disablePointTypes !== []) {
            $params['disablePointType'] = self::list($disablePointTypes, self::PointTypes, 'disablePointType');
        }
        if ($country !== null && trim($country) !== '') {
            $country = strtolower(trim($country));
            if (!in_array($country, self::Countries, true)) {
                throw new \InvalidArgumentException('One widget: unsupported country ' . $country . ' (cz, sk)');
            }
            $params['country'] = $country;
        }

        return self::BaseUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param string[] $values
     * @param string[] $allowed
     */
    private static function list(array $values, array $allowed, string $param): string
    {
        $values = array_values(array_unique(array_map(static fn (string $value): string => strtolower(trim($value)), $values)));
        $unknown = array_diff($values, $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('One widget: unsupported ' . $param . ' ' . implode(', ', $unknown));
        }
        return implode(',', $values);
    }
}
