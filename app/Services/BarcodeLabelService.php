<?php

namespace App\Services;

use App\Enums\CoverStatusEnum;
use App\Enums\DiskStatusEnum;
use App\Enums\RecordTypeEnum;
use App\Models\Record;
use Milon\Barcode\Facades\DNS1DFacade as DNS1D;

class BarcodeLabelService
{
    /**
     * Build the view payload for a single record's printed label.
     */
    public function forRecord(Record $record): array
    {
        $isSecondHand = $record->type === RecordTypeEnum::SECONDHAND;

        // For secondhand records, use rr_uid as the barcode source
        $barcodeSource = $isSecondHand
            ? $record->rr_uid
            : (! empty($record->barcode) ? $record->barcode : $record->rr_uid);

        return [
            'barcode_img' => $this->renderSvg($barcodeSource),
            'rr_uid' => $record->rr_uid,
            'title' => $record->title,
            'artist' => $isSecondHand
                ? $this->diskConditionCode($record->disk_status)
                : ($record->artist?->name ?? ''),
            'label' => $record->label?->name ?? '',
            'format' => $record->format?->name ?? '',
            'cat_number' => $isSecondHand ? ($record->location_text ?? '') : $record->cat_number,
            'barcode' => $barcodeSource,
            'type' => $record->type,
            'retail_price' => $this->formatPrice($record),
            'cover_condition' => $isSecondHand ? $this->coverConditionCode($record->cover_status) : '',
        ];
    }

    /**
     * Generate a Code 128 SVG that always stretches to the full width of its label.
     */
    private function renderSvg(string $source): string
    {
        $svg = DNS1D::getBarcodeSVG($source, 'C128', 4, 120, 'black', false);

        // Capture the intrinsic dimensions so we can drive scaling from a viewBox.
        preg_match('/width="(\d+(?:\.\d+)?)"/', $svg, $wMatch);
        preg_match('/height="(\d+(?:\.\d+)?)"/', $svg, $hMatch);
        if (empty($wMatch[1]) || empty($hMatch[1])) {
            return $svg;
        }

        // Add a viewBox (if missing) so the bars scale instead of clipping.
        if (! str_contains($svg, 'viewBox')) {
            $svg = preg_replace('/(<svg\b)/', '$1 viewBox="0 0 '.$wMatch[1].' '.$hMatch[1].'"', $svg, 1);
        }

        // preserveAspectRatio="none" lets the bars stretch to fill the full width
        // (instead of being letterboxed to a narrow strip when height is capped).
        if (! str_contains($svg, 'preserveAspectRatio')) {
            $svg = preg_replace('/(<svg\b)/', '$1 preserveAspectRatio="none"', $svg, 1);
        }

        // Force the SVG itself to span 100% width; the stylesheet caps the height.
        $svg = preg_replace('/(<svg\b[^>]*?)\swidth="[\d.]+"/', '$1 width="100%"', $svg, 1);
        $svg = preg_replace('/(<svg\b[^>]*?)\sheight="[\d.]+"/', '$1', $svg, 1);

        return $svg;
    }

    /**
     * Format the retail price for the printed label, normalising the cents.
     */
    private function formatPrice(Record $record): string
    {
        if (! $record->retail_price) {
            return '';
        }

        $priceValue = (float) $record->retail_price->formatByDecimal();
        $cents = (int) round(($priceValue - floor($priceValue)) * 100);

        switch ($cents) {
            case 99:
                // .99 prices: bump by 0.01 to round up to the whole euro
                $priceValue += 0.01;
                break;
            case 0:
                // .00 prices: already rounded, leave unchanged
                break;
            default:
                // Any other decimals are left unchanged
                break;
        }

        return number_format($priceValue, 2, ',', '').' €';
    }

    /**
     * Get the short disk condition code for printed labels.
     */
    private function diskConditionCode($diskStatus): string
    {
        if ($diskStatus === null || $diskStatus === '') {
            return '';
        }

        $status = $diskStatus instanceof DiskStatusEnum
            ? $diskStatus
            : DiskStatusEnum::tryFrom((int) $diskStatus);

        return $status?->getLabelCode() ?? '';
    }

    /**
     * Get the short cover condition code for printed labels.
     */
    private function coverConditionCode($coverStatus): string
    {
        if ($coverStatus === null || $coverStatus === '') {
            return '';
        }

        $status = $coverStatus instanceof CoverStatusEnum
            ? $coverStatus
            : CoverStatusEnum::tryFrom((int) $coverStatus);

        return $status?->getLabelCode() ?? '';
    }
}
