<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', fn (Blueprint $t) => $t->string('record_id')->change());
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_vehicle_history() RETURNS trigger AS $$
BEGIN
 IF (to_jsonb(NEW) - ARRAY['active','is_default','updated_at']) IS DISTINCT FROM
    (to_jsonb(OLD) - ARRAY['active','is_default','updated_at']) AND
    EXISTS(SELECT 1 FROM trips WHERE vehicle_id=OLD.id AND status NOT IN ('DRAFT','RETURNED')) THEN
  RAISE EXCEPTION 'Fahrzeugstammdaten historischer Fahrten sind unveränderlich';
 END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER vehicles_history BEFORE UPDATE ON vehicles FOR EACH ROW EXECUTE FUNCTION guard_vehicle_history();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS vehicles_history ON vehicles; DROP FUNCTION IF EXISTS guard_vehicle_history()');
        }
    }
};
