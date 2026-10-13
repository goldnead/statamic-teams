<?php

namespace Goldnead\Teams\Services;

use Goldnead\Teams\Contracts\ClaimPolicy;
use Goldnead\Teams\Contracts\ClaimResource;
use Goldnead\Teams\Events\ClaimDecided;
use Goldnead\Teams\Events\ClaimRequested;
use Goldnead\Teams\Exceptions\ClaimException;
use Goldnead\Teams\Models\ClaimOutbox;
use Goldnead\Teams\Models\TeamClaim;
use Goldnead\Teams\Support\Users;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\User as UserRepository;

class ClaimService
{
    public function __construct(protected TeamService $teams, protected ClaimLimitService $limits) {}

    public function request(string $type, string $key, string $site, User $actor, string $justification, string $contact): TeamClaim
    {
        $this->policy()->assertCanRequest($actor);
        $type = $this->bounded($type, 64, 'resource type');
        if (! preg_match('/\A[a-zA-Z0-9_-]+\z/', $type)) {
            throw new ClaimException(ClaimException::INVALID, 'Invalid resource type.');
        }
        $key = $this->bounded($key, 191, 'resource key');
        $site = $this->bounded($site, 64, 'site');
        $justification = $this->bounded($justification, 5000, 'justification', 20);
        $contact = $this->bounded($contact, 255, 'contact', 3);
        $claimantId = Users::key($actor) ?? throw new ClaimException(ClaimException::INVALID, 'Claimant has no stable user id.');
        $pendingKey = hash('sha256', implode("\0", [$type, $key, $site, $claimantId]));
        $resource = $this->resource($type);

        try {
            $claim = DB::transaction(function () use ($resource, $type, $key, $site, $claimantId, $justification, $contact, $pendingKey) {
                $resource->lock($key, $site);
                $this->limits->lockAccount($claimantId);

                $pending = TeamClaim::query()->where('pending_key', $pendingKey)->first();
                if ($pending !== null) {
                    return $pending;
                }

                $this->limits->assertCanRequest($claimantId, $type, $key, $site);
                $claim = TeamClaim::query()->create([
                    'resource_type' => $type,
                    'resource_key' => $key,
                    'site' => $site,
                    'claimant_id' => $claimantId,
                    'justification' => $justification,
                    'contact' => $contact,
                    'status' => TeamClaim::PENDING,
                    'pending_key' => $pendingKey,
                ]);

                DB::table('team_claim_outbox')->insert([
                    'claim_id' => $claim->getKey(),
                    'kind' => ClaimOutbox::REQUESTED,
                    'status' => TeamClaim::PENDING,
                    'created_at' => now(),
                ]);

                return $claim;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A pending_key unique constraint closes races on databases where
            // a row lock has weaker semantics. Never expose stored evidence.
            $claim = TeamClaim::query()->where('pending_key', $pendingKey)->first();
            if ($claim === null) {
                throw $exception;
            }
        }

        if ($claim->wasRecentlyCreated) {
            event(new ClaimRequested($claim));
        }

        return $claim;
    }

    public function approve(int $id, User $reviewer, string $note): TeamClaim
    {
        $this->policy()->assertCanReview($reviewer);
        $note = $this->bounded($note, 2000, 'decision note', 0, true);
        $claim = $this->decide($id, $reviewer, $note, true);
        event(new ClaimDecided($claim));

        return $claim;
    }

    public function reject(int $id, User $reviewer, string $note): TeamClaim
    {
        $this->policy()->assertCanReview($reviewer);
        $note = $this->bounded($note, 2000, 'decision note', 1);
        $claim = $this->decide($id, $reviewer, $note, false);
        event(new ClaimDecided($claim));

        return $claim;
    }

    protected function decide(int $id, User $reviewer, string $note, bool $approve): TeamClaim
    {
        $snapshot = TeamClaim::query()->find($id) ?? throw new ClaimException(ClaimException::NOT_FOUND);
        if (Users::key($reviewer) === $snapshot->claimant_id) {
            throw new ClaimException(ClaimException::CONFLICT, 'A claimant cannot review their own claim.');
        }

        $resource = $approve ? $this->resource($snapshot->resource_type) : null;
        $reviewerId = Users::key($reviewer) ?? throw new ClaimException(ClaimException::INVALID, 'Reviewer has no stable user id.');

        return DB::transaction(function () use ($id, $snapshot, $resource, $reviewerId, $note, $approve) {
            // Approvals always lock resource then claimant-account, claim.
            // Rejections take the same locks in the same order but may skip
            // the resource lock: they never mutate a resource, so a claim
            // whose adapter is gone or whose target is unavailable can still
            // be closed.
            if ($resource === null) {
                try {
                    $resource = $this->resource($snapshot->resource_type);
                } catch (ClaimException $exception) {
                    if ($exception->reason !== ClaimException::UNKNOWN_RESOURCE) {
                        throw $exception;
                    }
                }
            }
            if ($resource !== null) {
                try {
                    $resource->lock($snapshot->resource_key, $snapshot->site);
                } catch (ClaimException $exception) {
                    if ($approve || $exception->reason !== ClaimException::RESOURCE_UNAVAILABLE) {
                        throw $exception;
                    }
                }
            }
            if (is_string($snapshot->claimant_id) && $snapshot->claimant_id !== '') {
                $this->limits->lockAccount($snapshot->claimant_id);
            }
            $claim = TeamClaim::query()->lockForUpdate()->find($id)
                ?? throw new ClaimException(ClaimException::NOT_FOUND);

            if ($claim->status !== TeamClaim::PENDING) {
                throw new ClaimException(ClaimException::CONFLICT, 'This claim has already been decided.');
            }

            if ($approve) {
                if (! $resource instanceof ClaimResource) {
                    throw new \LogicException('An approval requires a claim resource adapter.');
                }
                $claimant = UserRepository::find($claim->claimant_id);
                if (! $claimant instanceof User) {
                    throw new ClaimException(ClaimException::CONFLICT, 'The claimant account no longer exists.');
                }
                $this->policy()->assertCanRequest($claimant);

                $name = $this->bounded($resource->teamName($claim->resource_key, $claim->site), 255, 'team name', 1);
                $team = $this->teams->create($name, $claimant);
                $resource->bind($claim->resource_key, $claim->site, $team);
                $claim->team_id = $team->getKey();
                $claim->status = TeamClaim::APPROVED;
            } else {
                $claim->status = TeamClaim::REJECTED;
            }

            $claim->decision_by = $reviewerId;
            $claim->decision_note = $note === '' ? null : $note;
            $claim->decided_at = now();
            $claim->pending_key = null;
            $claim->save();

            DB::table('team_claim_outbox')->insert([
                'claim_id' => $claim->getKey(),
                'kind' => ClaimOutbox::DECIDED,
                'status' => $claim->status,
                'created_at' => now(),
            ]);

            return $claim;
        });
    }

    protected function policy(): ClaimPolicy
    {
        $policy = app(ClaimPolicy::class);
        if (! $policy instanceof ClaimPolicy) {
            throw new \LogicException('teams.claims.policy must implement ClaimPolicy.');
        }

        return $policy;
    }

    protected function resource(string $type): ClaimResource
    {
        $class = config("teams.claims.resources.{$type}");
        if (! is_string($class) || $class === '') {
            throw new ClaimException(ClaimException::UNKNOWN_RESOURCE);
        }

        $resource = app($class);
        if (! $resource instanceof ClaimResource) {
            throw new \LogicException("Claim resource [{$type}] must implement ClaimResource.");
        }

        return $resource;
    }

    protected function bounded(string $value, int $max, string $field, int $min = 1, bool $allowEmpty = false): string
    {
        $value = trim($value);
        if (($value === '' && ! $allowEmpty) || mb_strlen($value) < $min || mb_strlen($value) > $max) {
            throw new ClaimException(ClaimException::INVALID, "Invalid {$field} length.");
        }

        return $value;
    }
}
