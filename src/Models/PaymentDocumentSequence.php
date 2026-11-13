<?php

namespace NetOS\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use NetOS\Payments\Models\Concerns\UsesTenantConnection;

/**
 * @property int $id
 * @property string $series
 * @property int $year
 * @property int $last_number
 */
class PaymentDocumentSequence extends Model
{
    use UsesTenantConnection;

    /** @var list<string> */
    protected $fillable = [
        'series',
        'year',
        'last_number',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_number' => 'integer',
        ];
    }
}
