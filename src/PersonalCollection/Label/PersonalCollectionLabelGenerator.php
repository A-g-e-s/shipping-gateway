<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\PersonalCollection\Label;

use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\PersonalCollection\Config\PersonalCollectionConfig;
use Mpdf\Mpdf;

class PersonalCollectionLabelGenerator
{
    public function __construct(private readonly PersonalCollectionConfig $config) {}

    /**
     * Generates all parcel labels as a single multi-page PDF.
     * Each parcel = one page (100×150 mm).
     */
    public function generateLabels(ShipmentRequest $request): string
    {
        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => [100, 150],
            'margin_left'   => 3,
            'margin_right'  => 3,
            'margin_top'    => 3,
            'margin_bottom' => 3,
            'margin_header' => 0,
            'margin_footer' => 0,
        ]);
        $mpdf->SetAutoPageBreak(false);

        $total = count($request->parcels);
        foreach ($request->parcels as $index => $parcel) {
            if ($index > 0) {
                $mpdf->AddPage();
            }
            $mpdf->WriteHTML($this->buildHtml($request, $parcel, $index + 1, $total));
        }

        return $mpdf->Output('', 'S');
    }

    private function buildHtml(
        ShipmentRequest $request,
        Parcel          $parcel,
        int             $parcelNumber,
        int             $totalParcels,
    ): string {
        $r      = $request->recipient;
        $pickup = $this->config->pickupAddress;

        $recipientName    = $r->company ?? $r->fullName();
        $recipientName2   = $r->company !== null ? $r->fullName() : null;
        $recipientStreet  = $r->streetWithNumber();
        $recipientZipCity = $r->country . ' ' . $r->zip . ' ' . $r->city;

        $pickupName    = $pickup->name;
        $pickupStreet  = $pickup->getStreetWithNumber();
        $pickupZipCity = $pickup->country . ' ' . $pickup->zip . ' ' . $pickup->city;

        $date   = (new \DateTimeImmutable())->format('d.m.Y');
        $weight = number_format($parcel->weight, 2, ',', '.') . ' KG';

        $logoHtml = '';
        if ($this->config->logoPath !== null && file_exists($this->config->logoPath)) {
            $path     = $this->esc($this->config->logoPath);
            $logoHtml = "<img src=\"{$path}\" style=\"display:block; margin:0 auto; max-width:26mm; max-height:19mm;\" />";
        }

        $name2Html = $recipientName2 !== null
            ? '<div style="font-size:11pt; font-weight:bold; line-height:1.1;">' . $this->esc($recipientName2) . '</div>'
            : '';

        $hasNote  = $request->note !== null && trim($request->note) !== '';
        $noteHtml = $hasNote ? $this->buildNoteHtml($request->note) : '';

        $serviceRowHeightMm = 10 + ($hasNote ? 9 : 0);
        $barcodeRowHeightMm = 45 - $serviceRowHeightMm;
        $serviceRowHeight   = $serviceRowHeightMm . 'mm';
        $barcodeRowHeight   = $barcodeRowHeightMm . 'mm';

        $reference = $this->esc($request->reference);

        // Row heights: 22+38+17+11+11+serviceRow+barcodeRow = 144mm
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
          <td style="width:30mm; border-right:0.5pt solid #000; text-align:center; vertical-align:middle; height:22mm; padding:1mm;">
            {$logoHtml}
          </td>
          <td style="font-size:9pt; font-weight:bold; vertical-align:middle; padding:2mm 2mm 0 2mm; text-align:center;">
            OSOBNÍ ODBĚR
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Row 2: PŘÍJEMCE — 38mm -->
  <tr style="height:38mm;">
    <td style="border-bottom:0.5pt solid #000; padding:1mm 1.5mm; vertical-align:top; overflow:hidden;">
      <div class="lbl">PŘÍJEMCE:</div>
      <div style="font-size:11pt; font-weight:bold; line-height:1.1;">{$this->esc($recipientName)}</div>
      {$name2Html}
      <div style="font-size:8pt; font-weight:bold; line-height:1.2;">{$this->esc($recipientStreet)}</div>
      <div style="font-size:10pt; font-weight:bold; line-height:1.15;">{$this->esc($recipientZipCity)}</div>
    </td>
  </tr>

  <!-- Row 3: VÝDEJNÍ MÍSTO — 17mm -->
  <tr style="height:17mm;">
    <td style="border-bottom:0.5pt solid #000; padding:1mm 1.5mm; vertical-align:top; overflow:hidden;">
      <div class="lbl">VÝDEJNÍ MÍSTO:</div>
      <div style="font-size:7.5pt; font-weight:bold;">{$this->esc($pickupName)}</div>
      <div style="font-size:7pt;">{$this->esc($pickupStreet)}</div>
      <div style="font-size:7pt;">{$this->esc($pickupZipCity)}</div>
    </td>
  </tr>

  <!-- Row 4: Order reference + date — 11mm -->
  <tr style="height:11mm;">
    <td style="border-bottom:0.5pt solid #000; padding:0; overflow:hidden;">
      <table style="height:11mm;">
        <tr>
          <td style="width:50%; border-right:0.5pt solid #000; padding:1mm 1.5mm; vertical-align:top;">
            <div class="lbl">Číslo objednávky:</div>
            <div class="bold">{$reference}</div>
          </td>
          <td style="padding:1mm 1.5mm; vertical-align:top;">
            <div class="lbl">Datum zásilky:</div>
            <div class="bold">{$date}</div>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Row 5: Colli + weight — 11mm -->
  <tr style="height:11mm;">
    <td style="border-bottom:0.5pt solid #000; padding:0; overflow:hidden;">
      <table style="height:11mm;">
        <tr>
          <td style="width:50%; border-right:0.5pt solid #000; padding:1mm; vertical-align:top;">
            <div class="lbl">Počet colli:</div>
            <div class="bold">{$parcelNumber}/{$totalParcels}</div>
          </td>
          <td style="padding:1mm; vertical-align:top;">
            <div class="lbl">Hmotnost:</div>
            <div class="bold">{$weight}</div>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Row 6: Note (optional) — 10mm or 19mm -->
  <tr style="height:{$serviceRowHeight};">
    <td style="border-bottom:0.5pt solid #000; padding:1mm 1.5mm; vertical-align:top; overflow:hidden;">
      {$noteHtml}
    </td>
  </tr>

  <!-- Row 7: Barcode — 35mm (or 26mm with note) -->
  <tr style="height:{$barcodeRowHeight};">
    <td style="text-align:center; vertical-align:middle; padding:2mm 4mm;">
      <barcode code="{$reference}" type="C128B" height="2" size="1" text="0" />
      <br>
      <span style="font-size:6.5pt;">{$reference}</span>
    </td>
  </tr>

</table>

</body>
</html>
HTML;
    }

    private function buildNoteHtml(string $note): string
    {
        $text = mb_substr(trim($note), 0, 100);

        return '<div>'
            . '<div class="lbl">Poznámka:</div>'
            . '<div style="font-size:6.5pt; line-height:1.15; word-break:break-word;">'
            . $this->esc($text)
            . '</div>'
            . '</div>';
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
