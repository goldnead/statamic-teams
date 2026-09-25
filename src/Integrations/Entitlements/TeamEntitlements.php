<?php

namespace Goldnead\Teams\Integrations\Entitlements;

use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Services\MembershipService;
use Throwable;

/**
 * A team as a subject in goldnead/statamic-entitlements.
 *
 * Access granted to a team holds for every member, and only while they are
 * a member: the check walks the user's current memberships each time, so a
 * member who leaves loses the team's access in the same request.
 *
 * Entitlements itself checks exactly one subject per call and has no hook
 * for "also consider these" (see README, "Entitlements"). Until it has one,
 * the expansion happens here: `allows()` asks entitlements once for the
 * user's own subject and once per team.
 */
class TeamEntitlements
{
    protected const FACADE = 'Goldnead\Entitlements\Facades\Entitlements';

    protected const REFERENCE = 'Goldnead\Entitlements\Support\SubjectReference';

    protected bool $registered = false;

    public function __construct(protected MembershipService $memberships) {}

    public function available(): bool
    {
        return (bool) config('teams.integrations.entitlements', true) && class_exists(self::FACADE);
    }

    /**
     * The subject a team is in entitlements: `team:<id>`, through the morph
     * alias the service provider registers.
     */
    public function subjectFor(Team $team): mixed
    {
        $reference = self::REFERENCE;

        return class_exists($reference)
            ? new $reference(Team::MORPH_ALIAS, (string) $team->getKey())
            : ['type' => Team::MORPH_ALIAS, 'id' => (string) $team->getKey()];
    }

    /**
     * Every subject whose access a user holds through teams: one per team
     * the user is currently in. The user's own subject is not included, it
     * is host-specific (see `allows()`).
     *
     * @return list<mixed>
     */
    public function subjectsFor(mixed $user): array
    {
        return $this->memberships->teamsOf($user)
            ->map(fn (Team $team) => $this->subjectFor($team))
            ->values()
            ->all();
    }

    /**
     * Does the user hold access to the product, personally or through one of
     * their teams?
     *
     * `$personalSubject` is how the host names the user in entitlements (the
     * user model, `new SubjectReference('user', $id)`, or null to check only
     * teams). Entitlements does not know Statamic users itself.
     */
    public function allows(mixed $user, string $productSlug, mixed $personalSubject = null): bool
    {
        if (! $this->available()) {
            return false;
        }

        $facade = self::FACADE;

        try {
            if ($personalSubject !== null && $facade::allows($personalSubject, $productSlug)) {
                return true;
            }

            foreach ($this->subjectsFor($user) as $subject) {
                if ($facade::allows($subject, $productSlug)) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return false;
    }

    /**
     * Does the team itself hold access to the product?
     */
    public function teamAllows(Team $team, string $productSlug): bool
    {
        if (! $this->available()) {
            return false;
        }

        $facade = self::FACADE;

        return (bool) $facade::allows($this->subjectFor($team), $productSlug);
    }

    /**
     * The products a user reaches through teams, deduplicated.
     *
     * @return list<string>
     */
    public function productsThroughTeams(mixed $user): array
    {
        if (! $this->available()) {
            return [];
        }

        $facade = self::FACADE;
        $slugs = [];

        foreach ($this->subjectsFor($user) as $subject) {
            foreach ((array) $facade::activeProductSlugsFor($subject) as $slug) {
                $slugs[(string) $slug] = true;
            }
        }

        return array_keys($slugs);
    }

    /**
     * Announce this class to entitlements as a subject expander, once.
     * From then on entitlements itself answers "does this user have access"
     * with the user's teams included, for grants and for limits.
     */
    public function register(): void
    {
        if ($this->registered || ! $this->available()) {
            return;
        }

        try {
            $facade = self::FACADE;
            $root = $facade::getFacadeRoot();

            if (is_object($root) && method_exists($root, 'extendSubjects')) {
                $root->extendSubjects($this);
                $this->registered = true;
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The subject types that name a user, so their id is a membership key.
     *
     * `user` (the convention for Statamic users), the configured auth model
     * (ChoirLive: `App\Models\User`) and its morph alias, plus whatever
     * `teams.entitlements.user_types` adds. A type that is not in this list
     * (an email, a team) is never expanded: a team id is not a user id.
     *
     * @return list<string>
     */
    public function userTypes(): array
    {
        $types = ['user'];
        $model = config('auth.providers.users.model');

        if (is_string($model) && class_exists($model)) {
            $types[] = $model;

            try {
                $types[] = (new $model)->getMorphClass();
            } catch (Throwable) {
                // Not an Eloquent model: its class name is the type.
            }
        }

        return array_values(array_unique(array_merge($types, array_map('strval', (array) config('teams.entitlements.user_types', [])))));
    }

    /**
     * The expander entitlements calls: given the subject it is asked about,
     * the team subjects it should also consider. Duck-typed (entitlements'
     * `Contracts\SubjectExpander` has the same signature), so this addon does
     * not require entitlements.
     *
     * @return list<mixed>
     */
    public function relatedSubjects(object $subject): array
    {
        $type = (string) ($subject->type ?? '');
        $id = (string) ($subject->id ?? '');

        if ($id === '' || ! in_array($type, $this->userTypes(), true)) {
            return [];
        }

        return $this->subjectsFor($id);
    }
}
