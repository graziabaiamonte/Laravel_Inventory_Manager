<?php

namespace App\Models;

use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecordsImportRecordTmp extends Model
{
    protected $table = 'records_import_record_tmp';

    use HasFactory, SoftDeletes;

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
        'records_import_id',
        'record_id',
        'delete',
        'soft_delete',
        'd_delete',
        'stocks_tmp',
    ];

    public function recordsImport()
    {
        return $this->belongsTo(RecordsImport::class, 'records_import_id');
    }

    public function record()
    {
        return $this->belongsTo(Record::class, 'record_id');
    }

    public function format()
    {
        return $this->belongsTo(Format::class);
    }

    public function label()
    {
        return $this->belongsTo(Label::class);
    }

    public function artist()
    {
        return $this->belongsTo(Artist::class);
    }
}
