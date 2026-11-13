<?php

namespace NetOS\Payments\Support;

use Carbon\CarbonInterface;
use NetOS\Payments\Models\PaymentDocumentSequence;
use NetOS\Payments\Models\PaymentInvoice;

/**
 * Hands out the next number of a series per year: {series}-{year}-0001, growing past 9999 instead of wrapping.
 * Call it inside the caller's transaction on the tenant connection, so a rolled back document frees its number.
 */
class DocumentNumberer
{
    public function next(string $series, CarbonInterface $issuedAt): string
    {
        $year = (int) $issuedAt->format('Y');

        // insertOrIgnore lets two first documents of a year race without a duplicate-key error. A new row starts at
        // the highest number already issued, so documents from before the sequence table are never numbered again.
        if (! PaymentDocumentSequence::query()->where('series', $series)->where('year', $year)->exists()) {
            PaymentDocumentSequence::query()->insertOrIgnore([
                'series' => $series,
                'year' => $year,
                'last_number' => $this->highestIssued($series, $year),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $sequence = PaymentDocumentSequence::query()
            ->where('series', $series)
            ->where('year', $year)
            ->lockForUpdate()
            ->firstOrFail();

        $sequence->increment('last_number');

        return sprintf('%s-%d-%s', $series, $year, mb_str_pad((string) $sequence->last_number, 4, '0', STR_PAD_LEFT));
    }

    private function highestIssued(string $series, int $year): int
    {
        $prefix = sprintf('%s-%d-', $series, $year);

        return (int) PaymentInvoice::query()
            ->where('number', 'like', addcslashes($prefix, '%_\\') . '%')
            ->pluck('number')
            ->map(fn (string $number): int => (int) mb_substr($number, mb_strlen($prefix)))
            ->max();
    }
}
