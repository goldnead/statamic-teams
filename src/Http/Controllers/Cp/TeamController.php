<?php

namespace Goldnead\Teams\Http\Controllers\Cp;

use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Integrations\EmailTemplates\MailTemplates;
use Goldnead\Teams\Models\Invitation;
use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\Roles;
use Goldnead\Teams\Support\Setup;
use Goldnead\Teams\Support\Users;
use Goldnead\Teams\Support\Wiring;
use Goldnead\Teams\TeamsManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Statamic\CP\Column;

/**
 * Teams in the Control Panel.
 *
 * Every write goes through the same services the front end and app-api
 * use, as the system (no actor): the Statamic permission `manage teams` is
 * the gate here, not a team role. Nobody is put into a team from the CP
 * without their consent: the CP invites, it does not add.
 */
class TeamController extends Controller
{
    public function __construct(
        protected TeamsManager $teams,
        protected Roles $roles,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view teams');

        if ($setup = Setup::guard(__('teams::messages.nav'))) {
            return $setup;
        }

        $rows = Team::query()
            ->withCount(['members', 'invitations' => fn ($q) => $q->pending()])
            ->orderBy('name')
            ->get()
            ->map(fn (Team $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'type' => $team->type,
                'type_label' => __((string) config("teams.types.{$team->type}", $team->type)),
                'owner' => $team->owner_id ? (Users::name($team->owner_id) ?? $team->owner_id) : null,
                'members' => $team->members_count,
                'invitations' => $team->invitations_count,
                'join_method' => $team->join_method,
                'read_only' => $team->isReadOnly(),
                'created_at' => $team->created_at?->toDateString(),
                'show_url' => cp_route('teams.show', $team->id),
            ])
            ->values()
            ->all();

        return Inertia::render('teams::Teams/Index', [
            'teams' => $rows,
            'columns' => [
                Column::make('name')->label(__('Name'))->sortable(true),
                Column::make('type_label')->label(__('Type'))->sortable(true),
                Column::make('owner')->label(__('teams::cp.owner'))->sortable(true),
                Column::make('members')->label(__('teams::cp.members'))->numeric(true)->sortable(true),
                Column::make('invitations')->label(__('teams::cp.open_invitations'))->numeric(true)->sortable(true),
                Column::make('created_at')->label(__('teams::cp.created'))->sortable(true),
            ],
            'types' => collect((array) config('teams.types', []))->map(fn ($label, $value) => ['value' => $value, 'label' => __((string) $label)])->values()->all(),
            'storeUrl' => cp_route('teams.store'),
            'wiringUrl' => cp_route('teams.wiring'),
            'canManage' => $this->userCan($request, 'manage teams'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeOrFail($request, 'manage teams');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', Rule::in(array_keys((array) config('teams.types', [])))],
            'owner_email' => ['nullable', 'email'],
        ]);

        $owner = null;

        if (! empty($data['owner_email'])) {
            $owner = Users::findByEmail($data['owner_email']);

            if ($owner === null) {
                return back()->withErrors(['owner_email' => __('teams::cp.owner_unknown')]);
            }
        }

        try {
            $team = $this->teams->create($data['name'], $owner, ['type' => $data['type']]);
        } catch (TeamsException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->to(cp_route('teams.show', $team->id))->with('success', __('teams::messages.created'));
    }

    public function show(Request $request, int $team)
    {
        $this->authorizeOrFail($request, 'view teams');

        if ($setup = Setup::guard(__('teams::messages.nav'))) {
            return $setup;
        }

        $record = Team::query()->find($team) ?? abort(404);
        $canManage = $this->userCan($request, 'manage teams');

        $members = $this->teams->members($record)->map(fn (Membership $m) => Users::summary($m->user_id) + [
            'membership_id' => $m->id,
            'role' => $m->role,
            'role_label' => $this->roles->label($m->role, $record),
            'meta' => $m->meta ?? [],
            'joined_at' => $m->joined_at?->toDateString(),
            'update_url' => cp_route('teams.members.update', [$record->id, $m->id]),
            'delete_url' => cp_route('teams.members.destroy', [$record->id, $m->id]),
        ])->values()->all();

        $invitations = $record->invitations()->latest()->limit(100)->get()->map(fn (Invitation $i) => $i->summary() + [
            'role_label' => $this->roles->label($i->role, $record),
            'created_at' => $i->created_at?->toDateString(),
            // Accepted or withdrawn: its end date says nothing any more.
            'expires_on' => in_array($i->status(), [Invitation::STATUS_PENDING, Invitation::STATUS_EXPIRED], true) ? $i->expires_at?->toDateString() : null,
            'resend_url' => cp_route('teams.invitations.resend', [$record->id, $i->id]),
            'delete_url' => cp_route('teams.invitations.destroy', [$record->id, $i->id]),
        ])->values()->all();

        return Inertia::render('teams::Teams/Show', [
            'team' => $record->summary() + [
                'type_label' => __((string) config("teams.types.{$record->type}", $record->type)),
                'owner' => $record->owner_id ? Users::summary($record->owner_id) : null,
                'join_method' => $record->join_method,
                'join_code' => $record->join_code,
                'read_only' => $record->isReadOnly(),
                'is_personal' => $record->isPersonal(),
                'billing' => (object) ($record->billing ?? []),
                'created_at' => $record->created_at?->toDateString(),
            ],
            'members' => $members,
            'memberColumns' => [
                Column::make('name')->label(__('Name'))->sortable(true),
                Column::make('email')->label(__('Email'))->sortable(true),
                Column::make('role_label')->label(__('Role'))->sortable(true),
                Column::make('joined_at')->label(__('teams::cp.joined'))->sortable(true),
            ],
            'invitations' => $invitations,
            'invitationColumns' => [
                Column::make('email')->label(__('Email'))->sortable(true),
                Column::make('role_label')->label(__('Role'))->sortable(true),
                Column::make('status')->label(__('Status'))->sortable(true),
                Column::make('expires_on')->label(__('teams::cp.expires'))->sortable(true),
            ],
            'roles' => collect($this->roles->all($record))->map(fn ($role, $handle) => ['value' => $handle, 'label' => __($role['label'])])->values()->all(),
            'metaValueLabels' => collect((array) config('teams.meta_value_labels', []))
                ->map(fn ($values) => collect((array) $values)->map(fn ($label) => __((string) $label))->all())
                ->all(),
            'metaLabels' => collect($members)
                ->flatMap(fn ($m) => array_keys($m['meta']))
                ->unique()
                ->mapWithKeys(fn ($key) => [$key => __((string) (config("teams.meta_labels.{$key}") ?? Str::ucfirst(str_replace('_', ' ', (string) $key))))])
                ->all(),
            'entitlementSubject' => Team::MORPH_ALIAS.':'.$record->id,
            'urls' => [
                'index' => cp_route('teams.index'),
                'update' => cp_route('teams.update', $record->id),
                'destroy' => cp_route('teams.destroy', $record->id),
                'joinCode' => cp_route('teams.join-code', $record->id),
                'invite' => cp_route('teams.invitations.store', $record->id),
                'wiring' => cp_route('teams.wiring'),
            ],
            'canManage' => $canManage,
        ]);
    }

    public function update(Request $request, int $team): RedirectResponse
    {
        $this->authorizeOrFail($request, 'manage teams');
        $record = Team::query()->find($team) ?? abort(404);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'join_method' => ['sometimes', Rule::in([Team::JOIN_INVITATION_ONLY, Team::JOIN_CODE])],
            'read_only' => ['sometimes', 'boolean'],
            'billing' => ['sometimes', 'array'],
            'billing.*' => ['nullable', 'string', 'max:191'],
            'billing.email' => ['nullable', 'email'],
            'billing.country' => ['nullable', 'string', 'size:2'],
        ]);

        $attributes = collect($data)->only(['name', 'join_method', 'billing'])->all();

        if (array_key_exists('read_only', $data)) {
            $attributes['settings'] = ['read_only' => (bool) $data['read_only']];
        }

        $this->teams->update($record, $attributes);

        return back()->with('success', __('teams::messages.updated'));
    }

    public function destroy(Request $request, int $team): RedirectResponse
    {
        $this->authorizeOrFail($request, 'manage teams');
        $record = Team::query()->find($team) ?? abort(404);

        $this->teams->delete($record);

        return redirect()->to(cp_route('teams.index'))->with('success', __('teams::messages.deleted'));
    }

    public function regenerateJoinCode(Request $request, int $team): RedirectResponse
    {
        $this->authorizeOrFail($request, 'manage teams');
        $record = Team::query()->find($team) ?? abort(404);

        $code = $this->teams->regenerateJoinCode($record);

        return back()->with('success', __('teams::messages.join_code_regenerated', ['code' => $code]));
    }

    public function updateMember(Request $request, int $team, int $membership): RedirectResponse
    {
        $this->authorizeOrFail($request, 'manage teams');
        $record = Team::query()->find($team) ?? abort(404);
        $member = $record->members()->find($membership) ?? abort(404);

        $data = $request->validate(['role' => ['required', 'string', 'max:64']]);

        return $this->attempt(fn () => $this->teams->changeRole($record, $member->user_id, $data['role']), __('teams::messages.role_changed'), 'role');
    }

    public function destroyMember(Request $request, int $team, int $membership): RedirectResponse
    {
        $this->authorizeOrFail($request, 'manage teams');
        $record = Team::query()->find($team) ?? abort(404);
        $member = $record->members()->find($membership) ?? abort(404);

        return $this->attempt(fn () => $this->teams->removeMember($record, $member->user_id), __('teams::messages.member_removed'), 'member');
    }

    public function invite(Request $request, int $team): RedirectResponse
    {
        $this->authorizeOrFail($request, 'manage teams');
        $record = Team::query()->find($team) ?? abort(404);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'role' => ['required', 'string', 'max:64'],
        ]);

        return $this->attempt(fn () => $this->teams->invite($record, $data['email'], $data['role']), __('teams::messages.invited', ['email' => $data['email']]), 'email');
    }

    public function resendInvitation(Request $request, int $team, int $invitation): RedirectResponse
    {
        $this->authorizeOrFail($request, 'manage teams');
        $record = Invitation::query()->where('team_id', $team)->find($invitation) ?? abort(404);

        return $this->attempt(fn () => $this->teams->resendInvitation($record), __('teams::messages.invited', ['email' => $record->email]), 'invitation');
    }

    public function destroyInvitation(Request $request, int $team, int $invitation): RedirectResponse
    {
        $this->authorizeOrFail($request, 'manage teams');
        $record = Invitation::query()->where('team_id', $team)->find($invitation) ?? abort(404);

        return $this->attempt(fn () => $this->teams->revokeInvitation($record), __('teams::messages.invitation_revoked'), 'invitation');
    }

    public function wiring(Request $request, Wiring $wiring)
    {
        $this->authorizeOrFail($request, 'view teams');

        return Inertia::render('teams::Teams/Wiring', $wiring->toArray() + [
            'installTemplatesUrl' => cp_route('teams.mail-templates'),
            'canManage' => $this->userCan($request, 'manage teams'),
        ]);
    }

    public function installMailTemplates(Request $request, MailTemplates $templates): RedirectResponse
    {
        $this->authorizeOrFail($request, 'manage teams');

        $result = $templates->install();

        return back()->with('success', __('teams::messages.templates_installed', ['count' => count($result['created'])]));
    }

    /**
     * A refusal from the service becomes a form error, not a 500.
     */
    protected function attempt(callable $operation, string $success, string $field): RedirectResponse
    {
        try {
            $operation();
        } catch (TeamsException $e) {
            return back()->withErrors([$field => $e->getMessage()]);
        }

        return back()->with('success', $success);
    }
}
