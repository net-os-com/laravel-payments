<?php

namespace NetOS\Payments\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use NetOS\Payments\Models\Concerns\UsesTenantConnection;

/**
 * @property int $id
 * @property string $country
 * @property string $category
 * @property string $rate
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_until
 */
class PaymentTaxRate extends Model
{
    use UsesTenantConnection;

    /** @var list<string> */
    protected $fillable = [
        'country',
        'category',
        'rate',
        'valid_from',
        'valid_until',
    ];

    /**
     * @param Builder<self> $query
     */
    public function scopeValidOn(Builder $query, CarbonInterface $date): void
    {
        $day = $date->toDateString();

        $query
            ->where(fn (Builder $from) => $from->whereNull('valid_from')->orWhere('valid_from', '<=', $day))
            ->where(fn (Builder $until) => $until->whereNull('valid_until')->orWhere('valid_until', '>=', $day));
    }

    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
        ];
    }
}
