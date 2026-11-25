<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Events\TeamEvent;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Teams\Support\Wiring;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

require_once __DIR__.'/../Fakes/webhook-manager.php';

/**
 * Stille Fehler aus dem Audit, Abschnitt Mittel (Nr. 5 bis 14).
 */
class SilentFailuresMidTest extends TestCase
{
    /**
     * Zählt, wie oft eine neue Transaktion beginnt, und merkt sich für jede
     * Query auf $table, wie viele davon zu diesem Zeitpunkt schon begonnen
     * waren. Die Testumgebung hüllt jeden Test selbst in eine Transaktion,
     * deshalb zählt nur der Zuwachs während der Aktion.
     *
     * @return list<int> Zuwachs je Query auf $table (0 = keine eigene Transaktion).
     */
    protected function transactionDepthsOn(string $table, callable $action): array
    {
        $began = 0;
        $base = null;
        $depths = [];

        Event::listen(TransactionBeginning::class, function () use (&$began) {
            $began++;
        });

        DB::listen(function (QueryExecuted $query) use (&$depths, &$began, &$base, $table) {
            if (str_contains($query->sql, $table)) {
                $depths[] = $began - ($base ?? 0);
            }
        });

        $base = $began;
        $action();

        return $depths;
    }

    #[Test]
    public function invitation_check_and_write_share_a_transaction(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));

        $depths = $this->transactionDepthsOn('team_invitations', fn () => Teams::invite($team, 'bob@example.com'));

        $this->assertNotEmpty($depths);
        $this->assertNotContains(0, $depths, 'Einladung: Prüfung und Anlage laufen außerhalb einer Transaktion.');
    }

    #[Test]
    public function membership_check_runs_inside_the_transaction(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');

        $depths = $this->transactionDepthsOn('team_members', fn () => Teams::addMember($team, $bob));

        $this->assertNotEmpty($depths);
        $this->assertNotContains(0, $depths, 'hasMember() läuft außerhalb der Transaktion.');
    }

    #[Test]
    public function wiring_logs_when_a_table_is_missing(): void
    {
        Log::spy();

        $wiring = app(Wiring::class);
        $safely = new ReflectionMethod($wiring, 'safely');
        $safely->setAccessible(true);

        $result = $safely->invoke($wiring, fn () => ['listeners' => 1], ['team_teststufe_fehlt']);

        $this->assertSame([], $result);
        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function webhook_dispatch_logs_a_missing_trigger(): void
    {
        Log::spy();

        $bridge = new WebhookManagerBridge;
        $dispatch = new ReflectionMethod($bridge, 'dispatch');
        $dispatch->setAccessible(true);

        $dispatch->invoke($bridge, 'teams.unbekannt', Mockery::mock(TeamEvent::class));

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message) => str_contains($message, 'teams.unbekannt'),
        )->once();
    }

    #[Test]
    public function team_deletion_runs_in_a_transaction(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $began = 0;
        Event::listen(TransactionBeginning::class, function () use (&$began) {
            $began++;
        });

        Teams::delete($team);

        $this->assertGreaterThan(0, $began, 'TeamService::delete() läuft ohne Transaktion.');
    }
}
