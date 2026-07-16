<?php

namespace Marmot\Laravel\Tests;

use Marmot\Laravel\MarmotServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [MarmotServiceProvider::class];
    }
}
