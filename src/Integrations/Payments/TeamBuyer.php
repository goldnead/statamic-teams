<?php

namespace Goldnead\Teams\Integrations\Payments;

use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\Users;
use Throwable;

/**
 * A team as the buyer in goldnead/statamic-payments.
 *
 * Payments knows no customer model: the buyer is email, name and country on
 * the payment row, the billing address lives in `payments.meta.address`.
 * This class fills exactly those fields from the team, so the invoice goes
 * to the choir and not to the person who clicked. The team itself goes as
 * `$details['for']` (payments ≥ eb8bfb6), which makes it the subject of the
 * grant; `meta.team_id` and `meta.team_uuid` let the site find it again.
 *
 * Billing keys on the team: `name`, `company`, `email`, `line1`, `line2`,
 * `postal_code`, `city`, `country` (ISO 3166-1 alpha-2), `vat_id`.
 */
class TeamBuyer
{
    public const CHECKOUT = 'Goldnead\StatamicPayments\Support\Checkout';

    public const ADMISSION = 'Goldnead\Invoices\Support\BuyerAdmission';

    public function available(): bool
    {
        return class_exists(self::CHECKOUT);
    }

    /**
     * The `$buyer` argument of `Checkout::start()`.
     *
     * The email falls back to the person paying, then to the owner: an
     * invoice needs an address, and the team's own may not be set yet.
     *
     * @return array{email: string|null, name: string, country?: string}
     */
    public function buyer(Team $team, mixed $payer = null): array
    {
        $billing = $team->billing ?? [];

        $buyer = [
            'email' => $this->stringOrNull($billing['email'] ?? null)
                ?? Users::email($payer)
                ?? Users::email($team->owner_id),
            'name' => $this->stringOrNull($billing['company'] ?? null)
                ?? $this->stringOrNull($billing['name'] ?? null)
                ?? $team->name,
        ];

        if (($country = $this->stringOrNull($billing['country'] ?? null)) !== null) {
            $buyer['country'] = strtoupper($country);
        }

        return $buyer;
    }

    /**
     * The `$details` argument of `Checkout::start()`.
     *
     * @return array{for: Team, meta: array<string, mixed>, country?: string, country_source?: string}
     */
    public function details(Team $team, mixed $payer = null): array
    {
        $billing = $team->billing ?? [];

        $address = array_filter([
            'company' => $billing['company'] ?? null,
            'name' => $billing['name'] ?? null,
            'line1' => $billing['line1'] ?? null,
            'line2' => $billing['line2'] ?? null,
            'postal_code' => $billing['postal_code'] ?? null,
            'city' => $billing['city'] ?? null,
            'country' => isset($billing['country']) ? strtoupper((string) $billing['country']) : null,
        ], fn ($value) => $value !== null && $value !== '');

        $vatId = $this->stringOrNull($billing['vat_id'] ?? null);

        $meta = array_filter([
            'team_id' => (int) $team->getKey(),
            'team_uuid' => (string) $team->uuid,
            'paid_by' => Users::key($payer),
            'address' => $address === [] ? null : $address,
            'vat_id' => $vatId,
            'vat_id_check' => $vatId === null ? null : $this->vatIdCheck($vatId),
        ], fn ($value) => $value !== null && $value !== '');

        // Who the purchase is for goes through payments' typed parameter, not
        // meta: payments checks it at the till and stores the subject itself
        // (`meta.entitlement_subject` is reserved there and refused).
        $details = ['for' => $team, 'meta' => $meta];

        if (isset($address['country'])) {
            $details['country'] = $address['country'];
            $details['country_source'] = 'billing_address';
        }

        return $details;
    }

    /**
     * Start a checkout with the team as buyer. Returns payments'
     * `CheckoutResult` (`->checkoutUrl`, `->payment`) or null when payments
     * is not installed or refused.
     *
     * @param  string|list<string>  $products
     */
    public function checkout(Team $team, string|array $products, mixed $payer = null, ?string $returnUrl = null): ?object
    {
        if (! $this->available()) {
            return null;
        }

        $checkout = app(self::CHECKOUT);

        return $checkout->start($products, $this->buyer($team, $payer), $returnUrl, null, $this->details($team, $payer));
    }

    /**
     * The team a payment was made for, from its meta. Null for a payment
     * by a person.
     */
    public function teamOf(object $payment): ?Team
    {
        $meta = (array) ($payment->meta ?? []);

        if (isset($meta['team_uuid'])) {
            return Team::query()->where('uuid', (string) $meta['team_uuid'])->first();
        }

        return isset($meta['team_id']) ? Team::query()->find((int) $meta['team_id']) : null;
    }

    /**
     * The VAT ID's check, frozen onto the payment when statamic-invoices can
     * make one (VIES by default, with its cache and timeout). The invoice
     * reads `meta.vat_id_check` and prints what was confirmed and when.
     * Without invoices nothing is claimed: the number travels unchecked, and
     * the CP says so next to the field.
     *
     * @return array<string, mixed>|null
     */
    /** Whether a VAT ID given here is checked at the checkout. */
    public function checksVatIds(): bool
    {
        return class_exists(self::ADMISSION) || app()->bound(self::ADMISSION);
    }

    protected function vatIdCheck(string $vatId): ?array
    {
        if (! $this->checksVatIds()) {
            return null;
        }

        try {
            $check = app(self::ADMISSION)->check($vatId);

            return is_object($check) && method_exists($check, 'toArray') ? (array) $check->toArray() : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
