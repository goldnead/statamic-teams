<?php

namespace Goldnead\Teams\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

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
}
