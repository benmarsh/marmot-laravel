<?php

namespace Marmot\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class BackfillCommandTest extends TestCase
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

        $this->app->instance(ClientInterface::class, new Client(['handler' => $stack]));
    }

    private function seedOrders(string $hour, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            \Marmot\Laravel\Tests\Fixtures\Order::create([
                'created_at' => $hour, 'updated_at' => $hour,
            ]);
        }
    }

    public function test_full_run_ships_grouped_hours_after_dry_run_confirm(): void
    {
        $this->seedOrders('2026-08-27 10:15:00', 3);
        $this->seedOrders('2026-08-27 10:45:00', 2);
        $this->seedOrders('2026-08-27 11:20:00', 4);
        // Outside the 1-week window — excluded.
        $this->seedOrders('2026-08-01 09:00:00', 9);

        $this->mock->append(
            new Response(200, [], json_encode([
                'dry_run' => true, 'new_hours' => 2,
                'overlap' => ['hours' => 0, 'exact_matches' => 0, 'max_delta' => 0, 'live_total' => 0, 'backfill_total' => 0],
            ])),
            new Response(200, [], json_encode([
                'dry_run' => false, 'inserted' => 2, 'skipped' => 0,
                'new_hours' => 2,
                'overlap' => ['hours' => 0, 'exact_matches' => 0, 'max_delta' => 0, 'live_total' => 0, 'backfill_total' => 0],
            ])),
        );

        $this->artisan('marmot:backfill')
            ->expectsChoice('Which models should backfill? (comma-separated)',
                ['Marmot\Laravel\Tests\Fixtures\Order'],
                ['Marmot\Laravel\Tests\Fixtures\Order'])
            ->expectsQuestion('How many weeks of history?', '1')
            ->expectsQuestion('Optional WHERE clause (must match what the live event means — blank for none)', '')
            ->expectsConfirmation('Ship it?', 'yes')
            ->expectsOutputToContain('Backfilled: 2 hour(s) written, 0 left to live data.')
            ->assertSuccessful();

        $this->assertCount(2, $this->history);

        $dryRun = json_decode((string) $this->history[0]['request']->getBody(), true);
        $real = json_decode((string) $this->history[1]['request']->getBody(), true);

        $this->assertSame('https://marmot.test/v1/backfill', (string) $this->history[0]['request']->getUri());
        $this->assertSame('Bearer test-key', $this->history[0]['request']->getHeaderLine('Authorization'));

        $this->assertTrue($dryRun['dry_run']);
        $this->assertFalse($real['dry_run']);

        foreach ([$dryRun, $real] as $payload) {
            $this->assertSame('eloquent.created: Marmot\Laravel\Tests\Fixtures\Order', $payload['stream']);
            $this->assertSame([
                ['hour' => '2026-08-27 10:00:00', 'count' => 5],
                ['hour' => '2026-08-27 11:00:00', 'count' => 4],
            ], $payload['hours']);
        }
    }

    public function test_declining_after_dry_run_ships_nothing(): void
    {
        $this->seedOrders('2026-08-27 10:15:00', 3);

        $this->mock->append(new Response(200, [], json_encode([
            'dry_run' => true, 'new_hours' => 1,
            'overlap' => ['hours' => 0, 'exact_matches' => 0, 'max_delta' => 0, 'live_total' => 0, 'backfill_total' => 0],
        ])));

        $this->artisan('marmot:backfill')
            ->expectsChoice('Which models should backfill? (comma-separated)',
                ['Marmot\Laravel\Tests\Fixtures\Order'],
                ['Marmot\Laravel\Tests\Fixtures\Order'])
            ->expectsQuestion('How many weeks of history?', '1')
            ->expectsQuestion('Optional WHERE clause (must match what the live event means — blank for none)', '')
            ->expectsConfirmation('Ship it?')
            ->assertSuccessful();

        $this->assertCount(1, $this->history);
    }

    public function test_where_clause_filters_counts(): void
    {
        $this->seedOrders('2026-08-27 10:15:00', 3);
        \Marmot\Laravel\Tests\Fixtures\Order::query()->limit(1)->update(['status' => 'cancelled']);

        $this->mock->append(
            new Response(200, [], json_encode([
                'dry_run' => true, 'new_hours' => 1,
                'overlap' => ['hours' => 0, 'exact_matches' => 0, 'max_delta' => 0, 'live_total' => 0, 'backfill_total' => 0],
            ])),
            new Response(200, [], json_encode([
                'dry_run' => false, 'inserted' => 1, 'skipped' => 0,
                'new_hours' => 1,
                'overlap' => ['hours' => 0, 'exact_matches' => 0, 'max_delta' => 0, 'live_total' => 0, 'backfill_total' => 0],
            ])),
        );

        $this->artisan('marmot:backfill')
            ->expectsChoice('Which models should backfill? (comma-separated)',
                ['Marmot\Laravel\Tests\Fixtures\Order'],
                ['Marmot\Laravel\Tests\Fixtures\Order'])
            ->expectsQuestion('How many weeks of history?', '1')
            ->expectsQuestion('Optional WHERE clause (must match what the live event means — blank for none)', "status = 'paid'")
            ->expectsConfirmation('Ship it?', 'yes')
            ->assertSuccessful();

        $payload = json_decode((string) $this->history[1]['request']->getBody(), true);

        $this->assertSame([['hour' => '2026-08-27 10:00:00', 'count' => 2]], $payload['hours']);
    }

    public function test_server_rejection_surfaces_and_fails(): void
    {
        $this->seedOrders('2026-08-27 10:15:00', 1);

        $this->mock->append(new Response(422, [], json_encode([
            'message' => "Stream 'eloquent.created: Marmot\Laravel\Tests\Fixtures\Order' has not been seen live yet — install the SDK, let discovery run, then backfill.",
        ])));

        $this->artisan('marmot:backfill')
            ->expectsChoice('Which models should backfill? (comma-separated)',
                ['Marmot\Laravel\Tests\Fixtures\Order'],
                ['Marmot\Laravel\Tests\Fixtures\Order'])
            ->expectsQuestion('How many weeks of history?', '1')
            ->expectsQuestion('Optional WHERE clause (must match what the live event means — blank for none)', '')
            ->expectsOutputToContain('has not been seen live yet')
            ->assertFailed();
    }

    public function test_unconfigured_install_gets_a_helpful_error(): void
    {
        config(['marmot.api_key' => null]);

        $this->artisan('marmot:backfill')
            ->expectsOutputToContain('Marmot is not configured')
            ->assertFailed();
    }

    public function test_timestampless_models_are_not_candidates(): void
    {
        // Only Order should be offered — Note has CREATED_AT null. The
        // choice prompt's option list is the assertion here.
        $this->seedOrders('2026-08-27 10:15:00', 1);

        $this->mock->append(new Response(200, [], json_encode([
            'dry_run' => true, 'new_hours' => 1,
            'overlap' => ['hours' => 0, 'exact_matches' => 0, 'max_delta' => 0, 'live_total' => 0, 'backfill_total' => 0],
        ])));

        $this->artisan('marmot:backfill')
            ->expectsChoice('Which models should backfill? (comma-separated)',
                ['Marmot\Laravel\Tests\Fixtures\Order'],
                ['Marmot\Laravel\Tests\Fixtures\Order'])
            ->expectsQuestion('How many weeks of history?', '1')
            ->expectsQuestion('Optional WHERE clause (must match what the live event means — blank for none)', '')
            ->expectsConfirmation('Ship it?')
            ->assertSuccessful();
    }
}
