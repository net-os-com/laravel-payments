<?php

use Carbon\CarbonImmutable;
use NetOS\Payments\Models\PaymentDocumentSequence;
use NetOS\Payments\Models\PaymentInvoice;
use NetOS\Payments\Support\DocumentNumberer;

beforeEach(function (): void {
    $this->numberer = app(DocumentNumberer::class);
    $this->issuedAt = CarbonImmutable::parse('2026-05-01');
});

it('numbers each series per year without gaps', function (): void {
    expect($this->numberer->next('INV', $this->issuedAt))->toBe('INV-2026-0001')
        ->and($this->numberer->next('INV', $this->issuedAt))->toBe('INV-2026-0002')
        ->and($this->numberer->next('CN', $this->issuedAt))->toBe('CN-2026-0001')
        ->and($this->numberer->next('INV', CarbonImmutable::parse('2027-01-01')))->toBe('INV-2027-0001');
});

it('keeps counting past 9999 instead of wrapping', function (): void {
    $this->numberer->next('INV', $this->issuedAt);
    PaymentDocumentSequence::query()->update(['last_number' => 9999]);

    expect($this->numberer->next('INV', $this->issuedAt))->toBe('INV-2026-10000')
        ->and($this->numberer->next('INV', $this->issuedAt))->toBe('INV-2026-10001');
});

it('starts a new sequence after documents numbered before the sequence existed', function (): void {
    foreach (['INV-2026-0001', 'INV-2026-0007', 'INV-2025-0042'] as $number) {
        PaymentInvoice::query()->create([
            'number' => $number, 'status' => 'issued', 'reference' => 'r', 'issuer' => [], 'payer_name' => 'P',
            'payer_email' => 'p@example.com', 'lines' => [], 'tax_amount' => 0, 'total' => 0, 'currency' => 'eur',
            'issued_at' => $this->issuedAt,
        ]);
    }

    expect($this->numberer->next('INV', $this->issuedAt))->toBe('INV-2026-0008');
});

it('treats LIKE wildcards in a series literally when seeding', function (): void {
    PaymentInvoice::query()->create([
        'number' => 'AB-2026-0005', 'status' => 'issued', 'reference' => 'r', 'issuer' => [], 'payer_name' => 'P',
        'payer_email' => 'p@example.com', 'lines' => [], 'tax_amount' => 0, 'total' => 0, 'currency' => 'eur',
        'issued_at' => $this->issuedAt,
    ]);

    expect($this->numberer->next('A_', $this->issuedAt))->toBe('A_-2026-0001');
});
