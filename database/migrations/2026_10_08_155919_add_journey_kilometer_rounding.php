<?php

use App\Models\ReimbursementRule;
use App\Services\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['reimbursement_rules' => 'rules', 'settlement_lines' => 'lines'] as $table => $prefix) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$prefix}_kilometer_rounding_check");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$prefix}_kilometer_rounding_check CHECK (kilometer_rounding IN ('exact', 'per_trip', 'aggregate', 'journey'))");
            }
        }
        DB::transaction(function () {
            DB::table('settings')->where('key', 'domain_lock')->lockForUpdate()->first();
            $rules = ReimbursementRule::where('category', 'business')->where('active', true)->where('kilometer_rounding', '!=', 'journey')
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')->from('reimbursement_rules as newer')->whereColumn('newer.family_id', 'reimbursement_rules.family_id')->whereColumn('newer.version', '>', 'reimbursement_rules.version');
                })->orderBy('id')->get();
            foreach ($rules as $previous) {
                $rule = $previous->replicate();
                $rule->version = $previous->version + 1;
                $rule->kilometer_rounding = 'journey';
                $rule->created_at = now();
                $rule->save();
                app(Audit::class)->record($rule, 'rule_version_created', [], 'Abrundung je Dienstreise für noch nicht genehmigte Abrechnungen aktiviert.', true);
            }
        });
    }

    public function down(): void
    {
        /** Historical rule versions and calculation methods must remain readable. */
    }
};
