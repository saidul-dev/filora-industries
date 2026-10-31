<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A spelling from an imported order sheet that points at a Party or
 * Product — saved when a user picks the record by hand on the import
 * preview, so the next sheet with the same spelling matches on its own.
 * One spelling maps to one record per type; remembering it again
 * re-points it.
 */
class ImportAlias extends Model
{
    protected $fillable = [
        'aliasable_type',
        'aliasable_id',
        'alias',
        'normalized',
    ];

    public function aliasable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Comparison key for names typed by different people: case, punctuation,
     * extra spaces and "Limited" vs "Ltd" don't matter; "%" and "/" do
     * (they carry meaning in thread descriptions like "100% … 50/2").
     */
    public static function normalize(string $name): string
    {
        $name = mb_strtolower($name);
        $name = preg_replace('/\blimited\b/u', 'ltd', $name);
        $name = preg_replace('/[^\p{L}\p{N}%\/]+/u', ' ', $name);

        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    public static function remember(Model $record, string $alias): void
    {
        static::updateOrCreate(
            ['aliasable_type' => $record->getMorphClass(), 'normalized' => static::normalize($alias)],
            ['aliasable_id' => $record->getKey(), 'alias' => trim($alias)]
        );
    }
}
