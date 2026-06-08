<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\Enum;

enum ProductType: string
{
    case KWire      = 'k-wire';
    case Screw      = 'screw';
    case Plate      = 'plate';
    case Nail       = 'nail';
    case Pin        = 'pin';
    case Wire       = 'wire';
    case Washer     = 'washer';
    case Instrument = 'instrument';
    case Other      = 'other';

    /**
     * Keyword lists checked in order — more specific entries first.
     * Instrument is first so "Plate Cutting Pliers" / "Screwdriver Blade"
     * are not misclassified as Plate or Screw.
     *
     * @return array<value-of<self>, list<string>>
     */
    private static function rules(): array
    {
        return [
            self::Instrument->value => [
                'screwdriver', 'screw driver', 'screwdr.',
                'pliers', 'bending plier', 'cutting plier',
                'depth gauge', 'gauge',
                'drill guide', 'drill stop', 'drill bit',
                'sleeve',
                'handle',
                'blade',
                'instr.',
                'positioning instr', 'holding instr',
                'plate holding', 'plate bending', 'plate cutting',
                'retractor', 'forceps', 'clamp', 'mallet', 'awl', 'punch',
                'cutter', 'bender',
            ],
            self::KWire->value      => ['k-wire'],
            self::Nail->value       => ['intramedullary nail', ' nail,', ' nail '],
            self::Screw->value      => ['screw'],
            self::Plate->value      => ['plate', ' pl '],
            self::Wire->value       => ['guide wire', ' wire,', ' wire '],
            self::Pin->value        => [' pin,', ' pin '],
            self::Washer->value     => ['washer'],
        ];
    }

    public static function fromProductName(string $productName): self
    {
        $lower = mb_strtolower($productName);

        foreach (self::rules() as $typeValue => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return self::from($typeValue);
                }
            }
        }

        return self::Other;
    }

    public function label(): string
    {
        return match($this) {
            self::KWire      => 'K-Wire',
            self::Screw      => 'Screw',
            self::Plate      => 'Plate',
            self::Nail       => 'Nail',
            self::Pin        => 'Pin',
            self::Wire       => 'Wire',
            self::Washer     => 'Washer',
            self::Instrument => 'Instrument',
            self::Other      => 'Other',
        };
    }

    /** CSS class suffix for styling, e.g. dc-type--screw */
    public function cssClass(): string
    {
        return 'dc-type--' . $this->value;
    }
}
