<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\GebruderWeiss\Label;

use Ages\ShippingGateway\Common\Shipment\CashOnDelivery;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\GebruderWeiss\Config\GebruderWeissConfig;
use Mpdf\Mpdf;

class GebruderWeissLabelGenerator
{
    public function __construct(private readonly GebruderWeissConfig $config) {}

    /**
     * Generates all parcel labels as a single multi-page PDF.
     * Each parcel = one page (100×150 mm).
     *
     * @param string[] $ssccCodes Indexed by parcel index (0-based)
     */
    public function generateLabels(ShipmentRequest $request, array $ssccCodes): string
    {
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
        $mpdf->SetAutoPageBreak(false);

        $total = count($ssccCodes);
        foreach ($ssccCodes as $index => $sscc) {
            if ($index > 0) {
                $mpdf->AddPage();
            }
            $mpdf->WriteHTML($this->buildHtml($request, $request->parcels[$index], $sscc, $index + 1, $total));
        }

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
            ? '<div style="font-size:11pt; font-weight:bold; line-height:1.1;">' . $this->esc($recipientName2) . '</div>'
            : '';

        // COD row: when present, service row grows +5mm, barcode row shrinks -5mm
        $hasCod = $request->cod !== null;
        $serviceRowHeight = $hasCod ? '15mm' : '10mm';
        $barcodeRowHeight = $hasCod ? '30mm' : '35mm';
        $codHtml = $hasCod ? $this->buildCodHtml($request->cod) : '';

        // Row heights: 22+38+17+11+11+serviceRow+barcodeRow = 144mm in both cases
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Arial, sans-serif; font-size: 7pt; }
table { width: 100%; border-collapse: collapse; }
.lbl { font-size: 6pt; color: #444; }
.bold { font-weight: bold; font-size: 7pt; }
</style>
</head>
<body>

<table style="height:144mm; table-layout:fixed;">

  <!-- Row 1: Header — 22mm -->
  <tr style="height:22mm;">
    <td style="border-bottom:0.5pt solid #000; padding:0; overflow:hidden;">
      <table style="height:22mm;">
        <tr>
          <td style="width:28mm; border-right:0.5pt solid #000; text-align:center; vertical-align:middle; height:22mm;">
            {$logoHtml}
          </td>
          <td style="font-size:6.5pt; vertical-align:top; padding:1.5mm 1.5mm 0 2mm;">
            <strong>Gebrüder Weiss</strong><br>
            transport a logistika<br>
            {$this->esc($senderName)}<br>
            {$this->esc($senderStreet)}<br>
            {$this->esc($senderZipCity)}
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Row 2: PRIJEMCE — 38mm -->
  <tr style="height:38mm;">
    <td style="border-bottom:0.5pt solid #000; padding:1mm 1.5mm; vertical-align:top; overflow:hidden;">
      <div class="lbl">PRIJEMCE:</div>
      <div style="font-size:11pt; font-weight:bold; line-height:1.1;">{$this->esc($recipientName)}</div>
      {$name2Html}
      <div style="font-size:8pt; font-weight:bold; line-height:1.2;">{$this->esc($recipientStreet)}</div>
      <div style="font-size:12pt; font-weight:bold; line-height:1.15;">{$this->esc($recipientZipCountry)}</div>
      <div style="font-size:11pt; font-weight:bold; line-height:1.1;">{$this->esc($recipientCity)}</div>
    </td>
  </tr>

  <!-- Row 3: ODESILATEL — 17mm -->
  <tr style="height:17mm;">
    <td style="border-bottom:0.5pt solid #000; padding:1mm 1.5mm; vertical-align:top; overflow:hidden;">
      <div class="lbl">ODESILATEL:</div>
      <div style="font-size:7.5pt; font-weight:bold;">{$this->esc($senderName)}</div>
      <div style="font-size:7pt;">{$this->esc($senderStreet)}</div>
      <div style="font-size:7pt;">{$this->esc($senderZipCity)}</div>
    </td>
  </tr>

  <!-- Row 4: Order reference + date — 11mm -->
  <tr style="height:11mm;">
    <td style="border-bottom:0.5pt solid #000; padding:0; overflow:hidden;">
      <table style="height:11mm;">
        <tr>
          <td style="width:50%; border-right:0.5pt solid #000; padding:1mm 1.5mm; vertical-align:top;">
            <div class="lbl">Cislo objednavky:</div>
            <div class="bold">{$this->esc($request->reference)}</div>
          </td>
          <td style="padding:1mm 1.5mm; vertical-align:top;">
            <div class="lbl">Datum zasilky:</div>
            <div class="bold">{$date}</div>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Row 5: Colli + weight + signo — 11mm -->
  <tr style="height:11mm;">
    <td style="border-bottom:0.5pt solid #000; padding:0; overflow:hidden;">
      <table style="height:11mm;">
        <tr>
          <td style="width:33%; border-right:0.5pt solid #000; padding:1mm; vertical-align:top;">
            <div class="lbl">Pocet colli:</div>
            <div class="bold">{$parcelNumber}/{$totalParcels}</div>
          </td>
          <td style="width:34%; border-right:0.5pt solid #000; padding:1mm; vertical-align:top;">
            <div class="lbl">Hmotnost:</div>
            <div class="bold">{$weight}</div>
          </td>
          <td style="padding:1mm; vertical-align:top;">
            <div class="lbl">Signo:</div>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Row 6: Service / EP (+ COD if present) — 10mm or 15mm -->
  <tr style="height:{$serviceRowHeight};">
    <td style="border-bottom:0.5pt solid #000; padding:1mm 1.5mm; vertical-align:top; overflow:hidden;">
      <div class="lbl">Cislo zasilky / Service / EP</div>
      <div class="bold">{$this->esc($request->reference)} /{$this->esc($cfg->branchCode)} /</div>
      {$codHtml}
    </td>
  </tr>

  <!-- Row 7: Barcode — 35mm (or 30mm with COD) -->
  <tr style="height:{$barcodeRowHeight};">
    <td style="text-align:center; vertical-align:middle; padding:1mm 0;">
      <barcode code="{$sscc}" type="C128C" height="2" size="1" text="0" />
      <br>
      <span style="font-size:6.5pt;">SSCC/NVE (00){$this->esc($sscc)}</span>
    </td>
  </tr>

</table>

</body>
</html>
HTML;
    }

    private function buildCodHtml(CashOnDelivery $cod): string
    {
        $amount = number_format($cod->amount, 2, ',', ' ') . ' ' . $this->esc($cod->currency);
        $vs = $cod->variableSymbol !== '' ? '  VS: ' . $this->esc($cod->variableSymbol) : '';
        return '<div style="margin-top:1mm; font-size:7pt;">'
            . '<strong>DOBÍRKA: ' . $amount . '</strong>'
            . $vs
            . '</div>';
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
