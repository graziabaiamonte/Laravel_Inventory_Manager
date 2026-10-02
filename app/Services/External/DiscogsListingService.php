<?php

namespace App\Services\External;

use App\Facades\Flash;
use App\Models\Area;
use App\Models\Record;
use App\Models\Stock;
use App\Traits\LogsToChannel;

/**
 * Handles all Discogs marketplace listing business logic.
 * Orchestrates interactions between internal models and the Discogs API.
 */
class DiscogsListingService
{
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'discogs';
    }

    public function __construct(
        private DiscogsClient $discogsClient
    ) {}

    /**
     * Handle record creation - called after stocks are saved
     */
    public function handleRecordCreated(Record $record): void
    {
        // Record creation doesn't auto-list; listing happens on first update when for_sale_on_discogs is set
        // This is intentional - stocks must exist first
    }

    /**
     * Handle record updates - main orchestration point
     *
     * @param  Record  $record  The record being updated
     * @param  array  $changedFields  Fields that were changed
     * @param  bool  $isDeferred  Whether stock updates are being deferred
     */
    public function handleRecordUpdated(Record $record, array $changedFields, bool $isDeferred = false): void
    {
        $this->logDetail('DiscogsListingService: handleRecordUpdated called', [
            'record_id' => $record->id,
            'for_sale_on_discogs' => $record->for_sale_on_discogs,
            'discogs_id' => $record->discogs_id,
            'changed_fields' => array_keys($changedFields),
            'is_deferred' => $isDeferred,
        ]);

        // FIRST: Check if already listed and relevant fields changed
        // This happens BEFORE defer check (matches original behavior)
        if ($record->for_sale_on_discogs && $record->discogs_id) {
            if ($this->shouldUpdateListing($record, $changedFields)) {
                $this->logInfo('DiscogsListingService: Updating listing for field changes', [
                    'record_id' => $record->id,
                ]);
                $this->updateRecordListing($record);
            }
        }

        // SECOND: If deferred, skip list/delist logic (stocks being saved first)
        if ($isDeferred) {
            $this->logInfo('DiscogsListingService: Skipping list/delist due to defer flag');

            return;
        }

        // THIRD: Check if for_sale_on_discogs itself changed (list/delist)
        if (isset($changedFields['for_sale_on_discogs'])) {
            if ($record->for_sale_on_discogs) {
                $this->listRecordOnDiscogs($record);
            } else {
                $this->deleteRecordListing($record);
            }
        }
    }

    /**
     * Handle record deletion
     */
    public function handleRecordDeleted(Record $record): void
    {
        if ($record->for_sale_on_discogs && $record->discogs_id) {
            $this->deleteRecordListing($record);
        }
    }

    /**
     * Handle stock quantity changes (deletion or quantity reaching zero)
     */
    public function handleStockChanged(Record $record): void
    {
        $this->checkAndRemoveIfNoStock($record);

        // If still listed after stock check, update location to reflect new stock areas
        $record->refresh();
        if ($record->for_sale_on_discogs && $record->discogs_id && $record->total_stocks > 0) {
            $this->updateRecordListing($record);
        }
    }

    /**
     * List a record on Discogs marketplace.
     *
     * @param  bool  $notify  Whether to emit user-facing Flash messages. Set false when called from
     *                        a queued job, which has no session/user to flash to.
     */
    public function listRecordOnDiscogs(Record $record, bool $notify = true): array
    {
        // A Discogs listing cannot be created without a Release ID (it is a required field of the
        // marketplace payload). Without one the record can never be listed, so also clear the
        // for-sale flag: it must not appear as "for sale on Discogs" when no listing is possible.
        // (Stock-only failures keep the flag, since those are genuinely pending — see below.)
        if (! $record->release_id) {
            $record->updateQuietly(['for_sale_on_discogs' => false]);
            $this->logInfo('Skipping Discogs listing: no release_id, cleared for_sale_on_discogs', [
                'record_id' => $record->id,
            ]);
            if ($notify) {
                Flash::error('Record non pubblicato su Discogs: manca il Release ID');
            }

            return ['success' => false, 'error' => 'Missing release_id'];
        }

        if (! $this->canListRecord($record)) {
            $record->updateQuietly(['for_sale_on_discogs' => false]);
            if ($notify) {
                Flash::error('Record non pubblicato su Discogs per stock insufficiente');
            }

            return ['success' => false, 'error' => 'Insufficient stock'];
        }

        try {
            $area = $this->getListingArea($record);
            $result = $this->discogsClient->listItem($record, $area);

            if (! ($result['success'] ?? true)) {
                // Discogs did not create the listing (e.g. release not found / not sellable):
                // the record is not on Discogs, so it must not stay flagged for sale.
                $record->updateQuietly(['for_sale_on_discogs' => false]);
                if ($notify) {
                    Flash::error('Failed to list on Discogs: '.($result['error'] ?? 'Unknown error'));
                }
            } elseif ($notify) {
                Flash::success('Successfully listed on Discogs (ID: '.$record->fresh()->discogs_id.')');
            }

            return $result;
        } catch (\Exception $e) {
            // An errored attempt did not produce a listing either: clear the for-sale flag.
            $record->updateQuietly(['for_sale_on_discogs' => false]);
            if ($notify) {
                Flash::discogs_error('Discogs sync error: '.$e->getMessage());
            }

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update an existing Discogs listing
     */
    public function updateRecordListing(Record $record): array
    {
        if (! $record->discogs_id) {
            return ['success' => false, 'error' => 'No Discogs listing ID'];
        }

        try {
            $area = $this->getListingArea($record);
            $result = $this->discogsClient->updateListing($record, $area);

            if (! ($result['success'] ?? true)) {
                Flash::error('Failed to update Discogs listing: '.($result['error'] ?? 'Unknown error'));
            } else {
                Flash::success('Successfully updated Discogs listing');
            }

            return $result;
        } catch (\Exception $e) {
            Flash::discogs_error('Discogs update error: '.$e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete a Discogs listing
     */
    public function deleteRecordListing(Record $record): array
    {
        if (! $record->discogs_id) {
            return ['success' => true, 'data' => null]; // Already not listed
        }

        try {
            $result = $this->discogsClient->deleteListing($record);

            if (! ($result['success'] ?? true)) {
                Flash::error('Failed to remove listing on Discogs: '.($result['error'] ?? 'Unknown error'));
            } else {
                Flash::success('Successfully removed from Discogs');
            }

            return $result;
        } catch (\Exception $e) {
            Flash::discogs_error('Discogs sync error: '.$e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check if record has stock, remove from Discogs if not
     */
    public function checkAndRemoveIfNoStock(Record $record): void
    {
        if (! $record->for_sale_on_discogs || ! $record->discogs_id) {
            return;
        }

        if ($record->total_stocks <= 0) {
            try {
                $result = $this->discogsClient->deleteListing($record);

                if (! ($result['success'] ?? true)) {
                    Flash::error('Failed to remove listing from Discogs due to zero stock: '.($result['error'] ?? 'Unknown error'));
                } else {
                    // Refresh model to get the discogs_id = null set by DiscogsClient
                    $record->refresh();
                    $record->updateQuietly(['for_sale_on_discogs' => false]);
                    Flash::success('Record rimosso da Discogs per stock insufficiente');
                }
            } catch (\Exception $e) {
                Flash::discogs_error('Discogs removal error (zero stock): '.$e->getMessage());
            }
        }
    }

    /**
     * Check if a record can be listed on Discogs
     */
    public function canListRecord(Record $record): bool
    {
        return $record->total_stocks > 0;
    }

    /**
     * Determine if listing should be updated based on changed fields
     */
    public function shouldUpdateListing(Record $record, array $dirtyFields): bool
    {
        $fieldsToWatch = [
            'title', 'retail_price', 'wholesale_price', 'disk_status',
            'cover_status', 'description', 'comments',
        ];

        return ! empty(array_intersect(array_keys($dirtyFields), $fieldsToWatch));
    }

    /**
     * Get the area to use for listing (first stock with quantity > 0, ordered)
     */
    private function getListingArea(Record $record): ?Area
    {
        $firstStock = $record->stocks()
            ->where('quantity', '>', 0)
            ->ordered()
            ->with('area')
            ->first();

        return $firstStock?->area;
    }
}
