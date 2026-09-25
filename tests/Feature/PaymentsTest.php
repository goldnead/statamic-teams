<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Integrations\Payments\TeamBuyer;
use Goldnead\Teams\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__.'/../Fakes/payments.php';

class PaymentsTest extends TestCase
{
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
        $this->assertSame($team->id, $details['meta']['team_id']);
        $this->assertSame($team->uuid, $details['meta']['team_uuid']);
        $this->assertSame('Domplatz 1', $details['meta']['address']['line1']);
        $this->assertSame('DE123', $details['meta']['vat_id']);
        $this->assertSame(['type' => 'team', 'id' => (string) $team->id], $details['meta']['entitlement_subject']);
        $this->assertSame((string) $anna->id(), $details['meta']['paid_by']);
        $this->assertSame('DE', $details['country']);
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
        Checkout::$started = [];
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

        $this->assertSame([], Checkout::$started);

        Teams::checkout($team, 'chortarif-jahr', $owner);
        $this->assertCount(1, Checkout::$started);
    }

    #[Test]
    public function a_checkout_starts_with_the_team_as_buyer_and_the_team_is_found_back(): void
    {
        Checkout::$started = [];
        $team = Teams::create('Kammerchor', $this->makeUser('owner@example.com'));

        $result = Teams::checkout($team, 'chortarif-jahr', null, 'https://site.test/danke');

        $this->assertSame('https://pay.test/checkout/1', $result->checkoutUrl);
        $this->assertSame('chortarif-jahr', Checkout::$started[0]['products']);
        $this->assertSame($team->uuid, Checkout::$started[0]['details']['meta']['team_uuid']);
        $this->assertTrue(app(TeamBuyer::class)->teamOf($result->payment)->is($team));
    }
}
