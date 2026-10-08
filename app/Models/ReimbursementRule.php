<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReimbursementRule extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    public static function kilometerRoundingOptions(): array
    {
        return ['journey' => 'Dienstreise abrunden', 'exact' => 'Unverändert', 'per_trip' => 'Abrunden je Fahrt', 'aggregate' => 'Summe abrunden'];
    }

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date', 'rate' => 'decimal:4', 'active' => 'boolean'];
    }

    public $timestamps = false;
}
