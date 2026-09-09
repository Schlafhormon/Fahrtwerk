<?php

namespace Database\Seeders;

use App\Models\OrganizationUnit;
use App\Models\Period;
use App\Models\ReimbursementRule;
use App\Models\TripTemplate;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\SettlementService;
use App\Services\TripService;
use App\Services\UserService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing') || ! config('fahrtwerk.demo_enabled')) {
            throw new \RuntimeException('Demo-Daten sind ausschließlich ausdrücklich aktiviert in local/testing erlaubt.');
        }
        if (User::where('username', 'demo.admin')->exists()) {
            return;
        }
        $credentials = [];
        $users = [];
        foreach ([['demo.admin', 'Clara', 'Weber', 'ADMIN', 'Hauptamt'], ['demo.meyer', 'Anna', 'Meyer', 'EMPLOYEE', 'Bauamt'], ['demo.schulz', 'Thomas', 'Schulz', 'EMPLOYEE', 'Ordnungsamt'], ['demo.wagner', 'Julia', 'Wagner', 'EMPLOYEE', 'Hauptamt']] as [$username,$first,$last,$role,$unit]) {
            $org = OrganizationUnit::firstOrCreate(['name' => $unit]);
            [$u,$password] = app(UserService::class)->create(['username' => $username, 'first_name' => $first, 'last_name' => $last, 'role' => $role, 'address' => 'Rathausplatz 1, 12345 Musterstadt', 'phone' => '030 123456', 'occupation' => 'Sachbearbeitung', 'organization_unit_id' => $org->id, 'active' => true, 'logbook_enabled' => true]);
            $users[] = $u;
            $credentials[] = $username.': '.$password;
            $v = Vehicle::create(['user_id' => $u->id, 'name' => 'Volkswagen Golf', 'plate' => 'FW-'.($u->id * 100 + 17), 'energy' => 'Benzin', 'type' => 'Privatfahrzeug', 'initial_odometer' => '12000.00', 'active' => true, 'is_default' => true]);
            foreach ([['Zum Bauhof', 'Bauhof', 'Abstimmung mit dem Gebäudemanagement'], ['Außentermin Rathaus', 'Rathaus West', 'Dienstbesprechung']] as [$name,$dest,$purpose]) {
                TripTemplate::create(['user_id' => $u->id, 'vehicle_id' => $v->id, 'name' => $name, 'route' => 'Rathaus → '.$dest.' → Rathaus', 'destination' => $dest, 'purpose' => $purpose, 'favorite' => true]);
            }
        }
        ReimbursementRule::create(['family_id' => (string) Str::uuid(), 'name' => 'Demo-Satz (nur Beispieldaten)', 'category' => 'business', 'rate' => '0.3000', 'valid_from' => now()->subYear()->startOfYear()->toDateString(), 'active' => true, 'created_by' => $users[0]->id, 'created_at' => now()]);
        foreach ([2, 1, 0] as $offset) {
            $month = now()->subMonthsNoOverflow($offset)->startOfMonth();
            $p = Period::firstOrCreate(['starts_on' => $month->toDateString()], ['name' => $month->translatedFormat('F Y'), 'ends_on' => $month->copy()->endOfMonth()->toDateString(), 'active' => true]);
            foreach ($users as $index => $u) {
                $v = $u->vehicles()->first();
                $odometer = '12000.00';
                for ($i = 0; $i < 4; $i++) {
                    $day = $month->copy()->addDays($i * 2 + 1);
                    $start = 12000 + ($offset === 2 ? 0 : ($offset === 1 ? 120 : 240)) + $i * 30;
                    app(TripService::class)->save($u, ['vehicle_id' => $v->id, 'starts_at' => $day->format('Y-m-d').' 08:30:00', 'ends_at' => $day->format('Y-m-d').' 09:30:00', 'route' => 'Rathaus → Bauhof → Rathaus', 'destination' => ['Bauhof', 'Rathaus West', 'Grundschule Nord', 'Bürgerzentrum'][$i], 'purpose' => ['Dienstbesprechung', 'Vor-Ort-Abstimmung', 'Gebäudebegehung', 'Projektbesprechung'][$i], 'odometer_start' => (string) $start, 'odometer_end' => (string) ($start + 30), 'business_km' => '30.00', 'commute_km' => '0.00', 'private_km' => '0.00']);
                }
                if ($offset > 0) {
                    $s = app(SettlementService::class)->submit($u, $p);
                    if ($offset === 2) {
                        app(SettlementService::class)->transition($users[0], $s, 'APPROVED');
                        app(SettlementService::class)->transition($users[0], $s, 'SETTLED');
                        if ($index % 2 === 0) {
                            app(SettlementService::class)->transition($users[0], $s, 'LOCKED');
                        }
                    } elseif ($index === 1) {
                        app(SettlementService::class)->transition($users[0], $s, 'RETURNED', 'Bitte den Fahrtzweck für den Termin an der Grundschule genauer beschreiben.');
                    }
                }
            }
        }
        Storage::disk('local')->put('demo-credentials.txt', implode("\n", $credentials)."\n");
    }
}
