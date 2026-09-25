<?php

namespace Goldnead\Teams\Facades;

use Goldnead\Teams\TeamsManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Goldnead\Teams\Models\Team create(string $name, mixed $owner = null, array $attributes = [])
 * @method static \Goldnead\Teams\Models\Team update(\Goldnead\Teams\Models\Team $team, array $attributes, mixed $actor = null)
 * @method static void delete(\Goldnead\Teams\Models\Team $team, mixed $actor = null)
 * @method static \Goldnead\Teams\Models\Team|null find(int|string|null $id)
 * @method static \Goldnead\Teams\Models\Team personalTeam(mixed $user)
 * @method static string regenerateJoinCode(\Goldnead\Teams\Models\Team $team, mixed $actor = null)
 * @method static \Goldnead\Teams\Models\Team transferOwnership(\Goldnead\Teams\Models\Team $team, mixed $to, mixed $actor = null)
 * @method static \Illuminate\Support\Collection teamsOf(mixed $user)
 * @method static \Illuminate\Support\Collection members(\Goldnead\Teams\Models\Team $team)
 * @method static \Goldnead\Teams\Models\Membership addMember(\Goldnead\Teams\Models\Team $team, mixed $user, ?string $role = null, array $meta = [], mixed $actor = null)
 * @method static void removeMember(\Goldnead\Teams\Models\Team $team, mixed $user, mixed $actor = null)
 * @method static void leave(\Goldnead\Teams\Models\Team $team, mixed $user)
 * @method static \Goldnead\Teams\Models\Membership changeRole(\Goldnead\Teams\Models\Team $team, mixed $user, string $role, mixed $actor = null)
 * @method static \Goldnead\Teams\Models\Membership updateMemberMeta(\Goldnead\Teams\Models\Team $team, mixed $user, array $meta, mixed $actor = null)
 * @method static \Goldnead\Teams\Models\Team|null current(mixed $user = null)
 * @method static \Goldnead\Teams\Models\Team currentOrFail(mixed $user = null)
 * @method static void setCurrent(?\Goldnead\Teams\Models\Team $team)
 * @method static \Goldnead\Teams\Models\Membership switch(mixed $user, \Goldnead\Teams\Models\Team $team)
 * @method static \Goldnead\Teams\Support\IssuedInvitation invite(\Goldnead\Teams\Models\Team $team, string $email, ?string $role = null, array $meta = [], mixed $actor = null)
 * @method static \Goldnead\Teams\Models\Membership acceptInvitation(string $token, mixed $user)
 * @method static \Goldnead\Teams\Models\Invitation revokeInvitation(\Goldnead\Teams\Models\Invitation $invitation, mixed $actor = null)
 * @method static \Goldnead\Teams\Support\IssuedInvitation resendInvitation(\Goldnead\Teams\Models\Invitation $invitation, mixed $actor = null)
 * @method static \Goldnead\Teams\Models\Invitation invitation(string $token)
 * @method static \Illuminate\Support\Collection pendingInvitationsFor(mixed $user)
 * @method static \Illuminate\Support\Collection pendingInvitationsOf(\Goldnead\Teams\Models\Team $team)
 * @method static \Goldnead\Teams\Models\Membership joinByCode(string $code, mixed $user, array $meta = [])
 * @method static void guardJoining(\Closure $guard)
 * @method static bool can(mixed $user, \Goldnead\Teams\Models\Team $team, string $permission)
 * @method static string|null roleOf(mixed $user, \Goldnead\Teams\Models\Team $team)
 * @method static array roles(?\Goldnead\Teams\Models\Team $team = null)
 * @method static array createRole(string $handle, string $label, array $permissions = [], ?\Goldnead\Teams\Models\Team $team = null, mixed $actor = null)
 * @method static array updateRole(string $handle, array $attributes, ?\Goldnead\Teams\Models\Team $team = null, mixed $actor = null)
 * @method static int deleteRole(string $handle, ?\Goldnead\Teams\Models\Team $team = null, ?string $reassignTo = null, mixed $actor = null)
 * @method static array resetRole(string $handle)
 * @method static array roleUsage(string $handle, ?\Goldnead\Teams\Models\Team $team = null)
 * @method static array permissions()
 * @method static void registerPermission(string $handle, ?string $label = null)
 * @method static array entitlementSubjectsFor(mixed $user)
 * @method static mixed entitlementSubject(\Goldnead\Teams\Models\Team $team)
 * @method static bool allows(mixed $user, string $productSlug, mixed $personalSubject = null)
 * @method static array checkoutBuyer(\Goldnead\Teams\Models\Team $team, mixed $payer = null)
 * @method static array checkoutDetails(\Goldnead\Teams\Models\Team $team, mixed $payer = null)
 * @method static object|null checkout(\Goldnead\Teams\Models\Team $team, string|array $products, mixed $payer = null, ?string $returnUrl = null)
 * @method static \Goldnead\Teams\Models\Team import(array $data)
 *
 * @see TeamsManager
 */
class Teams extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TeamsManager::class;
    }
}
