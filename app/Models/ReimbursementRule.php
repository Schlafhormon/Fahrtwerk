<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReimbursementRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date', 'rate' => 'decimal:4', 'active' => 'boolean'];
    }

    public $timestamps = false;
}
