<?php

namespace NetOS\Payments\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use NetOS\Payments\Models\Concerns\UsesTenantConnection;

/**
 * An invoice issued by the local driver.
 *
 * @property int $id
 * @property string $number
 * @property string $status issued or credited
 * @property string $reference
 * @property array<string, string|null> $issuer
 * @property string $payer_name
 * @property string $payer_email
 * @property list<array{description: string, amount: int, taxPercentage: float, taxAmount: int}> $lines
 * @property int $discount_amount
 * @property int $tax_amount
 * @property int $total
 * @property string $currency
 * @property int $credited_amount
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable|null $credited_at
 */
class PaymentInvoice extends Model
{
    use UsesTenantConnection;

    /** @var list<string> */
    protected $fillable = [
        'number',
        'status',
        'reference',
        'issuer',
        'payer_name',
        'payer_email',
        'lines',
        'discount_amount',
        'tax_amount',
        'total',
        'currency',
        'credited_amount',
        'issued_at',
        'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'issuer' => 'array',
            'lines' => 'array',
            'discount_amount' => 'integer',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'credited_amount' => 'integer',
            'issued_at' => 'immutable_datetime',
            'credited_at' => 'immutable_datetime',
        ];
    }
}
