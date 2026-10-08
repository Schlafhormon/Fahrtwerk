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
use App\Services\Settings;
use App\Services\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TripQuickCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'fahrtwerk.audit_log_enabled' => true]);
    }

    private function vehicle(User $user): Vehicle
    {
        return Vehicle::create(['user_id' => $user->id, 'name' => 'Golf', 'plate' => 'FW-'.$user->id, 'energy' => 'Benzin', 'type' => 'Privatfahrzeug', 'active' => true, 'is_default' => true, 'initial_odometer' => '1000.00']);
    }

    /** @return array<string, int|string> */
    private function payload(Vehicle $vehicle): array
    {
        return ['vehicle_id' => $vehicle->id, 'date' => '2026-08-03', 'start_time' => '08:00', 'end_time' => '08:10', 'origin' => 'Rathaus', 'destination' => 'Schule', 'route' => 'Rathaus → Schule', 'purpose' => 'IT-Betreuung', 'distance' => '2.20', 'kilometer_category' => 'business', 'include_return' => 1, 'return_start_time' => '12:00', 'return_end_time' => '12:10'];
    }

    /** @return array<string, int|string> */
    private function templatePayload(Vehicle $vehicle): array
    {
        return ['name' => 'Schule', 'origin' => 'Rathaus', 'destination' => 'Schule', 'purpose' => 'IT-Betreuung', 'vehicle_id' => $vehicle->id, 'distance' => '2.20', 'kilometer_category' => 'business', 'include_return' => 1, 'favorite' => 1];
    }

    private function period(string $start = '2026-08-01', string $end = '2026-08-31'): Period
    {
        return Period::create(['name' => $start, 'starts_on' => $start, 'ends_on' => $end, 'active' => true]);
    }

    private function rule(User $admin, string $mode, array $overrides = []): ReimbursementRule
    {
        return ReimbursementRule::create($overrides + ['family_id' => (string) Str::uuid(), 'version' => 1, 'name' => 'Erstattung', 'category' => 'business', 'rate' => '0.3000', 'valid_from' => '2026-01-01', 'active' => true, 'kilometer_rounding' => $mode, 'created_by' => $admin->id, 'created_at' => now()]);
    }

    public function test_template_saves_route_distances_category_and_return_defaults(): void
    {
        $user = User::factory()->create();
        $vehicle = $this->vehicle($user);

        $this->actingAs($user)->post('/vorlagen', $this->templatePayload($vehicle))->assertRedirect('/vorlagen');

        $this->assertDatabaseHas('trip_templates', ['user_id' => $user->id, 'origin' => 'Rathaus', 'route' => 'Rathaus → Schule', 'destination' => 'Schule', 'distance' => '2.20', 'return_distance' => '2.20', 'include_return' => true, 'kilometer_category' => 'business']);
    }

    public function test_dashboard_and_templates_open_the_same_prefilled_compact_capture(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-08-05 12:00:00'));
        $user = User::factory()->create();
        $this->actingAs($user)->post('/vorlagen', $this->templatePayload($this->vehicle($user)));
        $template = TripTemplate::firstOrFail();
        $url = route('trips.create', ['template' => $template->id]);

        $this->get('/')->assertSee($url, false);
        $this->get('/vorlagen')->assertSee($url, false);
        $response = $this->get($url)->assertViewIs('trips.quick')->assertSee('Schnellerfassung: Schule')->assertSee('Entwürfe gemeinsam speichern');

        $response->assertViewHas('trip', fn (Trip $trip): bool => $trip->origin === 'Rathaus' && $trip->destination === 'Schule' && $trip->vehicle_id === $template->vehicle_id);
        $response->assertSee('value="2026-08-05"', false)->assertSee('value="2.20"', false)->assertSee('value="IT-Betreuung"', false)->assertSee('Kilometerstände der Hinfahrt (optional)');
    }

    public function test_quick_capture_creates_linked_directions_with_independent_times_and_no_odometer(): void
    {
        $user = User::factory()->create();
        $vehicle = $this->vehicle($user);
        $this->actingAs($user)->post('/vorlagen', $this->templatePayload($vehicle));
        $template = TripTemplate::firstOrFail();

        $this->post('/fahrten', $this->payload($vehicle) + ['template_id' => $template->id, 'pair_id' => 'injected', 'user_id' => 999, 'status' => 'APPROVED'])->assertRedirect();

        $trips = Trip::orderBy('id')->get();
        $this->assertCount(2, $trips);
        $this->assertNotNull($trips[0]->pair_id);
        $this->assertSame($trips[0]->pair_id, $trips[1]->pair_id);
        $this->assertNotSame('injected', $trips[0]->pair_id);
        $this->assertSame(['outbound', 'return'], $trips->pluck('direction')->all());
        $this->assertSame(['Rathaus', 'Schule'], $trips->pluck('origin')->all());
        $this->assertSame(['Schule', 'Rathaus'], $trips->pluck('destination')->all());
        $this->assertSame('Schule → Rathaus', $trips[1]->route);
        $this->assertSame(['2.20', '2.20'], $trips->pluck('business_km')->all());
        $this->assertSame('4.40', Decimal::sum($trips->pluck('business_km')));
        $this->assertSame([null, null], $trips->pluck('odometer_start')->all());
        $this->assertSame([null, null], $trips->pluck('odometer_end')->all());
        $this->assertSame('08:10', $trips[0]->ends_at->format('H:i'));
        $this->assertSame('12:00', $trips[1]->starts_at->format('H:i'));
        $this->assertSame([], $trips[0]->anomalies);
        $this->assertSame(1, $template->fresh()->usage_count);
        $this->get('/fahrten/'.$trips[0]->id.'/edit')->assertSee('Zugehörige Rückfahrt')->assertSee(route('trips.edit', $trips[1]), false);
        $this->get('/fahrten')->assertSee('Fahrtenpaar')->assertSee('Rückfahrt');
    }

    public function test_manual_return_copies_all_kilometer_categories_and_allows_different_route_date_and_distance(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload($this->vehicle($user));
        unset($payload['distance'], $payload['kilometer_category']);
        $payload += ['business_km' => '2.20', 'commute_km' => '1.10', 'private_km' => '0.40', 'return_date' => '2026-08-04', 'return_origin' => 'Sporthalle', 'return_destination' => 'Bauhof', 'return_route' => 'Sporthalle → Bauhof', 'return_business_km' => '3.35'];

        $this->actingAs($user)->get('/fahrten/create')->assertSee('Rückfahrt hinzufügen');
        $this->post('/fahrten', $payload)->assertRedirect();

        $return = Trip::where('direction', 'return')->firstOrFail();
        $this->assertSame('2026-08-04', $return->starts_at->toDateString());
        $this->assertSame('3.35', $return->business_km);
        $this->assertSame('1.10', $return->commute_km);
        $this->assertSame('0.40', $return->private_km);
        $this->assertSame('Sporthalle → Bauhof', $return->route);
        $this->assertSame('IT-Betreuung', $return->purpose);
        $this->assertNull($return->odometer_start);
    }

    public function test_unselected_return_creates_only_one_trip_and_ignores_return_fields(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload($this->vehicle($user));
        $payload['include_return'] = 0;
        $payload['return_distance'] = '-99';

        $this->actingAs($user)->post('/fahrten', $payload)->assertRedirect();

        $this->assertDatabaseCount('trips', 1);
        $this->assertNull(Trip::firstOrFail()->pair_id);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidReturns(): array
    {
        return [
            'negative distance' => [['return_distance' => '-0.01'], 'return_distance'],
            'extra precision' => [['return_distance' => '2.201'], 'return_distance'],
            'return before outbound ends' => [['return_start_time' => '08:05'], 'return_start_time'],
            'end before start' => [['return_end_time' => '11:59'], 'return_ends_at'],
            'invalid date' => [['return_date' => '2026-02-30'], 'return_date'],
            'too long destination' => [['return_destination' => str_repeat('x', 256)], 'return_destination'],
            'decreasing odometer' => [['return_odometer_start' => '1002.20', 'return_odometer_end' => '1001'], 'return_odometer_end'],
            'inconsistent split' => [['return_odometer_start' => '1002.20', 'return_odometer_end' => '1005'], 'return_business_km'],
        ];
    }

    #[DataProvider('invalidReturns')]
    public function test_invalid_return_leaves_no_trip_audit_or_template_usage(array $invalid, string $error): void
    {
        $user = User::factory()->create();
        $vehicle = $this->vehicle($user);
        $this->actingAs($user)->post('/vorlagen', $this->templatePayload($vehicle));
        $template = TripTemplate::firstOrFail();

        $this->post('/fahrten', array_replace($this->payload($vehicle), $invalid, ['template_id' => $template->id]))->assertSessionHasErrors($error);

        $this->assertDatabaseCount('trips', 0);
        $this->assertSame(0, AuditEvent::where('record_type', 'Trip')->count());
        $this->assertSame(0, $template->fresh()->usage_count);
    }

    public function test_invalid_outbound_creates_neither_direction(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload($this->vehicle($user));
        $payload['distance'] = '-1';

        $this->actingAs($user)->post('/fahrten', $payload)->assertSessionHasErrors('distance');

        $this->assertDatabaseCount('trips', 0);
    }

    public function test_legacy_template_without_distance_keeps_unknown_distance_and_blocks_submission(): void
    {
        $user = User::factory()->create();
        $vehicle = $this->vehicle($user);
        $template = TripTemplate::create(['user_id' => $user->id, 'vehicle_id' => $vehicle->id, 'name' => 'Alt', 'route' => 'Rathaus → Schule', 'destination' => 'Schule', 'purpose' => 'IT-Betreuung']);
        $payload = $this->payload($vehicle);
        $payload['distance'] = null;
        $payload['include_return'] = 0;
        $payload['template_id'] = $template->id;

        $this->actingAs($user)->get('/fahrten/create?template='.$template->id)->assertSee('Diese Vorlage enthält noch keine Entfernung');
        $this->post('/fahrten', $payload)->assertRedirect();
        $this->post('/zeitraeume/'.$this->period()->id.'/einreichen')->assertSessionHasErrors('business_km');

        $trip = Trip::firstOrFail();
        $this->assertNull($trip->business_km);
        $this->assertSame('DRAFT', $trip->status);
    }

    /** @return array<string, array{string, string, string}> */
    public static function roundingModes(): array
    {
        return ['unchanged' => ['exact', '1.32', '4.40'], 'per direction' => ['per_trip', '1.20', '4.00'], 'sum' => ['aggregate', '1.20', '4.00']];
    }

    #[DataProvider('roundingModes')]
    public function test_pairs_without_odometers_can_be_submitted_and_approved_with_all_rounding_modes(string $mode, string $amount, string $billable): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $this->rule($admin, $mode);
        app(Settings::class)->set('enforce_odometer_continuity', true);
        $this->actingAs($user)->post('/fahrten', $this->payload($this->vehicle($user)))->assertRedirect();
        $period = $this->period();

        $this->post('/zeitraeume/'.$period->id.'/einreichen')->assertRedirect();
        $settlement = Settlement::firstOrFail();
        $this->actingAs($admin)->post('/abrechnungen/'.$settlement->id.'/status', ['status' => 'APPROVED'])->assertRedirect();

        $this->assertSame($amount, $settlement->fresh()->total);
        $lines = $settlement->lines()->get();
        $this->assertCount(2, $lines);
        $this->assertSame($billable, Decimal::sum($lines->pluck('billable_kilometers')));
        $this->assertSame(['2.20', '2.20'], $lines->pluck('kilometers')->all());
        $this->assertSame([null, null], $lines->pluck('trip_snapshot')->map(fn (array $snapshot) => $snapshot['odometer_start'])->all());
    }

    public function test_aggregate_rounding_combines_fractional_directions_before_rounding(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $this->rule($admin, 'aggregate');
        $payload = $this->payload($this->vehicle($user));
        $payload['distance'] = '2.60';
        $this->actingAs($user)->post('/fahrten', $payload)->assertRedirect();

        $settlement = app(SettlementService::class)->submit($user, $this->period());
        app(SettlementService::class)->transition($admin, $settlement, 'APPROVED');

        $this->assertSame('1.50', $settlement->fresh()->total);
        $this->assertSame('5.00', Decimal::sum($settlement->lines()->pluck('billable_kilometers')));
        $this->assertSame(['2.60', '2.60'], Trip::orderBy('id')->pluck('business_km')->all());
    }

    public function test_incomplete_return_times_block_submission_atomically(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload($this->vehicle($user));
        $payload['return_start_time'] = null;
        $payload['return_end_time'] = null;
        $this->actingAs($user)->post('/fahrten', $payload)->assertRedirect();

        $this->post('/zeitraeume/'.$this->period()->id.'/einreichen')->assertSessionHasErrors(['pair' => 'Bitte die tatsächlichen Uhrzeiten beider zusammengehörigen Fahrten ergänzen.']);

        $this->assertSame(['DRAFT', 'DRAFT'], Trip::orderBy('id')->pluck('status')->all());
        $this->assertDatabaseCount('settlements', 0);
    }

    public function test_pair_overlap_with_an_existing_trip_still_blocks_submission(): void
    {
        $user = User::factory()->create();
        $vehicle = $this->vehicle($user);
        $this->actingAs($user)->post('/fahrten', $this->payload($vehicle))->assertRedirect();
        $payload = $this->payload($vehicle);
        $payload['include_return'] = 0;
        $payload['start_time'] = '12:05';
        $payload['end_time'] = '12:15';
        $this->post('/fahrten', $payload)->assertRedirect();

        $this->post('/zeitraeume/'.$this->period()->id.'/einreichen')->assertSessionHasErrors('starts_at');

        $this->assertDatabaseCount('settlements', 0);
        $this->assertSame(['DRAFT'], Trip::pluck('status')->unique()->values()->all());
    }

    public function test_continuity_does_not_skip_a_neighbor_without_odometer_readings(): void
    {
        app(Settings::class)->set('enforce_odometer_continuity', true);
        $user = User::factory()->create();
        $vehicle = $this->vehicle($user);
        $payload = $this->payload($vehicle);
        $payload['include_return'] = 0;
        $this->actingAs($user)->post('/fahrten', $payload)->assertRedirect();
        $payload['start_time'] = '14:00';
        $payload['end_time'] = '14:10';
        $payload['odometer_start'] = '1800.00';
        $payload['odometer_end'] = '1802.20';

        $this->post('/fahrten', $payload)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([], Trip::orderByDesc('id')->firstOrFail()->anomalies);
        $this->assertDatabaseCount('trips', 2);
    }

    public function test_complete_neighbor_still_requires_an_explanation_for_a_jump(): void
    {
        app(Settings::class)->set('enforce_odometer_continuity', true);
        $user = User::factory()->create();
        $vehicle = $this->vehicle($user);
        $payload = $this->payload($vehicle) + ['odometer_start' => '1000.00', 'odometer_end' => '1002.20', 'return_odometer_start' => '1003.20', 'return_odometer_end' => '1005.40'];

        $this->actingAs($user)->post('/fahrten', $payload)->assertSessionHasErrors('return_correction_reason');

        $this->assertDatabaseCount('trips', 0);
        $this->post('/fahrten', $payload + ['return_correction_reason' => 'Privatfahrt während des Aufenthalts'])->assertRedirect();
        $this->assertContains('Kilometerstandsprung', Trip::where('direction', 'return')->firstOrFail()->anomalies);
    }

    public function test_foreign_template_or_vehicle_and_inactive_vehicle_cannot_create_a_pair(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $vehicle = $this->vehicle($user);
        $foreign = $this->vehicle($other);
        $this->actingAs($other)->post('/vorlagen', $this->templatePayload($foreign));
        $template = TripTemplate::firstOrFail();

        $this->actingAs($user)->post('/fahrten', $this->payload($vehicle) + ['template_id' => $template->id])->assertNotFound();
        $this->post('/fahrten', $this->payload($foreign))->assertNotFound();
        $vehicle->update(['active' => false]);
        $this->post('/fahrten', $this->payload($vehicle))->assertNotFound();

        $this->assertDatabaseCount('trips', 0);
        $this->assertSame(0, $template->fresh()->usage_count);
    }

    public function test_locked_return_period_rolls_back_both_directions(): void
    {
        $user = User::factory()->create();
        $vehicle = $this->vehicle($user);
        $payload = $this->payload($vehicle);
        $payload['date'] = '2026-09-02';
        $payload['include_return'] = 0;
        $this->actingAs($user)->post('/fahrten', $payload)->assertRedirect();
        app(SettlementService::class)->submit($user, $this->period('2026-09-01', '2026-09-30'));
        $payload = $this->payload($vehicle);
        $payload['return_date'] = '2026-09-03';

        $this->post('/fahrten', $payload)->assertSessionHasErrors('return_starts_at');

        $this->assertDatabaseCount('trips', 1);
        $this->assertSame(0, Trip::whereNotNull('pair_id')->count());
    }

    public function test_template_edits_do_not_modify_recorded_trips_or_approved_snapshots(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $vehicle = $this->vehicle($user);
        $this->rule($admin, 'exact');
        $this->actingAs($user)->post('/vorlagen', $this->templatePayload($vehicle));
        $template = TripTemplate::firstOrFail();
        $this->post('/fahrten', $this->payload($vehicle) + ['template_id' => $template->id]);
        $settlement = app(SettlementService::class)->submit($user, $this->period());
        app(SettlementService::class)->transition($admin, $settlement, 'APPROVED');
        $before = $settlement->lines()->get()->toArray();

        $this->put('/vorlagen/'.$template->id, array_replace($this->templatePayload($vehicle), ['distance' => '9.99', 'purpose' => 'Geändert', 'destination' => 'Bauhof']))->assertRedirect();

        $this->assertSame(['2.20', '2.20'], Trip::orderBy('id')->pluck('business_km')->all());
        $this->assertSame(['IT-Betreuung'], Trip::pluck('purpose')->unique()->values()->all());
        $this->assertSame($before, $settlement->lines()->get()->toArray());
    }

    public function test_returned_pair_preserves_correction_workflow_and_submitted_trips_stay_locked(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $vehicle = $this->vehicle($user);
        $this->actingAs($user)->post('/fahrten', $this->payload($vehicle));
        $period = $this->period();
        $settlement = app(SettlementService::class)->submit($user, $period);
        $trip = Trip::where('direction', 'return')->firstOrFail();

        $this->put('/fahrten/'.$trip->id, $this->payload($vehicle) + ['revision' => 1])->assertForbidden();
        app(SettlementService::class)->transition($admin, $settlement, 'RETURNED', 'Zweck konkretisieren');
        $payload = array_replace($this->payload($vehicle), ['start_time' => '12:00', 'end_time' => '12:10', 'revision' => 1, 'purpose' => 'IT-Wartung']);
        $this->put('/fahrten/'.$trip->id, $payload)->assertSessionHasErrors('correction_reason');
        $this->put('/fahrten/'.$trip->id, $payload + ['correction_reason' => 'Zweck konkretisiert'])->assertRedirect();
        app(SettlementService::class)->submit($user, $period);

        $this->assertSame('SUBMITTED', $trip->fresh()->status);
        $this->assertSame('IT-Wartung', $trip->fresh()->purpose);
        $this->assertNotNull($trip->fresh()->pair_id);
        $this->assertDatabaseHas('audit_events', ['record_type' => 'Trip', 'record_id' => (string) $trip->id, 'field' => 'purpose', 'reason' => 'Zweck konkretisiert']);
    }

    public function test_template_rejects_invalid_distance_and_disabled_categories_without_saving(): void
    {
        $user = User::factory()->create();
        $vehicle = $this->vehicle($user);
        $this->actingAs($user)->post('/vorlagen', array_replace($this->templatePayload($vehicle), ['distance' => '2.201']))->assertSessionHasErrors('distance');
        app(Settings::class)->set('allow_private_km', false);

        $this->post('/vorlagen', array_replace($this->templatePayload($vehicle), ['kilometer_category' => 'private']))->assertSessionHasErrors(['kilometer_category' => 'Diese Kilometerart ist derzeit deaktiviert.']);

        $this->assertDatabaseCount('trip_templates', 0);
    }

    public function test_optional_odometer_readings_still_support_a_valid_pair_and_approval(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $this->rule($admin, 'exact');
        $vehicle = $this->vehicle($user);
        $payload = $this->payload($vehicle) + ['odometer_start' => '1000.00', 'odometer_end' => '1002.20', 'return_odometer_start' => '1002.20', 'return_odometer_end' => '1004.40'];
        app(Settings::class)->set('enforce_odometer_continuity', true);
        $this->actingAs($user)->post('/fahrten', $payload)->assertRedirect();

        $settlement = app(SettlementService::class)->submit($user, $this->period());
        app(SettlementService::class)->transition($admin, $settlement, 'APPROVED');

        $this->assertSame('1.32', $settlement->fresh()->total);
        $this->assertSame(['1002.20', '1004.40'], Trip::orderBy('id')->pluck('odometer_end')->all());
    }

    public function test_editing_a_direction_cannot_move_the_return_before_the_outbound_ends(): void
    {
        $user = User::factory()->create();
        $vehicle = $this->vehicle($user);
        $this->actingAs($user)->post('/fahrten', $this->payload($vehicle));
        $trip = Trip::where('direction', 'return')->firstOrFail();
        $payload = array_replace($this->payload($vehicle), ['revision' => 1, 'start_time' => '07:00', 'end_time' => '07:10']);

        $this->put('/fahrten/'.$trip->id, $payload)->assertSessionHasErrors('starts_at');

        $this->assertSame('12:00', $trip->fresh()->starts_at->format('H:i'));
        $this->assertSame(1, $trip->fresh()->revision);
    }
}
