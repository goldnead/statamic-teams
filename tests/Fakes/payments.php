<?php

/*
 * Stand-in for goldnead/statamic-payments' `Support\Checkout::start()`, with
 * the real signature. Records the call and returns an object shaped like
 * `CheckoutResult`.
 */

namespace Goldnead\StatamicPayments\Support {
    if (! class_exists(Checkout::class)) {
        class Checkout
        {
            /** @var list<array<string, mixed>> */
            public static array $started = [];

            /**
             * @param  string|list<string>  $products
             * @param  array<string, mixed>  $buyer
             * @param  array<string, mixed>  $details
             */
            public function start(string|array $products, array $buyer = [], ?string $returnUrl = null, mixed $discount = null, array $details = []): object
            {
                static::$started[] = compact('products', 'buyer', 'returnUrl', 'details');

                return (object) ['checkoutUrl' => 'https://pay.test/checkout/1', 'payment' => (object) ['meta' => $details['meta'] ?? []]];
            }
        }
    }
}
