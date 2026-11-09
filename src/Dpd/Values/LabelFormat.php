<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Dpd\Values;

enum LabelFormat
{
    /** A6, jeden štítek na stránku. */
    case PdfA6;
    /** A4, čtyři štítky na stránku. */
    case PdfA4;

    /**
     * @return array{printType: string, printProperties: array{pageSize: string, labelsPerPage: int}}
     */
    public function printSpec(): array
    {
        return [
            'printType' => 'PDF',
            'printProperties' => match ($this) {
                self::PdfA6 => ['pageSize' => 'A6', 'labelsPerPage' => 1],
                self::PdfA4 => ['pageSize' => 'A4', 'labelsPerPage' => 4],
            },
        ];
    }
}
