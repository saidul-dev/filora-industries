<?php

namespace App\Services\SalesOrderImport;

use App\Models\ImportAlias;
use App\Models\Party;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Finds the Party / Product a name on an order sheet refers to: first by
 * normalised name (ImportAlias::normalize), then by a spelling a user
 * matched by hand before. Only records the order form itself would accept
 * are considered — active customers, and active simple products that can
 * be sold.
 */
class RecordMatcher
{
    public function customer(string $name): ?Party
    {
        return $this->match(
            Party::where('is_customer', true)->where('status', true),
            $name
        );
    }

    public function product(string $description): ?Product
    {
        return $this->match(
            Product::where('status', true)->where('has_variants', false)
                ->whereIn('product_type', ['manufactured', 'direct_purchase']),
            $description
        );
    }

    private function match(Builder $candidates, string $name): ?Model
    {
        $key = ImportAlias::normalize($name);

        if ($key === '') {
            return null;
        }

        // Customer and product lists are small; compare in PHP so the
        // same normalisation applies to both sides.
        $byName = (clone $candidates)->get()->first(fn (Model $record) => ImportAlias::normalize($record->name) === $key);

        if ($byName) {
            return $byName;
        }

        $alias = ImportAlias::where('aliasable_type', $candidates->getModel()->getMorphClass())
            ->where('normalized', $key)->first();

        return $alias ? (clone $candidates)->whereKey($alias->aliasable_id)->first() : null;
    }
}
