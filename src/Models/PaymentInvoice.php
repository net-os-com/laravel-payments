<?php

namespace NetOS\Payments\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use NetOS\Payments\Models\Concerns\UsesTenantConnection;

/**
 * An invoice or credit note issued by the local driver. Amounts are positive; the type says which way they go.
 *
 * @property int $id
 * @property string $type invoice or credit_note
 * @property int|null $credits_invoice_id
 * @property string $number
 * @property string $status issued, partially_credited or credited
 * @property string $reference
 * @property array<string, string|null> $issuer
 * @property string $payer_name
 * @property string $payer_email
 * @property array<string, string|null>|null $payer
 * @property list<array{description: string, amount: int, taxPercentage: float, taxAmount: int}> $lines
 * @property int $discount_amount
 * @property int $tax_amount
 * @property int $total
 * @property string $currency
 * @property int $credited_amount
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable|null $credited_at
 * @property string|null $pdf_disk
 * @property string|null $pdf_path
 * @property-read PaymentInvoice|null $creditedInvoice
 * @property-read Collection<int, PaymentInvoice> $creditNotes
 */
class PaymentInvoice extends Model
{
    use UsesTenantConnection;

    /** @var list<string> */
    protected $fillable = [
        'type',
        'credits_invoice_id',
        'number',
        'status',
        'reference',
        'issuer',
        'payer_name',
        'payer_email',
        'payer',
        'lines',
        'discount_amount',
        'tax_amount',
        'total',
        'currency',
        'credited_amount',
        'issued_at',
        'credited_at',
        'pdf_disk',
        'pdf_path',
    ];

    /**
     * @return BelongsTo<PaymentInvoice, $this>
     */
    public function creditedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credits_invoice_id');
    }

    /**
     * @return HasMany<PaymentInvoice, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'credits_invoice_id');
    }

    protected function casts(): array
    {
        return [
            'issuer' => 'array',
            'payer' => 'array',
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
