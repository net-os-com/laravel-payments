@php
    /** @var \NetOS\Payments\Models\PaymentInvoice $document */
    $isCreditNote = $document->type === 'credit_note';
    $money = fn (int $minor): string => ($document->currency === 'eur' ? '€' : mb_strtoupper($document->currency) . ' ')
        . number_format(($isCreditNote ? -$minor : $minor) / 100, 2, ',', '.');
    $issuer = $document->issuer;
    $payer = $document->payer ?? [];
    $taxPerRate = collect($document->lines)
        ->groupBy(fn (array $line): string => (string) (float) $line['taxPercentage'])
        ->map(fn ($lines): int => $lines->sum('taxAmount'));
    $grossBeforeDiscount = $document->total + $document->discount_amount;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $isCreditNote ? 'Credit note' : 'Invoice' }} {{ $document->number }}</title>
    <style>
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #0d0d0d; font-size: 12px; margin: 0; padding: 32px; }
        .page { max-width: 760px; margin: 0 auto; }
        h1 { font-size: 24px; font-weight: normal; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; padding: 4px 0; text-align: left; }
        .muted { color: #737373; }
        .right { text-align: right; }
        .parties td { width: 50%; padding-bottom: 24px; }
        .lines th { border-bottom: 1px solid #d9d9d9; font-weight: bold; padding: 8px 0; }
        .lines td { border-bottom: 1px solid #f2f2f2; padding: 8px 0; }
        .totals td { padding: 4px 0; }
        .totals .grand td { border-top: 1px solid #0d0d0d; font-weight: bold; padding-top: 8px; }
        .download { display: inline-block; margin-bottom: 24px; padding: 8px 16px; border-radius: 999px; background: #0a2d51; color: #fff; text-decoration: none; }
    </style>
</head>
<body>
<div class="page">
    @if ($pdfUrl ?? null)
        <a class="download" href="{{ $pdfUrl }}">Download PDF</a>
    @endif

    <table>
        <tr>
            <td>
                <h1>{{ $isCreditNote ? 'Credit note' : 'Invoice' }}</h1>
                <div class="muted">{{ $document->number }}</div>
            </td>
            <td class="right">
                <strong>{{ $issuer['name'] ?? '' }}</strong><br>
                @if (! empty($issuer['address']))
                    {!! nl2br(e($issuer['address'])) !!}<br>
                @endif
                @if (! empty($issuer['chamberOfCommerceNumber']))
                    Chamber of Commerce {{ $issuer['chamberOfCommerceNumber'] }}<br>
                @endif
                @if (! empty($issuer['vatNumber']))
                    VAT {{ $issuer['vatNumber'] }}
                @endif
            </td>
        </tr>
    </table>

    <table class="parties" style="margin-top: 24px;">
        <tr>
            <td>
                <div class="muted">Billed to</div>
                @if (! empty($payer['companyName']))
                    <strong>{{ $payer['companyName'] }}</strong><br>
                @endif
                {{ $payer['name'] ?? $document->payer_name }}<br>
                {{ $payer['email'] ?? $document->payer_email }}<br>
                @if (! empty($payer['addressLine1']))
                    {{ $payer['addressLine1'] }}<br>
                @endif
                @if (! empty($payer['addressLine2']))
                    {{ $payer['addressLine2'] }}<br>
                @endif
                @if (! empty($payer['postalCode']) || ! empty($payer['city']))
                    {{ trim(($payer['postalCode'] ?? '') . ' ' . ($payer['city'] ?? '')) }}<br>
                @endif
                @if (! empty($payer['country']))
                    {{ $payer['country'] }}<br>
                @endif
                @if (! empty($payer['taxIdValue']))
                    VAT {{ $payer['taxIdValue'] }}
                @endif
            </td>
            <td class="right">
                <div class="muted">Date</div>
                {{ $document->issued_at->format('j M Y') }}<br>
                @if ($isCreditNote && $document->creditedInvoice)
                    <div class="muted" style="margin-top: 8px;">Credit note for invoice</div>
                    {{ $document->creditedInvoice->number }}
                @endif
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
        <tr>
            <th>Description</th>
            <th class="right">VAT</th>
            <th class="right">Amount incl. VAT</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($document->lines as $line)
            <tr>
                <td>{{ $line['description'] }}</td>
                <td class="right">{{ rtrim(rtrim(number_format((float) $line['taxPercentage'], 2, ',', ''), '0'), ',') }}%</td>
                <td class="right">{{ $money((int) $line['amount']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top: 16px;">
        @if ($document->discount_amount > 0)
            <tr>
                <td class="right muted">Subtotal</td>
                <td class="right" style="width: 160px;">{{ $money($grossBeforeDiscount) }}</td>
            </tr>
            <tr>
                <td class="right muted">Discount</td>
                <td class="right">{{ $money(-$document->discount_amount) }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td class="right">Total incl. VAT</td>
            <td class="right" style="width: 160px;">{{ $money($document->total) }}</td>
        </tr>
        @foreach ($taxPerRate as $rate => $taxAmount)
            <tr>
                <td class="right muted">of which VAT {{ rtrim(rtrim(number_format((float) $rate, 2, ',', ''), '0'), ',') }}%</td>
                <td class="right muted">{{ $money((int) $taxAmount) }}</td>
            </tr>
        @endforeach
    </table>

    <p class="muted" style="margin-top: 32px;">Reference {{ $document->reference }}</p>
</div>
</body>
</html>
