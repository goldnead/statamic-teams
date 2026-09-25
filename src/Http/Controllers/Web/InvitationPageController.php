<?php

namespace Goldnead\Teams\Http\Controllers\Web;

use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Support\Roles;
use Goldnead\Teams\TeamsManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page the invitation link opens.
 *
 * A guest is sent to the login first and comes back here. The page shows
 * team, role and a button; the button posts. A dead link (expired, used,
 * withdrawn) says why instead of a bare 404.
 *
 * The view is `teams::invitation`, publishable with `--tag=teams-views`.
 */
class InvitationPageController extends Controller
{
    public function __construct(
        protected TeamsManager $teams,
        protected Roles $roles,
    ) {}

    public function show(Request $request, string $token): Response
    {
        if ($request->user() === null) {
            return redirect()->to((string) config('teams.invitations.login_url', '/login').'?redirect='.urlencode($request->fullUrl()));
        }

        try {
            $invitation = $this->teams->invitation($token);
        } catch (TeamsException $e) {
            return response()->view('teams::invitation', [
                'invitation' => null,
                'team' => null,
                'error' => $e->getMessage(),
                'reason' => $e->reason,
            ], $e->status());
        }

        return response()->view('teams::invitation', [
            'invitation' => $invitation,
            'team' => $invitation->team,
            'role' => $this->roles->label($invitation->role, $invitation->team),
            'acceptUrl' => route('statamic.teams.forms.accept', ['token' => $token]),
            'error' => null,
            'reason' => null,
        ]);
    }
}
