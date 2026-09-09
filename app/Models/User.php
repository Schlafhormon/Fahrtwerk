<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['auth_version' => 1, 'active' => true, 'logbook_enabled' => true, 'force_password_change' => true, 'role' => 'EMPLOYEE'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'logbook_enabled' => 'boolean', 'force_password_change' => 'boolean', 'password' => 'hashed'];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'ADMIN';
    }

    public function profileComplete(): bool
    {
        return (bool) ($this->first_name && $this->last_name && $this->address && $this->phone && $this->occupation);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name) ?: $this->username;
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }

    public function trips()
    {
        return $this->hasMany(Trip::class);
    }

    public function organizationUnit()
    {
        return $this->belongsTo(OrganizationUnit::class);
    }
}
