<?php

namespace Goldnead\Teams\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Facades\User;

/**
 * One way to turn "a user" into a key and back.
 *
 * A team stores its members by string key. What arrives here can be a
 * Statamic user (file or Eloquent repository), the Eloquent model behind the
 * Eloquent repository, anything `Authenticatable`, or a bare id. All of them
 * reduce to the same string, so `5` and `"5"` and the user object with id 5
 * name the same member.
 */
class Users
{
    public static function key(mixed $user): ?string
    {
        return match (true) {
            $user === null => null,
            // Statamic's users (file and Eloquent) are Authenticatable too;
            // the identifier is their id.
            $user instanceof Authenticatable => static::stringOrNull($user->getAuthIdentifier()),
            is_int($user), is_string($user) => static::stringOrNull($user),
            is_object($user) && method_exists($user, 'id') => static::stringOrNull($user->id()),
            is_object($user) && method_exists($user, 'getKey') => static::stringOrNull($user->getKey()),
            default => null,
        };
    }

    /** @param  mixed  $user  A key, a Statamic user or an Authenticatable. */
    public static function find(mixed $user): ?StatamicUser
    {
        if ($user instanceof StatamicUser) {
            return $user;
        }

        $key = static::key($user);

        return $key === null ? null : User::find($key);
    }

    public static function findByEmail(string $email): ?StatamicUser
    {
        $email = trim($email);

        return $email === '' ? null : User::findByEmail($email);
    }

    public static function email(mixed $user): ?string
    {
        if (is_object($user) && ! $user instanceof StatamicUser && isset($user->email) && is_string($user->email)) {
            return $user->email;
        }

        $email = static::find($user)?->email();

        return is_string($email) && $email !== '' ? $email : null;
    }

    public static function name(mixed $user): ?string
    {
        $found = static::find($user);

        if ($found === null) {
            return null;
        }

        // Every user class Statamic ships extends `Statamic\Auth\User`; the
        // contract alone declares no name.
        $name = $found instanceof \Statamic\Auth\User ? $found->name() : null;

        return is_string($name) && $name !== '' ? $name : $found->email();
    }

    /**
     * The fields an event, a mail or an API response may carry about a user.
     *
     * @return array{id: string|null, email: string|null, name: string|null}
     */
    public static function summary(mixed $user): array
    {
        return [
            'id' => static::key($user),
            'email' => static::email($user),
            'name' => static::name($user),
        ];
    }

    protected static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
