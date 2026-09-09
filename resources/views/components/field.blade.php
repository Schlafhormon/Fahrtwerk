@props(['name','label','value'=>'','type'=>'text','required'=>false,'help'=>null,'step'=>null])
<div class="field"><label for="{{ $name }}">{{ $label }}@if($required)<span class="required" aria-hidden="true"> *</span>@endif</label>
@if($type==='textarea')<textarea id="{{ $name }}" name="{{ $name }}" rows="3" @required($required) {{ $attributes }} @if($errors->has($name)) aria-invalid="true" aria-describedby="error-{{ $name }}" @endif>{{ old($name,$value) }}</textarea>
@else<input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $type==='password' ? '' : old($name,$value) }}" @required($required) @if($step) step="{{ $step }}" @endif {{ $attributes }} @if($errors->has($name)) aria-invalid="true" aria-describedby="error-{{ $name }}" @endif>@endif
@if($help)<small>{{ $help }}</small>@endif @error($name)<small class="field-error" id="error-{{ $name }}">{{ $message }}</small>@enderror</div>
