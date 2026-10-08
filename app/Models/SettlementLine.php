<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['trip_snapshot' => 'array', 'kilometers' => 'decimal:2', 'billable_kilometers' => 'decimal:2', 'rate' => 'decimal:4', 'amount' => 'decimal:2', 'calculated_at' => 'datetime'];
    }

    public $timestamps = false;

    public function rule()
    {
        return $this->belongsTo(ReimbursementRule::class, 'reimbursement_rule_id');
    }
}
