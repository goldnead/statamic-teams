<?php

namespace Goldnead\Teams\Tags;

use Goldnead\Teams\Models\Invitation;
use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\Roles;
use Goldnead\Teams\Support\Users;
use Goldnead\Teams\TeamsManager;
use Statamic\Tags\Concerns\GetsFormSession;
use Statamic\Tags\Concerns\RendersForms;
use Statamic\Tags\Tags;

/**
 * Antlers tags for the front end.
 *
 * Reading:   {{ teams }} … {{ /teams }}          the signed-in user's teams
 *            {{ teams:current }} … {{ /teams:current }}
 *            {{ teams:members }} … {{ /teams:members }}     team="…" optional
 *            {{ teams:invitations }} … {{ /teams:invitations }}  open ones of a team
 *            {{ teams:my_invitations }} … {{ /teams:my_invitations }}
 *            {{ teams:roles }} … {{ /teams:roles }}
 *            {{ teams:can do="invite members" }} … {{ /teams:can }}
 * Forms:     {{ teams:switch_form }}, {{ teams:create_form }}, {{ teams:join_form }},
 *            {{ teams:invite_form }}, {{ teams:leave_form }}, {{ teams:accept_form token="…" }}
 *            each renders <form> with CSRF around its content; `redirect="/path"`
 *            sets where to go afterwards. Errors and success: {{ teams:form_session }}.
 *
 * Without `team`, the tags work on the current team.
 */
class Teams extends Tags
{
    use GetsFormSession, RendersForms;

    protected static $handle = 'teams';

    /** The params this tag reads itself; everything else becomes a form attribute. */
    protected const KNOWN = ['team', 'redirect', 'token', 'do', 'as'];

    public function index(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $current = $this->manager()->current($user);

        return $this->manager()->teamsOf($user)
            ->map(fn (Team $team) => $this->teamData($team, $user) + ['is_current' => $current !== null && $current->is($team)])
            ->values()
            ->all();
    }

    public function current(): array
    {
        $user = auth()->user();
        $team = $this->manager()->current($user);

        return $team === null || $user === null ? [] : $this->teamData($team, $user);
    }

    public function members(): array
    {
        $user = auth()->user();
        $team = $this->team();

        if ($team === null || $user === null || ! $team->hasMember($user)) {
            return [];
        }

        $canRemove = $this->roleService()->can($user, $team, 'remove members');
        $canChangeRoles = $this->roleService()->can($user, $team, 'change roles');
        $self = Users::key($user);

        return $this->manager()->members($team)
            ->map(function (Membership $membership) use ($team, $canRemove, $canChangeRoles, $self) {
                $isSelf = $membership->user_id === $self;

                return Users::summary($membership->user_id) + [
                    'role' => $membership->role,
                    'role_label' => $this->roleService()->label($membership->role, $team),
                    'meta' => $membership->meta ?? [],
                    'joined_at' => $membership->joined_at,
                    'is_self' => $isSelf,
                    'is_owner' => $membership->role === $this->roleService()->ownerRole(),
                    'remove_url' => $canRemove && ! $isSelf ? route('statamic.teams.forms.members.remove', [$team->id, $membership->user_id]) : null,
                    'role_url' => $canChangeRoles && ! $isSelf ? route('statamic.teams.forms.members.role', [$team->id, $membership->user_id]) : null,
                ];
            })
            ->values()
            ->all();
    }

    public function invitations(): array
    {
        $user = auth()->user();
        $team = $this->team();

        if ($team === null || $user === null || ! $this->roleService()->can($user, $team, 'invite members')) {
            return [];
        }

        return $this->manager()->pendingInvitationsOf($team)
            ->map(fn (Invitation $invitation) => $invitation->summary() + [
                'role_label' => $this->roleService()->label($invitation->role, $team),
                'revoke_url' => route('statamic.teams.forms.invitations.revoke', [$team->id, $invitation->id]),
            ])
            ->values()
            ->all();
    }

    /**
     * Open invitations addressed to the signed-in user. The link is not
     * in here (only its hash is stored); accepting from this list needs the
     * mail. The list says where to look.
     */
    public function myInvitations(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        return $this->manager()->pendingInvitationsFor($user)
            ->map(fn (Invitation $invitation) => $invitation->summary() + [
                'team_name' => $invitation->team?->name,
                'role_label' => $this->roleService()->label($invitation->role, $invitation->team),
            ])
            ->values()
            ->all();
    }

    public function roles(): array
    {
        return collect($this->roleService()->all($this->team()))
            ->map(fn (array $role, string $handle) => ['handle' => $handle, 'label' => $role['label']])
            ->values()
            ->all();
    }

    /**
     * {{ teams:can do="invite members" }} … {{ /teams:can }}; as a single
     * tag it returns true or false.
     */
    public function can(): string|bool
    {
        $user = auth()->user();
        $team = $this->team();
        $allowed = $user !== null && $team !== null && $this->roleService()->can($user, $team, (string) $this->params->get('do', ''));

        if ($this->isPair) {
            return $allowed ? (string) $this->parse() : '';
        }

        return $allowed;
    }

    public function formSession(): array
    {
        return $this->getFormSession('teams');
    }

    public function switchForm(): string
    {
        return $this->form(route('statamic.teams.forms.switch'), ['teams' => $this->index()]);
    }

    public function createForm(): string
    {
        return $this->form(route('statamic.teams.forms.create'));
    }

    public function joinForm(): string
    {
        return $this->form(route('statamic.teams.forms.join'));
    }

    public function inviteForm(): string
    {
        $team = $this->team();

        if ($team === null) {
            return '';
        }

        return $this->form(route('statamic.teams.forms.invite', $team->id), [
            'team' => $team->summary(),
            'roles' => collect($this->roleService()->all($team))
                ->except($this->roleService()->ownerRole())
                ->map(fn (array $role, string $handle) => ['handle' => $handle, 'label' => $role['label']])
                ->values()
                ->all(),
        ]);
    }

    public function leaveForm(): string
    {
        $team = $this->team();

        return $team === null ? '' : $this->form(route('statamic.teams.forms.leave', $team->id), ['team' => $team->summary()]);
    }

    public function acceptForm(): string
    {
        $token = (string) $this->params->get('token', '');

        return $token === '' ? '' : $this->form(route('statamic.teams.forms.accept', $token));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function form(string $action, array $data = []): string
    {
        $html = $this->formOpen($action, 'POST', self::KNOWN);

        if (($redirect = $this->params->get('redirect')) !== null) {
            $html .= '<input type="hidden" name="_redirect" value="'.e((string) $redirect).'" />';
        }

        $html .= $this->parse(array_merge($this->getFormSession('teams'), $data));

        return $html.$this->formClose();
    }

    /**
     * The team a tag works on: `team="…"` (id or uuid), else the current one.
     */
    protected function team(): ?Team
    {
        $param = $this->params->get('team');

        if ($param instanceof Team) {
            return $param;
        }

        if (is_array($param) && isset($param['id'])) {
            $param = $param['id'];
        }

        if ($param !== null && $param !== '') {
            return $this->manager()->find(is_scalar($param) ? (string) $param : null);
        }

        return $this->manager()->current(auth()->user());
    }

    /**
     * @return array<string, mixed>
     */
    protected function teamData(Team $team, mixed $user): array
    {
        $role = $team->roleOf($user);

        return $team->summary() + [
            'role' => $role,
            'role_label' => $role === null ? null : $this->roleService()->label($role, $team),
            'is_personal' => $team->isPersonal(),
            'is_read_only' => $team->isReadOnly(),
            'member_count' => $team->members()->count(),
            'join_code' => $role !== null && $this->roleService()->can($user, $team, 'invite members') && $team->allowsJoinCode() ? $team->join_code : null,
        ];
    }

    protected function manager(): TeamsManager
    {
        return app(TeamsManager::class);
    }

    protected function roleService(): Roles
    {
        return app(Roles::class);
    }
}
