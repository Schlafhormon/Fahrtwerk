<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['favorite' => 'boolean'];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
