<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxFormFieldOverride extends Model
{
    protected $guarded = [];

    protected $casts = [
        'x' => 'float',
        'y' => 'float',
        'page' => 'integer',
    ];
}
