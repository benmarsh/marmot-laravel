<?php

namespace Marmot\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The standing rule: any table-backed stream not yet backfilled gets
 * backfilled, from the scheduler, with no queue and no instruction from
 * the server.
 */
class AutoBackfillTest extends TestCase
{
    private MockHandler $mock;

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('marmot.api_key', 'test-key');
        $app['config']->set('marmot.endpoint', 'https://marmot.test/v1/events');
        $app['config']->set('marmot.backfill.automatic', true);
        $app['config']->set('marmot.backfill.models_path', __DIR__.'/Fixtures');
        $app['config']->set('marmot.backfill.models_namespace', 'Marmot\\Laravel\\Tests\\Fixtures');
        $app['config']->set('app.timezone', 'UTC');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-28 12:10:00');

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('paid');
            $table->timestamps();
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->string('body');
        });

        $this->mock = new MockHandler;
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        $this->app->instance('marmot.http_client', new Client(['handler' => $stack]));
    }

    private function seedOrders(string $hour, int $count = 3): void
    {
        for ($i = 0; $i < $count; $i++) {
            Fixtures\Order::create(['created_at' => $hour, 'updated_at' => $hour]);
        }
    }

    private function okDryRun(int $overlapHours = 0, int $live = 0, int $backfill = 0): Response
    {
        return new Response(200, [], json_encode([
            'dry_run' => true, 'new_hours' => 1,
            'overlap' => [
                'hours' => $overlapHours, 'exact_matches' => 0, 'max_delta' => 0,
                'live_total' => $live, 'backfill_total' => $backfill,
            ],
        ]));
    }

    private function payload(int $index): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true);
    }

    public function test_it_backfills_a_table_backed_stream_without_being_asked(): void
    {
        $this->seedOrders('2026-08-27 10:15:00');

        $this->mock->append(
            $this->okDryRun(),
            new Response(200, [], json_encode(['inserted' => 1, 'skipped' => 0])),
        );

        $this->artisan('marmot:backfill-auto')->assertSuccessful();

        $this->assertCount(2, $this->history);
        $this->assertTrue($this->payload(0)['dry_run']);
        $this->assertFalse($this->payload(1)['dry_run']);
        $this->assertSame('eloquent.created: Marmot\Laravel\Tests\Fixtures\Order', $this->payload(1)['stream']);
    }

    public function test_it_does_nothing_when_the_flag_is_off(): void
    {
        config()->set('marmot.backfill.automatic', false);
        $this->seedOrders('2026-08-27 10:15:00');

        $this->artisan('marmot:backfill-auto')->assertSuccessful();

        $this->assertSame([], $this->history);
    }

    /** A standing rule, not a one-shot — but done means done. */
    public function test_a_backfilled_stream_is_not_scanned_again(): void
    {
        $this->seedOrders('2026-08-27 10:15:00');

        $this->mock->append(
            $this->okDryRun(),
            new Response(200, [], json_encode(['inserted' => 1, 'skipped' => 0])),
        );

        $this->artisan('marmot:backfill-auto')->assertSuccessful();
        $this->assertCount(2, $this->history);

        $this->artisan('marmot:backfill-auto')->assertSuccessful();
        $this->assertCount(2, $this->history); // No further requests.
    }

    public function test_force_re_runs_a_completed_stream(): void
    {
        $this->seedOrders('2026-08-27 10:15:00');
        Cache::forever('marmot:backfilled:'.md5('eloquent.created: Marmot\Laravel\Tests\Fixtures\Order'), time());

        $this->mock->append(
            $this->okDryRun(),
            new Response(200, [], json_encode(['inserted' => 1, 'skipped' => 0])),
        );

        $this->artisan('marmot:backfill-auto', ['--force' => true])->assertSuccessful();

        $this->assertCount(2, $this->history);
    }

    /**
     * Nobody is watching an unattended run, so a table that has lost rows
     * must not quietly teach a baseline that normal is lower than it is.
     */
    public function test_it_refuses_to_ship_history_that_disagrees_with_live_data(): void
    {
        $this->seedOrders('2026-08-27 10:15:00');

        // Live collected 100 across the overlap; the table can only account
        // for 10 — rows have been deleted since.
        $this->mock->append($this->okDryRun(overlapHours: 5, live: 100, backfill: 10));

        $this->artisan('marmot:backfill-auto')->assertSuccessful();

        $this->assertCount(1, $this->history); // Dry run only; nothing committed.
        $this->assertTrue($this->payload(0)['dry_run']);
    }

    /** A stream discovery hasn't reached yet is retried, not written off. */
    public function test_a_stream_not_yet_seen_live_is_retried_next_run(): void
    {
        $this->seedOrders('2026-08-27 10:15:00');

        $this->mock->append(new Response(422, [], json_encode([
            'message' => "Stream 'x' has not been seen live yet — install the SDK, let discovery run, then backfill.",
        ])));

        $this->artisan('marmot:backfill-auto')->assertSuccessful();

        // Not marked done, so the next scheduled run tries again.
        $this->mock->append(
            $this->okDryRun(),
            new Response(200, [], json_encode(['inserted' => 1, 'skipped' => 0])),
        );

        $this->artisan('marmot:backfill-auto')->assertSuccessful();

        $this->assertCount(3, $this->history);
        $this->assertFalse($this->payload(2)['dry_run']);
    }

    /** Non-UTC apps can shift every bucket — that needs a human. */
    public function test_it_declines_to_run_unattended_on_a_non_utc_app(): void
    {
        config()->set('app.timezone', 'Europe/London');
        $this->seedOrders('2026-08-27 10:15:00');

        $this->artisan('marmot:backfill-auto')->assertSuccessful();

        $this->assertSame([], $this->history);
    }

    public function test_models_without_a_timestamp_column_are_skipped(): void
    {
        $this->seedOrders('2026-08-27 10:15:00');

        $this->mock->append(
            $this->okDryRun(),
            new Response(200, [], json_encode(['inserted' => 1, 'skipped' => 0])),
        );

        $this->artisan('marmot:backfill-auto')->assertSuccessful();

        foreach ($this->history as $entry) {
            $body = json_decode((string) $entry['request']->getBody(), true);
            $this->assertStringNotContainsString('Note', $body['stream']);
        }
    }

    /** An oversized table is left to a human rather than scanned unattended. */
    public function test_a_table_over_the_ceiling_is_skipped(): void
    {
        config()->set('marmot.backfill.max_rows', 1);
        $this->seedOrders('2026-08-27 10:15:00', 5);

        $this->artisan('marmot:backfill-auto')->assertSuccessful();

        $this->assertSame([], $this->history);
    }
}
