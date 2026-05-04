<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\GebruderWeiss\Label;

use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\GebruderWeiss\Config\GebruderWeissConfig;
use Mpdf\Mpdf;

class GebruderWeissLabelGenerator
{
    public function __construct(private readonly GebruderWeissConfig $config) {}

    /**
     * @param string[] $ssccCodes Indexed by parcel index (0-based)
     * @return string[] PDF bytes per parcel (same index)
     */
    public function generateLabels(ShipmentRequest $request, array $ssccCodes): array
    {
        $labels = [];
        $total = count($ssccCodes);
        foreach ($ssccCodes as $index => $sscc) {
            $labels[$index] = $this->generateLabel($request, $request->parcels[$index], $sscc, $index + 1, $total);
        }
        return $labels;
    }

    private function generateLabel(
        ShipmentRequest $request,
        Parcel $parcel,
        string $sscc,
        int $parcelNumber,
        int $totalParcels,
    ): string {
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => [100, 150],
            'margin_left' => 3,
            'margin_right' => 3,
            'margin_top' => 3,
            'margin_bottom' => 3,
            'margin_header' => 0,
            'margin_footer' => 0,
        ]);

        $mpdf->WriteHTML($this->buildHtml($request, $parcel, $sscc, $parcelNumber, $totalParcels));

        return $mpdf->Output('', 'S');
    }

    private function buildHtml(
        ShipmentRequest $request,
        Parcel $parcel,
        string $sscc,
        int $parcelNumber,
        int $totalParcels,
    ): string {
        $r = $request->recipient;
        $cfg = $this->config;
        $pickup = $cfg->pickupAddress;

        $recipientName = $r->company ?? $r->fullName();
        $recipientName2 = $r->company !== null ? $r->fullName() : null;
        $recipientStreet = $r->streetWithNumber();
        $recipientZipCountry = $r->country . ' ' . $r->zip;
        $recipientCity = $r->city;

        $senderName = $pickup->name;
        $senderStreet = $pickup->getStreetWithNumber();
        $senderZipCity = $pickup->country . ' ' . $pickup->zip . ' ' . $pickup->city;

        $date = (new \DateTimeImmutable())->format('d.m.Y');
        $weight = number_format($parcel->weight, 2, ',', '.') . ' KG';

        $logoHtml = '';
        if ($cfg->logoPath !== null && file_exists($cfg->logoPath)) {
            $path = $this->esc($cfg->logoPath);
            $logoHtml = "<img src=\"{$path}\" style=\"max-width:24mm; max-height:24mm;\" />";
        }

        $name2Html = $recipientName2 !== null
            ? '<div class="name-lg">' . $this->esc($recipientName2) . '</div>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Arial, sans-serif; font-size: 7pt; }
table { width: 100%; border-collapse: collapse; }
td { padding: 1.5mm 1.5mm; vertical-align: top; }
.bb { border-bottom: 0.5pt solid #000; }
.br { border-right: 0.5pt solid #000; }
.lbl { font-size: 6pt; color: #444; }
.bold { font-weight: bold; }
.name-lg { font-size: 12pt; font-weight: bold; line-height: 1.15; }
.street-md { font-size: 9pt; font-weight: bold; line-height: 1.2; }
.zip-lg { font-size: 13pt; font-weight: bold; line-height: 1.2; }
.city-lg { font-size: 12pt; font-weight: bold; line-height: 1.15; }
.center { text-align: center; }
</style>
</head>
<body>

<table class="bb" style="height:27mm;">
  <tr>
    <td class="br" style="width:30mm; text-align:center; vertical-align:middle; height:27mm;">
      {$logoHtml}
    </td>
    <td style="font-size:6.5pt; vertical-align:top; padding-top:2mm;">
      <strong>Gebrüder Weiss</strong><br>
      transport a logistika<br>
      {$this->esc($senderName)}<br>
      {$this->esc($senderStreet)}<br>
      {$this->esc($senderZipCity)}
    </td>
  </tr>
</table>

<table class="bb">
  <tr>
    <td style="padding:1mm 1.5mm 2mm 1.5mm;">
      <div class="lbl">PRIJEMCE:</div>
      <div class="name-lg">{$this->esc($recipientName)}</div>
      {$name2Html}
      <div class="street-md">{$this->esc($recipientStreet)}</div>
      <div class="zip-lg">{$this->esc($recipientZipCountry)}</div>
      <div class="city-lg">{$this->esc($recipientCity)}</div>
    </td>
  </tr>
</table>

<table class="bb">
  <tr>
    <td style="padding:1mm 1.5mm;">
      <div class="lbl">ODESILATEL:</div>
      <div class="bold" style="font-size:7.5pt;">{$this->esc($senderName)}</div>
      <div>{$this->esc($senderStreet)}</div>
      <div>{$this->esc($senderZipCity)}</div>
    </td>
  </tr>
</table>

<table class="bb">
  <tr>
    <td class="br" style="width:50%;">
      <div class="lbl">Cislo objednavky:</div>
      <div class="bold">{$this->esc($request->reference)}</div>
    </td>
    <td>
      <div class="lbl">Datum zasilky:</div>
      <div class="bold">{$date}</div>
    </td>
  </tr>
</table>

<table class="bb">
  <tr>
    <td class="br" style="width:33%;">
      <div class="lbl">Pocet colli:</div>
      <div class="bold">{$parcelNumber}/{$totalParcels}</div>
    </td>
    <td class="br" style="width:34%;">
      <div class="lbl">Hmotnost:</div>
      <div class="bold">{$weight}</div>
    </td>
    <td>
      <div class="lbl">Signo:</div>
      &nbsp;
    </td>
  </tr>
</table>

<table class="bb">
  <tr>
    <td>
      <div class="lbl">Cislo zasilky / Service / EP</div>
      <div class="bold">{$this->esc($request->reference)} /{$this->esc($cfg->branchCode)} /</div>
    </td>
  </tr>
</table>

<div class="center" style="margin-top:3mm;">
  <barcode code="{$sscc}" type="C128B" height="18" text="0" />
</div>
<div class="center" style="font-size:6.5pt; margin-top:1.5mm;">
  SSCC/NVE (00){$this->esc($sscc)}
</div>

</body>
</html>
HTML;
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
