<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['favorite' => 'boolean', 'include_return' => 'boolean', 'distance' => 'decimal:2', 'return_distance' => 'decimal:2'];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
