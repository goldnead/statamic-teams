<?php

namespace Goldnead\Teams\Services;

use Goldnead\Teams\Exceptions\ClaimException;
use Illuminate\Support\Facades\DB;

class ClaimLimitService
{
    /** Serialize this claimant's create and decision transactions. */
    public function lockAccount(string $claimantId): void
    {
        DB::table('team_claim_limit_locks')->insertOrIgnore(['claimant_id' => $claimantId]);

        DB::table('team_claim_limit_locks')
            ->where('claimant_id', $claimantId)
            ->lockForUpdate()
            ->first();
    }

    public function assertCanRequest(string $claimantId, string $type, string $key, string $site): void
    {
        $limits = config('teams.claims.limits', []);
        $limits = is_array($limits) ? $limits : [];
        $max = $this->setting($limits, 'max_per_account', 3);
        $window = $this->setting($limits, 'window_minutes', 1440);
        $cooldown = $this->setting($limits, 'rejected_cooldown_minutes', 1440);

        if ($max > 0 && $window > 0) {
            $count = DB::table('team_claims')
                ->where('claimant_id', $claimantId)
                ->where('created_at', '>=', now()->subMinutes($window))
                ->count();

            if ($count >= $max) {
                throw new ClaimException(ClaimException::RATE_LIMITED);
            }
        }

        if ($cooldown > 0 && DB::table('team_claims')
            ->where('claimant_id', $claimantId)
            ->where('resource_type', $type)
            ->where('resource_key', $key)
            ->where('site', $site)
            ->where('status', 'rejected')
            ->where('decided_at', '>=', now()->subMinutes($cooldown))
            ->exists()) {
            throw new ClaimException(ClaimException::COOLDOWN);
        }
    }

    private function setting(array $limits, string $key, int $default): int
    {
        $value = $limits[$key] ?? $default;
        if (! is_int($value) || $value < 0) {
            throw new \LogicException("teams.claims.limits.{$key} must be a non-negative integer.");
        }

        return $value;
    }
}
