<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>@yield('title','Übersicht') · Fahrtwerk</title><link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/assets/app.css"><script src="/assets/app.js" defer></script></head>
<body><a class="skip-link" href="#main">Zum Inhalt springen</a>
@auth
<aside class="sidebar"><a class="brand" href="{{ route('dashboard') }}"><span class="brandmark">F<span>↗</span></span><span>Fahrtwerk<small>Digitale Fahrtenverwaltung</small></span></a><p class="nav-caption">{{ auth()->user()->isAdmin() ? 'VERWALTUNG' : 'MEIN FAHRTENBUCH' }}</p><nav aria-label="Hauptnavigation">
@php($nav=[['dashboard','Übersicht','◫'],['trips.create','Fahrt eintragen','＋'],['trips.index',auth()->user()->isAdmin()?'Fahrten':'Meine Fahrten','↗'],['settlements.index','Abrechnungen','▤']])
@if(auth()->user()->isAdmin()) @php($nav=array_merge($nav,[['admin.people.index','Mitarbeitende','○'],['vehicles.index','Fahrzeuge','▱'],['reports.index','Auswertungen','▥'],['admin.master','Stammdaten','▦'],['admin.audit','Audit-Log','≡'],['admin.settings.edit','Einstellungen','⚙']]))
@else @php($nav=array_merge($nav,[['templates.index','Vorlagen','☆'],['vehicles.index','Meine Fahrzeuge','▱'],['reports.index','Auswertungen','▥'],['profile.edit','Profil','○']])) @endif
@foreach($nav as [$route,$label,$icon])<a href="{{ route($route) }}" class="{{ request()->routeIs($route) ? 'active' : '' }}"><span aria-hidden="true">{{ $icon }}</span>{{ $label }}</a>@endforeach
</nav><div class="sidebar-bottom"><span class="online-dot"></span> Interne Anwendung<small>{{ app(\App\Services\Settings::class)->get('organization_name') }}</small></div></aside>
<div class="workspace"><header class="topbar"><button type="button" class="menu-button secondary" aria-label="Navigation öffnen" aria-expanded="false">☰</button><span class="context">{{ app(\App\Services\Settings::class)->get('organization_name') }}</span><div class="account"><a href="{{ route('profile.edit') }}"><span class="avatar">{{ mb_substr(auth()->user()->display_name,0,1) }}</span><span>{{ auth()->user()->display_name }}<small>{{ __('status.'.auth()->user()->role) }}</small></span></a><form action="{{ route('logout') }}" method="post">@csrf<button class="text-button">Abmelden</button></form></div></header>
@else <div class="guest-workspace"> @endauth
<main id="main" tabindex="-1">
@if(session('success'))<div role="status" class="alert success">{{ session('success') }}</div>@endif
@if(session('notice'))<div role="status" class="alert">{{ session('notice') }}</div>@endif
@if($errors->any())<div role="alert" class="alert error"><strong>Bitte prüfen Sie Ihre Angaben.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</main><footer>Fahrtwerk <span>Digitale Fahrtenverwaltung</span></footer></div></body></html>
