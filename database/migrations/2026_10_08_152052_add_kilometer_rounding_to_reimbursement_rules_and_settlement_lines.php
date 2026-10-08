<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reimbursement_rules', function (Blueprint $table) {
            $table->string('kilometer_rounding')->default('exact');
        });
        Schema::table('settlement_lines', function (Blueprint $table) {
            $table->string('kilometer_rounding')->default('exact');
            $table->decimal('billable_kilometers', 12, 2)->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE reimbursement_rules ADD CONSTRAINT rules_kilometer_rounding_check CHECK (kilometer_rounding IN ('exact', 'per_trip', 'aggregate'))");
            DB::statement("ALTER TABLE settlement_lines ADD CONSTRAINT lines_kilometer_rounding_check CHECK (kilometer_rounding IN ('exact', 'per_trip', 'aggregate'))");
            DB::statement('ALTER TABLE settlement_lines ADD CONSTRAINT billable_kilometers_check CHECK (billable_kilometers >= 0 AND billable_kilometers <= kilometers)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reimbursement_rules DROP CONSTRAINT rules_kilometer_rounding_check');
            DB::statement('ALTER TABLE settlement_lines DROP CONSTRAINT lines_kilometer_rounding_check');
            DB::statement('ALTER TABLE settlement_lines DROP CONSTRAINT billable_kilometers_check');
        }
        Schema::table('settlement_lines', function (Blueprint $table) {
            $table->dropColumn(['kilometer_rounding', 'billable_kilometers']);
        });
        Schema::table('reimbursement_rules', function (Blueprint $table) {
            $table->dropColumn('kilometer_rounding');
        });
    }
};
