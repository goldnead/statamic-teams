<?php

use Goldnead\Teams\Http\Controllers\Web\TeamFormController;
use Illuminate\Support\Facades\Route;

/*
 * Front-end form posts, under /!/statamic-teams/… (Statamic prefixes this
 * file with the addon slug). Route names: `statamic.teams.forms.*`.
 * Every route needs a signed-in user; the controller checks, and the team
 * role decides inside the service. The antlers tags build these URLs.
 */
if (config('teams.routes.enabled', true)) {
    Route::name('teams.forms.')->group(function () {
        Route::post('create', [TeamFormController::class, 'create'])->name('create');
        Route::post('switch', [TeamFormController::class, 'switch'])->name('switch');
        Route::post('join', [TeamFormController::class, 'join'])
            ->middleware('throttle:'.config('teams.routes.join_throttle', '10,1'))
            ->name('join');
        Route::post('invitations/{token}/accept', [TeamFormController::class, 'accept'])
            ->where('token', '[A-Za-z0-9]+')
            ->name('accept');

        Route::post('{team}/update', [TeamFormController::class, 'update'])->whereNumber('team')->name('update');
        Route::post('{team}/invite', [TeamFormController::class, 'invite'])->whereNumber('team')->name('invite');
        Route::post('{team}/leave', [TeamFormController::class, 'leave'])->whereNumber('team')->name('leave');
        Route::post('{team}/join-code', [TeamFormController::class, 'regenerateJoinCode'])->whereNumber('team')->name('join-code');
        Route::post('{team}/members/{user}/remove', [TeamFormController::class, 'removeMember'])->whereNumber('team')->name('members.remove');
        Route::post('{team}/members/{user}/role', [TeamFormController::class, 'changeRole'])->whereNumber('team')->name('members.role');
        Route::post('{team}/invitations/{invitation}/revoke', [TeamFormController::class, 'revokeInvitation'])
            ->whereNumber('team')->whereNumber('invitation')->name('invitations.revoke');
    });
}
