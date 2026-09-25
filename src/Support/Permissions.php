<?php

namespace Goldnead\Teams\Support;

use Illuminate\Support\Str;

/**
 * The team permissions a role can hold.
 *
 * `teams.permissions` in the config, plus what a host or another addon
 * registers at boot with `Teams::registerPermission()`. A role may only
 * list permissions from here; the owner role holds `*`, which stands for
 * all of them, including those registered later.
 *
 * Labels: a registered label, else `teams::permissions.<handle>`, else the
 * handle with a capital letter. Each goes through the translator, so a
 * host's `lang/de.json` can translate its own permissions.
 */
class Permissions
{
    /** @var array<string, string|null> */
    protected array $registered = [];

    public function register(string $handle, ?string $label = null): void
    {
        $this->registered[$handle] = $label;
    }

    /** @return array<string, string> handle => translated label */
    public function all(): array
    {
        $all = [];

        foreach ((array) config('teams.permissions', []) as $key => $value) {
            // A list of handles, or handle => label.
            [$handle, $label] = is_string($key) ? [$key, (string) $value] : [(string) $value, null];
            $all[$handle] = $this->label($handle, $label);
        }

        foreach ($this->registered as $handle => $label) {
            $all[$handle] = $this->label($handle, $label);
        }

        return $all;
    }

    /** @return list<string> */
    public function handles(): array
    {
        return array_keys($this->all());
    }

    public function exists(string $handle): bool
    {
        return array_key_exists($handle, $this->all());
    }

    protected function label(string $handle, ?string $label): string
    {
        if ($label !== null && $label !== '') {
            return (string) __($label);
        }

        $key = 'teams::permissions.'.$handle;
        $translated = __($key);

        return is_string($translated) && $translated !== $key ? $translated : (string) __(Str::ucfirst($handle));
    }
}
