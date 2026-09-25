<?php

use Goldnead\Teams\Http\Controllers\Web\InvitationPageController;
use Illuminate\Support\Facades\Route;

/*
 * The page behind the link in the invitation mail. Showing it changes
 * nothing; accepting is a POST from the page (see routes/actions.php), so a
 * mail scanner that follows every link does not accept on anyone's behalf.
 */
if (config('teams.routes.enabled', true)) {
    Route::middleware((array) config('teams.routes.middleware', ['web']))
        ->prefix(trim((string) config('teams.routes.prefix', 'teams'), '/'))
        ->name('teams.')
        ->group(function () {
            Route::get('invitations/{token}', [InvitationPageController::class, 'show'])
                ->where('token', '[A-Za-z0-9]+')
                ->name('invitations.show');
        });
}
