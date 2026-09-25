<?php

namespace Goldnead\Teams\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

abstract class Controller extends BaseController
{
    /**
     * Through Laravel's Gate (`$user->can()`): Statamic's `Gate::after`
     * resolves the Statamic user and lets super users through, for the file
     * and the Eloquent repository alike.
     */
    protected function authorizeOrFail(Request $request, string $permission): void
    {
        if (! $this->userCan($request, $permission)) {
            abort(403);
        }
    }

    protected function userCan(Request $request, string $permission): bool
    {
        return (bool) $request->user()?->can($permission);
    }

    /**
     * The setup screen when the migrations have not run, instead of a 500.
     * The reason goes to the log as well: an empty page that says nothing
     * anywhere would look installed and never work.
     */
    protected function setupGuard(string $title): ?Response
    {
        $missing = array_values(array_filter(
            ['teams', 'team_members', 'team_invitations', 'team_roles'],
            fn (string $table) => ! Schema::hasTable($table)
        ));

        if ($missing === []) {
            return null;
        }

        Log::error('statamic-teams: tables missing ('.implode(', ', $missing).'). Run `php artisan migrate`.');

        return Inertia::render('teams::SetupRequired', [
            'title' => $title,
            'heading' => __('teams::cp.setup_heading'),
            'description' => __('teams::cp.setup_description'),
            'tables' => $missing,
        ]);
    }
}
