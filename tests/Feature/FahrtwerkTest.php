<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Period;
use App\Models\ReimbursementRule;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\TripTemplate;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Decimal;
use App\Services\Display;
use App\Services\ImportService;
use App\Services\Settings;
use App\Services\SettlementService;
use App\Services\TripService;
use App\Services\TripValidator;
use App\Services\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FahrtwerkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'fahrtwerk.audit_log_enabled' => true]);
    }

    private function person(array $attributes = []): User
    {
        return User::create($attributes + ['name' => 'Anna Meyer', 'username' => 'user_'.Str::random(10), 'password' => 'test-password-123', 'role' => 'EMPLOYEE', 'active' => true, 'force_password_change' => false, 'logbook_enabled' => true, 'first_name' => 'Anna', 'last_name' => 'Meyer', 'address' => 'Rathausplatz 1', 'phone' => '030 123', 'occupation' => 'Sachbearbeitung']);
    }

    private function vehicle(User $u): Vehicle
    {
        return Vehicle::create(['user_id' => $u->id, 'name' => 'Golf', 'plate' => 'FW-'.$u->id, 'energy' => 'Benzin', 'type' => 'Privatfahrzeug', 'active' => true, 'is_default' => true, 'initial_odometer' => '1000.00']);
    }

    private function data(Vehicle $v, array $override = []): array
    {
        return $override + ['vehicle_id' => $v->id, 'starts_at' => '2026-08-03 08:00:00', 'ends_at' => '2026-08-03 09:00:00', 'route' => 'Rathaus – Bauhof – Rathaus', 'destination' => 'Bauhof', 'purpose' => 'Gebäudebegehung', 'odometer_start' => '1000.00', 'odometer_end' => '1030.00', 'business_km' => '25.50', 'commute_km' => '4.50', 'private_km' => '0.00'];
    }

    private function trip(User $u, ?Vehicle $v = null, array $override = []): Trip
    {
        return app(TripService::class)->save($u, $this->data($v ?? $this->vehicle($u), $override));
    }

    private function rule(User $admin, array $override = []): ReimbursementRule
    {
        return ReimbursementRule::create($override + ['family_id' => (string) Str::uuid(), 'version' => 1, 'name' => 'Testregel', 'category' => 'business', 'rate' => '0.3000', 'valid_from' => '2026-01-01', 'active' => true, 'created_by' => $admin->id, 'created_at' => now()]);
    }

    private function period(): Period
    {
        return Period::create(['name' => 'August 2026', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31', 'active' => true]);
    }

    public function test_login_logout_and_session_regeneration(): void
    {
        $u = $this->person();
        $this->get('/anmelden')->assertOk();
        $before = session()->getId();
        $this->post('/anmelden', ['username' => $u->username, 'password' => 'test-password-123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);
        $this->assertNotSame($before, session()->getId());
        $this->post('/abmelden')->assertRedirect('/anmelden');
        $this->assertGuest();
    }

    public function test_login_rejects_inactive_accounts(): void
    {
        $u = $this->person(['active' => false]);
        $this->post('/anmelden', ['username' => $u->username, 'password' => 'test-password-123'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_login_rate_limit(): void
    {
        $u = $this->person();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/anmelden', ['username' => $u->username, 'password' => 'wrong'])->assertSessionHasErrors('username');
        }
        $this->post('/anmelden', ['username' => $u->username, 'password' => 'test-password-123'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_forced_password_change_blocks_all_domain_routes(): void
    {
        $u = $this->person(['force_password_change' => true]);
        $this->actingAs($u)->get('/')->assertRedirect('/passwort');
        $this->get('/export?format=csv')->assertRedirect('/passwort');
        $this->post('/passwort', ['current_password' => 'test-password-123', 'password' => 'mein-neues-passwort', 'password_confirmation' => 'mein-neues-passwort'])->assertRedirect('/');
        $this->assertFalse($u->fresh()->force_password_change);
        $this->assertTrue(Hash::check('mein-neues-passwort', $u->fresh()->password));
    }

    public function test_profile_must_be_completed(): void
    {
        $u = $this->person(['address' => null]);
        $this->actingAs($u)->get('/')->assertRedirect('/profil');
        $this->put('/profil', ['first_name' => 'Anna', 'last_name' => 'Meyer', 'address' => 'Teststraße 1', 'phone' => '123', 'occupation' => 'Verwaltung'])->assertRedirect('/profil');
        $this->get('/')->assertOk();
    }

    public function test_employee_cannot_reach_admin_routes(): void
    {
        $u = $this->person();
        $this->actingAs($u);
        foreach (['/admin/mitarbeitende', '/admin/audit', '/admin/stammdaten', '/admin/einstellungen', '/admin/import', '/admin/auffaelligkeiten'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/organisationseinheiten', ['name' => 'Unauthorized', 'active' => 1])->assertForbidden();
    }

    public function test_employee_can_view_own_trip_and_cannot_read_mutate_or_delete_foreign_trip(): void
    {
        $alice = $this->person();
        $bob = $this->person();
        $own = $this->trip($alice);
        $other = $this->trip($bob);
        $this->actingAs($alice)->get('/fahrten/'.$own->id.'/edit')->assertOk();
        $this->get('/fahrten/'.$other->id.'/edit')->assertForbidden();
        $this->put('/fahrten/'.$other->id, ['vehicle_id' => $own->vehicle_id, 'revision' => 1])->assertForbidden();
        $this->delete('/fahrten/'.$other->id)->assertForbidden();
    }

    public function test_employee_list_report_and_export_ignore_manipulated_owner_filter(): void
    {
        $a = $this->person();
        $b = $this->person();
        $this->trip($a, null, ['destination' => 'MEIN_ZIEL']);
        $foreign = $this->trip($b, null, ['destination' => 'FREMDES_GEHEIMES_ZIEL']);
        $this->actingAs($a)->get('/fahrten?user_id='.$b->id)->assertOk()->assertSee('MEIN_ZIEL')->assertDontSee('FREMDES_GEHEIMES_ZIEL');
        $this->get('/auswertungen?user_id='.$b->id)->assertOk()->assertDontSee('FREMDES_GEHEIMES_ZIEL');
        $csv = $this->get('/export?format=csv&user_id='.$b->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('MEIN_ZIEL', $csv);
        $this->assertStringNotContainsString('FREMDES_GEHEIMES_ZIEL', $csv);
        $this->get('/export?format=print&vehicle_id='.$foreign->vehicle_id)->assertOk()->assertDontSee('FREMDES_GEHEIMES_ZIEL');
    }

    public function test_foreign_vehicle_template_and_settlement_are_forbidden(): void
    {
        $a = $this->person();
        $b = $this->person();
        $t = $this->trip($b);
        $template = TripTemplate::create(['user_id' => $b->id, 'vehicle_id' => $t->vehicle_id, 'name' => 'Private Vorlage', 'route' => 'A-B', 'destination' => 'B', 'purpose' => 'Test']);
        $s = app(SettlementService::class)->submit($b, $this->period());
        $this->actingAs($a);
        foreach (['/fahrzeuge/'.$t->vehicle_id.'/edit', '/vorlagen/'.$template->id.'/edit', '/fahrten/create?template='.$template->id, '/abrechnungen/'.$s->id, '/abrechnungen/'.$s->id.'/pdf'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->put('/fahrzeuge/'.$t->vehicle_id, [])->assertForbidden();
        $this->delete('/vorlagen/'.$template->id)->assertForbidden();
        $this->post('/fahrten', ['vehicle_id' => $t->vehicle_id])->assertNotFound();
    }

    public function test_client_cannot_inject_owner_or_status(): void
    {
        $a = $this->person();
        $b = $this->person();
        $v = $this->vehicle($a);
        $this->actingAs($a)->post('/fahrten', ['vehicle_id' => $v->id, 'user_id' => $b->id, 'status' => 'APPROVED', 'created_by' => $b->id])->assertRedirect();
        $trip = Trip::firstOrFail();
        $this->assertSame($a->id, $trip->user_id);
        $this->assertSame('DRAFT', $trip->status);
        $this->assertSame($a->id, $trip->created_by);
    }

    public function test_incomplete_draft_can_be_saved_but_cannot_be_submitted(): void
    {
        $u = $this->person();
        $v = $this->vehicle($u);
        $this->actingAs($u)->post('/fahrten', ['vehicle_id' => $v->id, 'date' => '2026-08-03', 'start_time' => '08:00'])->assertRedirect();
        $this->post('/zeitraeume/'.$this->period()->id.'/einreichen')->assertSessionHasErrors();
        $this->assertSame('DRAFT', Trip::first()->status);
        $this->assertDatabaseCount('settlements', 0);
    }

    public function test_invalid_kilometers_and_times_are_rejected(): void
    {
        $u = $this->person();
        $v = $this->vehicle($u);
        foreach ([['odometer_end' => '999'], ['business_km' => '-1'], ['business_km' => '25.51'], ['ends_at' => '2026-08-03 07:00:00'], ['private_km' => '1.234']] as $invalid) {
            try {
                app(TripValidator::class)->validate($this->data($v, $invalid), $v, null, true);
                $this->fail('Invalid input accepted');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_kilometers_and_money_use_exact_decimal_arithmetic(): void
    {
        $this->assertSame(123456, Decimal::scaled('1234.56'));
        $this->assertSame('7.65', Decimal::amount('25.50', '0.3000'));
        $this->assertSame('0.01', Decimal::amount('0.01', '0.5000'));
        $this->assertSame('0.30', Decimal::sum(['0.10', '0.20']));
    }

    public function test_odometer_continuity_requires_explicit_reason(): void
    {
        app(Settings::class)->set('enforce_odometer_continuity', true);
        $u = $this->person();
        $v = $this->vehicle($u);
        $d = $this->data($v, ['odometer_start' => '1010', 'odometer_end' => '1040']);
        try {
            app(TripService::class)->save($u, $d);
            $this->fail('Continuity bypass');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('correction_reason', $e->errors());
        }
        $trip = app(TripService::class)->save($u, $d + ['correction_reason' => 'Nicht erfasste Privatfahrt vor Beginn']);
        $this->assertContains('Kilometerstandsprung', $trip->anomalies);
    }

    public function test_overlaps_are_detected_for_person_across_vehicles(): void
    {
        $u = $this->person();
        $v = $this->vehicle($u);
        $this->trip($u, $v);
        $v2 = Vehicle::create(['user_id' => $u->id, 'name' => 'Zweitwagen', 'plate' => 'FW-X', 'type' => 'Privatfahrzeug', 'energy' => 'Elektro']);
        $d = $this->data($v2);
        try {
            app(TripValidator::class)->validate($d, $v2, null, true);
            $this->fail('Overlap accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('starts_at', $e->errors());
        }
    }

    public function test_disabled_logbook_and_inactive_vehicle_block_new_trips(): void
    {
        $u = $this->person(['logbook_enabled' => false]);
        $v = $this->vehicle($u);
        $this->actingAs($u)->post('/fahrten', ['vehicle_id' => $v->id])->assertForbidden();
        $u->update(['logbook_enabled' => true]);
        $v->update(['active' => false]);
        $this->post('/fahrten', ['vehicle_id' => $v->id])->assertNotFound();
    }

    public function test_full_settlement_workflow_and_historical_snapshots(): void
    {
        $admin = $this->person(['role' => 'ADMIN']);
        $u = $this->person();
        $t = $this->trip($u);
        $rule = $this->rule($admin);
        $p = $this->period();
        $service = app(SettlementService::class);
        $this->actingAs($u)->post('/zeitraeume/'.$p->id.'/einreichen')->assertRedirect();
        $s = Settlement::firstOrFail();
        $this->assertSame('SUBMITTED', $t->fresh()->status);
        $this->put('/fahrten/'.$t->id, ['vehicle_id' => $t->vehicle_id, 'revision' => 1])->assertForbidden();
        $this->post('/abrechnungen/'.$s->id.'/status', ['status' => 'APPROVED'])->assertForbidden();
        $this->actingAs($admin)->post('/abrechnungen/'.$s->id.'/status', ['status' => 'APPROVED'])->assertRedirect();
        $this->assertSame('7.65', $s->fresh()->total);
        $this->assertDatabaseCount('settlement_lines', 2);
        $this->rule($admin, ['family_id' => $rule->family_id, 'version' => 2, 'rate' => '0.9000']);
        $u->update(['last_name' => 'Geändert']);
        try {
            DB::transaction(fn () => $t->vehicle->update(['plate' => 'NEU']));
            $this->fail('Historische Fahrzeugdaten geändert');
        } catch (QueryException $e) {
            $this->assertStringContainsString('P0001', $e->getMessage());
        }
        $this->assertSame('7.65', $s->fresh()->total);
        $this->assertSame('Meyer', $s->fresh()->identity_snapshot['employee']['last_name']);
        $pdf = $this->get('/abrechnungen/'.$s->id.'/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->post('/abrechnungen/'.$s->id.'/status', ['status' => 'SETTLED'])->assertRedirect();
        $this->post('/abrechnungen/'.$s->id.'/status', ['status' => 'LOCKED'])->assertRedirect();
        $this->assertSame('LOCKED', $s->fresh()->status);
        $this->assertSame('LOCKED', $t->fresh()->status);
        $this->put('/fahrten/'.$t->id, ['vehicle_id' => $t->vehicle_id, 'revision' => 1])->assertForbidden();
        $this->post('/abrechnungen/'.$s->id.'/status', ['status' => 'RETURNED', 'reason' => 'x'])->assertSessionHasErrors('status');
    }

    public function test_return_correction_resubmit_and_audit_trail(): void
    {
        $admin = $this->person(['role' => 'ADMIN']);
        $u = $this->person();
        $v = $this->vehicle($u);
        $t = $this->trip($u, $v);
        $p = $this->period();
        $service = app(SettlementService::class);
        $s = $service->submit($u, $p);
        $this->actingAs($admin)->post('/abrechnungen/'.$s->id.'/status', ['status' => 'RETURNED'])->assertSessionHasErrors('reason');
        $this->post('/abrechnungen/'.$s->id.'/status', ['status' => 'RETURNED', 'reason' => 'Zweck bitte genauer angeben'])->assertRedirect();
        $this->actingAs($u)->get('/abrechnungen/'.$s->id)->assertSee('Zweck bitte genauer angeben');
        $t = $t->fresh();
        $updated = app(TripService::class)->save($u, $this->data($v, ['revision' => $t->revision, 'purpose' => 'Prüfung der Heizungsanlage', 'correction_reason' => 'Zweck konkretisiert']), $t);
        $this->assertDatabaseHas('audit_events', ['record_type' => 'Trip', 'record_id' => $t->id, 'field' => 'purpose', 'reason' => 'Zweck konkretisiert']);
        $service->submit($u, $p);
        $this->assertSame('SUBMITTED', $updated->fresh()->status);
    }

    public function test_future_rule_does_not_apply_to_earlier_trip(): void
    {
        $admin = $this->person(['role' => 'ADMIN']);
        $u = $this->person();
        $this->trip($u);
        $this->rule($admin, ['valid_from' => '2026-01-01', 'valid_until' => '2026-08-31']);
        $this->rule($admin, ['valid_from' => '2026-09-01', 'rate' => '0.9000']);
        $s = app(SettlementService::class)->submit($u, $this->period());
        app(SettlementService::class)->transition($admin, $s, 'APPROVED');
        $this->assertSame('7.65', $s->fresh()->total);
    }

    public function test_missing_rate_rolls_back_entire_approval(): void
    {
        $admin = $this->person(['role' => 'ADMIN']);
        $u = $this->person();
        $t = $this->trip($u);
        $s = app(SettlementService::class)->submit($u, $this->period());
        $this->actingAs($admin)->post('/abrechnungen/'.$s->id.'/status', ['status' => 'APPROVED'])->assertSessionHasErrors('rule');
        $this->assertSame('SUBMITTED', $s->fresh()->status);
        $this->assertSame('SUBMITTED', $t->fresh()->status);
        $this->assertDatabaseCount('settlement_lines', 0);
    }

    public function test_cannot_add_trip_to_submitted_period(): void
    {
        $u = $this->person();
        $v = $this->vehicle($u);
        $this->trip($u, $v);
        app(SettlementService::class)->submit($u, $this->period());
        $this->actingAs($u)->post('/fahrten', ['vehicle_id' => $v->id, 'date' => '2026-08-04', 'start_time' => '10:00', 'end_time' => '11:00'])->assertSessionHasErrors('starts_at');
    }

    public function test_admin_user_creation_reset_and_no_secret_in_audit(): void
    {
        $a = $this->person(['role' => 'ADMIN']);
        $this->actingAs($a)->post('/admin/mitarbeitende', ['username' => 'neu.user', 'first_name' => 'Neu', 'last_name' => 'User', 'role' => 'EMPLOYEE', 'active' => 1, 'logbook_enabled' => 1])->assertOk()->assertSee('Temporäres Passwort');
        $u = User::where('username', 'neu.user')->firstOrFail();
        $this->assertTrue($u->force_password_change);
        $old = $u->password;
        $this->post('/admin/mitarbeitende/'.$u->id.'/passwort')->assertOk();
        $this->assertNotSame($old, $u->fresh()->password);
        $this->assertSame(0, AuditEvent::whereIn('field', ['password', 'remember_token'])->count());
    }

    public function test_reset_revokes_existing_sessions(): void
    {
        $u = $this->person();
        $this->actingAs($u)->withSession(['auth_version' => 1]);
        app(UserService::class)->reset($u);
        $this->actingAs($u->fresh())->get('/')->assertRedirect('/anmelden');
    }

    public function test_admin_cannot_disable_own_account(): void
    {
        $a = $this->person(['role' => 'ADMIN']);
        $this->actingAs($a)->put('/admin/mitarbeitende/'.$a->id, ['username' => $a->username, 'first_name' => 'A', 'last_name' => 'B', 'role' => 'ADMIN', 'logbook_enabled' => 1])->assertSessionHasErrors('active');
        $this->assertTrue($a->fresh()->active);
    }

    public function test_admin_period_overlap_rejected(): void
    {
        $a = $this->person(['role' => 'ADMIN']);
        $this->period();
        $this->actingAs($a)->post('/admin/zeitraeume', ['month' => '2026-08'])->assertSessionHasErrors('starts_on');
        $this->post('/admin/zeitraeume', ['month' => '2026-09'])->assertRedirect();
        $this->assertDatabaseCount('periods', 2);
    }

    public function test_all_main_pages_render_for_admin_and_employee(): void
    {
        $a = $this->person(['role' => 'ADMIN']);
        $u = $this->person();
        $v = $this->vehicle($u);
        $t = $this->trip($u, $v);
        $p = $this->period();
        $template = TripTemplate::create(['user_id' => $u->id, 'vehicle_id' => $v->id, 'name' => 'Vorlage', 'route' => 'A-B', 'destination' => 'B', 'purpose' => 'Test']);
        foreach ([$a, $u] as $actor) {
            $this->actingAs($actor);
            foreach (['/', '/profil', '/passwort', '/fahrten', '/fahrten/create', '/fahrzeuge', '/fahrzeuge/create', '/vorlagen', '/vorlagen/create', '/auswertungen', '/abrechnungen', '/zeitraeume/'.$p->id] as $url) {
                $this->get($url)->assertOk();
            }
        }
        $this->get('/vorlagen/'.$template->id.'/edit')->assertOk();
        $this->get('/fahrzeuge/'.$v->id.'/edit')->assertOk();
        $this->actingAs($a);
        foreach (['/admin/mitarbeitende', '/admin/mitarbeitende/create', '/admin/mitarbeitende/'.$u->id.'/edit', '/admin/stammdaten', '/admin/einstellungen', '/admin/audit', '/admin/import', '/admin/auffaelligkeiten'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_exports_respect_filters_and_escape_spreadsheet_formulas(): void
    {
        $u = $this->person();
        $this->trip($u, null, ['destination' => '=HYPERLINK("https://example.invalid")']);
        $this->actingAs($u);
        $csv = $this->get('/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $xlsx = $this->get('/export?format=xlsx')->assertOk()->assertDownload('fahrten.xlsx');
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($xlsx->baseResponse->getFile()->getPathname()));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringNotContainsString('<f ', $sheet);
        $zip->close();
        $this->get('/export?format=csv&from=2026-09-01')->assertOk();
    }

    public function test_import_preview_and_invalid_batch_are_atomic(): void
    {
        $a = $this->person(['role' => 'ADMIN']);
        $u = $this->person();
        $v = $this->vehicle($u);
        $data = $this->data($v) + ['username' => $u->username, 'plate' => $v->plate];
        $mapping = [];
        $row = [];
        foreach (array_keys(ImportService::FIELDS) as $i => $f) {
            $mapping[$f] = $i;
            $row[] = $data[$f] ?? '';
        }
        $s = app(ImportService::class);
        $valid = $s->process($a, [$row], $mapping, false);
        $this->assertEmpty($valid['errors']);
        $this->assertDatabaseCount('trips', 0);
        $bad = $row;
        $bad[$mapping['business_km']] = '999';
        $result = $s->process($a, [$row, $bad], $mapping, true);
        $this->assertNotEmpty($result['errors']);
        $this->assertDatabaseCount('trips', 0);
        $done = $s->process($a, [$row], $mapping, true);
        $this->assertEmpty($done['errors']);
        $this->assertDatabaseCount('trips', 1);
        $again = $s->process($a, [$row], $mapping, true);
        $this->assertNotEmpty($again['errors']);
        $this->assertDatabaseCount('trips', 1);
    }

    public function test_optimistic_revision_prevents_lost_update(): void
    {
        $u = $this->person();
        $v = $this->vehicle($u);
        $t = $this->trip($u, $v);
        app(TripService::class)->save($u, $this->data($v, ['revision' => 1]), $t);
        $this->actingAs($u)->put('/fahrten/'.$t->id, ['vehicle_id' => $v->id, 'revision' => 1])->assertSessionHasErrors('revision');
    }

    public function test_postgres_rejects_audit_rewrite_and_historical_trip_edit(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $u = $this->person();
        $t = $this->trip($u);
        app(SettlementService::class)->submit($u, $this->period());
        foreach ([fn () => DB::table('audit_events')->update(['action' => 'hidden']), fn () => DB::table('trips')->where('id', $t->id)->update(['purpose' => 'tampered'])] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('Database accepted immutable rewrite');
            } catch (QueryException $e) {
                $this->assertStringContainsString('P0001', $e->getMessage());
            }
        }
    }

    public function test_no_registration_and_security_headers(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/anmelden')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY');
        $this->get('/health')->assertOk();
        $this->get('/fahrten')->assertRedirect('/anmelden');
    }

    public function test_vehicle_type_cannot_change_after_submission(): void
    {
        $u = $this->person();
        $v = $this->vehicle($u);
        $this->trip($u, $v);
        app(SettlementService::class)->submit($u, $this->period());
        $this->actingAs($u)->put('/fahrzeuge/'.$v->id, ['name' => $v->name, 'plate' => $v->plate, 'energy' => $v->energy, 'type' => 'Dienstfahrzeug', 'initial_odometer' => '1000.00', 'active' => 1, 'is_default' => 1])->assertSessionHasErrors('type');
        $this->assertSame('Privatfahrzeug', $v->fresh()->type);
    }

    public function test_stale_password_change_cannot_overwrite_reset(): void
    {
        $u = $this->person();
        $old = $u->replicate();
        $old->id = $u->id;
        $old->exists = true;
        app(UserService::class)->reset($u);
        $resetHash = $u->fresh()->password;
        $this->actingAs($old)->withSession(['auth_version' => $old->auth_version])->post('/passwort', ['current_password' => 'test-password-123', 'password' => 'attacker-password', 'password_confirmation' => 'attacker-password'])->assertSessionHasErrors('current_password');
        $this->assertSame($resetHash, $u->fresh()->password);
        $this->assertTrue($u->fresh()->force_password_change);
    }

    public function test_settings_are_audited_and_display_preferences_are_effective(): void
    {
        $a = $this->person(['role' => 'ADMIN']);
        $this->actingAs($a)->put('/admin/einstellungen', ['organization_name' => 'Stadtverwaltung Test', 'organization_address' => 'Rathausplatz 1', 'default_period' => 'monthly', 'page_size' => 20, 'retention_years' => 10, 'number_format' => 'en-GB', 'date_format' => 'Y-m-d', 'allow_private_km' => 1, 'allow_commute_km' => 1])->assertRedirect();
        $this->assertDatabaseHas('audit_events', ['record_type' => 'Setting', 'record_id' => 'organization_name']);
        $this->assertSame('1,234.56', Display::number('1234.56'));
    }

    public function test_zero_kilometer_trip_is_preserved_in_invoice(): void
    {
        $a = $this->person(['role' => 'ADMIN']);
        $u = $this->person();
        $t = $this->trip($u, null, ['odometer_end' => '1000.00', 'business_km' => '0.00', 'commute_km' => '0.00']);
        $s = app(SettlementService::class)->submit($u, $this->period());
        app(SettlementService::class)->transition($a, $s, 'APPROVED');
        $this->assertSame('0.00', $s->fresh()->total);
        $this->assertDatabaseHas('settlement_lines', ['trip_id' => $t->id, 'kilometers' => '0.00']);
    }
}
