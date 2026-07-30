<?php

namespace Marmot\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Soft-deleting: the schema report must flag it (backfill can undercount). */
class Refund extends Model
{
    use SoftDeletes;

    protected $table = 'refunds';

    protected $guarded = [];
}
