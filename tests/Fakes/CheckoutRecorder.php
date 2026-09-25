<?php

namespace Goldnead\Teams\Tests\Fakes;

use Goldnead\StatamicPayments\Support\PaymentDetails;
use Goldnead\StatamicPayments\Support\PurchaseSubject;

/**
 * Stands in for payments' `Checkout` in the container, with its signature,
 * so no payment provider is called. What it receives goes through payments'
 * own `PaymentDetails::from()` and `PurchaseSubject::fromCaller()` — the
 * real validation, including the refusal of a reserved meta key — and is
 * recorded.
 */
class CheckoutRecorder
{
    /** @var list<array<string, mixed>> */
    public array $started = [];

    /**
     * @param  string|list<string>  $products
     * @param  array<string, mixed>  $buyer
     * @param  array<string, mixed>  $details
     */
    public function start(string|array $products, array $buyer = [], ?string $returnUrl = null, mixed $discount = null, array $details = []): object
    {
        $validated = PaymentDetails::from($details);
        $subject = PurchaseSubject::fromCaller($details['for'] ?? null);
        $columns = $validated->onto([]);

        $this->started[] = compact('products', 'buyer', 'returnUrl', 'details', 'subject', 'columns');

        return (object) ['checkoutUrl' => 'https://pay.test/checkout/1', 'payment' => (object) ['meta' => $columns['meta'] ?? []]];
    }
}
