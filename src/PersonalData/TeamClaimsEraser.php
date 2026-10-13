<?php

namespace Goldnead\Teams\PersonalData;

use Goldnead\Accounts\Contracts\ErasesPersonalData;
use Goldnead\Accounts\PersonalData\ErasureResult;
use Goldnead\Teams\Models\TeamClaim;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Statamic\Auth\User;

class TeamClaimsEraser implements ErasesPersonalData
{
    public function key(): string
    {
        return 'team_claims';
    }

    public function label(): string
    {
        return 'Team claim records';
    }

    public function available(): bool
    {
        return Schema::hasTable('team_claims');
    }

    public function blockers(User $user, string $audience = 'customer'): array
    {
        return [];
    }

    public function erase(User $user): ErasureResult
    {
        if (! $this->available()) {
            return new ErasureResult($this->key());
        }

        $id = (string) $user->getAuthIdentifier();
        $applications = DB::table('team_claims')->where('claimant_id', $id);
        $applicationCount = (clone $applications)->count();
        $applications->update([
            'claimant_id' => null,
            'justification' => '',
            'contact' => '',
            'pending_key' => null,
            'decision_note' => null,
            'status' => DB::raw("CASE WHEN status = '".TeamClaim::PENDING."' THEN '".TeamClaim::WITHDRAWN."' ELSE status END"),
        ]);

        $reviews = DB::table('team_claims')->where('decision_by', $id);
        $reviewCount = (clone $reviews)->count();
        $reviews->update(['decision_by' => null, 'decision_note' => null]);

        return new ErasureResult(
            $this->key(),
            anonymized: array_filter([
                'applications' => $applicationCount,
                'reviews' => $reviewCount,
            ]),
            retained: array_filter(['claim_history' => $applicationCount + $reviewCount]),
        );
    }
}
