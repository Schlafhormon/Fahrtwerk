<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['old_value' => 'json', 'new_value' => 'json', 'created_at' => 'datetime'];
    }

    public $timestamps = false;

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
