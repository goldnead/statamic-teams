<?php

namespace Goldnead\Teams\Http\Controllers\Cp;

use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Services\RoleService;
use Goldnead\Teams\Support\GlobalRoleStore;
use Goldnead\Teams\Support\Permissions;
use Goldnead\Teams\Support\Roles;
use Goldnead\Teams\Support\Setup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Statamic\CP\Column;
use Statamic\CP\PublishForm;
use Statamic\Facades\Blueprint;
use Statamic\Fields\Blueprint as BlueprintInstance;

/**
 * Roles in the Control Panel: the global ones (page "Roles") and those of
 * one team (panel on the team page).
 *
 * Gated by the Statamic permission `manage team roles`, on the route and
 * here. `manage teams` is not enough: moving people between roles and
 * deciding what a role may do are two different trusts. Every write goes
 * through {@see RoleService} as the system, so its rules (wildcard, owner
 * and default role, roles in use) hold here exactly as for app-api.
 */
class RoleController extends Controller
{
    public const PERMISSION = 'manage team roles';

    public function __construct(
        protected RoleService $service,
        protected Roles $roles,
        protected Permissions $permissions,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, self::PERMISSION);

        if ($setup = Setup::guard(__('teams::messages.nav_roles'))) {
            return $setup;
        }

        $permissions = $this->permissions->all();
        $handles = array_keys($permissions);
        $configured = $this->roles->configured();
        $rows = [];

        foreach ($this->roles->global() as $handle => $role) {
            $rows[] = $this->row($handle, $role, $handles) + [
                'removed' => false,
                'usage' => $this->service->usage($handle),
                'resettable' => $role['source'] === 'customised',
                'edit_url' => cp_route('teams.roles.edit', $handle),
                'reset_url' => cp_route('teams.roles.reset', $handle),
                'delete_url' => cp_route('teams.roles.destroy', $handle),
            ];
        }

        // Deleted default roles stay visible, so they can be brought back.
        foreach (app(GlobalRoleStore::class)->rows() as $handle => $stored) {
            if ($stored->removed && isset($configured[$handle])) {
                $rows[] = $this->row($handle, $configured[$handle] + ['source' => 'removed'], $handles) + [
                    'removed' => true,
                    'usage' => ['members' => 0, 'invitations' => 0],
                    'resettable' => true,
                    'edit_url' => null,
                    'reset_url' => cp_route('teams.roles.reset', $handle),
                    'delete_url' => null,
                ];
            }
        }

        $columns = [
            Column::make('title')->label(__('Name'))->sortable(true),
            Column::make('handle')->label(__('Handle'))->sortable(true)->visible(false),
            // Before the matrix: with many permissions the table scrolls
            // sideways, and who holds a role is what decides a deletion.
            Column::make('members')->label(__('teams::cp.members'))->numeric(true)->sortable(true),
        ];

        foreach (array_values($permissions) as $index => $label) {
            $columns[] = Column::make('perm_'.$index)->label($label)->sortable(false);
        }

        return Inertia::render('teams::Roles/Index', [
            'roles' => $rows,
            'columns' => $columns,
            'permissions' => collect($permissions)->map(fn ($label, $handle) => ['value' => $handle, 'label' => $label])->values()->all(),
            'ownerRole' => $this->roles->ownerRole(),
            'defaultRole' => $this->roles->defaultRole(),
            'createUrl' => cp_route('teams.roles.create'),
        ]);
    }

    public function create(Request $request): PublishForm
    {
        $this->authorizeOrFail($request, self::PERMISSION);

        return PublishForm::make($this->blueprint(creating: true, owner: false))
            ->title(__('teams::cp.create_role'))
            ->icon('users')
            ->submittingTo(cp_route('teams.roles.store'), 'POST');
    }

    /** @return array{saved: bool, redirect: string} */
    public function store(Request $request): array
    {
        $this->authorizeOrFail($request, self::PERMISSION);

        $values = PublishForm::make($this->blueprint(creating: true, owner: false))->submit($request->all());

        $this->orFieldError(fn () => $this->service->create(
            (string) $values['handle'],
            (string) $values['title'],
            array_values((array) ($values['permissions'] ?? [])),
        ));

        // No flash: the publish form toasts "Saved" itself, a second toast
        // after the redirect would say the same twice.
        return ['saved' => true, 'redirect' => cp_route('teams.roles.index')];
    }

    public function edit(Request $request, string $role): PublishForm
    {
        $this->authorizeOrFail($request, self::PERMISSION);

        $found = $this->findGlobal($role);
        $owner = $role === $this->roles->ownerRole();

        return PublishForm::make($this->blueprint(creating: false, owner: $owner))
            ->title($found['label'])
            ->icon('users')
            ->values([
                'title' => $found['label'],
                'handle' => $role,
                'permissions' => $owner ? [] : $found['permissions'],
            ])
            ->submittingTo(cp_route('teams.roles.update', $role));
    }

    /** @return array{saved: bool, redirect: string} */
    public function update(Request $request, string $role): array
    {
        $this->authorizeOrFail($request, self::PERMISSION);

        $this->findGlobal($role);
        $owner = $role === $this->roles->ownerRole();
        $values = PublishForm::make($this->blueprint(creating: false, owner: $owner))->submit($request->all());

        $attributes = ['label' => (string) $values['title']];

        if (! $owner) {
            $attributes['permissions'] = array_values((array) ($values['permissions'] ?? []));
        } elseif (is_array($request->input('permissions')) && $request->input('permissions') !== [] && $request->input('permissions') !== ['*']) {
            // The form does not offer it; a hand-made request is refused
            // like any other caller.
            $attributes['permissions'] = (array) $request->input('permissions');
        }

        $this->orFieldError(fn () => $this->service->update($role, $attributes));

        return ['saved' => true, 'redirect' => cp_route('teams.roles.index')];
    }

    public function destroy(Request $request, string $role): RedirectResponse
    {
        $this->authorizeOrFail($request, self::PERMISSION);

        $to = $request->input('reassign_to');

        return $this->attempt(
            fn () => $this->service->delete($role, null, is_string($to) && $to !== '' ? $to : null),
        );
    }

    public function reset(Request $request, string $role): RedirectResponse
    {
        $this->authorizeOrFail($request, self::PERMISSION);

        try {
            $this->service->reset($role);
        } catch (TeamsException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return back()->with('success', __('teams::messages.role_reset'));
    }

    // Roles of one team ------------------------------------------------------

    public function storeForTeam(Request $request, int $team): RedirectResponse
    {
        $this->authorizeOrFail($request, self::PERMISSION);
        $record = Team::query()->find($team) ?? abort(404);

        $data = $request->validate([
            'handle' => ['required', 'string', 'max:64'],
            'label' => ['required', 'string', 'max:191'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        try {
            $this->service->create($data['handle'], $data['label'], array_values($data['permissions'] ?? []), $record);
        } catch (TeamsException $e) {
            // Creating, "protected" can only mean the owner handle.
            $field = $e->reason === TeamsException::ROLE_PROTECTED ? 'handle' : $this->fieldFor($e);

            return back()->withErrors([$field => $e->getMessage()]);
        }

        return back()->with('success', __('teams::messages.role_created'));
    }

    public function updateForTeam(Request $request, int $team, string $role): RedirectResponse
    {
        $this->authorizeOrFail($request, self::PERMISSION);
        $record = Team::query()->find($team) ?? abort(404);

        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:191'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ]);

        try {
            $this->service->update($role, $data, $record);
        } catch (TeamsException $e) {
            return back()->withErrors([$this->fieldFor($e) => $e->getMessage()]);
        }

        return back()->with('success', __('teams::messages.role_updated'));
    }

    public function destroyForTeam(Request $request, int $team, string $role): RedirectResponse
    {
        $this->authorizeOrFail($request, self::PERMISSION);
        $record = Team::query()->find($team) ?? abort(404);

        $to = $request->input('reassign_to');

        return $this->attempt(
            fn () => $this->service->delete($role, $record, is_string($to) && $to !== '' ? $to : null),
        );
    }

    // Helpers ---------------------------------------------------------------

    /**
     * One role as a listing row: its permissions as `perm_<index>` flags,
     * one per column of the matrix.
     *
     * @param  array<string, mixed>  $role
     * @param  list<string>  $handles
     * @return array<string, mixed>
     */
    protected function row(string $handle, array $role, array $handles): array
    {
        $held = (array) ($role['permissions'] ?? []);
        $all = in_array('*', $held, true);
        $row = [
            'id' => $handle,
            'handle' => $handle,
            'title' => (string) $role['label'],
            'source' => (string) ($role['source'] ?? 'config'),
            'all' => $all,
            'protected' => $handle === $this->roles->ownerRole() || $handle === $this->roles->defaultRole(),
            'permissions' => array_values($held),
        ];

        foreach ($handles as $index => $permission) {
            $row['perm_'.$index] = $all || in_array($permission, $held, true);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    protected function findGlobal(string $role): array
    {
        try {
            return $this->service->find($role);
        } catch (TeamsException) {
            abort(404);
        }
    }

    protected function blueprint(bool $creating, bool $owner): BlueprintInstance
    {
        $fields = [
            ['handle' => 'title', 'field' => [
                'type' => 'text',
                'display' => __('Name'),
                'validate' => ['required', 'max:191'],
                'width' => 50,
            ]],
            ['handle' => 'handle', 'field' => [
                'type' => 'slug',
                'display' => __('Handle'),
                'from' => 'title',
                'separator' => '_',
                'generate' => $creating,
                'read_only' => ! $creating,
                'instructions' => $creating ? __('teams::cp.role_handle_instructions') : __('teams::cp.role_handle_fixed'),
                'validate' => $creating ? ['required', 'max:64'] : [],
                'width' => 50,
            ]],
        ];

        $fields[] = $owner
            ? ['handle' => 'permissions', 'field' => [
                'type' => 'section',
                'display' => __('teams::cp.permissions'),
                'instructions' => __('teams::cp.owner_permissions'),
            ]]
            : ['handle' => 'permissions', 'field' => [
                'type' => 'checkboxes',
                'display' => __('teams::cp.permissions'),
                'instructions' => __('teams::cp.permissions_instructions'),
                'options' => $this->permissions->all(),
            ]];

        return Blueprint::make()->setContents(['tabs' => [
            'main' => ['sections' => [['fields' => $fields]]],
        ]]);
    }

    /**
     * A refusal as a field error in the publish form (422), where the field
     * shows it.
     */
    protected function orFieldError(callable $operation): void
    {
        try {
            $operation();
        } catch (TeamsException $e) {
            throw ValidationException::withMessages([$this->fieldFor($e, 'title') => $e->getMessage()]);
        }
    }

    protected function fieldFor(TeamsException $e, string $fallback = 'role'): string
    {
        return match ($e->reason) {
            TeamsException::INVALID_ROLE_HANDLE, TeamsException::ROLE_EXISTS, TeamsException::ROLE_HANDLE_IN_TEAMS => 'handle',
            TeamsException::WILDCARD, TeamsException::UNKNOWN_PERMISSION, TeamsException::ROLE_PROTECTED => 'permissions',
            default => $fallback,
        };
    }

    protected function attempt(callable $delete): RedirectResponse
    {
        try {
            $moved = (int) $delete();
        } catch (TeamsException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return back()->with('success', $moved > 0
            ? __('teams::messages.role_deleted_moved', ['count' => $moved])
            : __('teams::messages.role_deleted'));
    }
}
