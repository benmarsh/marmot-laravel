<?php

namespace Marmot\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Marmot\Laravel\Jobs\RunBackfillInstruction;
use Marmot\Laravel\Support\EventBuffer;
use Marmot\Laravel\Support\InstructionHandler;
use Marmot\Laravel\Support\ModelStreams;
use Marmot\Laravel\Support\SchemaReporter;

/**
 * The SDK half of the control channel (build brief §4). Contract:
 * docs/marmot-control-channel-protocol.md in the marmot repo.
 */
class ControlChannelTest extends TestCase
{
    private MockHandler $mock;

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('marmot.api_key', 'test-key');
        $app['config']->set('marmot.endpoint', 'https://marmot.test/v1/events');
        $app['config']->set('marmot.backfill.models_path', __DIR__.'/Fixtures');
        $app['config']->set('marmot.backfill.models_namespace', 'Marmot\\Laravel\\Tests\\Fixtures');
        // A real (non-sync) queue, so dispatch doesn't run inline.
        $app['config']->set('queue.default', 'database');
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

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->mock = new MockHandler;
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        $this->app->instance('marmot.http_client', new Client(['handler' => $stack]));
    }

    private function lastPayload(): array
    {
        $request = end($this->history)['request'];

        return json_decode((string) $request->getBody(), true);
    }

    private function flushWith(array $responseBody): void
    {
        $this->mock->append(new Response(200, [], json_encode($responseBody)));

        $buffer = new EventBuffer($this->app->make('marmot.http_client'));
        $buffer->push('OrderPlaced');
        $buffer->flush();
    }

    // ---- Schema reporting: the SDK's veto -------------------------------

    public function test_schema_reporting_is_off_by_default(): void
    {
        $this->assertFalse(SchemaReporter::enabled());
        $this->assertSame([], SchemaReporter::due());

        $this->flushWith(['ok' => true]);

        $this->assertArrayNotHasKey('schema_report', $this->lastPayload());
    }

    public function test_enabling_reporting_describes_tables_in_names_and_counts_only(): void
    {
        config()->set('marmot.schema_reporting', true);

        \Marmot\Laravel\Tests\Fixtures\Order::create(['created_at' => '2026-06-01 10:00:00', 'updated_at' => now()]);

        $this->flushWith(['ok' => true]);

        $report = collect($this->lastPayload()['schema_report'])->keyBy('stream');
        $orders = $report['eloquent.created: Marmot\Laravel\Tests\Fixtures\Order'];

        $this->assertSame('orders', $orders['table']);
        $this->assertSame('created_at', $orders['timestamp_column']);
        $this->assertSame(1, $orders['row_count_approx']);
        $this->assertStringStartsWith('2026-06-01', $orders['earliest_at']);
        $this->assertFalse($orders['soft_deletes']);

        // Names and counts only — no column values anywhere in the payload.
        $this->assertSame(
            ['stream', 'table', 'timestamp_column', 'row_count_approx', 'earliest_at', 'soft_deletes'],
            array_keys($orders),
        );
    }

    /**
     * Sizing a table must never scan it. This runs automatically, so a
     * count(*) would be telemetry triggering an unbounded scan of a
     * customer table — the "never affects your app" promise broken by the
     * thing that promises it.
     */
    public function test_sizing_a_table_never_issues_a_count(): void
    {
        for ($i = 0; $i < 5; $i++) {
            \Marmot\Laravel\Tests\Fixtures\Order::create(['created_at' => now(), 'updated_at' => now()]);
        }

        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $rows = ModelStreams::approximateRows('orders');

        // The primary key's high-water mark — an index lookup, and an
        // over-estimate once rows are deleted, which is the safe direction
        // for a "too heavy" ceiling.
        $this->assertSame(5, $rows);
        $this->assertNotEmpty($queries, 'No queries observed — the assertion below would be vacuous.');

        foreach ($queries as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('count(', $sql,
                "Sizing issued a counting query: {$sql}");
        }
    }

    /** Unknown is reported as null — never as zero, which reads as "small". */
    public function test_an_unsizeable_table_reports_null_rather_than_guessing(): void
    {
        // No id column, so there's no cheap high-water mark to read.
        \Illuminate\Support\Facades\Schema::create('legacy', function (Blueprint $table) {
            $table->string('ref');
            $table->timestamps();
        });

        $this->assertNull(ModelStreams::approximateRows('legacy'));
    }

    public function test_soft_deleting_models_are_flagged(): void
    {
        config()->set('marmot.schema_reporting', true);

        $report = collect(SchemaReporter::due())->keyBy('stream');

        $this->assertTrue($report['eloquent.created: Marmot\Laravel\Tests\Fixtures\Refund']['soft_deletes']);
        $this->assertFalse($report['eloquent.created: Marmot\Laravel\Tests\Fixtures\Order']['soft_deletes']);
    }

    /** A model with no created-at column has no history to backfill. */
    public function test_models_without_a_timestamp_column_are_never_reported(): void
    {
        config()->set('marmot.schema_reporting', true);

        $streams = array_column(SchemaReporter::due(), 'stream');

        $this->assertNotContains('eloquent.created: Marmot\Laravel\Tests\Fixtures\Note', $streams);
    }

    /** An allowlist is consent for exactly those tables and nothing else. */
    public function test_a_table_allowlist_limits_what_is_reported(): void
    {
        config()->set('marmot.schema_reporting', false);
        config()->set('marmot.schema_reporting_tables', ['orders']);

        $this->assertTrue(SchemaReporter::enabled());

        $streams = array_column(SchemaReporter::due(), 'stream');

        $this->assertSame(['eloquent.created: Marmot\Laravel\Tests\Fixtures\Order'], $streams);
    }

    public function test_a_reported_stream_is_not_re_reported_every_flush(): void
    {
        config()->set('marmot.schema_reporting', true);

        $this->assertNotSame([], SchemaReporter::due());
        $this->assertSame([], SchemaReporter::due());

        // ...but the daily refresh brings it back (tables get renamed).
        Carbon::setTestNow(now()->addDays(2));
        $this->assertNotSame([], SchemaReporter::due());
    }

    // ---- Instructions: resolve locally or refuse ------------------------

    private function instruction(array $overrides = []): array
    {
        return array_merge([
            'id' => 'b3f1c2d4-0000-4000-8000-000000000001',
            'type' => 'backfill',
            'stream_id' => 'a71c0000-0000-4000-8000-000000000002',
            'stream' => 'eloquent.created: Marmot\Laravel\Tests\Fixtures\Order',
            'weeks' => 4,
        ], $overrides);
    }

    public function test_an_instruction_dispatches_backfill_work_to_the_queue(): void
    {
        Bus::fake();

        InstructionHandler::handle([$this->instruction()]);

        Bus::assertDispatched(RunBackfillInstruction::class);
    }

    /**
     * The veto: an instruction naming a stream this app doesn't have is
     * refused outright. Marmot cannot make an app query something it
     * wouldn't have reported itself.
     */
    public function test_an_unresolvable_stream_is_refused(): void
    {
        Bus::fake();

        InstructionHandler::handle([$this->instruction([
            'stream' => 'eloquent.created: App\Models\SomethingElse',
        ])]);

        Bus::assertNothingDispatched();
    }

    public function test_a_table_name_on_the_wire_is_ignored_entirely(): void
    {
        Bus::fake();

        // A hostile instruction naming a table directly: the protocol has no
        // such field, and the SDK resolves tables only from its own models.
        InstructionHandler::handle([$this->instruction([
            'stream' => 'users',
            'table' => 'users',
        ])]);

        Bus::assertNothingDispatched();
    }

    /** Delivery is at-least-once, so the same instruction arrives repeatedly. */
    public function test_a_repeated_instruction_is_only_actioned_once(): void
    {
        Bus::fake();

        InstructionHandler::handle([$this->instruction()]);
        InstructionHandler::handle([$this->instruction()]);
        InstructionHandler::handle([$this->instruction()]);

        Bus::assertDispatchedTimes(RunBackfillInstruction::class, 1);
    }

    public function test_malformed_instructions_are_ignored(): void
    {
        Bus::fake();

        InstructionHandler::handle([
            ['type' => 'backfill'],                          // no id or stream
            $this->instruction(['type' => 'rm-rf']),         // unknown type
            $this->instruction(['id' => '', 'stream' => '']),
        ]);

        Bus::assertNothingDispatched();
    }

    public function test_instructions_ride_the_flush_response(): void
    {
        Bus::fake();

        $this->flushWith(['ok' => true, 'instructions' => [$this->instruction()]]);

        Bus::assertDispatched(RunBackfillInstruction::class);
    }

    public function test_a_normal_response_dispatches_nothing(): void
    {
        Bus::fake();

        $this->flushWith(['ok' => true, 'instructions' => []]);

        Bus::assertNothingDispatched();
    }

    /**
     * Never in the flush path (§4b). A sync queue would run the job inline
     * — inside the host app's web request — so those apps park the work for
     * the scheduler instead.
     */
    public function test_a_sync_queue_parks_work_instead_of_running_it_inline(): void
    {
        config()->set('queue.default', 'sync');
        Bus::fake();

        InstructionHandler::handle([$this->instruction()]);

        Bus::assertNothingDispatched();

        $parked = InstructionHandler::drainParked();

        $this->assertCount(1, $parked);
        $this->assertSame('eloquent.created: Marmot\Laravel\Tests\Fixtures\Order', reset($parked)['stream']);

        // Draining is destructive — work runs once, not on every tick.
        $this->assertSame([], InstructionHandler::drainParked());
    }

    public function test_the_scheduler_fallback_runs_parked_work(): void
    {
        config()->set('queue.default', 'sync');

        InstructionHandler::handle([$this->instruction(['weeks' => 1])]);

        // One dry-run + one real chunk.
        $this->mock->append(new Response(200, [], json_encode([
            'dry_run' => true, 'new_hours' => 1,
            'overlap' => ['hours' => 0, 'exact_matches' => 0, 'max_delta' => 0, 'live_total' => 0, 'backfill_total' => 0],
        ])));
        $this->mock->append(new Response(200, [], json_encode(['dry_run' => false, 'inserted' => 1, 'skipped' => 0])));

        \Marmot\Laravel\Tests\Fixtures\Order::create([
            'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
        ]);

        $this->artisan('marmot:process-instructions')->assertSuccessful();

        $this->assertSame([], InstructionHandler::drainParked());
    }

    // ---- Execution ------------------------------------------------------

    public function test_chunks_carry_their_instruction_and_chunk_number(): void
    {
        \Marmot\Laravel\Tests\Fixtures\Order::create([
            'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
        ]);

        $this->mock->append(new Response(200, [], json_encode([
            'dry_run' => true, 'new_hours' => 1,
            'overlap' => ['hours' => 0, 'live_total' => 0, 'backfill_total' => 0],
        ])));
        $this->mock->append(new Response(200, [], json_encode(['dry_run' => false, 'inserted' => 1, 'skipped' => 0])));

        (new RunBackfillInstruction('inst-1', 'eloquent.created: Marmot\Laravel\Tests\Fixtures\Order', 1))->handle();

        $payload = $this->lastPayload();

        $this->assertSame('inst-1', $payload['instruction_id']);
        $this->assertSame(1, $payload['chunk']);
        $this->assertFalse($payload['dry_run']);
        $this->assertSame('eloquent.created: Marmot\Laravel\Tests\Fixtures\Order', $payload['stream']);
    }

    /** Validate small before committing large (§4c). */
    public function test_a_disagreeing_first_chunk_stops_the_run(): void
    {
        \Marmot\Laravel\Tests\Fixtures\Order::create([
            'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
        ]);

        // Live collected 100 for the overlapping window; the table can only
        // account for 10 — the hard/soft-delete undercount announcing itself.
        $this->mock->append(new Response(200, [], json_encode([
            'dry_run' => true, 'new_hours' => 1,
            'overlap' => ['hours' => 5, 'exact_matches' => 0, 'max_delta' => 90, 'live_total' => 100, 'backfill_total' => 10],
        ])));

        (new RunBackfillInstruction('inst-2', 'eloquent.created: Marmot\Laravel\Tests\Fixtures\Order', 4))->handle();

        // Only the dry run happened — nothing was committed.
        $this->assertCount(1, $this->history);
        $this->assertTrue($this->lastPayload()['dry_run']);
    }

    public function test_the_job_re_checks_the_veto_at_execution_time(): void
    {
        (new RunBackfillInstruction('inst-3', 'eloquent.created: App\Models\Gone', 4))->handle();

        $this->assertSame([], $this->history);
    }

    public function test_hourly_counts_read_the_table_including_soft_deleted_rows(): void
    {
        // Capture-time truth: live collection counted the row when it was
        // created, so history must still contain it.
        $refund = \Marmot\Laravel\Tests\Fixtures\Refund::create([
            'created_at' => '2026-08-27 10:15:00', 'updated_at' => '2026-08-27 10:15:00',
        ]);
        $refund->delete();

        $counts = ModelStreams::hourlyCounts(
            ModelStreams::resolve('eloquent.created: Marmot\Laravel\Tests\Fixtures\Refund'),
            Carbon::parse('2026-08-27 00:00:00'),
            Carbon::parse('2026-08-28 00:00:00'),
        );

        $this->assertSame([['hour' => '2026-08-27 10:00:00', 'count' => 1]], $counts);
    }
}
