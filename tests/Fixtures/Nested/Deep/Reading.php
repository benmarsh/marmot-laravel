<?php

namespace Marmot\Laravel\Tests\Fixtures\Nested\Deep;

use Illuminate\Database\Eloquent\Model;

/**
 * Two directories deep — mirrors real apps that organise models by domain
 * (App\UKSnowMap\GFS\GfsRun). Discovery must find these, not just the
 * top level.
 */
class Reading extends Model
{
    protected $table = 'readings';

    protected $guarded = [];
}
