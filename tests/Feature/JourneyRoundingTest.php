<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\ReimbursementRule;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Decimal;
use App\Services\RateService;
use App\Services\ReportService;
use App\Services\SettlementService;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JourneyRoundingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'fahrtwerk.audit_log_enabled' => true]);
    }

    private function vehicle(User $user): Vehicle
    {
        return Vehicle::create(['user_id' => $user->id, 'name' => 'Golf', 'plate' => 'FW-'.$user->id, 'energy' => 'Benzin', 'type' => 'Privatfahrzeug', 'active' => true]);
    }

    private function rule(User $admin, string $mode = 'journey', array $overrides = []): ReimbursementRule
    {
        return ReimbursementRule::create($overrides + ['family_id' => (string) Str::uuid(), 'version' => 1, 'name' => 'Erstattung', 'category' => 'business', 'rate' => '0.3000', 'valid_from' => '2026-01-01', 'active' => true, 'kilometer_rounding' => $mode, 'created_by' => $admin->id, 'created_at' => now()]);
    }

    private function period(string $start = '2026-08-01', string $end = '2026-08-31'): Period
    {
        return Period::create(['name' => $start, 'starts_on' => $start, 'ends_on' => $end, 'active' => true]);
    }

    /** @return array<string, int|string> */
    private function data(Vehicle $vehicle, string $distance, string $start = '2026-08-03 08:00:00', string $end = '2026-08-03 08:10:00'): array
    {
        return ['vehicle_id' => $vehicle->id, 'starts_at' => $start, 'ends_at' => $end, 'origin' => 'Rathaus', 'destination' => 'Schule', 'route' => 'Rathaus → Schule', 'purpose' => 'IT-Betreuung', 'business_km' => $distance, 'commute_km' => '0.00', 'private_km' => '0.00'];
    }

    private function pair(User $user, Vehicle $vehicle, string $outbound, string $return, string $day = '2026-08-03'): Trip
    {
        return app(TripService::class)->saveJourney($user, $this->data($vehicle, $outbound, $day.' 08:00:00', $day.' 08:10:00'), $this->data($vehicle, $return, $day.' 12:00:00', $day.' 12:10:00'));
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function distances(): array
    {
        return [
            'school' => ['2.20', '2.20', '4.00', '1.20'],
            'fractions make another kilometer' => ['2.60', '2.60', '5.00', '1.50'],
            'never round up' => ['2.90', '2.90', '5.00', '1.50'],
            'less than one' => ['0.30', '0.30', '0.00', '0.00'],
            'short directions form a kilometer' => ['0.60', '0.60', '1.00', '0.30'],
            'unequal directions' => ['1.25', '3.80', '5.00', '1.50'],
            'whole kilometers' => ['2.00', '3.00', '5.00', '1.50'],
        ];
    }

    #[DataProvider('distances')]
    public function test_journey_sums_both_directions_then_truncates_without_changing_recorded_distances(string $outbound, string $return, string $billable, string $amount): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $user = User::factory()->create();
        $this->rule($admin);
        $this->pair($user, $this->vehicle($user), $outbound, $return);
        $settlement = app(SettlementService::class)->submit($user, $this->period());

        app(SettlementService::class)->transition($admin, $settlement, 'APPROVED');

        $this->assertSame($amount, $settlement->fresh()->total);
        $lines = $settlement->lines()->get();
        $this->assertSame($billable, Decimal::sum($lines->pluck('billable_kilometers')));
        $this->assertSame([$outbound, $return], Trip::orderBy('id')->pluck('business_km')->all());
        $this->assertSame(['journey'], $lines->pluck('kilometer_rounding')->unique()->values()->all());
    }

    public function test_separate_journeys_in_one_period_are_rounded_individually_and_filtered_reports_use_both_directions(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $user = User::factory()->create();
        $this->rule($admin);
        $vehicle = $this->vehicle($user);
        $this->pair($user, $vehicle, '2.80', '2.80');
        $this->pair($user, $vehicle, '2.80', '2.80', '2026-08-04');
        $period = $this->period();
        $report = app(ReportService::class)->build($user, []);
        $filtered = app(RateService::class)->positionsForTrips(Trip::where('direction', 'return')->get());

        $settlement = app(SettlementService::class)->submit($user, $period);
        app(SettlementService::class)->transition($admin, $settlement, 'APPROVED');

        $this->assertSame(300, $report['cost']);
        $this->assertSame('3.00', $settlement->fresh()->total);
        $this->assertSame('10.00', Decimal::sum($settlement->lines()->pluck('billable_kilometers')));
        $this->assertSame(['0.66', '0.66'], array_column(array_merge(...array_values($filtered)), 'amount'));
    }

    public function test_unpaired_trip_is_truncated_individually(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $user = User::factory()->create();
        $this->rule($admin);
        $trip = app(TripService::class)->save($user, $this->data($this->vehicle($user), '2.99'));

        $positions = app(RateService::class)->positionsForTrips([$trip]);

        $this->assertSame('2.00', $positions[$trip->id][0]['billable_kilometers']);
        $this->assertSame('0.60', $positions[$trip->id][0]['amount']);
    }

    public function test_journey_rounding_also_works_without_an_existing_settlement_period(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $user = User::factory()->create();
        $this->rule($admin);
        $trip = $this->pair($user, $this->vehicle($user), '2.60', '2.60');

        $positions = app(RateService::class)->positionsForTrips(Trip::where('pair_id', $trip->pair_id)->get());

        $this->assertSame('5.00', Decimal::sum(array_column(array_merge(...array_values($positions)), 'billable_kilometers')));
    }

    public function test_journey_crossing_a_period_boundary_is_calculated_once_and_locked_partner_cannot_be_changed(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $user = User::factory()->create();
        $vehicle = $this->vehicle($user);
        $this->rule($admin);
        $trip = app(TripService::class)->saveJourney($user, $this->data($vehicle, '2.60', '2026-08-31 08:00:00', '2026-08-31 08:10:00'), $this->data($vehicle, '2.60', '2026-09-01 12:00:00', '2026-09-01 12:10:00'));
        $august = $this->period();
        $september = $this->period('2026-09-01', '2026-09-30');
        $return = Trip::where('direction', 'return')->firstOrFail();
        $firstSettlement = app(SettlementService::class)->submit($user, $august);

        $this->actingAs($user)->get('/fahrten/'.$return->id.'/edit')->assertSee('Beide Richtungen sind gegen Änderungen gesperrt.')->assertDontSee('Entwurf speichern')->assertDontSee('Entwurf löschen');
        $this->delete('/fahrten/'.$return->id)->assertForbidden();
        $this->put('/fahrten/'.$return->id, ['vehicle_id' => $vehicle->id, 'revision' => 1, 'business_km' => '9.99'])->assertForbidden();
        app(SettlementService::class)->transition($admin, $firstSettlement, 'APPROVED');
        $secondSettlement = app(SettlementService::class)->submit($user, $september);
        app(SettlementService::class)->transition($admin, $secondSettlement, 'APPROVED');

        $this->assertSame('0.78', $firstSettlement->fresh()->total);
        $this->assertSame('0.72', $secondSettlement->fresh()->total);
        $this->assertSame('2.60', $trip->fresh()->business_km);
        $this->assertSame('2.60', $return->fresh()->business_km);
    }

    public function test_journey_uses_each_direction_rate_when_rule_versions_change_during_the_trip(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $user = User::factory()->create();
        $vehicle = $this->vehicle($user);
        $this->rule($admin, overrides: ['valid_until' => '2026-08-03']);
        $this->rule($admin, overrides: ['valid_from' => '2026-08-04', 'rate' => '0.2000']);
        app(TripService::class)->saveJourney($user, $this->data($vehicle, '0.60'), $this->data($vehicle, '0.60', '2026-08-04 12:00:00', '2026-08-04 12:10:00'));
        $settlement = app(SettlementService::class)->submit($user, $this->period());

        app(SettlementService::class)->transition($admin, $settlement, 'APPROVED');

        $this->assertSame('0.26', $settlement->fresh()->total);
        $this->assertSame('1.00', Decimal::sum($settlement->lines()->pluck('billable_kilometers')));
    }

    public function test_business_rules_default_to_journey_rounding_in_the_admin_form_and_endpoint(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $payload = ['name' => 'Erstattung', 'category' => 'business', 'rate' => '0.30', 'valid_from' => '2026-01-01', 'active' => 1];

        $this->actingAs($admin)->get('/admin/stammdaten')->assertSee('Dienstreise abrunden')->assertSee('Hin- und Rückfahrt derselben Dienstreise');
        $this->post('/admin/regeln', $payload)->assertRedirect();

        $this->assertDatabaseHas('reimbursement_rules', ['category' => 'business', 'kilometer_rounding' => 'journey']);
    }

    public function test_migration_versions_active_business_rules_for_pending_trips_and_preserves_approved_calculations(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $approvedUser = User::factory()->create();
        $pendingUser = User::factory()->create();
        $rule = $this->rule($admin, 'exact');
        $privateRule = $this->rule($admin, 'exact', ['category' => 'private']);
        $inactiveRule = $this->rule($admin, 'aggregate', ['active' => false]);
        $this->pair($approvedUser, $this->vehicle($approvedUser), '2.60', '2.60');
        $this->pair($pendingUser, $this->vehicle($pendingUser), '2.60', '2.60');
        $period = $this->period();
        $approved = app(SettlementService::class)->submit($approvedUser, $period);
        app(SettlementService::class)->transition($admin, $approved, 'APPROVED');
        $snapshot = $approved->lines()->get()->toArray();
        $pending = app(SettlementService::class)->submit($pendingUser, $period);
        $migration = require database_path('migrations/2026_10_08_155919_add_journey_kilometer_rounding.php');

        $migration->up();
        $migration->up();
        app(SettlementService::class)->transition($admin, $pending, 'APPROVED');

        $this->assertDatabaseHas('reimbursement_rules', ['family_id' => $rule->family_id, 'version' => 2, 'kilometer_rounding' => 'journey', 'rate' => '0.3000', 'valid_from' => '2026-01-01']);
        $this->assertSame(2, ReimbursementRule::where('family_id', $rule->family_id)->count());
        $this->assertSame('exact', $rule->fresh()->kilometer_rounding);
        $this->assertSame(1, ReimbursementRule::where('family_id', $privateRule->family_id)->count());
        $this->assertSame(1, ReimbursementRule::where('family_id', $inactiveRule->family_id)->count());
        $this->assertSame('1.56', $approved->fresh()->total);
        $this->assertSame($snapshot, $approved->lines()->get()->toArray());
        $this->assertSame('1.50', $pending->fresh()->total);
        $this->assertDatabaseHas('audit_events', ['record_type' => 'ReimbursementRule', 'action' => 'rule_version_created', 'reason' => 'Abrundung je Dienstreise für noch nicht genehmigte Abrechnungen aktiviert.']);
    }
}
