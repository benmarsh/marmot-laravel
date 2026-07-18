<?php

namespace Marmot\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** No timestamps: must never appear as a backfill candidate. */
class Note extends Model
{
    protected $table = 'notes';

    public $timestamps = false;

    const CREATED_AT = null;

    protected $guarded = [];
}
