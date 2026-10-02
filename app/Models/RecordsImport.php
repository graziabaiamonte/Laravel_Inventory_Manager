<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// use Illuminate\Database\Eloquent\SoftDeletes;

class RecordsImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'draft',
    ];

    protected $casts = [
        'draft' => 'boolean',
    ];

    public function recordsImportRecordTmp(): HasMany
    {
        return $this->hasMany(RecordsImportRecordTmp::class, 'records_import_id');
    }

    /**
     * Check if this import is still editable (in draft mode)
     */
    public function isEditable(): bool
    {
        return $this->draft;
    }

    /**
     * Get the count of records in this import
     */
    public function getRecordsCountAttribute(): int
    {
        return $this->recordsImportRecordTmp()->count();
    }
}
