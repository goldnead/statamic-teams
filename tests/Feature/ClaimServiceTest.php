<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Contracts\ClaimPolicy;
use Goldnead\Teams\Contracts\ClaimResource;
use Goldnead\Teams\Events\ClaimDecided;
use Goldnead\Teams\Events\MemberJoined;
use Goldnead\Teams\Events\TeamCreated;
use Goldnead\Teams\Exceptions\ClaimException;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Models\TeamClaim;
use Goldnead\Teams\Services\ClaimService;
use Goldnead\Teams\Support\DenyClaimPolicy;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Statamic\Contracts\Auth\User;

class ClaimServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('claim_targets', function (Blueprint $table) {
            $table->string('key');
            $table->string('site');
            $table->string('name');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->primary(['key', 'site']);
        });
        DB::table('claim_targets')->insert(['key' => 'choir-1', 'site' => 'main', 'name' => 'Choir One']);
        config(['teams.claims.resources.choir' => TestClaimResource::class]);
        app()->instance(ClaimPolicy::class, new TestClaimPolicy);
        app()->instance(TestClaimResource::class, new TestClaimResource);
    }

    public function test_request_deduplicates_pending_claims_and_hides_evidence(): void
    {
        $actor = $this->makeUser('claimant@example.test');
        $service = app(ClaimService::class);
        $first = $service->request('choir', 'choir-1', 'main', $actor, 'I lead this choir and can verify the public listing.', 'adrian@example.test');
        $second = $service->request('choir', 'choir-1', 'main', $actor, 'A changed explanation should not replace the pending request.', 'other@example.test');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TeamClaim::query()->count());
        $this->assertSame(1, DB::table('team_claim_outbox')->where('kind', 'requested')->count());
        $this->assertArrayNotHasKey('justification', $first->toArray());
        $this->assertArrayNotHasKey('contact', $first->toArray());
    }

    public function test_default_policy_denies_and_review_cannot_be_self_approved(): void
    {
        $actor = $this->makeUser('requester@example.test');
        $this->app->instance(ClaimPolicy::class, new DenyClaimPolicy);
        try {
            app(ClaimService::class)->request('choir', 'choir-1', 'main', $actor, 'I lead this choir and can verify the public listing.', 'requester@example.test');
            $this->fail('Default policy must deny requests.');
        } catch (TeamsException $exception) {
            $this->assertSame(TeamsException::FORBIDDEN, $exception->reason);
        }

        $this->app->instance(ClaimPolicy::class, new TestClaimPolicy);
        $claim = $this->requestAs($actor);
        $this->expectException(ClaimException::class);
        $this->expectExceptionMessage('A claimant cannot review their own claim.');
        app(ClaimService::class)->approve($claim->id, $actor, '');
    }

    public function test_approval_creates_owned_team_and_binds_in_one_operation(): void
    {
        $claimant = $this->makeUser('owner@example.test');
        $reviewer = $this->makeUser('reviewer@example.test');
        $claim = $this->requestAs($claimant);

        $approved = app(ClaimService::class)->approve($claim->id, $reviewer, 'Verified by the directory team.');

        $this->assertSame(TeamClaim::APPROVED, $approved->status);
        $this->assertSame((string) $claimant->id(), (string) Team::findOrFail($approved->team_id)->owner_id);
        $this->assertSame($approved->team_id, DB::table('claim_targets')->value('team_id'));
        $outbox = DB::table('team_claim_outbox')->where('claim_id', $claim->id)->orderBy('id')->get();
        $this->assertSame(['requested', 'decided'], $outbox->pluck('kind')->all());
        $this->assertSame(['pending', 'approved'], $outbox->pluck('status')->all());
        $this->assertSame([0, 0], $outbox->pluck('attempts')->all());
        $this->assertSame([null, null], $outbox->pluck('last_error_code')->all());
        $this->assertTrue($outbox->every(fn ($row) => $row->available_at !== null));
    }

    public function test_reject_requires_a_reason_and_decision_cannot_be_replayed(): void
    {
        $claimant = $this->makeUser('requester2@example.test');
        $reviewer = $this->makeUser('reviewer2@example.test');
        $claim = $this->requestAs($claimant);

        try {
            app(ClaimService::class)->reject($claim->id, $reviewer, ' ');
            $this->fail('Reject reason is required.');
        } catch (ClaimException $exception) {
            $this->assertSame(ClaimException::INVALID, $exception->reason);
        }

        $rejected = app(ClaimService::class)->reject($claim->id, $reviewer, 'The evidence did not match the official page.');
        $this->assertSame(TeamClaim::REJECTED, $rejected->status);

        try {
            app(ClaimService::class)->approve($claim->id, $reviewer, '');
            $this->fail('A decision cannot be replayed.');
        } catch (ClaimException $exception) {
            $this->assertSame(ClaimException::CONFLICT, $exception->reason);
        }
    }

    public function test_approval_rolls_back_team_membership_binding_and_events_on_bind_failure(): void
    {
        $claimant = $this->makeUser('rollback@example.test');
        $reviewer = $this->makeUser('rollback-reviewer@example.test');
        $claim = $this->requestAs($claimant);
        $resource = new TestClaimResource;
        $resource->throwOnBind = true;
        $this->app->instance(TestClaimResource::class, $resource);
        Event::fake([TeamCreated::class, MemberJoined::class, ClaimDecided::class]);

        try {
            app(ClaimService::class)->approve($claim->id, $reviewer, '');
            $this->fail('Binding failure should abort approval.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('bind failed', $exception->getMessage());
        }

        $this->assertSame(0, Team::query()->count());
        $this->assertSame(0, DB::table('team_members')->count());
        $this->assertSame(TeamClaim::PENDING, $claim->fresh()->status);
        $this->assertSame(1, DB::table('team_claim_outbox')->where('claim_id', $claim->id)->count());
        $this->assertNull(DB::table('claim_targets')->value('team_id'));
        Event::assertNotDispatched(TeamCreated::class);
        Event::assertNotDispatched(MemberJoined::class);
        Event::assertNotDispatched(ClaimDecided::class);
    }

    public function test_bound_resource_and_missing_claimant_fail_closed(): void
    {
        config(['teams.claims.limits.rejected_cooldown_minutes' => 0]);
        $claimant = $this->makeUser('bound@example.test');
        $reviewer = $this->makeUser('bound-reviewer@example.test');
        $claim = $this->requestAs($claimant);
        DB::table('claim_targets')->update(['team_id' => 999]);
        try {
            app(ClaimService::class)->approve($claim->id, $reviewer, '');
            $this->fail('A bound resource cannot be claimed again.');
        } catch (ClaimException $exception) {
            $this->assertSame(ClaimException::RESOURCE_UNAVAILABLE, $exception->reason);
        }

        $rejected = app(ClaimService::class)->reject($claim->id, $reviewer, 'Another team already owns this resource.');
        $this->assertSame(TeamClaim::REJECTED, $rejected->status);

        DB::table('claim_targets')->update(['team_id' => null]);
        $claim = $this->requestAs($claimant);
        $claimant->delete();
        try {
            app(ClaimService::class)->approve($claim->id, $reviewer, '');
            $this->fail('A missing claimant cannot receive a team.');
        } catch (ClaimException $exception) {
            $this->assertSame(ClaimException::CONFLICT, $exception->reason);
        }
    }

    public function test_account_limit_counts_claims_across_resources_and_pending_duplicate_consumes_no_extra_quota(): void
    {
        config(['teams.claims.limits' => [
            'max_per_account' => 1,
            'window_minutes' => 1440,
            'rejected_cooldown_minutes' => 0,
        ]]);
        $claimant = $this->makeUser('limit@example.test');
        $service = app(ClaimService::class);
        $first = $service->request('choir', 'choir-1', 'main', $claimant, 'I lead this choir and can verify the public listing.', 'claim@example.test');
        $duplicate = $service->request('choir', 'choir-1', 'main', $claimant, 'Different text does not replace pending evidence.', 'different@example.test');
        $this->assertSame($first->id, $duplicate->id);

        DB::table('claim_targets')->insert(['key' => 'choir-2', 'site' => 'main', 'name' => 'Choir Two']);
        try {
            $service->request('choir', 'choir-2', 'main', $claimant, 'Another resource worth claiming.', 'claim@example.test');
            $this->fail('A second pending claim should exceed the account limit.');
        } catch (ClaimException $exception) {
            $this->assertSame(ClaimException::RATE_LIMITED, $exception->reason);
        }
        $this->assertSame(1, TeamClaim::query()->count());
    }

    public function test_rejection_cooldown_is_scoped_to_site_and_expires(): void
    {
        config(['teams.claims.limits' => [
            'max_per_account' => 0,
            'window_minutes' => 0,
            'rejected_cooldown_minutes' => 60,
        ]]);
        $claimant = $this->makeUser('cooldown@example.test');
        $reviewer = $this->makeUser('cooldown-reviewer@example.test');
        $claim = $this->requestAs($claimant);
        app(ClaimService::class)->reject($claim->id, $reviewer, 'The evidence did not match the official page.');

        try {
            $this->requestAs($claimant);
            $this->fail('A rejection should start the cooldown for the same site.');
        } catch (ClaimException $exception) {
            $this->assertSame(ClaimException::COOLDOWN, $exception->reason);
        }

        DB::table('claim_targets')->insert(['key' => 'choir-1', 'site' => 'secondary', 'name' => 'Choir One']);
        $crossSite = app(ClaimService::class)->request('choir', 'choir-1', 'secondary', $claimant, 'A different-site request with enough detail.', 'claim@example.test');
        $this->assertSame('secondary', $crossSite->site);

        $this->travel(61)->minutes();
        $afterExpiry = app(ClaimService::class)->request('choir', 'choir-1', 'main', $claimant, 'A request after the cooldown period has expired.', 'claim@example.test');
        $this->assertSame(TeamClaim::PENDING, $afterExpiry->status);
    }

    public function test_rejection_closes_a_claim_whose_adapter_is_no_longer_registered(): void
    {
        $claimant = $this->makeUser('unregistered@example.test');
        $reviewer = $this->makeUser('unregistered-reviewer@example.test');
        $claim = $this->requestAs($claimant);
        config(['teams.claims.resources' => []]);

        $rejected = app(ClaimService::class)->reject($claim->id, $reviewer, 'The directory no longer lists this choir.');

        $this->assertSame(TeamClaim::REJECTED, $rejected->status);
        $this->assertSame(2, DB::table('team_claim_outbox')->where('claim_id', $claim->id)->count());
    }

    public function test_limit_and_outbox_migration_rolls_back_cleanly(): void
    {
        $this->artisan('migrate:rollback', [
            '--path' => realpath(__DIR__.'/../../database/migrations/2026_10_13_000003_create_team_claim_limit_locks_and_outbox.php'),
            '--realpath' => true,
        ])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('team_claim_limit_locks'));
        $this->assertFalse(Schema::hasTable('team_claim_outbox'));
        $this->assertTrue(Schema::hasTable('team_claims'));
    }

    protected function requestAs(User $actor): TeamClaim
    {
        return app(ClaimService::class)->request('choir', 'choir-1', 'main', $actor, 'I lead this choir and can verify the public listing.', 'claim@example.test');
    }
}

class TestClaimPolicy implements ClaimPolicy
{
    public function assertCanRequest(User $actor): void {}

    public function assertCanReview(User $actor): void {}
}

class TestClaimResource implements ClaimResource
{
    public bool $throwOnBind = false;

    public function lock(string $key, string $site): void
    {
        $target = DB::table('claim_targets')->where('key', $key)->where('site', $site)->lockForUpdate()->first();
        if ($target === null || $target->team_id !== null) {
            throw new ClaimException(ClaimException::RESOURCE_UNAVAILABLE);
        }
    }

    public function teamName(string $key, string $site): string
    {
        return (string) DB::table('claim_targets')->where('key', $key)->where('site', $site)->value('name');
    }

    public function bind(string $key, string $site, Team $team): void
    {
        if ($this->throwOnBind) {
            throw new \RuntimeException('bind failed');
        }
        DB::table('claim_targets')->where('key', $key)->where('site', $site)->update(['team_id' => $team->getKey()]);
    }
}
