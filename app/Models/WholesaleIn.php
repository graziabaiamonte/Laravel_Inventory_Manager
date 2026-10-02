<?php

namespace App\Models;

use App\Enums\ForSaleOnDiscogsStatusEnum;
use App\Facades\Flash;
use App\Jobs\ListRecordOnDiscogsJob;
use App\Traits\HasAdminFilters;
use App\Traits\LogsToChannel;
use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class WholesaleIn extends Model
{
    use HasAdminFilters, HasFactory;
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'discogs';
    }

    protected $casts = [
        'total_price' => MoneyIntegerCast::class,
    ];

    protected $fillable = [
        'supplier_id',
        'area_id',
        'total_price',
        'file',
        'description',
        'doc_num',
        'status',
    ];

    protected static function booted()
    {
        static::updated(function ($wholesaleIn) {
            // Add debug logging for status changes
            $wholesaleIn->logInfo('WholesaleIn updated event fired', [
                'wholesale_in_id' => $wholesaleIn->id,
                'current_status' => $wholesaleIn->status,
                'is_dirty_status' => $wholesaleIn->isDirty('status'),
                'original_status' => $wholesaleIn->getOriginal('status'),
                'dirty_attributes' => $wholesaleIn->getDirty(),
            ]);

            // Handle Discogs listing when status changes
            if ($wholesaleIn->isDirty('status')) {
                $oldStatus = $wholesaleIn->getOriginal('status');
                $newStatus = $wholesaleIn->status;

                $wholesaleIn->logInfo('WholesaleIn status is dirty - processing Discogs integration', [
                    'wholesale_in_id' => $wholesaleIn->id,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                ]);

                // When status changes from inactive (0) to active (1)
                if ($oldStatus == 0 && $newStatus == 1) {
                    $wholesaleIn->logInfo('Status changed from inactive to active - calling processDiscogsListings(true)');
                    $wholesaleIn->processDiscogsListings(true);
                }
                // When status changes from active (1) to inactive (0)
                elseif ($oldStatus == 1 && $newStatus == 0) {
                    $wholesaleIn->logInfo('Status changed from active to inactive - calling processDiscogsListings(false)');
                    $wholesaleIn->processDiscogsListings(false);
                }
            } else {
                $wholesaleIn->logInfo('WholesaleIn status is not dirty - no Discogs processing needed', [
                    'wholesale_in_id' => $wholesaleIn->id,
                    'current_status' => $wholesaleIn->status,
                ]);
            }
        });
    }

    /**
     * Process Discogs listings for all records in this wholesale
     *
     * @param  bool  $shouldList  true to list records, false to remove them
     */
    private function processDiscogsListings(bool $shouldList): void
    {
        $this->logDetail('processDiscogsListings called', [
            'wholesale_in_id' => $this->id,
            'should_list' => $shouldList,
            'records_count' => $this->records->count(),
        ]);

        $queuedCount = 0;
        $errorCount = 0;
        $errors = [];

        // Load the records with their parent records to ensure we have the data
        $this->load(['records.parentRecord']);

        foreach ($this->records as $wholesaleInRecord) {
            $record = $wholesaleInRecord->parentRecord;

            $this->logInfo('Processing WholesaleInRecord', [
                'wholesale_in_record_id' => $wholesaleInRecord->id,
                'record_id' => $wholesaleInRecord->record_id,
                'has_parent_record' => $record !== null,
            ]);

            if (! $record) {
                $this->logWarning('No parent record found for WholesaleInRecord', [
                    'wholesale_in_record_id' => $wholesaleInRecord->id,
                    'record_id' => $wholesaleInRecord->record_id,
                ]);

                continue;
            }

            // Log record details for debugging
            $this->logInfo('Record details', [
                'record_id' => $record->id,
                'for_sale_on_discogs' => $record->for_sale_on_discogs,
                'discogs_id' => $record->discogs_id,
                'total_stocks' => $record->total_stocks ?? 'accessor_not_available',
            ]);

            // Only process records that are marked for sale on Discogs BY THIS WHOLESALE
            // Skip records that were already on Discogs before this wholesale import
            if ($record->for_sale_on_discogs != ForSaleOnDiscogsStatusEnum::ForSale->value) {
                $this->logInfo('Record not marked for sale on Discogs, skipping', [
                    'record_id' => $record->id,
                    'for_sale_on_discogs' => $record->for_sale_on_discogs,
                    'expected_value' => ForSaleOnDiscogsStatusEnum::ForSale->value,
                ]);

                continue;
            }

            // Additional safety check: only process records that were likely listed by this wholesale
            // If a record already had a discogs_id AND for_sale_on_discogs was already true before this wholesale,
            // we should not process it (as it was already on Discogs)
            // However, since we don't have historical data, we'll be conservative and only list (never remove)
            if (! $shouldList) {
                $this->logInfo('Skipping removal to prevent unlisting pre-existing records', [
                    'record_id' => $record->id,
                    'discogs_id' => $record->discogs_id,
                    'for_sale_on_discogs' => $record->for_sale_on_discogs,
                    'wholesale_in_id' => $this->id,
                    'reason' => 'Safety policy: WholesaleIn operations should never remove existing Discogs listings',
                ]);

                continue;
            }

            try {
                if ($shouldList) {
                    // Queue the listing instead of calling Discogs synchronously: a bulk activation
                    // could otherwise fire dozens of API calls within one request and hit the Discogs
                    // rate limit. The job is throttled and re-checks eligibility (see ListRecordOnDiscogsJob).
                    if (! $record->discogs_id && $record->total_stocks > 0) {
                        ListRecordOnDiscogsJob::dispatch($record->id);
                        $queuedCount++;
                        $this->logInfo('Queued record for Discogs listing', [
                            'record_id' => $record->id,
                            'wholesale_in_id' => $this->id,
                        ]);
                    } else {
                        $this->logInfo('Record not eligible for listing', [
                            'record_id' => $record->id,
                            'discogs_id' => $record->discogs_id,
                            'total_stocks' => $record->total_stocks,
                            'reason' => ! $record->discogs_id ? 'no_stock' : 'already_has_discogs_id',
                        ]);
                    }
                } else {
                    // NEVER remove records from Discogs when deactivating/deleting WholesaleIn
                    // This prevents accidentally removing pre-existing Discogs listings
                    $this->logInfo('Skipping Discogs removal for safety - will not remove any records from Discogs during WholesaleIn deactivation', [
                        'record_id' => $record->id,
                        'discogs_id' => $record->discogs_id,
                        'for_sale_on_discogs' => $record->for_sale_on_discogs,
                        'wholesale_in_id' => $this->id,
                        'reason' => 'Safety policy: WholesaleIn operations should never remove existing Discogs listings',
                    ]);
                }
            } catch (\Exception $e) {
                $errorCount++;
                $errors[] = "Record ID {$record->id}: ".$e->getMessage();
                $this->logError('Exception during Discogs processing via WholesaleIn status change', [
                    'record_id' => $record->id,
                    'wholesale_in_id' => $this->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->logInfo('processDiscogsListings completed', [
            'wholesale_in_id' => $this->id,
            'queued_count' => $queuedCount,
            'error_count' => $errorCount,
            'should_list' => $shouldList,
        ]);

        // Listing now happens in the background (throttled queue), so we report what was queued.
        if ($queuedCount > 0) {
            Flash::success("Pubblicazione su Discogs in corso: {$queuedCount} record in coda.");
        }

        if ($errorCount > 0) {
            $errorMessage = "Errori durante l'accodamento su Discogs ({$errorCount} record)";
            if (count($errors) <= 3) {
                $errorMessage .= ': '.implode('; ', $errors);
            }
            Flash::error($errorMessage);
        }
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(WholesaleInRecord::class);
    }

    public function scopeFilterByAdminRoles(Builder $query): Builder
    {
        /** @var User|null */
        $user = Auth::user();

        // Users with all permission can see all wholesale ins
        if ($user->hasAnyPermission(['all'])) {
            return $query;
        }

        // Other users can only see wholesale ins for areas connected to their locations
        return $query->whereIn('wholesale_ins.area_id', function ($subquery) use ($user) {
            $subquery->select('area_id')
                ->from('area_location')
                ->whereIn('location_id', $user->locations()->pluck('locations.id'));
        });
    }
}
