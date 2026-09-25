<?php

namespace Goldnead\Teams\Http\Controllers\Web;

use Closure;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Models\Invitation;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\TeamsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * The front-end forms the antlers tags render.
 *
 * Thin on purpose: every decision is in the services, the same ones
 * statamic-app-api exposes as JSON. A form gets a redirect back with
 * `teams.success` or the error bag `teams` (read by `{{ teams:form_session }}`); a request that wants JSON gets
 * JSON, with the refusal's `reason`.
 */
class TeamFormController extends Controller
{
    public function __construct(protected TeamsManager $teams) {}

    public function create(Request $request): Response
    {
        $data = $request->validateWithBag('teams', ['name' => ['required', 'string', 'max:191']]);

        return $this->run($request, function ($user) use ($data) {
            $team = $this->teams->create($data['name'], $user);
            $this->teams->switch($user, $team);

            return [__('teams::messages.created'), ['team' => $team->summary()]];
        });
    }

    public function switch(Request $request): Response
    {
        $data = $request->validateWithBag('teams', ['team' => ['required']]);

        return $this->run($request, function ($user) use ($data) {
            $team = $this->teamOrFail($data['team']);
            $this->teams->switch($user, $team);

            return [__('teams::messages.switched', ['team' => $team->name]), ['team' => $team->summary()]];
        });
    }

    public function join(Request $request): Response
    {
        $data = $request->validateWithBag('teams', ['code' => ['required', 'string', 'max:64']]);

        return $this->run($request, function ($user) use ($data) {
            $membership = $this->teams->joinByCode($data['code'], $user);
            $team = $membership->team;

            if ($team !== null) {
                $this->teams->switch($user, $team);
            }

            return [__('teams::messages.joined', ['team' => $team?->name]), ['team' => $team?->summary()]];
        });
    }

    public function accept(Request $request, string $token): Response
    {
        return $this->run($request, function ($user) use ($token) {
            $membership = $this->teams->acceptInvitation($token, $user);
            $team = $membership->team;

            if ($team !== null) {
                $this->teams->switch($user, $team);
            }

            return [__('teams::messages.invitation_accepted', ['team' => $team?->name]), ['team' => $team?->summary()]];
        });
    }

    public function update(Request $request, int $team): Response
    {
        $data = $request->validateWithBag('teams', [
            'name' => ['sometimes', 'string', 'max:191'],
            'join_method' => ['sometimes', 'in:'.Team::JOIN_INVITATION_ONLY.','.Team::JOIN_CODE],
            'billing' => ['sometimes', 'array'],
            'billing.*' => ['nullable', 'string', 'max:191'],
        ]);

        return $this->run($request, function ($user) use ($team, $data) {
            $record = $this->teams->update($this->teamOrFail($team), $data, $user);

            return [__('teams::messages.updated'), ['team' => $record->summary()]];
        });
    }

    public function invite(Request $request, int $team): Response
    {
        $data = $request->validateWithBag('teams', [
            'email' => ['required', 'email', 'max:191'],
            'role' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->run($request, function ($user) use ($team, $data) {
            $issued = $this->teams->invite($this->teamOrFail($team), $data['email'], $data['role'] ?? null, [], $user);

            return [__('teams::messages.invited', ['email' => $issued->invitation->email]), ['invitation' => $issued->invitation->summary()]];
        });
    }

    public function leave(Request $request, int $team): Response
    {
        return $this->run($request, function ($user) use ($team) {
            $record = $this->teamOrFail($team);
            $this->teams->leave($record, $user);

            return [__('teams::messages.left', ['team' => $record->name]), []];
        });
    }

    public function removeMember(Request $request, int $team, string $user): Response
    {
        return $this->run($request, function ($actor) use ($team, $user) {
            $this->teams->removeMember($this->teamOrFail($team), $user, $actor);

            return [__('teams::messages.member_removed'), []];
        });
    }

    public function changeRole(Request $request, int $team, string $user): Response
    {
        $data = $request->validateWithBag('teams', ['role' => ['required', 'string', 'max:64']]);

        return $this->run($request, function ($actor) use ($team, $user, $data) {
            $membership = $this->teams->changeRole($this->teamOrFail($team), $user, $data['role'], $actor);

            return [__('teams::messages.role_changed'), ['role' => $membership->role]];
        });
    }

    public function revokeInvitation(Request $request, int $team, int $invitation): Response
    {
        return $this->run($request, function ($actor) use ($team, $invitation) {
            $record = Invitation::query()->where('team_id', $team)->find($invitation)
                ?? throw TeamsException::because(TeamsException::INVITATION_NOT_FOUND);

            $this->teams->revokeInvitation($record, $actor);

            return [__('teams::messages.invitation_revoked'), []];
        });
    }

    public function regenerateJoinCode(Request $request, int $team): Response
    {
        return $this->run($request, function ($actor) use ($team) {
            $code = $this->teams->regenerateJoinCode($this->teamOrFail($team), $actor);

            return [__('teams::messages.join_code_regenerated', ['code' => $code]), ['join_code' => $code]];
        });
    }

    /**
     * @param  Closure(mixed): array{0: string, 1: array<string, mixed>}  $operation
     */
    protected function run(Request $request, Closure $operation): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $request->expectsJson()
                ? new JsonResponse(['message' => __('Unauthenticated.')], 401)
                : redirect()->to((string) config('teams.invitations.login_url', '/login').'?redirect='.urlencode((string) url()->previous()));
        }

        try {
            [$message, $data] = $operation($user);
        } catch (TeamsException $e) {
            if ($request->expectsJson()) {
                return new JsonResponse($e->toArray(), $e->status());
            }

            return $this->back($request)->withErrors(['teams' => $e->getMessage()], 'teams')->withInput();
        }

        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $message] + $data);
        }

        return $this->back($request)->with('teams.success', $message);
    }

    /**
     * `_redirect` in the form wins, as with Statamic's own forms. Only a
     * path on this site: an open redirect is a phishing tool.
     */
    protected function back(Request $request): RedirectResponse
    {
        $target = (string) $request->input('_redirect', '');

        if ($target !== '' && str_starts_with($target, '/') && ! str_starts_with($target, '//')) {
            return redirect()->to($target);
        }

        return redirect()->back();
    }

    protected function teamOrFail(int|string $id): Team
    {
        return $this->teams->find($id) ?? throw TeamsException::because(TeamsException::NOT_MEMBER);
    }
}
