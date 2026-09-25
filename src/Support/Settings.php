<?php

namespace Goldnead\Teams\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * The values an operator may change in the Control Panel, on the suite's
 * shared screen (Settings, Addon settings). Only loaded when
 * goldnead/statamic-brand-context is installed.
 *
 * **Not here, and why.** `routes.*` and `current.*` are read while routes
 * and middleware are registered, before the stored values are applied.
 * `integrations.automations` and `integrations.webhook_manager` are read
 * once when the triggers are registered. `roles` is a nested map, which the
 * settings layer does not edit (decision 07.09.2026): it stays in config,
 * and a team adds its own roles in `team_roles`.
 */
class Settings implements ProvidesSettings
{
    /** Never rename: it is stored in every `brand_settings` row. */
    public static function settingsNamespace(): string
    {
        return 'teams';
    }

    public static function settingsConfigPath(): string
    {
        return 'teams';
    }

    public static function settingsPermission(): string
    {
        return 'manage teams settings';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('teams::settings.groups.invitations.title'),
                'description' => __('teams::settings.groups.invitations.description'),
                'fields' => [
                    static::field('invitations.expires_after_days', 'integer', ['min' => 0, 'max' => 365]),
                    static::field('invitations.require_matching_email', 'boolean'),
                    static::field('join_codes.length', 'integer', ['min' => 6, 'max' => 32]),
                    static::field('personal.create_on_registration', 'boolean'),
                ],
            ],
            [
                'title' => __('teams::settings.groups.mail.title'),
                'description' => __('teams::settings.groups.mail.description'),
                'fields' => [
                    static::field('mail.invitation.enabled', 'boolean'),
                    static::field('mail.member_joined.enabled', 'boolean'),
                    static::field('mail.member_removed.enabled', 'boolean'),
                    static::field('mail.role_changed.enabled', 'boolean'),
                ],
            ],
        ];
    }

    /**
     * The translation key is the config path with its dots flattened: a dot
     * in a translation key is a path separator.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("teams::settings.fields.{$handle}.label"),
            'description' => __("teams::settings.fields.{$handle}.description"),
            'nullable' => false,
        ], $extra);
    }
}
