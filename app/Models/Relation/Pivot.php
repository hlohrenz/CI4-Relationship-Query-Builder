<?php

namespace App\Models\Relation;

use App\Models\BaseModel as Model;
use App\Models\Relation\Concerns\AsPivot;

class Pivot extends Model
{
    use AsPivot;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];
}