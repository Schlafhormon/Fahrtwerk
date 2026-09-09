<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';
}
