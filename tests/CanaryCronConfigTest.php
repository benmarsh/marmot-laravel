<?php

namespace Marmot\Laravel\Tests;

use Illuminate\Console\Scheduling\Schedule;

class CanaryCronConfigTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('marmot.api_key', 'test-key');
        $app['config']->set('marmot.canary_cron', '*/2 * * * *');
    }

    public function test_canary_cron_expression_is_configurable(): void
    {
        $canary = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => $event->description === 'marmot-canary');

        $this->assertNotNull($canary);
        $this->assertSame('*/2 * * * *', $canary->expression);
    }
}
