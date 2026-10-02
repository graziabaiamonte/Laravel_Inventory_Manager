<?php

namespace App\Models;

use App\Services\External\DiscogsListingService;
use App\Traits\HasAdminFilters;
use App\Traits\LogsToChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class Stock extends Model implements Sortable
{
    use HasAdminFilters, HasFactory, LogsToChannel, SortableTrait;

    /**
     * Upper bound accepted for any single quantity input.
     *
     * The column is a signed integer, so a barcode typed or scanned into a
     * quantity field overflows it and MySQL rejects the write with a 1264 after
     * the surrounding work has already been done. Well below the column limit
     * and far above any real document line, so it catches the barcode case
     * without getting in the way.
     */
    public const MAX_QUANTITY = 1000000;

    protected $fillable = [
        'record_id',
        'area_id',
        'quantity',
        'description',
        'order_column',
    ];

    protected function logChannel(): string
    {
        return 'stock';
    }

    protected static function booted()
    {
        static::updated(function ($stock) {
            // Check if quantity was changed
            if (! $stock->isDirty('quantity') || $stock->quantity > 0) {
                return;
            }

            $record = $stock->record;
            if (! $record) {
                return;
            }

            app(DiscogsListingService::class)->handleStockChanged($record);
        });

        static::deleted(function ($stock) {
            $record = $stock->record;
            if (! $record) {
                return;
            }

            app(DiscogsListingService::class)->handleStockChanged($record);
        });
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function wholesaleOutRecords(): HasMany
    {
        return $this->hasMany(WholesaleOutRecord::class);
    }

    public function saleRecords(): HasMany
    {
        return $this->hasMany(SaleRecord::class);
    }

    /**
     * Whether a document line still points at this stock row.
     *
     * wholesale_out_records.stock_id is ON DELETE RESTRICT, so deleting a
     * referenced row raises a 1451. sale_records.stock_id is ON DELETE
     * CASCADE, so deleting a referenced row silently takes the sale line with
     * it. Both are checked: the first to avoid the error, the second to avoid
     * losing history.
     */
    public function isReferencedByDocuments(): bool
    {
        return $this->wholesaleOutRecords()->exists()
            || $this->saleRecords()->exists();
    }

    /**
     * Remove this stock row, or empty it when a document still references it.
     *
     * A stock row is the identity of a record/area pair, not a container for a
     * quantity: a zero row is a valid state and the rest of the app already
     * treats it as one, since availability is summed over quantity. Dropping
     * the row instead loses that identity, which is what produces the 1451
     * violations, the duplicate-key errors from deleting and re-creating the
     * same pair, and the stale stock_id references from clients still holding
     * the old id.
     *
     * Returns true when the row was deleted, false when it was emptied.
     */
    public function deleteOrEmpty(): bool
    {
        if ($this->isReferencedByDocuments()) {
            $this->emptyInsteadOfDeleting('referenced by a wholesale-out or sale line');

            return false;
        }

        try {
            $this->delete();
        } catch (QueryException $e) {
            // A document line can be inserted between the check above and the
            // delete. The constraint is the authority, so treat a 1451 the
            // same as a reference found up front rather than failing the
            // request.
            if (($e->errorInfo[1] ?? null) !== 1451) {
                throw $e;
            }

            $this->emptyInsteadOfDeleting('a document line was added during the delete');

            return false;
        }

        return true;
    }

    private function emptyInsteadOfDeleting(string $reason): void
    {
        if ($this->quantity !== 0) {
            $this->update(['quantity' => 0]);
        }

        $this->logInfo('Stock row emptied instead of deleted.', [
            'stock_id' => $this->id,
            'record_id' => $this->record_id,
            'area_id' => $this->area_id,
            'reason' => $reason,
        ]);
    }

    public function scopeFilterByAdminRoles(Builder $query): Builder
    {
        /** @var User|null */
        $user = Auth::user();

        // Users with all permission can see all stock
        if ($user->hasAnyPermission(['all'])) {
            return $query;
        }

        // Other users can only see stocks in areas connected to their locations
        return $query->whereIn('stocks.area_id', function ($subquery) use ($user) {
            $subquery->select('area_id')
                ->from('area_location')
                ->whereIn('location_id', $user->locations()->pluck('locations.id'));
        });
    }
}
