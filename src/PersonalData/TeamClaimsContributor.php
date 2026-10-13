<?php

namespace Goldnead\Teams\PersonalData;

use Goldnead\Accounts\Contracts\ContributesPersonalData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Statamic\Auth\User;

class TeamClaimsContributor implements ContributesPersonalData
{
    public function key(): string
    {
        return 'team_claims';
    }

    public function label(): string
    {
        return 'Team claims';
    }

    public function available(): bool
    {
        return Schema::hasTable('team_claims');
    }

    public function collect(User $user): array
    {
        if (! $this->available()) {
            return ['applications' => [], 'reviews' => []];
        }

        $id = (string) $user->getAuthIdentifier();

        return [
            'applications' => DB::table('team_claims')
                ->where('claimant_id', $id)
                ->orderBy('id')
                ->get(['resource_type', 'resource_key', 'site', 'status', 'team_id', 'justification', 'contact', 'created_at', 'updated_at', 'decided_at'])
                ->map(fn ($row) => (array) $row)
                ->all(),
            'reviews' => DB::table('team_claims')
                ->where('decision_by', $id)
                ->orderBy('id')
                ->get(['resource_type', 'resource_key', 'site', 'status', 'decision_note', 'created_at', 'updated_at', 'decided_at'])
                ->map(fn ($row) => (array) $row)
                ->all(),
        ];
    }
}
