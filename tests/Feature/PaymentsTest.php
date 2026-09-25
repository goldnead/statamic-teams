<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\PaymentDetails;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Integrations\Payments\TeamBuyer;
use Goldnead\Teams\Tests\Fakes\CheckoutRecorder;
use Goldnead\Teams\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Against the real goldnead/statamic-payments (dev dependency): the details
 * this addon hands over go through payments' own validation. Only the
 * provider call is replaced ({@see CheckoutRecorder}).
 */
class PaymentsTest extends TestCase
{
    protected CheckoutRecorder $checkout;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkout = new CheckoutRecorder;
        $this->app->instance(Checkout::class, $this->checkout);
    }

    #[Test]
    public function the_buyer_is_the_team_with_its_billing_address(): void
    {
        $anna = $this->makeUser('anna@example.com');
        $team = Teams::create('Kammerchor', $anna);
        Teams::update($team, ['billing' => [
            'company' => 'Kammerchor Köln e.V.', 'email' => 'kasse@kammerchor.test',
            'line1' => 'Domplatz 1', 'postal_code' => '50667', 'city' => 'Köln', 'country' => 'de', 'vat_id' => 'DE123',
        ]]);

        $buyer = Teams::checkoutBuyer($team, $anna);
        $details = Teams::checkoutDetails($team, $anna);

        $this->assertSame(['email' => 'kasse@kammerchor.test', 'name' => 'Kammerchor Köln e.V.', 'country' => 'DE'], $buyer);
        $this->assertTrue($details['for']->is($team));
        $this->assertArrayNotHasKey('entitlement_subject', $details['meta']);
        $this->assertSame($team->id, $details['meta']['team_id']);
        $this->assertSame($team->uuid, $details['meta']['team_uuid']);
        $this->assertSame('Domplatz 1', $details['meta']['address']['line1']);
        $this->assertSame('DE123', $details['meta']['vat_id']);
        $this->assertSame((string) $anna->id(), $details['meta']['paid_by']);
        $this->assertSame('DE', $details['country']);
    }

    #[Test]
    public function payments_accepts_the_details_and_names_the_team_as_subject(): void
    {
        $team = Teams::create('Kammerchor', $this->makeUser('owner@example.com'));
        Teams::update($team, ['billing' => ['company' => 'Kammerchor e.V.', 'line1' => 'Weg 1', 'city' => 'Köln', 'country' => 'DE']]);

        $result = Teams::checkout($team, 'chortarif-jahr', null, 'https://site.test/danke');

        $started = $this->checkout->started[0];
        $this->assertSame(['type' => 'team', 'id' => (string) $team->id], $started['subject']);
        $this->assertSame('chortarif-jahr', $started['products']);
        $this->assertSame($team->uuid, $started['columns']['meta']['team_uuid']);
        $this->assertStringContainsString('Weg 1', (string) $started['columns']['meta']['address']);
        $this->assertTrue(app(TeamBuyer::class)->teamOf($result->payment)->is($team));
    }

    #[Test]
    public function payments_refuses_the_old_meta_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentDetails::from(['meta' => ['entitlement_subject' => ['type' => 'team', 'id' => '1']]]);
    }

    #[Test]
    public function without_a_billing_email_the_payer_gets_the_invoice(): void
    {
        $anna = $this->makeUser('anna@example.com');
        $team = Teams::create('Kammerchor', $this->makeUser('owner@example.com'));

        $this->assertSame('anna@example.com', Teams::checkoutBuyer($team, $anna)['email']);
        $this->assertSame('owner@example.com', Teams::checkoutBuyer($team)['email']);
        $this->assertSame('Kammerchor', Teams::checkoutBuyer($team)['name']);
    }

    #[Test]
    public function only_a_member_allowed_to_manage_billing_may_pay_for_the_team(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $member = $this->makeUser('member@example.com');
        $team = Teams::create('Kammerchor', $owner);
        Teams::addMember($team, $member);

        foreach ([[$member, TeamsException::FORBIDDEN], [$this->makeUser('stranger@example.com'), TeamsException::NOT_MEMBER]] as [$payer, $reason]) {
            try {
                Teams::checkout($team, 'chortarif-jahr', $payer);
                $this->fail('A checkout started for a payer without the right.');
            } catch (TeamsException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }

        $this->assertSame([], $this->checkout->started);

        Teams::checkout($team, 'chortarif-jahr', $owner);
        $this->assertCount(1, $this->checkout->started);
    }

    #[Test]
    public function a_vat_id_check_is_frozen_when_invoices_can_make_one(): void
    {
        $this->app->bind('Goldnead\Invoices\Support\BuyerAdmission', fn () => new class
        {
            public function check(string $vatId): object
            {
                return new class($vatId)
                {
                    public function __construct(private string $vatId) {}

                    public function toArray(): array
                    {
                        return ['vat_id' => $this->vatId, 'status' => 'valid', 'checked_at' => '2026-09-25T10:00:00+00:00', 'service' => 'vies'];
                    }
                };
            }
        });

        $team = Teams::create('Kammerchor', $this->makeUser('owner@example.com'));
        Teams::update($team, ['billing' => ['vat_id' => 'ATU12345678', 'country' => 'AT']]);

        $details = Teams::checkoutDetails($team);

        $this->assertSame('valid', $details['meta']['vat_id_check']['status']);
        $this->assertSame('ATU12345678', $details['meta']['vat_id_check']['vat_id']);
    }

    #[Test]
    public function the_cp_says_whether_a_vat_id_is_checked(): void
    {
        $team = Teams::create('Kammerchor', $this->makeUser('owner@example.com'));

        $this->actingAsCpUser('viewer@example.com', ['view teams'])
            ->get('/cp/teams/'.$team->id)
            ->assertOk()
            ->assertSee('&quot;vatIdChecked&quot;:false', false);
    }

    #[Test]
    public function without_invoices_no_check_is_claimed(): void
    {
        $team = Teams::create('Kammerchor', $this->makeUser('owner@example.com'));
        Teams::update($team, ['billing' => ['vat_id' => 'ATU12345678']]);

        $this->assertArrayNotHasKey('vat_id_check', Teams::checkoutDetails($team)['meta']);
    }
}
