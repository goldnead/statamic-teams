<?php

namespace Goldnead\Teams\Support;

use Goldnead\Teams\Events\InvitationAccepted;
use Goldnead\Teams\Events\InvitationRevoked;
use Goldnead\Teams\Events\InvitationSent;
use Goldnead\Teams\Events\MemberJoined;
use Goldnead\Teams\Events\MemberLeft;
use Goldnead\Teams\Events\MemberRoleChanged;
use Goldnead\Teams\Events\OwnershipTransferred;
use Goldnead\Teams\Events\RoleCreated;
use Goldnead\Teams\Events\RoleDeleted;
use Goldnead\Teams\Events\RoleUpdated;
use Goldnead\Teams\Events\TeamCreated;
use Goldnead\Teams\Events\TeamDeleted;
use Goldnead\Teams\Events\TeamEvent;
use Goldnead\Teams\Events\TeamUpdated;

/**
 * The one list of events this addon fires.
 *
 * The automations bridge, the webhook bridge, the activity bridge and the
 * CP page "Wiring" all read from here, so an event added here reaches all
 * four and an event missing here reaches none. The mail column says which
 * mail (key under `teams.mail`) the event sends.
 */
class EventCatalog
{
    /**
     * @var array<class-string<TeamEvent>, array{mail: string|null}>
     */
    public const EVENTS = [
        TeamCreated::class => ['mail' => null],
        TeamUpdated::class => ['mail' => null],
        TeamDeleted::class => ['mail' => null],
        OwnershipTransferred::class => ['mail' => null],
        MemberJoined::class => ['mail' => 'member_joined'],
        MemberLeft::class => ['mail' => 'member_removed'],
        MemberRoleChanged::class => ['mail' => 'role_changed'],
        InvitationSent::class => ['mail' => 'invitation'],
        InvitationAccepted::class => ['mail' => null],
        InvitationRevoked::class => ['mail' => null],
        RoleCreated::class => ['mail' => null],
        RoleUpdated::class => ['mail' => null],
        RoleDeleted::class => ['mail' => null],
    ];

    /**
     * @return list<array{class: class-string<TeamEvent>, handle: string, label: string, description: string, mail: string|null}>
     */
    public static function all(): array
    {
        $rows = [];

        foreach (self::EVENTS as $class => $meta) {
            $handle = $class::handle();
            $key = str_replace('.', '_', substr($handle, strlen('teams.')));

            $rows[] = [
                'class' => $class,
                'handle' => $handle,
                'label' => __("teams::events.{$key}.label"),
                'description' => __("teams::events.{$key}.description"),
                'mail' => $meta['mail'],
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    public static function handles(): array
    {
        return array_map(fn (string $class) => $class::handle(), array_keys(self::EVENTS));
    }
}
