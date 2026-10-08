@extends('layout')
@section('title','Schnellerfassung')
@section('content')
<div class="page-heading"><div><a class="back-link" href="{{ route('templates.index') }}">← Meine Vorlagen</a><h1>Schnellerfassung: {{ $template->name }}</h1><p>Uhrzeiten ergänzen und gemeinsam speichern. Alle Vorgaben bleiben änderbar.</p></div></div>
@if($vehicles->isEmpty())
<div class="panel empty"><h2>Bitte zuerst ein Fahrzeug anlegen</h2><a class="button primary" href="{{ route('vehicles.create') }}">Fahrzeug anlegen</a></div>
@else
<form method="post" action="{{ route('trips.store') }}" class="trip-form" data-quick-capture>@csrf
<input type="hidden" name="template_id" value="{{ $template->id }}">
<section class="panel"><div class="form-grid">
<x-field name="date" label="Datum" type="date" :value="today()->toDateString()"/>
<div class="field"><label for="vehicle_id">Fahrzeug *</label><select name="vehicle_id" id="vehicle_id" required>@foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(old('vehicle_id',$trip->vehicle_id)==$v->id)>{{ $v->name }} · {{ $v->plate }}{{ !$v->active?' (inaktiv – bitte anderes Fahrzeug wählen)':'' }}</option>@endforeach</select></div>
<x-field name="origin" label="Start" :value="$trip->origin"/>
<x-field name="destination" label="Ziel" :value="$trip->destination"/>
<x-field name="route" label="Reiseroute" :value="$trip->route"/>
<x-field name="purpose" label="Zweck der Fahrt" :value="$trip->purpose"/>
@include('trips.category',['category'=>$template->kilometer_category])
<x-field name="distance" label="Entfernung Hinfahrt (km)" type="number" step="0.01" min="0" :value="$template->distance"/>
<x-field name="start_time" label="Fahrtbeginn" type="time"/>
<x-field name="end_time" label="Fahrtende" type="time"/>
<x-field name="end_date" label="Enddatum (bei mehrtägiger Fahrt)" type="date"/>
</div>
@if($template->distance === null)<p class="alert warning">Diese Vorlage enthält noch keine Entfernung. Bitte die tatsächlich gefahrenen Kilometer ergänzen.</p>@endif
<details><summary>Kilometerstände der Hinfahrt (optional)</summary><div class="form-grid">
<x-field name="odometer_start" label="Kilometerstand Beginn" type="number" step="0.01" min="0"/>
<x-field name="odometer_end" label="Kilometerstand Ende" type="number" step="0.01" min="0"/>
<x-field name="correction_reason" label="Begründung bei abweichendem Kilometerstand" type="textarea"/>
</div></details>
</section>
@include('trips.return',['includeReturn'=>$template->include_return,'returnDistance'=>$template->return_distance ?? $template->distance,'quick'=>true])
<div class="panel km-summary" aria-live="polite">Hin- und Rückfahrt zusammen: <output id="journey-distance">–</output></div>
<div class="form-actions"><a class="button secondary" href="{{ route('templates.index') }}">Abbrechen</a><button class="primary">Entwürfe gemeinsam speichern</button></div>
</form>
@endif
@endsection
