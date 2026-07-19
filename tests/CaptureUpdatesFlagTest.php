<?php

namespace Marmot\Laravel\Tests;

use Marmot\Laravel\Listeners\CaptureEverything;
use Marmot\Laravel\Support\EventBuffer;

class CaptureUpdatesFlagTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('marmot.api_key', 'test-key');
    }

    public function test_updates_are_off_by_default_and_opt_in_by_flag(): void
    {
        $buffer = new EventBuffer;
        $listener = new CaptureEverything($buffer);

        $listener->handle('eloquent.updated: App\Models\FacebookPost', []);
        $this->assertSame([], $buffer->pending(), 'updates must be off by default');

        config(['marmot.capture_updates' => true]);

        $listener->handle('eloquent.updated: App\Models\FacebookPost', []);
        $listener->handle('eloquent.created: App\Models\Order', []);

        $streams = array_column($buffer->pending(), 'stream');
        sort($streams);

        $this->assertSame([
            'eloquent.created: App\Models\Order',
            'eloquent.updated: App\Models\FacebookPost',
        ], $streams);
    }
}
