<?php

use Goldnead\Teams\Http\Controllers\Cp\RoleController;
use Goldnead\Teams\Http\Controllers\Cp\TeamController;
use Illuminate\Support\Facades\Route;

/*
 * Every route carries `can:` middleware AND the controller checks again.
 */
Route::prefix('teams')->name('teams.')->group(function () {
    Route::get('/', [TeamController::class, 'index'])->name('index')->middleware('can:view teams');
    Route::post('/', [TeamController::class, 'store'])->name('store')->middleware('can:manage teams');
    Route::get('/wiring', [TeamController::class, 'wiring'])->name('wiring')->middleware('can:view teams');
    Route::post('/mail-templates', [TeamController::class, 'installMailTemplates'])->name('mail-templates')->middleware('can:manage teams');

    // Roles: their own permission, `manage teams` is not enough.
    Route::middleware('can:manage team roles')->group(function () {
        $handle = '[a-z0-9][a-z0-9_-]{0,63}';

        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit')->where('role', $handle);
        Route::patch('/roles/{role}', [RoleController::class, 'update'])->name('roles.update')->where('role', $handle);
        Route::post('/roles/{role}/reset', [RoleController::class, 'reset'])->name('roles.reset')->where('role', $handle);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy')->where('role', $handle);

        Route::post('/{team}/roles', [RoleController::class, 'storeForTeam'])->name('team-roles.store')->whereNumber('team');
        Route::patch('/{team}/roles/{role}', [RoleController::class, 'updateForTeam'])->name('team-roles.update')->whereNumber('team')->where('role', $handle);
        Route::delete('/{team}/roles/{role}', [RoleController::class, 'destroyForTeam'])->name('team-roles.destroy')->whereNumber('team')->where('role', $handle);
    });

    Route::get('/{team}', [TeamController::class, 'show'])->name('show')->whereNumber('team')->middleware('can:view teams');
    Route::patch('/{team}', [TeamController::class, 'update'])->name('update')->whereNumber('team')->middleware('can:manage teams');
    Route::delete('/{team}', [TeamController::class, 'destroy'])->name('destroy')->whereNumber('team')->middleware('can:manage teams');
    Route::post('/{team}/join-code', [TeamController::class, 'regenerateJoinCode'])->name('join-code')->whereNumber('team')->middleware('can:manage teams');

    Route::patch('/{team}/members/{membership}', [TeamController::class, 'updateMember'])->name('members.update')->whereNumber(['team', 'membership'])->middleware('can:manage teams');
    Route::delete('/{team}/members/{membership}', [TeamController::class, 'destroyMember'])->name('members.destroy')->whereNumber(['team', 'membership'])->middleware('can:manage teams');

    Route::post('/{team}/invitations', [TeamController::class, 'invite'])->name('invitations.store')->whereNumber('team')->middleware('can:manage teams');
    Route::post('/{team}/invitations/{invitation}/resend', [TeamController::class, 'resendInvitation'])->name('invitations.resend')->whereNumber(['team', 'invitation'])->middleware('can:manage teams');
    Route::delete('/{team}/invitations/{invitation}', [TeamController::class, 'destroyInvitation'])->name('invitations.destroy')->whereNumber(['team', 'invitation'])->middleware('can:manage teams');
});
