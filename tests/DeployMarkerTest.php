<?php

namespace Marmot\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Marmot\Laravel\Listeners\CaptureEverything;
use Marmot\Laravel\Support\EventBuffer;

class DeployMarkerTest extends TestCase
{
    private MockHandler $mock;

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('marmot.api_key', 'test-key');
        $app['config']->set('marmot.endpoint', 'https://marmot.test/v1/events');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock = new MockHandler;
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        $this->app->instance('marmot.http_client', new Client(['handler' => $stack]));
    }

    public function test_deploy_command_posts_a_marker_to_the_deploys_endpoint(): void
    {
        $this->mock->append(new Response(200, [], '{"ok":true}'));

        $this->artisan('marmot:deploy', ['description' => 'big release'])
            ->expectsOutputToContain('Deploy marker sent')
            ->assertSuccessful();

        $request = $this->history[0]['request'];
        $body = json_decode((string) $request->getBody(), true);

        $this->assertSame('https://marmot.test/v1/deploys', (string) $request->getUri());
        $this->assertSame('deploy', $body['type']);
        $this->assertSame('big release', $body['description']);
    }

    public function test_unconfigured_install_never_fails_the_deploy_pipeline(): void
    {
        config(['marmot.api_key' => null]);

        $this->artisan('marmot:deploy')
            ->expectsOutputToContain('marker skipped')
            ->assertSuccessful();

        $this->assertCount(0, $this->history);
    }

    public function test_maintenance_mode_events_become_markers_not_counts(): void
    {
        $this->mock->append(new Response(200), new Response(200));

        $buffer = new EventBuffer;
        $listener = new CaptureEverything($buffer);

        $listener->handle('Illuminate\Foundation\Events\MaintenanceModeEnabled', []);
        $listener->handle('Illuminate\Foundation\Events\MaintenanceModeDisabled', []);

        $this->assertSame([], $buffer->pending(), 'markers must not be counted as streams');
        $this->assertCount(2, $this->history);

        $types = array_map(fn ($h) => json_decode((string) $h['request']->getBody(), true)['type'], $this->history);
        $this->assertSame(['maintenance.started', 'maintenance.ended'], $types);
    }
}
