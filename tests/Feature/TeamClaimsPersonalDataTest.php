<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\PersonalData\TeamClaimsContributor;
use Goldnead\Teams\PersonalData\TeamClaimsEraser;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

class TeamClaimsPersonalDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! interface_exists('Goldnead\\Accounts\\Contracts\\ContributesPersonalData')
            || ! interface_exists('Goldnead\\Accounts\\Contracts\\ErasesPersonalData')) {
            $this->markTestSkipped('The Accounts addon contracts are not installed in this test harness.');
        }
    }

    #[Test]
    public function contributor_exports_separate_role_allowlists_without_internal_identifiers(): void
    {
        $claimant = $this->makeUser('claimant@example.test');
        $reviewer = $this->makeUser('reviewer@example.test');
        $this->insertClaim((string) $claimant->id(), (string) $reviewer->id());

        $data = (new TeamClaimsContributor)->collect(User::find($claimant->id()));
        $application = $data['applications'][0];

        $this->assertSame(['applications', 'reviews'], array_keys($data));
        $this->assertArrayHasKey('justification', $application);
        $this->assertArrayHasKey('contact', $application);
        $this->assertArrayNotHasKey('decision_by', $application);
        $this->assertArrayNotHasKey('decision_note', $application);
        $this->assertArrayNotHasKey('claimant_id', $application);
        $this->assertArrayNotHasKey('pending_key', $application);

        $review = (new TeamClaimsContributor)->collect(User::find($reviewer->id()))['reviews'][0];
        $this->assertArrayHasKey('decision_note', $review);
        $this->assertArrayNotHasKey('claimant_id', $review);
        $this->assertArrayNotHasKey('contact', $review);
        $this->assertArrayNotHasKey('justification', $review);
        $this->assertArrayNotHasKey('team_id', $review);
    }

    #[Test]
    public function eraser_removes_evidence_and_actor_ids_but_retains_decided_history(): void
    {
        $claimant = $this->makeUser('claimant@example.test');
        $reviewer = $this->makeUser('reviewer@example.test');
        $pendingId = $this->insertClaim((string) $claimant->id(), null, 'pending');
        $decidedId = $this->insertClaim((string) $claimant->id(), (string) $reviewer->id(), 'approved');
        DB::table('team_claim_limit_locks')->insert(['claimant_id' => (string) $claimant->id()]);
        DB::table('team_claim_outbox')->insert([
            ['claim_id' => $pendingId, 'kind' => 'requested', 'status' => 'pending', 'created_at' => now()],
            ['claim_id' => $decidedId, 'kind' => 'requested', 'status' => 'pending', 'created_at' => now()],
        ]);

        $result = (new TeamClaimsEraser)->erase(User::find($claimant->id()));

        $this->assertSame('team_claims', $result->key);
        $pending = DB::table('team_claims')->find($pendingId);
        $this->assertNull($pending->claimant_id);
        $this->assertSame('', $pending->justification);
        $this->assertSame('', $pending->contact);
        $this->assertSame('withdrawn', $pending->status);
        $this->assertNull($pending->pending_key);

        $decided = DB::table('team_claims')->find($decidedId);
        $this->assertNull($decided->claimant_id);
        $this->assertSame((string) $reviewer->id(), $decided->decision_by);
        $this->assertNull($decided->decision_note);
        $this->assertSame('approved', $decided->status);
        $this->assertNotEmpty($decided->resource_key);
        $this->assertSame(0, DB::table('team_claim_outbox')->whereIn('claim_id', [$pendingId, $decidedId])->count());
        $this->assertSame(0, DB::table('team_claim_limit_locks')->where('claimant_id', (string) $claimant->id())->count());

        (new TeamClaimsEraser)->erase(User::find($reviewer->id()));
        $this->assertNull(DB::table('team_claims')->find($decidedId)->decision_by);
    }

    #[Test]
    public function eraser_stays_available_on_the_old_schema_and_aborts_without_partial_erasure(): void
    {
        Schema::table('team_claims', function ($table): void {
            $table->string('claimant_id', 191)->nullable(false)->change();
        });

        $claimant = $this->makeUser('legacy-claimant@example.test');
        $claimId = $this->insertClaim((string) $claimant->id(), null);
        $contributor = new TeamClaimsContributor;
        $eraser = new TeamClaimsEraser;

        $this->assertTrue($contributor->available());
        $this->assertTrue($eraser->available());
        $this->assertCount(1, $contributor->collect(User::find($claimant->id()))['applications']);

        try {
            DB::transaction(fn () => $eraser->erase(User::find($claimant->id())));
            $this->fail('Erasure should fail while claimant_id is still required.');
        } catch (QueryException) {
            $claim = DB::table('team_claims')->find($claimId);
            $this->assertSame((string) $claimant->id(), $claim->claimant_id);
            $this->assertSame('I maintain this resource.', $claim->justification);
            $this->assertSame('person@example.test', $claim->contact);
        }
    }

    private function insertClaim(string $claimantId, ?string $reviewerId, string $status = 'rejected'): int
    {
        return (int) DB::table('team_claims')->insertGetId([
            'resource_type' => 'catalogue',
            'resource_key' => 'resource-123',
            'site' => 'default',
            'claimant_id' => $claimantId,
            'justification' => 'I maintain this resource.',
            'contact' => 'person@example.test',
            'status' => $status,
            'decision_by' => $reviewerId,
            'decision_note' => $reviewerId === null ? null : 'Reviewed with claimant context.',
            'decided_at' => $reviewerId === null ? null : now(),
            'pending_key' => $status === 'pending' ? hash('sha256', uniqid('', true)) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
