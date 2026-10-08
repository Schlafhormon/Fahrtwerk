<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'odometer_start' => 'decimal:2', 'odometer_end' => 'decimal:2', 'business_km' => 'decimal:2', 'commute_km' => 'decimal:2', 'private_km' => 'decimal:2', 'anomalies' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function settlement()
    {
        return $this->belongsTo(Settlement::class);
    }

    public function editable(): bool
    {
        return in_array($this->status, ['DRAFT', 'RETURNED']);
    }

    public function pairedTrips(): HasMany
    {
        return $this->hasMany(self::class, 'pair_id', 'pair_id');
    }
}
