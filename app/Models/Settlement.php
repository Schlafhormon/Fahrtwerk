<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Settlement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['identity_snapshot' => 'array', 'total' => 'decimal:2', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'settled_at' => 'datetime', 'locked_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    public function trips()
    {
        return $this->hasMany(Trip::class);
    }

    public function lines()
    {
        return $this->hasMany(SettlementLine::class);
    }
}
