<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_units', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('username')->unique();
            $t->string('role')->default('EMPLOYEE')->index();
            $t->boolean('active')->default(true);
            $t->boolean('logbook_enabled')->default(true);
            $t->boolean('force_password_change')->default(true);
            $t->unsignedInteger('auth_version')->default(1);
            $t->string('first_name')->default('');
            $t->string('last_name')->default('');
            $t->text('address')->nullable();
            $t->string('phone')->nullable();
            $t->string('occupation')->nullable();
            $t->string('personnel_number')->nullable();
            $t->foreignId('organization_unit_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('email')->nullable()->change();
        });
        Schema::create('vehicles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('plate');
            $t->string('vin')->nullable();
            $t->string('registration_number')->nullable();
            $t->string('energy');
            $t->string('type');
            $t->decimal('initial_odometer', 12, 2)->nullable();
            $t->boolean('active')->default(true);
            $t->boolean('is_default')->default(false);
            $t->timestamps();
            $t->index(['user_id', 'active']);
        });
        Schema::create('trip_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('vehicle_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('name');
            $t->text('route');
            $t->string('destination');
            $t->text('purpose');
            $t->boolean('favorite')->default(false);
            $t->unsignedInteger('usage_count')->default(0);
            $t->timestamps();
        });
        Schema::create('periods', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->date('starts_on')->index();
            $t->date('ends_on');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('reimbursement_rules', function (Blueprint $t) {
            $t->id();
            $t->uuid('family_id')->index();
            $t->unsignedInteger('version')->default(1);
            $t->string('name');
            $t->string('category');
            $t->string('vehicle_type')->nullable();
            $t->decimal('rate', 10, 4);
            $t->date('valid_from');
            $t->date('valid_until')->nullable();
            $t->boolean('active')->default(true);
            $t->text('description')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['family_id', 'version']);
            $t->index(['category', 'valid_from']);
        });
        Schema::create('settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('period_id')->constrained()->restrictOnDelete();
            $t->string('status')->default('OPEN')->index();
            $t->text('return_reason')->nullable();
            $t->json('identity_snapshot')->nullable();
            $t->decimal('total', 14, 2)->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('settled_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'period_id']);
        });
        Schema::create('trips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $t->foreignId('settlement_id')->nullable()->constrained()->restrictOnDelete();
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->text('route')->nullable();
            $t->string('destination')->nullable();
            $t->text('purpose')->nullable();
            foreach (['odometer_start', 'odometer_end', 'business_km', 'commute_km', 'private_km'] as $c) {
                $t->decimal($c, 12, 2)->nullable();
            }$t->text('note')->nullable();
            $t->text('correction_reason')->nullable();
            $t->json('anomalies')->nullable();
            $t->string('status')->default('DRAFT')->index();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'starts_at']);
            $t->index(['vehicle_id', 'starts_at']);
        });
        Schema::create('settlement_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('settlement_id')->constrained()->restrictOnDelete();
            $t->foreignId('trip_id')->constrained()->restrictOnDelete();
            $t->foreignId('reimbursement_rule_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('category');
            $t->decimal('kilometers', 12, 2);
            $t->decimal('rate', 10, 4);
            $t->decimal('amount', 14, 2);
            $t->json('trip_snapshot');
            $t->timestamp('calculated_at');
            $t->unique(['settlement_id', 'trip_id', 'category']);
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value');
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('record_type');
            $t->unsignedBigInteger('record_id');
            $t->string('action');
            $t->string('field')->nullable();
            $t->json('old_value')->nullable();
            $t->json('new_value')->nullable();
            $t->text('reason')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at');
            $t->index(['record_type', 'record_id']);
            $t->index(['created_at', 'actor_id']);
        });
        DB::table('settings')->insert(['key' => 'domain_lock', 'value' => 'true', 'created_at' => now(), 'updated_at' => now()]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX vehicles_one_default ON vehicles(user_id) WHERE is_default = true AND active = true');
            DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicles_id_owner UNIQUE (id,user_id)');
            DB::statement('ALTER TABLE trips ADD CONSTRAINT trips_vehicle_owner FOREIGN KEY (vehicle_id,user_id) REFERENCES vehicles(id,user_id)');
            DB::statement("ALTER TABLE trips ADD CONSTRAINT trips_status_check CHECK (status IN ('DRAFT','RETURNED','SUBMITTED','APPROVED','SETTLED','LOCKED'))");
            DB::statement("ALTER TABLE settlements ADD CONSTRAINT settlements_status_check CHECK (status IN ('OPEN','RETURNED','SUBMITTED','APPROVED','SETTLED','LOCKED'))");
            DB::statement('ALTER TABLE trips ADD CONSTRAINT nonnegative_km CHECK (odometer_start >= 0 AND odometer_end >= 0 AND business_km >= 0 AND commute_km >= 0 AND private_km >= 0)');
            DB::statement('ALTER TABLE reimbursement_rules ADD CONSTRAINT nonnegative_rate CHECK (rate >= 0)');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_immutable_change() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Unveränderlicher historischer Datensatz'; END; $$ LANGUAGE plpgsql;
CREATE TRIGGER audit_immutable BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION reject_immutable_change();
CREATE TRIGGER rules_immutable BEFORE UPDATE OR DELETE ON reimbursement_rules FOR EACH ROW EXECUTE FUNCTION reject_immutable_change();
CREATE TRIGGER lines_immutable BEFORE UPDATE OR DELETE ON settlement_lines FOR EACH ROW EXECUTE FUNCTION reject_immutable_change();
CREATE OR REPLACE FUNCTION guard_trip_history() RETURNS trigger AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN
  IF OLD.status <> 'DRAFT' THEN RAISE EXCEPTION 'Historische Fahrt darf nicht gelöscht werden'; END IF;
  RETURN OLD;
 END IF;
 IF OLD.status NOT IN ('DRAFT','RETURNED') AND
  (to_jsonb(NEW) - ARRAY['status','approved_at','locked_at','updated_at']) IS DISTINCT FROM
  (to_jsonb(OLD) - ARRAY['status','approved_at','locked_at','updated_at']) THEN
  RAISE EXCEPTION 'Eingereichte Fahrtdaten sind unveränderlich';
 END IF;
 IF OLD.status <> NEW.status AND NOT (
  (OLD.status IN ('DRAFT','RETURNED') AND NEW.status = 'SUBMITTED') OR
  (OLD.status = 'SUBMITTED' AND NEW.status IN ('RETURNED','APPROVED')) OR
  (OLD.status = 'APPROVED' AND NEW.status = 'SETTLED') OR
  (OLD.status = 'SETTLED' AND NEW.status = 'LOCKED')
 ) THEN RAISE EXCEPTION 'Ungültiger Fahrtstatuswechsel'; END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER trips_history BEFORE UPDATE OR DELETE ON trips FOR EACH ROW EXECUTE FUNCTION guard_trip_history();
CREATE OR REPLACE FUNCTION guard_settlement_history() RETURNS trigger AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Abrechnungen dürfen nicht gelöscht werden'; END IF;
 IF OLD.status IN ('APPROVED','SETTLED','LOCKED') AND
 (to_jsonb(NEW) - ARRAY['status','settled_at','locked_at','updated_at']) IS DISTINCT FROM
 (to_jsonb(OLD) - ARRAY['status','settled_at','locked_at','updated_at']) THEN
 RAISE EXCEPTION 'Abgeschlossene Abrechnung ist unveränderlich'; END IF;
 IF OLD.status <> NEW.status AND NOT (
 (OLD.status IN ('OPEN','RETURNED') AND NEW.status = 'SUBMITTED') OR
 (OLD.status = 'SUBMITTED' AND NEW.status IN ('RETURNED','APPROVED')) OR
 (OLD.status = 'APPROVED' AND NEW.status = 'SETTLED') OR
 (OLD.status = 'SETTLED' AND NEW.status = 'LOCKED')
 ) THEN RAISE EXCEPTION 'Ungültiger Abrechnungsstatuswechsel'; END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER settlements_history BEFORE UPDATE OR DELETE ON settlements FOR EACH ROW EXECUTE FUNCTION guard_settlement_history();
SQL);
        }
    }

    public function down(): void
    {
        foreach (['audit_events', 'settlement_lines', 'trips', 'settlements', 'reimbursement_rules', 'periods', 'trip_templates', 'vehicles', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropForeign(['organization_unit_id']);
            $t->dropColumn(['username', 'role', 'active', 'logbook_enabled', 'force_password_change', 'auth_version', 'first_name', 'last_name', 'address', 'phone', 'occupation', 'personnel_number', 'organization_unit_id']);
        });
        Schema::dropIfExists('organization_units');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS reject_immutable_change(), guard_trip_history(), guard_settlement_history()');
        }
    }
};
