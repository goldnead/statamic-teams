<?php

namespace Goldnead\Teams\Http\Middleware;

use Closure;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Support\CurrentTeam;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses writes (anything but GET, HEAD, OPTIONS) in a read-only team with
 * 423. Runs after `teams.current`, which decides the team.
 */
class EnsureTeamWritable
{
    public function __construct(protected CurrentTeam $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $team = $this->current->get();

        if ($team !== null && $team->isReadOnly() && ! $request->isMethodSafe()) {
            $exception = TeamsException::because(TeamsException::READ_ONLY);

            abort($exception->status(), $exception->getMessage());
        }

        return $next($request);
    }
}
