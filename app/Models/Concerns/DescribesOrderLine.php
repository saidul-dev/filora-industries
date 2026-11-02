<?php

namespace App\Models\Concerns;

/**
 * Shared by Sales Order and Proforma Invoice lines — both carry a product
 * (and maybe a variant), an ordered quantity, a running delivered_quantity
 * and the thread details that tell apart lines of the same product.
 */
trait DescribesOrderLine
{
    public function remaining(): float
    {
        return max(round((float) $this->quantity - (float) $this->delivered_quantity, 4), 0);
    }

    /**
     * "Style TS472302 · Color 0160 · 2000 m · Rush" — empty when there are none.
     */
    public function lineDetails(): string
    {
        return collect([
            $this->style ? "Style {$this->style}" : null,
            $this->color ? "Color {$this->color}" : null,
            $this->length_per_unit !== null ? rtrim(rtrim(number_format((float) $this->length_per_unit, 2), '0'), '.').' m' : null,
            $this->remarks,
        ])->filter()->implode(' · ');
    }

    public function label(): string
    {
        return $this->product->name.($this->productVariant ? ' — '.$this->productVariant->label : '');
    }
}
