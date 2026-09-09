<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ImportService
{
    public const FIELDS = ['username' => 'Benutzername', 'plate' => 'Kennzeichen', 'starts_at' => 'Beginn (JJJJ-MM-TT HH:MM:SS)', 'ends_at' => 'Ende (JJJJ-MM-TT HH:MM:SS)', 'route' => 'Reiseroute', 'destination' => 'Ziel', 'purpose' => 'Zweck', 'odometer_start' => 'Kilometerstand Beginn', 'odometer_end' => 'Kilometerstand Ende', 'business_km' => 'Geschäftlich km', 'commute_km' => 'Arbeitsweg km', 'private_km' => 'Privat km', 'note' => 'Bemerkung', 'correction_reason' => 'Korrekturgrund'];

    public function parse(string $path, string $delimiter): array
    {
        $f = fopen($path, 'r');
        $headers = fgetcsv($f, 0, $delimiter, '"', '');
        if (! $headers) {
            throw ValidationException::withMessages(['file' => 'Die Datei ist leer.']);
        }$headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        if (count($headers) > 40) {
            throw ValidationException::withMessages(['file' => 'Maximal 40 Spalten sind erlaubt.']);
        }$rows = [];
        while (($row = fgetcsv($f, 0, $delimiter, '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }$rows[] = $row;
            if (count($rows) > 1000) {
                throw ValidationException::withMessages(['file' => 'Bitte höchstens 1.000 Zeilen pro Import verwenden.']);
            }
        }fclose($f);
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'Keine Datenzeilen gefunden.']);
        }

        return compact('headers', 'rows');
    }

    public function process(User $actor, array $rows, array $mapping, bool $commit): array
    {
        DB::beginTransaction();
        $errors = [];
        $count = 0;
        try {
            app(Settings::class)->lock();
            foreach ($rows as $index => $row) {
                try {
                    $data = [];
                    foreach (self::FIELDS as $field => $label) {
                        $column = $mapping[$field] ?? null;
                        $data[$field] = $column !== null && $column !== '' ? trim((string) ($row[(int) $column] ?? '')) : null;
                    }Validator::make($data, ['username' => 'required|string', 'plate' => 'required|string', 'starts_at' => 'required|date_format:Y-m-d H:i:s', 'ends_at' => 'required|date_format:Y-m-d H:i:s'])->validate();
                    $user = User::where('username', $data['username'])->first();
                    if (! $user) {
                        throw ValidationException::withMessages(['username' => 'Unbekannter Benutzername.']);
                    }$vehicle = Vehicle::where('user_id', $user->id)->where('plate', $data['plate'])->where('active', true)->first();
                    if (! $vehicle) {
                        throw ValidationException::withMessages(['plate' => 'Kein eindeutig zugeordnetes aktives Fahrzeug gefunden.']);
                    }$data['vehicle_id'] = $vehicle->id;
                    app(TripValidator::class)->validate($data, $vehicle, null, true);
                    if (Trip::where('user_id', $user->id)->where('vehicle_id', $vehicle->id)->where('starts_at', $data['starts_at'])->exists()) {
                        throw ValidationException::withMessages(['starts_at' => 'Diese Fahrt ist bereits vorhanden.']);
                    }$trip = app(TripService::class)->save($user, $data);
                    $trip->created_by = $actor->id;
                    $trip->save();
                    app(Audit::class)->record($trip, 'historical_import', [], null, true);
                    $count++;
                } catch (ValidationException $e) {
                    $errors[] = ['line' => $index + 2, 'messages' => $e->errors()];
                } catch (HttpException $e) {
                    $errors[] = ['line' => $index + 2, 'messages' => ['access' => ['Profil, Konto oder Fahrtenbuch ist für diesen Import nicht freigegeben.']]];
                }
            }
            if (! $commit || $errors) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return compact('errors', 'count');
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
