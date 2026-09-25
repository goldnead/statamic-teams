<?php

namespace Goldnead\Teams\Exceptions;

use RuntimeException;

/**
 * Why an operation was refused.
 *
 * `reason` is a stable machine code (public contract, for app-api and
 * front-end forms), `status` the HTTP status that fits it. The message is
 * translated and may change.
 */
class TeamsException extends RuntimeException
{
    public const NOT_MEMBER = 'not_member';

    public const FORBIDDEN = 'forbidden';

    public const ALREADY_MEMBER = 'already_member';

    public const INVITATION_NOT_FOUND = 'invitation_not_found';

    public const INVITATION_EXPIRED = 'invitation_expired';

    public const INVITATION_USED = 'invitation_used';

    public const INVITATION_REVOKED = 'invitation_revoked';

    public const INVITATION_WRONG_EMAIL = 'invitation_wrong_email';

    public const JOIN_CODE_INVALID = 'join_code_invalid';

    public const JOIN_DISABLED = 'join_disabled';

    public const UNKNOWN_ROLE = 'unknown_role';

    public const LAST_OWNER = 'last_owner';

    public const PERSONAL_TEAM = 'personal_team';

    public const ALREADY_OWNER = 'already_owner';

    public const IMPORT_COLLISION = 'import_collision';

    public const TEAM_MISMATCH = 'team_mismatch';

    public const TEAM_REQUIRED = 'team_required';

    public const READ_ONLY = 'read_only';

    public const ROLE_EXISTS = 'role_exists';

    /** The owner role or the default role: not deleted, not narrowed. */
    public const ROLE_PROTECTED = 'role_protected';

    /** Members or open invitations hold the role; `details` says how many. */
    public const ROLE_IN_USE = 'role_in_use';

    public const UNKNOWN_PERMISSION = 'unknown_permission';

    /** `*` written into a role other than the owner role. */
    public const WILDCARD = 'wildcard_not_allowed';

    public const INVALID_ROLE_HANDLE = 'invalid_role_handle';

    /** @var array<string, int> */
    protected const STATUS = [
        self::NOT_MEMBER => 403,
        self::FORBIDDEN => 403,
        self::ALREADY_MEMBER => 422,
        self::INVITATION_NOT_FOUND => 404,
        self::INVITATION_EXPIRED => 410,
        self::INVITATION_USED => 410,
        self::INVITATION_REVOKED => 410,
        self::INVITATION_WRONG_EMAIL => 403,
        self::JOIN_CODE_INVALID => 404,
        self::JOIN_DISABLED => 403,
        self::UNKNOWN_ROLE => 422,
        self::LAST_OWNER => 422,
        self::PERSONAL_TEAM => 422,
        self::ALREADY_OWNER => 422,
        self::IMPORT_COLLISION => 409,
        self::TEAM_MISMATCH => 422,
        self::TEAM_REQUIRED => 422,
        self::READ_ONLY => 423,
        self::ROLE_EXISTS => 422,
        self::ROLE_PROTECTED => 422,
        self::ROLE_IN_USE => 409,
        self::UNKNOWN_PERMISSION => 422,
        self::WILDCARD => 422,
        self::INVALID_ROLE_HANDLE => 422,
    ];

    /**
     * @param  array<string, mixed>  $details  Machine-readable context, e.g.
     *                                         `['members' => 3]` for `role_in_use`. Part of `toArray()`.
     */
    public function __construct(
        public readonly string $reason,
        ?string $message = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message ?? __("teams::messages.errors.{$reason}", $details));
    }

    /** @param  array<string, mixed>  $details */
    public static function because(string $reason, array $details = []): self
    {
        return new self($reason, null, $details);
    }

    public function status(): int
    {
        return self::STATUS[$this->reason] ?? 422;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['reason' => $this->reason, 'message' => $this->getMessage()] + ($this->details === [] ? [] : ['details' => $this->details]);
    }
}
