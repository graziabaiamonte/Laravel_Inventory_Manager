<?php

namespace App\Models;

// use App\Models\Scopes\ExcludeRecordInDraftScope;
use App\Services\External\DiscogsListingService;
use App\Traits\HasAdminFilters;
use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Record extends Model implements HasMedia
{
    use HasAdminFilters, HasFactory, InteractsWithMedia, SoftDeletes;

    protected $casts = [
        'retail_price' => MoneyIntegerCast::class,
        'wholesale_price' => MoneyIntegerCast::class,
        'purchase_price' => MoneyIntegerCast::class,
    ];

    protected $fillable = [
        'barcode',
        'cat_number',
        'release_id',
        'type',
        'title',
        'retail_price',
        'wholesale_price',
        'purchase_price',
        'disk_status',
        'cover_status',
        'for_sale_on_discogs',
        'discogs_id',
        'description',
        'comments',
        'format_id',
        'supplier_id',
        'artist_id',
        'label_id',
        'location_text',
    ];

    protected $appends = ['total_stocks'];

    /**
     * Attributes that should not be persisted to the database
     */
    public $defer_discogs_listing = false;

    protected static function booted()
    {
        // static::addGlobalScope(new ExcludeRecordInDraftScope);

        static::created(function ($record) {
            $record->rr_uid = 'RAD'.$record->id;
            if (empty($record->barcode) && empty($record->cat_number)) {
                $record->barcode = $record->rr_uid;
            }
            $record->saveQuietly(); // Prevents recursion
            // Note: Discogs listing happens on first update after stocks are saved
        });

        static::updated(function ($record) {
            // Delegate all Discogs logic to service
            app(DiscogsListingService::class)->handleRecordUpdated(
                $record,
                $record->getChanges(),
                $record->defer_discogs_listing ?? false
            );
        });

        static::deleted(function ($record) {
            // Delegate Discogs deletion to service
            app(DiscogsListingService::class)->handleRecordDeleted($record);
        });
    }

    public function format()
    {
        return $this->belongsTo(Format::class);
    }

    public function artist()
    {
        return $this->belongsTo(Artist::class);
    }

    // public function supplier()
    // {
    //     return $this->belongsTo(Supplier::class);
    // }

    public function label()
    {
        return $this->belongsTo(Label::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    // public function store()
    // {
    //     return $this->belongsTo(Store::class);
    // }

    public function saleRecords(): HasMany
    {
        return $this->hasMany(SaleRecord::class);
    }

    /**
     * Get the date of the last sale for this record.
     *
     * @return \Illuminate\Support\Carbon|null
     */
    public function getLastSaleDate()
    {
        return $this->saleRecords()
            ->join('sales', 'sale_records.sale_id', '=', 'sales.id')
            ->orderBy('sales.date', 'desc')
            ->value('sales.date');
    }

    // public function recordsImport()
    // {
    //     return $this->belongsTo(RecordsImport::class, 'import_id');
    // }

    public function recordsImportRecordTmp(): HasMany
    {
        return $this->hasMany(RecordsImportRecordTmp::class, 'record_id');
    }

    public function wholesaleInRecords(): HasMany
    {
        return $this->hasMany(WholesaleInRecord::class);
    }

    public function wholesaleOutRecords(): HasMany
    {
        return $this->hasMany(WholesaleOutRecord::class, 'record_id');
    }

    // public function scopeWithStock(Builder $query)
    // {
    //     return $this->whereHas('stocks', function ($query) {
    //         $query->where('quantity', '>', 0);
    //     });
    // }

    public function getTotalStocksAttribute($value = null): int
    {
        if ($value !== null) {
            return (int) $value;
        }

        return (int) $this->stocks()->sum('quantity');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('records')
            ->useDisk('records')
            ->singleFile();
    }

    /**
     * Check if the record has stock and remove it from Discogs if not.
     * This is called when the stock is updated or deleted.
     */
    public function checkAndRemoveFromDiscogsIfNoStock(): void
    {
        app(DiscogsListingService::class)->checkAndRemoveIfNoStock($this);
    }

    public function scopeFilterByAdminRoles(Builder $query): Builder
    {
        // All users can see all records
        // Stock modification permissions are handled at the controller level
        return $query;
    }
}
