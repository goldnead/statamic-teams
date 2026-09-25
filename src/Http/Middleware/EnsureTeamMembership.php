<?php

namespace Goldnead\Teams\Http\Middleware;

use Closure;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Services\MembershipService;
use Goldnead\Teams\Services\TeamService;
use Goldnead\Teams\Support\CurrentTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides which team a request is about, and that the user belongs to it.
 *
 * The team may be named in the header (`X-Team-ID`), the query or body
 * (`team_id`) or the route (`{team}`, `{team_id}`), by id or uuid. Several
 * names that point to different teams are a 422: the request contradicts
 * itself, and picking one would be guessing which half the client meant.
 * A team the user is not in is a 403, whether it exists or not.
 *
 * `teams.current` (mode `mixed`): without a named team, the user's current
 * team is used, or none; with `teams.current.fallback_to_current` off, none
 * (then `Teams::currentOrFail()` answers 422, as ChoirLive's
 * `currentTenantId()` does). `teams.current:required`: without a named team,
 * 422. Either way `Teams::current()` returns the team afterwards.
 */
class EnsureTeamMembership
{
    public function __construct(
        protected TeamService $teams,
        protected MembershipService $memberships,
        protected CurrentTeam $current,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = 'mixed'): Response
    {
        $named = $this->namedValues($request);
        $resolved = [];

        foreach ($named as $value) {
            $team = $value instanceof Team ? $value : $this->teams->find($value);
            $resolved[$team ? 'id:'.$team->getKey() : 'unknown:'.$value] = $team;
        }

        if (count($resolved) > 1) {
            $this->refuse(TeamsException::TEAM_MISMATCH);
        }

        $user = $request->user();
        $team = $resolved === [] ? null : reset($resolved);

        if ($resolved !== []) {
            if ($team === null || $user === null || ! $team->hasMember($user)) {
                $this->refuse(TeamsException::NOT_MEMBER);
            }
        } elseif ($mode === 'required') {
            $this->refuse(TeamsException::TEAM_REQUIRED);
        } elseif (config('teams.current.fallback_to_current', true)) {
            $team = $user === null ? null : $this->memberships->currentFor($user);
        }

        $this->current->set($team ?? null);

        return $next($request);
    }

    /**
     * The team names this request carries, as strings (or a bound model),
     * empty values dropped.
     *
     * @return list<string|Team>
     */
    protected function namedValues(Request $request): array
    {
        $parameter = (string) config('teams.current.parameter', 'team_id');

        $values = [
            $request->header((string) config('teams.current.header', 'X-Team-ID')),
            $request->query($parameter),
            $request->request->get($parameter) ?? ($request->isJson() ? $request->json($parameter) : null),
        ];

        foreach ((array) config('teams.current.route_parameters', ['team', 'team_id']) as $name) {
            $values[] = $request->route((string) $name);
        }

        $named = [];

        foreach ($values as $value) {
            if ($value instanceof Team) {
                $named[] = $value;
            } elseif ($value instanceof Model) {
                $named[] = (string) $value->getKey();
            } elseif (is_scalar($value) && (string) $value !== '') {
                $named[] = (string) $value;
            }
        }

        return $named;
    }

    protected function refuse(string $reason): never
    {
        $exception = TeamsException::because($reason);

        abort($exception->status(), $exception->getMessage());
    }
}
