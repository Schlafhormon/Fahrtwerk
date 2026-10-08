<section class="panel" data-return-section>
<input type="hidden" name="include_return" value="0">
<label class="check"><input type="checkbox" name="include_return" id="include_return" value="1" aria-controls="return-fields" @checked(old('include_return',$includeReturn ?? false))> {{ ($quick ?? false)?'Rückfahrt einschließen':'Rückfahrt hinzufügen' }}</label>
<p>Fahrzeug, Zweck und Kilometerart werden übernommen. Start und Ziel werden vertauscht. Die Aufenthaltszeit zählt nicht als Fahrzeit.</p>
<fieldset id="return-fields" data-restored="{{ old('include_return')!==null ? 'true' : 'false' }}" @disabled(!old('include_return',$includeReturn ?? false))><legend>Rückfahrt</legend><div class="form-grid">
<x-field name="return_date" label="Rückfahrdatum" type="date" :value="today()->toDateString()"/>
<x-field name="return_start_time" label="Rückfahrt Beginn" type="time"/>
<x-field name="return_end_time" label="Rückfahrt Ende" type="time"/>
<x-field name="return_end_date" label="Rückfahrt Enddatum (bei mehrtägiger Fahrt)" type="date"/>
<x-field name="return_origin" label="Start der Rückfahrt" :value="$trip->destination" data-return-copy="destination"/>
<x-field name="return_destination" label="Ziel der Rückfahrt" :value="$trip->origin" data-return-copy="origin"/>
<x-field name="return_route" label="Reiseroute der Rückfahrt" :value="$trip->origin && $trip->destination ? $trip->destination.' → '.$trip->origin : null"/>
@if($quick ?? false)
<x-field name="return_distance" label="Entfernung Rückfahrt (km)" type="number" step="0.01" min="0" :value="$returnDistance ?? null" data-return-copy="distance"/>
@else
@foreach(['business'=>'Geschäftlich','commute'=>'Wohnung / Arbeitsstätte','private'=>'Privat'] as $kind=>$label)
@if($kind==='business' || app(\App\Services\Settings::class)->get('allow_'.$kind.'_km'))
<x-field :name="'return_'.$kind.'_km'" :label="$label.' Rückfahrt (km)'" type="number" step="0.01" min="0" :value="$trip->{$kind.'_km'}" :data-return-copy="$kind.'_km'"/>
@else<input type="hidden" name="return_{{ $kind }}_km" value="0">@endif
@endforeach
@endif
<details class="wide"><summary>Kilometerstände der Rückfahrt (optional)</summary><div class="form-grid">
<x-field name="return_odometer_start" label="Rückfahrt Kilometerstand Beginn" type="number" step="0.01" min="0"/>
<x-field name="return_odometer_end" label="Rückfahrt Kilometerstand Ende" type="number" step="0.01" min="0"/>
<x-field name="return_correction_reason" label="Rückfahrt Begründung bei abweichendem Kilometerstand" type="textarea"/>
</div></details>
</div></fieldset></section>
