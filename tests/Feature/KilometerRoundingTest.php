<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Period;
use App\Models\ReimbursementRule;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Decimal;
use App\Services\ReportService;
use App\Services\SettlementService;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KilometerRoundingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'fahrtwerk.audit_log_enabled' => true]);
    }

    /** @return array<string, array{?string, string}> */
    public static function ruleModes(): array
    {
        return ['unchanged' => ['exact', 'exact'], 'per trip' => ['per_trip', 'per_trip'], 'sum' => ['aggregate', 'aggregate'], 'journey' => ['journey', 'journey'], 'omitted uses journey default' => [null, 'journey']];
    }

    #[DataProvider('ruleModes')]
    public function test_admin_saves_the_selected_kilometer_calculation(?string $input, string $expected): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $payload = ['name' => 'Erstattung', 'category' => 'business', 'rate' => '0.3000', 'valid_from' => '2026-01-01', 'active' => 1];
        if ($input !== null) {
            $payload['kilometer_rounding'] = $input;
        }

        $this->actingAs($admin)->post('/admin/regeln', $payload)->assertRedirect();

        $rule = ReimbursementRule::firstOrFail();
        $this->assertDatabaseHas('reimbursement_rules', ['id' => $rule->id, 'kilometer_rounding' => $expected]);
        $this->assertDatabaseHas('audit_events', ['record_type' => 'ReimbursementRule', 'record_id' => (string) $rule->id, 'action' => 'rule_version_created']);
    }

    /** @return array<string, array{?string}> */
    public static function invalidRuleModes(): array
    {
        return ['unsupported' => ['round_up'], 'empty' => [''], 'null' => [null]];
    }

    #[DataProvider('invalidRuleModes')]
    public function test_invalid_kilometer_calculation_creates_no_rule_or_audit(?string $rounding): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($admin)->post('/admin/regeln', ['name' => 'Erstattung', 'category' => 'business', 'rate' => '0.30', 'valid_from' => '2026-01-01', 'active' => 1, 'kilometer_rounding' => $rounding])->assertSessionHasErrors('kilometer_rounding');

        $this->assertDatabaseCount('reimbursement_rules', 0);
        $this->assertSame(0, AuditEvent::where('record_type', 'ReimbursementRule')->count());
    }

    public function test_employee_cannot_create_a_rounding_rule(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->post('/admin/regeln', ['name' => 'Erstattung', 'category' => 'business', 'rate' => '0.30', 'valid_from' => '2026-01-01', 'active' => 1, 'kilometer_rounding' => 'aggregate'])->assertForbidden();

        $this->assertDatabaseCount('reimbursement_rules', 0);
    }

    public function test_master_data_displays_the_three_calculation_options_and_saved_method(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $this->rule($admin, 'aggregate');

        $this->actingAs($admin)->get('/admin/stammdaten')->assertSee('Kilometerberechnung')->assertSee('Unverändert')->assertSee('Abrunden je Fahrt')->assertSee('Summe abrunden')->assertSee('value="aggregate"', false);
    }

    /** @return array<string, array{string, string, string}> */
    public static function settlementModes(): array
    {
        return ['unchanged' => ['exact', '6.36', '21.20'], 'per trip' => ['per_trip', '6.00', '20.00'], 'sum' => ['aggregate', '6.30', '21.00']];
    }

    #[DataProvider('settlementModes')]
    public function test_approval_uses_the_selected_method_and_preserves_recorded_distances(string $rounding, string $expectedAmount, string $expectedKilometers): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $employee = User::factory()->create();
        $period = $this->period();
        $rule = $this->rule($admin, $rounding);
        $vehicle = $this->vehicle($employee);
        $first = $this->trip($employee, $vehicle, '10.60');
        $second = $this->trip($employee, $vehicle, '10.60', ['starts_at' => '2026-08-04 08:00:00', 'ends_at' => '2026-08-04 09:00:00', 'odometer_start' => '1010.60', 'odometer_end' => '1021.20']);
        $settlement = app(SettlementService::class)->submit($employee, $period);

        $this->actingAs($admin)->post('/abrechnungen/'.$settlement->id.'/status', ['status' => 'APPROVED'])->assertRedirect();

        $this->assertDatabaseHas('settlements', ['id' => $settlement->id, 'status' => 'APPROVED', 'total' => $expectedAmount]);
        $lines = $settlement->lines()->get();
        $this->assertCount(2, $lines);
        $this->assertSame($expectedKilometers, Decimal::sum($lines->pluck('billable_kilometers')));
        $this->assertSame($expectedAmount, Decimal::sum($lines->pluck('amount')));
        $this->assertSame([$rounding], $lines->pluck('kilometer_rounding')->unique()->values()->all());
        $this->assertSame([$rule->id], $lines->pluck('reimbursement_rule_id')->unique()->values()->all());
        $this->assertSame(['10.60', '10.60'], $lines->pluck('kilometers')->all());
        $this->assertSame('10.60', $first->fresh()->business_km);
        $this->assertSame('1021.20', $second->fresh()->odometer_end);
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function aggregateBoundaries(): array
    {
        return ['sum below one kilometer' => ['0.30', '0.30', '0.3000', '0.00', '0.00'], 'fractions form a kilometer' => ['0.60', '0.60', '0.3000', '1.00', '0.30'], 'whole kilometers' => ['10.00', '11.00', '0.3000', '21.00', '6.30'], 'round money once for the group' => ['0.50', '0.50', '0.3333', '1.00', '0.33']];
    }

    #[DataProvider('aggregateBoundaries')]
    public function test_sum_rounding_handles_fractional_and_whole_kilometers(string $firstDistance, string $secondDistance, string $rate, string $expectedKilometers, string $expectedAmount): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $employee = User::factory()->create();
        $period = $this->period();
        $this->rule($admin, 'aggregate', ['rate' => $rate]);
        $vehicle = $this->vehicle($employee);
        $this->trip($employee, $vehicle, $firstDistance);
        $this->trip($employee, $vehicle, $secondDistance, ['starts_at' => '2026-08-04 08:00:00', 'ends_at' => '2026-08-04 09:00:00']);

        $settlement = $this->approve($employee, $period, $admin);

        $this->assertSame($expectedAmount, $settlement->total);
        $lines = $settlement->lines()->get();
        $this->assertCount(2, $lines);
        $this->assertSame($expectedKilometers, Decimal::sum($lines->pluck('billable_kilometers')));
        $this->assertSame($expectedAmount, Decimal::sum($lines->pluck('amount')));
    }

    public function test_per_trip_rounding_keeps_zero_kilometer_positions(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $employee = User::factory()->create();
        $period = $this->period();
        $this->rule($admin, 'per_trip');
        $trip = $this->trip($employee, $this->vehicle($employee), '0.99');

        $settlement = $this->approve($employee, $period, $admin);

        $this->assertSame('0.00', $settlement->total);
        $this->assertDatabaseHas('settlement_lines', ['trip_id' => $trip->id, 'kilometers' => '0.99', 'billable_kilometers' => '0.00', 'amount' => '0.00', 'kilometer_rounding' => 'per_trip']);
    }

    public function test_report_does_not_combine_different_employees_or_periods(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $firstEmployee = User::factory()->create();
        $secondEmployee = User::factory()->create();
        $this->period();
        $this->period(['name' => 'September', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
        $this->rule($admin, 'aggregate');
        $vehicle = $this->vehicle($firstEmployee);
        $this->trip($firstEmployee, $vehicle, '0.60');
        $this->trip($firstEmployee, $vehicle, '0.60', ['starts_at' => '2026-09-03 08:00:00', 'ends_at' => '2026-09-03 09:00:00']);
        $this->trip($secondEmployee, $this->vehicle($secondEmployee), '0.60');

        $summary = app(ReportService::class)->build($admin, []);

        $this->assertSame(3, $summary['count']);
        $this->assertSame(180, $summary['business']);
        $this->assertSame(0, $summary['cost']);
    }

    public function test_sum_rounding_keeps_different_rule_versions_and_rates_separate(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $employee = User::factory()->create();
        $period = $this->period();
        $firstRule = $this->rule($admin, 'aggregate', ['valid_until' => '2026-08-03']);
        $secondRule = $this->rule($admin, 'aggregate', ['valid_from' => '2026-08-04', 'rate' => '0.2000']);
        $vehicle = $this->vehicle($employee);
        $this->trip($employee, $vehicle, '0.60');
        $this->trip($employee, $vehicle, '0.60', ['starts_at' => '2026-08-04 08:00:00', 'ends_at' => '2026-08-04 09:00:00']);

        $settlement = $this->approve($employee, $period, $admin);

        $this->assertSame('0.00', $settlement->total);
        $this->assertSame([$firstRule->id, $secondRule->id], $settlement->lines()->orderBy('trip_id')->pluck('reimbursement_rule_id')->all());
    }

    public function test_categories_use_their_own_rounding_methods(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $employee = User::factory()->create();
        $period = $this->period();
        $this->rule($admin, 'per_trip');
        $this->rule($admin, 'exact', ['category' => 'private', 'rate' => '0.2000']);
        $trip = $this->trip($employee, $this->vehicle($employee), '1.90', ['private_km' => '1.90', 'odometer_end' => '1003.80']);

        $settlement = $this->approve($employee, $period, $admin);

        $this->assertSame('0.68', $settlement->total);
        $this->assertDatabaseHas('settlement_lines', ['trip_id' => $trip->id, 'category' => 'business', 'billable_kilometers' => '1.00', 'amount' => '0.30']);
        $this->assertDatabaseHas('settlement_lines', ['trip_id' => $trip->id, 'category' => 'private', 'billable_kilometers' => '1.90', 'amount' => '0.38']);
    }

    public function test_missing_rate_still_blocks_approval_for_less_than_one_kilometer(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $employee = User::factory()->create();
        $period = $this->period();
        $this->rule($admin, 'per_trip', ['valid_from' => '2026-09-01']);
        $this->trip($employee, $this->vehicle($employee), '0.60');
        $settlement = app(SettlementService::class)->submit($employee, $period);

        $this->actingAs($admin)->post('/abrechnungen/'.$settlement->id.'/status', ['status' => 'APPROVED'])->assertSessionHasErrors('rule');

        $this->assertDatabaseHas('settlements', ['id' => $settlement->id, 'status' => 'SUBMITTED', 'total' => null]);
        $this->assertDatabaseCount('settlement_lines', 0);
    }

    public function test_filtered_estimates_use_the_full_period_and_match_approved_amounts(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $employee = User::factory()->create();
        $period = $this->period();
        $this->rule($admin, 'aggregate');
        $vehicle = $this->vehicle($employee);
        $this->trip($employee, $vehicle, '10.60', ['destination' => 'Rathaus']);
        $this->trip($employee, $vehicle, '10.60', ['starts_at' => '2026-08-04 08:00:00', 'ends_at' => '2026-08-04 09:00:00', 'destination' => 'Bauhof']);
        $report = app(ReportService::class);
        $filters = ['from' => '2026-08-04', 'to' => '2026-08-04', 'destination' => 'Bauhof'];

        $estimated = $report->build($employee, $filters);
        $settlement = $this->approve($employee, $period, $admin);
        $approved = $report->build($employee, $filters);

        $this->assertSame(1, $estimated['count']);
        $this->assertSame(312, $estimated['cost']);
        $this->assertSame(312, $approved['cost']);
        $this->assertSame(630, $report->build($employee, [])['cost']);
        $this->assertSame('6.30', $settlement->total);
    }

    public function test_dashboard_estimate_uses_sum_rounding(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-08-05 12:00:00'));
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $employee = User::factory()->create();
        $this->period();
        $this->rule($admin, 'aggregate');
        $vehicle = $this->vehicle($employee);
        $this->trip($employee, $vehicle, '0.60');
        $this->trip($employee, $vehicle, '0.60', ['starts_at' => '2026-08-04 08:00:00', 'ends_at' => '2026-08-04 09:00:00']);

        $this->actingAs($employee)->get('/')->assertViewHas('summary', fn (array $summary): bool => $summary['cost'] === 30);
    }

    public function test_estimate_without_an_existing_period_rounds_each_trip_independently(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $employee = User::factory()->create();
        $this->rule($admin, 'aggregate');
        $vehicle = $this->vehicle($employee);
        $this->trip($employee, $vehicle, '0.60');
        $this->trip($employee, $vehicle, '0.60', ['starts_at' => '2026-08-04 08:00:00', 'ends_at' => '2026-08-04 09:00:00']);

        $summary = app(ReportService::class)->build($employee, []);

        $this->assertSame(0, $summary['cost']);
    }

    public function test_new_rule_version_does_not_change_the_approved_calculation_or_pdf(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $employee = User::factory()->create();
        $period = $this->period();
        $rule = $this->rule($admin, 'aggregate');
        $vehicle = $this->vehicle($employee);
        $this->trip($employee, $vehicle, '10.60');
        $this->trip($employee, $vehicle, '10.60', ['starts_at' => '2026-08-04 08:00:00', 'ends_at' => '2026-08-04 09:00:00']);
        $settlement = $this->approve($employee, $period, $admin);
        $before = view('exports.settlement', ['settlement' => $settlement->load('lines')])->render();

        $this->actingAs($admin)->post('/admin/regeln', ['name' => 'Folgeversion', 'category' => 'business', 'rate' => '0.30', 'valid_from' => '2026-01-01', 'active' => 1, 'predecessor_id' => $rule->id, 'kilometer_rounding' => 'per_trip'])->assertRedirect();

        $this->assertDatabaseHas('reimbursement_rules', ['family_id' => $rule->family_id, 'version' => 2, 'kilometer_rounding' => 'per_trip']);
        $after = view('exports.settlement', ['settlement' => $settlement->fresh()->load('lines')])->render();
        $this->assertSame($before, $after);
        $this->assertStringContainsString('km abrechenbar', $after);
        $this->assertStringContainsString('Summe abrunden', $after);
        $this->assertStringContainsString('21,00', $after);
        $this->assertSame(630, app(ReportService::class)->build($employee, [])['cost']);
        $this->get('/abrechnungen/'.$settlement->id)->assertSee('km abrechenbar')->assertSee('21,00')->assertSee('Summe abrunden')->assertDontSee('Abrunden je Fahrt');
        $this->get('/abrechnungen/'.$settlement->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_migration_preserves_existing_rules_and_immutable_settlement_lines(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $employee = User::factory()->create();
        $period = $this->period();
        $rule = $this->rule($admin, 'exact');
        $this->trip($employee, $this->vehicle($employee), '10.60');
        $settlement = $this->approve($employee, $period, $admin);
        $migration = require database_path('migrations/2026_10_08_152052_add_kilometer_rounding_to_reimbursement_rules_and_settlement_lines.php');
        $migration->down();

        $migration->up();

        $this->assertSame('exact', $rule->fresh()->kilometer_rounding);
        $line = $settlement->lines()->firstOrFail();
        $this->assertNull($line->billable_kilometers);
        $this->assertSame('exact', $line->kilometer_rounding);
        $this->assertSame('10.60', $line->kilometers);
        $this->assertSame('3.18', $line->amount);
        $html = view('exports.settlement', ['settlement' => $settlement->fresh()->load('lines')])->render();
        $this->assertStringContainsString('10,60', $html);
        $this->assertStringContainsString('Unverändert', $html);
    }

    /** @param array{name?: string, starts_on?: string, ends_on?: string, active?: bool} $overrides */
    private function period(array $overrides = []): Period
    {
        return Period::create($overrides + ['name' => 'August 2026', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31', 'active' => true]);
    }

    /** @param array{rate?: string, category?: string, valid_from?: string, valid_until?: string} $overrides */
    private function rule(User $admin, string $rounding, array $overrides = []): ReimbursementRule
    {
        return ReimbursementRule::create($overrides + ['family_id' => (string) Str::uuid(), 'version' => 1, 'name' => 'Erstattung', 'category' => 'business', 'rate' => '0.3000', 'valid_from' => '2026-01-01', 'active' => true, 'kilometer_rounding' => $rounding, 'created_by' => $admin->id, 'created_at' => now()]);
    }

    private function vehicle(User $employee): Vehicle
    {
        return Vehicle::create(['user_id' => $employee->id, 'name' => 'Golf', 'plate' => 'FW-'.$employee->id, 'energy' => 'Benzin', 'type' => 'Privatfahrzeug', 'active' => true, 'is_default' => true, 'initial_odometer' => '1000.00']);
    }

    /** @param array{starts_at?: string, ends_at?: string, odometer_start?: string, odometer_end?: string, private_km?: string, destination?: string} $overrides */
    private function trip(User $employee, Vehicle $vehicle, string $distance, array $overrides = []): Trip
    {
        return app(TripService::class)->save($employee, $overrides + ['vehicle_id' => $vehicle->id, 'starts_at' => '2026-08-03 08:00:00', 'ends_at' => '2026-08-03 09:00:00', 'route' => 'Rathaus – Bauhof', 'destination' => 'Bauhof', 'purpose' => 'Gebäudebegehung', 'odometer_start' => '1000.00', 'odometer_end' => Decimal::sum(['1000.00', $distance]), 'business_km' => $distance, 'commute_km' => '0.00', 'private_km' => '0.00']);
    }

    private function approve(User $employee, Period $period, User $admin): Settlement
    {
        $service = app(SettlementService::class);
        $settlement = $service->submit($employee, $period);
        $service->transition($admin, $settlement, 'APPROVED');

        return $settlement->fresh();
    }
}
