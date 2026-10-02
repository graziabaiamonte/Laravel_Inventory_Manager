<?php

namespace App\Services;

use App\Enums\CoverStatusEnum;
use App\Enums\DiskStatusEnum;
use App\Models\Artist;
use App\Models\Format;
use App\Models\Label;

class RecordDataTransformer
{
    public function __construct(
        private RecordImportService $recordImportService
    ) {}

    /**
     * Transform relationship objects to strings for validation compatibility
     */
    public function transformRelationshipsForValidation(array $data): array
    {
        if (isset($data['records']) && is_array($data['records'])) {
            foreach ($data['records'] as &$record) {
                $record['artist'] = $this->extractRelationshipName($record['artist'] ?? '');
                $record['format'] = $this->extractRelationshipName($record['format'] ?? '');
                $record['label'] = $this->extractRelationshipName($record['label'] ?? '');
            }
        }

        return $data;
    }

    /**
     * Extract name from relationship object or return string as-is
     */
    private function extractRelationshipName($relationship): string
    {
        if (is_array($relationship)) {
            return $relationship['name'] ?? '';
        }

        return (string) $relationship;
    }

    /**
     * Build update data array for temporary record updates
     */
    public function buildUpdateData(array $recordData, array $originalRecordData = []): array
    {
        $updateData = [];

        // Basic fields
        $basicFields = [
            'record_id', 'barcode', 'cat_number', 'title', 'release_id',
            'description', 'comments', 'soft_delete', 'delete', 'd_delete', 'stocks_tmp',
        ];

        foreach ($basicFields as $field) {
            if (isset($recordData[$field])) {
                $updateData[$field] = $recordData[$field];
            }
        }

        // Price fields
        $priceFields = ['retail_price', 'wholesale_price', 'purchase_price'];
        foreach ($priceFields as $field) {
            if (isset($recordData[$field])) {
                $updateData[$field] = (float) $recordData[$field];
            }
        }

        // Enum fields
        if (isset($recordData['condition_disk'])) {
            $updateData['disk_status'] = DiskStatusEnum::fromDescription($recordData['condition_disk']);
        }
        if (isset($recordData['condition_cover'])) {
            $updateData['cover_status'] = CoverStatusEnum::fromDescription($recordData['condition_cover']);
        }

        // Relationship fields - use original data to preserve objects
        $relationships = [
            'artist' => Artist::class,
            'format' => Format::class,
            'label' => Label::class,
        ];

        foreach ($relationships as $relationship => $modelClass) {
            $value = $this->getRelationshipValue($originalRecordData, $recordData, $relationship);
            if ($value !== null) {
                $updateData[$relationship.'_id'] = $this->recordImportService->findOrCreateModel($value, $modelClass);
            }
        }

        return $updateData;
    }

    /**
     * Get relationship value from original or transformed data
     */
    private function getRelationshipValue(array $originalData, array $transformedData, string $relationship): ?string
    {
        // Try original data first (preserves objects)
        if (isset($originalData[$relationship])) {
            return $this->extractRelationshipName($originalData[$relationship]);
        }

        // Try alternative name field
        if (isset($originalData[$relationship.'_name'])) {
            return $originalData[$relationship.'_name'];
        }

        // Fall back to transformed data
        if (isset($transformedData[$relationship])) {
            return $this->extractRelationshipName($transformedData[$relationship]);
        }

        return null;
    }

    /**
     * Build data for new temporary record creation
     */
    public function buildNewRecordData(array $recordData, array $originalData, int $recordsImportId, ?int $recordId = null): array
    {
        return [
            'barcode' => $recordData['barcode'] ?? '',
            'cat_number' => $recordData['cat_number'] ?? '',
            'release_id' => ! empty($recordData['release_id']) ? (int) $recordData['release_id'] : null, // release_id from input, null (not 0) when absent
            'type' => 'new',
            'title' => $recordData['title'] ?? '',
            'retail_price' => (float) ($recordData['retail_price'] ?? 0),
            'wholesale_price' => (float) ($recordData['wholesale_price'] ?? 0),
            'purchase_price' => (float) ($recordData['purchase_price'] ?? 0),
            'disk_status' => isset($recordData['condition_disk'])
                ? DiskStatusEnum::fromDescription($recordData['condition_disk'])
                : DiskStatusEnum::Mint,
            'cover_status' => isset($recordData['condition_cover'])
                ? CoverStatusEnum::fromDescription($recordData['condition_cover'])
                : CoverStatusEnum::Mint,
            'description' => $recordData['description'] ?? '',
            'comments' => $recordData['comments'] ?? '',
            'for_sale_on_discogs' => ($recordData['d_delete'] ?? false) ? 0 : 1,
            'format_id' => $this->getRelationshipId($originalData, 'format', Format::class),
            'label_id' => $this->getRelationshipId($originalData, 'label', Label::class),
            'artist_id' => $this->getRelationshipId($originalData, 'artist', Artist::class),
            'records_import_id' => $recordsImportId,
            'record_id' => $recordId,
            'soft_delete' => (int) ($recordData['soft_delete'] ?? 0),
            'delete' => (int) ($recordData['delete'] ?? 0),
            'd_delete' => (int) ($recordData['d_delete'] ?? 0),
        ];
    }

    /**
     * Get relationship ID by finding or creating the model
     */
    private function getRelationshipId(array $data, string $relationship, string $modelClass): ?int
    {
        $value = $this->extractRelationshipName($data[$relationship] ?? '');

        return ! empty($value) ? $this->recordImportService->findOrCreateModel($value, $modelClass) : null;
    }
}
