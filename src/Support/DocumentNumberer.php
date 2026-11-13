<?php

namespace NetOS\Payments\Support;

use Carbon\CarbonInterface;
use NetOS\Payments\Models\PaymentDocumentSequence;

/**
 * Hands out the next number of a series per year: {series}-{year}-0001, growing past 9999 instead of wrapping.
 * Call it inside the caller's transaction on the tenant connection, so a rolled back document frees its number.
 */
class DocumentNumberer
{
    public function next(string $series, CarbonInterface $issuedAt): string
    {
        $year = (int) $issuedAt->format('Y');

        PaymentDocumentSequence::query()->firstOrCreate(['series' => $series, 'year' => $year]);

        $sequence = PaymentDocumentSequence::query()
            ->where('series', $series)
            ->where('year', $year)
            ->lockForUpdate()
            ->firstOrFail();

        $sequence->increment('last_number');

        return sprintf('%s-%d-%s', $series, $year, mb_str_pad((string) $sequence->last_number, 4, '0', STR_PAD_LEFT));
    }
}
