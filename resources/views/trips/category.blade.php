<div class="field"><label for="kilometer_category">Kilometerart</label><select name="kilometer_category" id="kilometer_category">
@foreach(['business'=>'Geschäftlich','commute'=>'Wohnung / Arbeitsstätte','private'=>'Privat'] as $kind=>$label)
@if($kind==='business' || app(\App\Services\Settings::class)->get('allow_'.$kind.'_km') || ($category ?? 'business')===$kind)
<option value="{{ $kind }}" @selected(old('kilometer_category',$category ?? 'business')===$kind)>{{ $label }}</option>
@endif
@endforeach
</select></div>
