<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Contracts\ClaimPolicy;
use Goldnead\Teams\Support\DenyClaimPolicy;
use Goldnead\Teams\Tests\TestCase;
use Statamic\Contracts\Auth\User;

class ClaimPolicyConfigTest extends TestCase
{
    public function test_policy_is_resolved_from_config(): void
    {
        config(['teams.claims.policy' => ConfiguredClaimPolicy::class]);

        $this->assertInstanceOf(ConfiguredClaimPolicy::class, app(ClaimPolicy::class));
    }

    public function test_default_is_deny_policy(): void
    {
        $this->assertInstanceOf(DenyClaimPolicy::class, app(ClaimPolicy::class));
    }

    public function test_host_binding_wins_over_config(): void
    {
        config(['teams.claims.policy' => ConfiguredClaimPolicy::class]);
        app()->bind(ClaimPolicy::class, HostClaimPolicy::class);

        $this->assertInstanceOf(HostClaimPolicy::class, app(ClaimPolicy::class));
    }
}

class ConfiguredClaimPolicy implements ClaimPolicy
{
    public function assertCanRequest(User $actor): void {}

    public function assertCanReview(User $actor): void {}
}

class HostClaimPolicy extends ConfiguredClaimPolicy {}
