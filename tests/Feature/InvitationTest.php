<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Events\InvitationAccepted;
use Goldnead\Teams\Events\InvitationRevoked;
use Goldnead\Teams\Events\InvitationSent;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Mail\TeamMail;
use Goldnead\Teams\Models\Invitation;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

class InvitationTest extends TestCase
{
    #[Test]
    public function an_invitation_stores_only_the_hash_and_mails_the_link(): void
    {
        Mail::fake();
        $team = Teams::create('Kammerchor', $this->makeUser('owner@example.com', 'Olga'));

        $issued = Teams::invite($team, 'Bob@Example.com', 'member');

        $this->assertSame('bob@example.com', $issued->invitation->email);
        $this->assertSame(hash('sha256', $issued->token), $issued->invitation->token_hash);
        $this->assertStringNotContainsString($issued->token, json_encode($issued->invitation->toArray()));
        $this->assertStringContainsString($issued->token, $issued->url);

        Mail::assertSent(TeamMail::class, function (TeamMail $mail) use ($issued) {
            return $mail->mail === 'invitation'
                && $mail->hasTo('bob@example.com')
                && str_contains($mail->renderedHtml, $issued->url)
                && str_contains($mail->renderedHtml, 'Kammerchor')
                && str_contains($mail->renderedHtml, 'Olga')
                && str_contains($mail->renderedSubject, 'Kammerchor');
        });
    }

    #[Test]
    public function the_event_payload_carries_no_token(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        Event::fake([InvitationSent::class]);

        $issued = Teams::invite($team, 'bob@example.com');

        Event::assertDispatched(InvitationSent::class, function (InvitationSent $e) use ($issued) {
            $json = json_encode($e->payload());

            return ! str_contains($json, $issued->token) && ! str_contains($json, $issued->invitation->token_hash);
        });
    }

    #[Test]
    public function accepting_makes_a_member_with_the_invited_role_and_meta(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        $issued = Teams::invite($team, 'bob@example.com', 'admin', ['voice_part' => 'bass']);
        Event::fake([InvitationAccepted::class]);

        $membership = Teams::acceptInvitation($issued->token, $bob);

        $this->assertSame('admin', $membership->role);
        $this->assertSame(['voice_part' => 'bass'], $membership->meta);
        $this->assertSame(Invitation::STATUS_ACCEPTED, $issued->invitation->fresh()->status());
        Event::assertDispatched(InvitationAccepted::class);
    }

    #[Test]
    public function an_expired_invitation_is_refused(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        $issued = Teams::invite($team, 'bob@example.com');

        $this->travel(8)->days();

        $this->expectExceptionObject(TeamsException::because(TeamsException::INVITATION_EXPIRED));
        Teams::acceptInvitation($issued->token, $bob);
    }

    #[Test]
    public function a_used_invitation_is_refused_the_second_time(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        $issued = Teams::invite($team, 'bob@example.com');
        Teams::acceptInvitation($issued->token, $bob);

        $this->expectExceptionObject(TeamsException::because(TeamsException::INVITATION_USED));
        Teams::acceptInvitation($issued->token, $bob);
    }

    #[Test]
    public function a_revoked_invitation_is_refused(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $issued = Teams::invite($team, 'bob@example.com');
        Event::fake([InvitationRevoked::class]);

        Teams::revokeInvitation($issued->invitation);
        Event::assertDispatched(InvitationRevoked::class);

        $this->expectExceptionObject(TeamsException::because(TeamsException::INVITATION_REVOKED));
        Teams::acceptInvitation($issued->token, $this->makeUser('bob@example.com'));
    }

    #[Test]
    public function an_invitation_is_bound_to_its_address(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $issued = Teams::invite($team, 'bob@example.com');

        $this->expectExceptionObject(TeamsException::because(TeamsException::INVITATION_WRONG_EMAIL));
        Teams::acceptInvitation($issued->token, $this->makeUser('mallory@example.com'));
    }

    #[Test]
    public function inviting_again_replaces_the_link(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        $first = Teams::invite($team, 'bob@example.com');
        $second = Teams::invite($team, 'bob@example.com', 'admin');

        $this->assertSame($first->invitation->id, $second->invitation->id);
        $this->assertSame(1, Invitation::query()->count());

        try {
            Teams::acceptInvitation($first->token, $bob);
            $this->fail('The old link still worked.');
        } catch (TeamsException $e) {
            $this->assertSame(TeamsException::INVITATION_NOT_FOUND, $e->reason);
        }

        $this->assertSame('admin', Teams::acceptInvitation($second->token, $bob)->role);
    }

    #[Test]
    public function a_member_is_not_invited_again(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $team = Teams::create('Chor', $owner);

        $this->expectExceptionObject(TeamsException::because(TeamsException::ALREADY_MEMBER));
        Teams::invite($team, 'owner@example.com');
    }

    #[Test]
    public function inviting_needs_the_permission_when_an_actor_is_given(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($team, $bob);

        try {
            Teams::invite($team, 'eve@example.com', null, [], $bob);
            $this->fail('A plain member invited somebody.');
        } catch (TeamsException $e) {
            $this->assertSame(TeamsException::FORBIDDEN, $e->reason);
        }

        $this->expectExceptionObject(TeamsException::because(TeamsException::NOT_MEMBER));
        Teams::invite($team, 'eve@example.com', null, [], $this->makeUser('stranger@example.com'));
    }

    #[Test]
    public function pending_invitations_are_listed_for_the_invited_address(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::invite($team, 'bob@example.com');
        $revoked = Teams::invite(Teams::create('Anderer'), 'bob@example.com');
        Teams::revokeInvitation($revoked->invitation);

        $pending = Teams::pendingInvitationsFor($bob);

        $this->assertCount(1, $pending);
        $this->assertSame('Chor', $pending->first()->team->name);
    }

    #[Test]
    public function the_mail_can_be_switched_off(): void
    {
        Mail::fake();
        config(['teams.mail.invitation.enabled' => false]);

        Teams::invite(Teams::create('Chor'), 'bob@example.com');

        Mail::assertNothingSent();
    }

    #[Test]
    public function owners_hear_about_a_new_member_but_not_about_themselves(): void
    {
        Mail::fake();
        $owner = $this->makeUser('owner@example.com');
        $team = Teams::create('Chor', $owner);
        Mail::assertNotSent(TeamMail::class, fn (TeamMail $m) => $m->mail === 'member_joined');

        Teams::addMember($team, $this->makeUser('bob@example.com', 'Bob'));

        Mail::assertSent(TeamMail::class, fn (TeamMail $m) => $m->mail === 'member_joined'
            && $m->hasTo('owner@example.com')
            && str_contains($m->renderedHtml, 'Bob'));
    }
}
