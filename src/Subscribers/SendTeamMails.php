<?php

namespace Goldnead\Teams\Subscribers;

use Goldnead\Teams\Events\InvitationSent;
use Goldnead\Teams\Events\MemberJoined;
use Goldnead\Teams\Events\MemberLeft;
use Goldnead\Teams\Events\MemberRoleChanged;
use Goldnead\Teams\Integrations\EmailTemplates\MailTemplates;
use Goldnead\Teams\Mail\TeamMail;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\Roles;
use Goldnead\Teams\Support\Users;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The mails that belong to team events.
 *
 * A failed mail is logged and swallowed: the invitation exists and can be
 * resent from the CP, whereas an exception here would roll the whole
 * request back into "nothing happened" while the row stays written.
 */
class SendTeamMails
{
    public function __construct(
        protected MailTemplates $templates,
        protected Roles $roles,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(InvitationSent::class, [self::class, 'invitation']);
        $events->listen(MemberJoined::class, [self::class, 'memberJoined']);
        $events->listen(MemberLeft::class, [self::class, 'memberRemoved']);
        $events->listen(MemberRoleChanged::class, [self::class, 'roleChanged']);
    }

    public function invitation(InvitationSent $event): void
    {
        $invitation = $event->invitation;
        $team = $invitation->team;

        if ($team === null || $event->acceptUrl === null) {
            return;
        }

        $this->send('invitation', [$invitation->email], [
            'team' => ['name' => $team->name],
            // Invited from the CP there is no inviting member; the owner is
            // who the invitation comes from as far as the reader is concerned.
            'inviter' => ['name' => Users::name($invitation->invited_by) ?? Users::name($team->owner_id) ?? $team->name],
            'role' => $this->roles->label($invitation->role, $team),
            'email' => $invitation->email,
            'accept_url' => $event->acceptUrl,
            'expires_at' => $invitation->expires_at?->locale(app()->getLocale())->isoFormat('LL') ?? '',
        ]);
    }

    /**
     * To the owners, not to the one who joined, and not when a team is
     * created: the owner joining their own team is not news to them.
     */
    public function memberJoined(MemberJoined $event): void
    {
        if ($event->via === 'created') {
            return;
        }

        $recipients = $this->ownerEmails($event->team, except: $event->membership->user_id);

        $this->send('member_joined', $recipients, [
            'team' => ['name' => $event->team->name],
            'member' => [
                'name' => Users::name($event->membership->user_id) ?? '',
                'email' => Users::email($event->membership->user_id) ?? '',
            ],
            'role' => $this->roles->label($event->membership->role, $event->team),
        ]);
    }

    /** Only to somebody removed by others. Who leaves knows it. */
    public function memberRemoved(MemberLeft $event): void
    {
        if ($event->reason !== 'removed') {
            return;
        }

        $this->send('member_removed', array_filter([Users::email($event->userId)]), [
            'team' => ['name' => $event->team->name],
            'member' => ['name' => Users::name($event->userId) ?? ''],
        ]);
    }

    public function roleChanged(MemberRoleChanged $event): void
    {
        $this->send('role_changed', array_filter([Users::email($event->membership->user_id)]), [
            'team' => ['name' => $event->team->name],
            'member' => ['name' => Users::name($event->membership->user_id) ?? ''],
            'role' => [
                'from' => $this->roles->label($event->from, $event->team),
                'to' => $this->roles->label($event->to, $event->team),
            ],
        ]);
    }

    /**
     * @param  list<string>  $recipients
     * @param  array<string, mixed>  $data
     */
    protected function send(string $mail, array $recipients, array $data): void
    {
        if (! $this->templates->enabled($mail) || $recipients === []) {
            return;
        }

        try {
            $rendered = $this->templates->render($mail, $data);

            foreach (array_unique($recipients) as $recipient) {
                Mail::to($recipient)->send(new TeamMail($mail, $rendered['subject'], $rendered['html']));
            }
        } catch (Throwable $e) {
            Log::warning('statamic-teams: the mail could not be sent.', [
                'mail' => $mail,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** @return list<string> */
    protected function ownerEmails(Team $team, ?string $except = null): array
    {
        return $team->members()
            ->where('role', $this->roles->ownerRole())
            ->when($except !== null, fn ($query) => $query->where('user_id', '!=', $except))
            ->pluck('user_id')
            ->map(fn ($id) => Users::email((string) $id))
            ->filter()
            ->values()
            ->all();
    }
}
