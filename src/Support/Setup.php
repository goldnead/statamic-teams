<?php

namespace Goldnead\Teams\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The check a CP page runs before its first query (the studio's idiom).
 *
 * When the migrations have not run, the page shows a sentence instead of a
 * 500, and the reason goes to the log as well: an empty page that says
 * nothing anywhere would look installed and never work.
 */
final class Setup
{
    public const TABLES = ['teams', 'team_members', 'team_invitations', 'team_roles'];

    public static function guard(string $title): ?Response
    {
        $missing = array_values(array_filter(self::TABLES, fn (string $table) => ! Schema::hasTable($table)));

        if ($missing === []) {
            return null;
        }

        Log::error('statamic-teams: the CP page "'.$title.'" cannot load, these tables are missing: '.implode(', ', $missing).'. Run `php artisan migrate`.');

        return Inertia::render('teams::SetupRequired', [
            'title' => $title,
            'heading' => __('teams::cp.setup_heading'),
            'description' => __('teams::cp.setup_description'),
            'tables' => $missing,
        ]);
    }
}
