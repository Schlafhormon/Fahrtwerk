# Fahrtwerk – Digitale Fahrtenverwaltung

Deutschsprachige interne Webanwendung für kommunale Fahrtenbücher, persönliche Fahrzeuge und Vorlagen, Einreichung, Prüfung, Abrechnung und Auswertung. Mitarbeitende sehen ausschließlich eigene personenbezogene Daten; die Personalstelle arbeitet organisationsweit.

## Architektur

```text
Browser → Nginx → Laravel 13 / PHP 8.4-FPM → PostgreSQL 17
                         └─ privates Storage-Volume
```

Klassischer Monolith mit serverseitigem Blade, responsive CSS und wenigen lokalen JavaScript-Funktionen. Keine SPA, externen CDNs oder Trackingdienste. Der Scheduler verwendet dasselbe Anwendungsimage und führt ausschließlich Laravel-Wartungsaufgaben aus.

Installierter Frameworkstand: Laravel **13.31.0**. PHP-Abhängigkeiten sind durch `composer.lock` fixiert. `TripService`/`TripValidator` kapseln Fahrtenlogik, `SettlementService` den Prüfworkflow, `RateService`/`Decimal` die Erstattungsberechnung. `TripQuery` und Policies erzwingen Eigentümergrenzen; `Audit` führt das fachliche Journal. Der `Authenticator`-Adapter ermöglicht später LDAP/AD, implementiert ist lokale Authentifizierung.

## Voraussetzungen

Linux-Server mit Docker Engine und Docker Compose v2, mindestens 2 CPU-Kerne und 2 GB RAM; 4 GB sind für kleine produktive Installationen sinnvoll. Ausreichenden Speicher für Images, Datenbank und Backups vorsehen. Internet wird für den initialen Build benötigt, nicht für den laufenden Fachdienst.

Unter Windows: Docker Desktop mit laufender **Linux-Container-Engine**, etwa über WSL2. Eine allein installierte Docker-Kommandozeile genügt nicht.

## Installation und erster Start

```sh
cp .env.example .env
docker compose up -d --build
docker compose ps
```

PowerShell: `Copy-Item .env.example .env`. Die Shell-Skripte benötigen LF-Zeilenenden; `.gitattributes` gibt diese vor.

In der ausgelieferten Arbeitsumgebung existiert eine ignorierte `.env` für den nativen lokalen Testserver (PostgreSQL auf Port 55432). Für eine neue Docker-Installation die Konfiguration aus `.env.example` übernehmen; diese lokale Testkonfiguration nicht unverändert für Compose verwenden. Bestehende produktive `.env`-Dateien dagegen bei Updates bewahren.

Standardadresse: **http://localhost:8080**. `APP_PORT` ändert den Port. `APP_BIND=127.0.0.1` begrenzt den Erststart auf den Server. Für Intranet-Zugriff eine interne Bind-IP oder `0.0.0.0` setzen, Firewall passend beschränken und `APP_URL` auf die verwendete Serveradresse ändern.

Automatisch erfolgen Geheimniserzeugung, Datenbankmigrationen, einmalige Bootstrap-Administratoranlage und View-Kompilierung. Die Datenbank veröffentlicht keinen Host-Port. Alle regulären Container verwenden `restart: unless-stopped` und Healthchecks.

### Bootstrap-Administrator

Benutzername standardmäßig **admin**, konfigurierbar über `BOOTSTRAP_ADMIN_USERNAME`. Ist `BOOTSTRAP_ADMIN_PASSWORD` leer, entsteht ein zufälliges Einmalpasswort in einer privaten Datei, niemals im Containerlog:

```sh
docker compose exec app cat storage/app/private/bootstrap-admin.txt
# Nach vertraulicher Übergabe entfernen:
docker compose exec app rm storage/app/private/bootstrap-admin.txt
```

Alternativ vor Erststart ein mindestens acht Zeichen langes Passwort in `.env` festlegen. Neustarts setzen bestehende Konten niemals zurück. Nach Anmeldung sind ein eigenes Passwort und ein vollständiges Profil erforderlich. Keine Selbstregistrierung, kein öffentlicher Passwortreset.

Ersteinrichtung: Profil vervollständigen → unter „Stammdaten“ Organisationseinheiten, Erstattungssätze und Zeitraum anlegen → Mitarbeitende anlegen und temporäre Passwörter vertraulich übergeben. Mitarbeitende ergänzen ihre Profile und legen ein aktives Fahrzeug an.

## Konfiguration

Die kommentierte `.env.example` enthält alle Ausgangswerte. Geheimnisse gehören ausschließlich in `.env` oder die vorgesehenen Volumes.

| Variable | Bedeutung |
|---|---|
| `APP_NAME` | Fahrtwerk – Digitale Fahrtenverwaltung |
| `APP_ENV`, `APP_DEBUG` | Produktiv: `production`, `false` |
| `APP_URL`, `APP_BIND`, `APP_PORT` | Adresse, Bind-IP, veröffentlichter Port |
| `APP_TIMEZONE`, `APP_LOCALE` | Europe/Berlin, de; de-DE wird ebenfalls unterstützt |
| `APP_KEY` | Leer: einmalig generiert, dauerhaft im Storage-Volume |
| `DB_HOST`, `DB_PORT` | Innerhalb Compose: db, 5432 |
| `DB_DATABASE`, `DB_USERNAME` | Standard: fahrtwerk |
| `DB_PASSWORD` | Leer: sicher generiert im Secret-Volume |
| `SESSION_LIFETIME` | Inaktivitätsfrist, 120 Minuten |
| `SESSION_ENCRYPT` | Standard true; verschlüsselte Datenbanksitzungen |
| `SESSION_SECURE_COOKIE` | Bei HTTPS true; lokaler HTTP-Erststart false |
| `HASH_DRIVER` | Argon2id; schnelle Bcrypt-Konfiguration nur für Tests |
| `ALLOW_SELF_REGISTRATION` | Selbstregistrierung bleibt immer deaktiviert |
| `INITIAL_PASSWORD_MODE` | Ausschließlich random unterstützt |
| `ALLOW_PRIVATE_KM`, `ALLOW_COMMUTE_KM` | Fachliche Ausgangswerte, true |
| `ENFORCE_ODOMETER_CONTINUITY` | Ausgangswert false |
| `AUDIT_LOG_ENABLED` | Nichtkritisches Journal; kritische Änderungen immer protokolliert |
| `BOOTSTRAP_ADMIN_USERNAME/PASSWORD` | Einmalige Administratoranlage |
| `DEMO_ENABLED` | false; nur ausdrücklich in local/testing |
| `LOG_CHANNEL`, `LOG_LEVEL` | Strukturierte stderr-Logs, warning |

Fachliche Einstellungen in der Oberfläche: Organisationsname, Adresse, Logo, Standardzeitraum, erlaubte Kilometerarten, Kontinuität, Datums-/Zahlenformat, Seitengröße und Aufbewahrungsprüffrist. Gespeicherte Datenbankwerte haben Vorrang vor fachlichen `.env`-Ausgangswerten. Secrets sind dort nicht editierbar.

Ein initialisierter PostgreSQL-Cluster übernimmt Änderungen an Benutzer, Passwort oder Datenbankname **nicht** durch bloßes Ändern der `.env`. Zugang kontrolliert in PostgreSQL ändern und Anwendung/Secret-Datei synchronisieren. Niemals deswegen ein produktives Volume löschen.

### HTTPS

Produktiven Zugriff über HTTPS bereitstellen, zum Beispiel über den vorhandenen internen Reverse Proxy mit vertrauenswürdigem Zertifikat. `APP_URL=https://fahrtwerk.intern` und `SESSION_SECURE_COOKIE=true` setzen; HTTP am Proxy umleiten und HSTS dort konfigurieren. Der interne HTTP-Hop bleibt auf Loopback/einem geschützten Netz.

Beliebigen `X-Forwarded-*`-Headern wird standardmäßig nicht vertraut. Falls Client-IP-/HTTPS-Erkennung hinter einem Proxy benötigt wird, ausschließlich dessen konkrete IP in Laravels `trustProxies` konfigurieren. Ohne diese Konfiguration greifen Loginlimits zusätzlich über die gemeinsame Proxy-IP.

## Rollen und Workflow

EMPLOYEE: eigene Profile, Fahrzeuge, Vorlagen, Fahrten, Abrechnungen, Auswertungen und Exporte. Manipulierte fremde Objekt-IDs erhalten 403/404; Listen-/Exportfilter können Eigentümergrenzen nicht erweitern. Eingaben wie `user_id`, Rolle oder Status werden in Mitarbeiterformularen nicht übernommen.

ADMIN: organisationsweite Prüfung, Zugangsverwaltung, Stammdaten, Import, Auswertungen und Audit. Keine Umgehung historischer Sperren. Selbstdeaktivierung/-herabstufung und Verlust des letzten aktiven Administrators werden verhindert. Konten werden deaktiviert, nicht mit historischen Referenzen gelöscht.

Ein Adminreset erzeugt ein neues temporäres Passwort, erzwingt dessen Änderung und entzieht vorhandenen Sitzungen über `auth_version` die Berechtigung. Die einmalige Ausgabeseite speichert Klartextpasswörter weder in Flashdaten noch im Audit.

### Fahrten

`DRAFT → SUBMITTED → APPROVED → SETTLED → LOCKED`

Korrektur: `SUBMITTED → RETURNED → SUBMITTED`. Entwürfe dürfen unvollständig sein und gelöscht werden. Zurückgegebene Fahrten benötigen einen Änderungsgrund und bleiben im bisherigen Abrechnungszeitraum. Alle anderen Zustände sperren Änderungen auch für Administratoren.

Vollständige Fahrten benötigen Fahrzeug, Start-/Endzeitpunkt, Route, Ziel, Zweck, beide Kilometerstände und alle Kilometerarten. Mehrtägige Fahrten haben ein optionales separates Enddatum. Kilometer werden mit zwei Nachkommastellen erfasst; Anfang/Ende, exakte Aufteilung und Zeitreihenfolge werden geprüft. Überschneidungen derselben Person bzw. desselben Fahrzeugs verhindern die Einreichung. Entwürfe zeigen Prüfhinweise für fehlende Daten, Sprünge, Überlappungen und Strecken über 1.000 km.

Bei erzwungener Kontinuität werden vorheriger Endstand und nachfolgender Anfangsstand berücksichtigt. Abweichungen benötigen eine ausdrückliche Begründung. Nicht erfasste Privatfahrten werden nicht automatisch ergänzt.

Fahrzeugstammdaten werden festgeschrieben, sobald eingereichte oder abgeschlossene Fahrten darauf verweisen. Aktivierung/Standardkennzeichnung bleiben änderbar. Für ein geändertes Fahrzeug einen neuen Datensatz anlegen. Damit kann eine eingereichte Abrechnung nicht durch nachträgliche Änderung der Fahrzeugart beeinflusst werden.

### Abrechnungen und Regeln

Zeiträume dürfen sich nicht überschneiden. Einreichung umfasst die eigenen Fahrten nach Startdatum im gewählten Zeitraum; neue Fahrten in bereits eingereichten Zeiträumen werden verhindert. Alle Prüfungen laufen nochmals beim Einreichen.

`OPEN → SUBMITTED → APPROVED → SETTLED → LOCKED`, mit begründeter Rückgabe nach `RETURNED`. Freigabe, Abrechnung und Sperre sind getrennte bestätigte Schritte. „Abgerechnet“ dokumentiert eine außerhalb der Anwendung veranlasste Auszahlung; Fahrtwerk führt keine Banktransaktion aus.

Keine fest codierten produktiven Kilometersätze. Regeln besitzen Kategorie, optional Fahrzeugart, Gültigkeit, Aktivstatus und Version. Folgeversionen ersetzen ihre Regelfamilie für neue Berechnungen. Neue unabhängige Gültigkeitszeiträume können separate Regelfamilien erhalten. Fahrzeugspezifische Regeln haben Vorrang, danach der neueste passende Gültigkeitsbeginn, bei Gleichstand die neuere Regel-ID. Regeln daher ohne unbeabsichtigte Überschneidungen pflegen.

Fehlt ein geschäftlicher Satz für Geschäftskilometer, scheitert die gesamte Freigabe atomar. Fehlende Privat-/Arbeitswegregeln bedeuten 0 €. Freigabe speichert pro Position Kilometer, vierstelligen Satz, Betrag, Regel-ID, Berechnungszeitpunkt und vollständige Fahrtdaten. Auch Nullkilometerfahrten bleiben enthalten. Rundung kaufmännisch **pro Position auf Cent**, danach Addition ganzer Centbeträge. Floats werden ausschließlich für Anzeigeformatierung genutzt.

Abrechnungs-PDFs ab Freigabe verwenden historische Mitarbeiter-, Organisations-, Fahrzeug-, Fahrt- und Regeldaten. Spätere Profil-/Regeländerungen verändern diese Berechnung nicht. Der aktuelle Workflowstatus wird weiterhin angezeigt. Reproduzierbarkeit ist inhaltlich; PDFs sind keine byteidentischen Signaturcontainer.

## Auswertungen und Exporte

Filter: Zeitraum, Fahrzeug, Kilometerart, Ziel, Zweck, Suche; administrativ zusätzlich Person, Organisationseinheit und Fahrt-/Abrechnungsstatus. Enthalten sind Kilometerarten, Anzahl, Durchschnitt, Kosten, Monats-/Jahreswerte, Fahrzeugverteilung, häufige Ziele/Zwecke und Zeitraumvergleich; administrativ Person-/Organisationskosten und Auffälligkeitsansicht. Große Fahrtenlisten sind paginiert und sortierbar.

CSV, XLSX und Druckansicht übernehmen die aktuellen Fahrtenfilter. CSV: UTF-8/BOM/Semikolon, neutralisierte Formelpräfixe. XLSX: explizite Textzellen für Benutzereingaben. Abrechnungs-PDFs sind ab Freigabe verfügbar. Reports verwenden freigegebene Snapshotbeträge und sonst aktuelle Schätzungen; fehlende Sätze werden angezeigt. Allgemeine Berichte gruppieren nach aktuellen Stammdatenzuordnungen; historische Identitätsdaten bleiben im PDF.

## Historischer CSV-Import

Administration → Fahrten → CSV importieren. Musterdatei: `public/examples/historical-trips.csv`.

1. UTF-8-Datei mit Kopfzeile und Semikolon/Komma auswählen.
2. Spalten zuordnen, Vorschau der ersten fünf Zeilen kontrollieren.
3. Sämtliche Zeilen validieren; Probeschreibvorgänge werden vollständig zurückgerollt.
4. Erst danach verbindlich importieren; erneute Prüfung und atomarer Commit des ganzen Stapels.

Maximal 2 MB, 40 Spalten und 1.000 Datenzeilen. Zeitpunkte: `JJJJ-MM-TT HH:MM:SS`; Kilometer mit Dezimalpunkt, ohne Tausendertrennzeichen. Konten mit vollständigen Profilen und aktive zugeordnete Fahrzeuge müssen bestehen. Import erzeugt prüfbare Entwürfe, keine manipulierten historischen Abrechnungen. Doppelte Person/Fahrzeug/Startzeit wird abgewiesen. Vorschauen laufen nach 30 Minuten ab; der Scheduler löscht zurückgebliebene Stagingdateien.

## Betrieb, Migrationen und Updates

```sh
docker compose ps
docker compose logs --tail=100 app web db scheduler
curl --fail http://localhost:8080/health
docker compose exec app php artisan migrate:status
docker compose restart app web scheduler
```

`/health` prüft Datenbank und beschreibbaren View-Storage. PHP-FPM wird über FastCGI geprüft, Nginx per HTTP, PostgreSQL über `pg_isready`, Scheduler über einen minütlichen Heartbeat.

| Volume | Persistente Inhalte |
|---|---|
| `postgres_data` | Datenbank, Sitzungen, Audit, Snapshotpositionen |
| `app_storage` | APP_KEY, private Dateien, Logos, Imports und lokale Logs |
| `secrets` | DB-Passwort; für App/Scheduler read-only |

Vor jedem Update Backup erstellen, Quellen aktualisieren, Images neu bauen:

```sh
sh scripts/backup.sh
docker compose build --pull
docker compose up -d --wait --wait-timeout 240
docker compose exec app php artisan migrate:status
```

Migrationen laufen beim Appstart; manuell `docker compose exec app php artisan migrate --force`. **Nie `migrate:fresh` oder `docker compose down -v` auf Produktivdaten ausführen.** Rückwärtsmigration ersetzt kein konsistentes Restore.

Abhängigkeiten in einer Testumgebung aktualisieren, `composer audit`, Tests und Imagebuild durchführen. Lockfiles versionieren. Image-Tags sind auf Major-/Minor-Linien festgelegt; für freigegebene Deployments können geprüfte Image-Digests ergänzt werden.

## Backup

```sh
sh scripts/backup.sh
# Alternativ ein geschütztes Ziel:
sh scripts/backup.sh /sicheres-backup/fahrtwerk-2026-09-09
```

Das Script aktiviert Wartungsmodus und erzeugt mit `umask 077`:

- `database.dump`: PostgreSQL Custom-Dump mit Schema, Triggern und Daten.
- `storage.tar.gz`: Anwendungsstorage einschließlich APP_KEY.
- `secrets.tar.gz`: generierter Datenbankzugang.
- `environment.env`: vorhandene lokale Konfiguration.

Diese Dateien enthalten personenbezogene Daten und Geheimnisse. Auf einem gesonderten verschlüsselten, zugriffsbeschränkten System sichern. Aufbewahrung und regelmäßige Rücksicherungen festlegen. Backup außerhalb aktiver Import-/Abrechnungsläufe durchführen. Für strikte Betriebsruhe zuvor `docker compose stop -t 130 web` ausführen und nach dem Backup `docker compose start web`; keine parallelen administrativen CLI-Schreibzugriffe durchführen.

## Restore

Zuerst aktuellen Zustand sichern. **Dieser Befehl ersetzt die Ziel-Datenbank und den Anwendungsstorage:**

```sh
sh scripts/restore.sh /sicheres-backup/fahrtwerk-2026-09-09 --replace-current-data
```

Web/App/Scheduler werden gestoppt, Dump und Storage restauriert und die Anwendung neu gestartet. Datenbankkonto muss zum Zielcluster passen; Datenbankrollen werden nicht durch `pg_dump` gesichert. `.env` und DB-Zugang bleiben bewusst die des Zielsystems. Bei explizitem `APP_KEY` den passenden Backupwert übernehmen; bei leerem Wert wird die restaurierte Schlüsseldatei verwendet.

Neuer Server: passenden Quellstand bereitstellen, `environment.env` geschützt als `.env` übernehmen, Hostadresse/Port anpassen. Der neue Datenbankcluster kann einen neuen generierten DB-Zugang erhalten; der Dump enthält keine Rollenpasswörter. Alternativ das gesicherte Secret-Volume vor Initialisierung kontrolliert wiederherstellen. APP_KEY unbedingt bewahren.

```sh
docker compose up -d --build --wait --wait-timeout 240
sh scripts/restore.sh /sicheres-backup/fahrtwerk-2026-09-09 --replace-current-data
docker compose ps
curl --fail http://localhost:8080/health
```

Danach Login, alte Abrechnungen und PDF-Beträge prüfen. Wiederherstellung regelmäßig auf einem getrennten System erproben. Ein verlorener APP_KEY macht verschlüsselte Daten unlesbar.

## Entwicklungsumgebung und Tests

Backendtests verwenden **echtes PostgreSQL**, einschließlich Constraints und Triggern:

```sh
docker compose -f docker-compose.test.yml up --build --abort-on-container-exit --exit-code-from test
docker compose -f docker-compose.test.yml down -v
```

Eigener Projektname, flüchtige Testdatenbank, kein veröffentlichter DB-Port. Trust-Authentifizierung gilt nur für diesen isolierten Teststack.

Lokale Alternative: PHP 8.4, Composer, PostgreSQL; getrennte Datenbanken `fahrtwerk` und `fahrtwerk_test`. PHP benötigt pdo_pgsql, mbstring, openssl, fileinfo, intl, zip, gd und DOM/XML. `composer install` ausführen, `.env` mit lokalen DB-Zugangsdaten erstellen. `.env.testing` davon ableiten mit `APP_ENV=testing`, `DB_DATABASE=fahrtwerk_test`. Tests leeren die Testdatenbank; niemals dieselbe Datenbank wie für den Betrieb verwenden.

```sh
php artisan key:generate
php artisan migrate
php artisan fahrtwerk:bootstrap
php artisan serve --host=127.0.0.1 --port=8080
php artisan test --compact
vendor/bin/pint
composer audit
```

Abgedeckt: Login/Logout/Limits, Pflichtpasswortwechsel, Deaktivierung, Rollen/IDOR/BOLA, Filter-/Exportisolation, Kilometer/Kontinuität/Überlappung, Berechnung/historische Sätze, Statuswechsel/Snapshots, atomarer Import, Audit, konkurrierender Reset und Datenbank-Unveränderlichkeit.

### Opt-in Demo und Browserprüfung

Nur isoliert mit `APP_ENV=local`, `DEMO_ENABLED=true`:

```sh
php artisan db:seed
npm ci
npx playwright install chromium
E2E_BROWSER=chromium npm run test:e2e
```

Unter Windows verwendet `npm run test:e2e` standardmäßig installiertes Microsoft Edge. Server muss unter http://127.0.0.1:8080 laufen; überschreibbar mit `E2E_BASE_URL`. `E2E_CREDENTIAL_FILE` kann eine alternative private Demo-Zugangsdatei angeben.

Demo enthält mehrere Mitarbeitende, Fahrzeuge, Vorlagen, Fahrten, Regeln und Abrechnungszustände. Zufällige Passwörter liegen nur in `storage/app/private/demo-credentials.txt`. Die Browserprüfung führt bei Bedarf den Pflichtwechsel durch und aktualisiert diese private Testdatei. Der Demo-Satz von 0,30 €/km ist ausschließlich Testmaterial. Produktiv wird nie automatisch geseedet.

Playwright prüft Desktop/Smartphone, Login, Fahrterfassung, Navigation, Downloads, Cookies und CSRF. Screenshots/PDF/XLSX unter `docs/qa` und Browserergebnisse werden nicht versioniert. Abgebrochene Browserprüfungen können Testentwürfe hinterlassen.

Die GitHub-CI führt PostgreSQL-Tests sowie Produktionsimagebuild und Compose-Healthchecks auf einem Docker-fähigen Linux-Runner aus. Ein lokaler PHP-Test ersetzt diesen Containercheck nicht.

## Sicherheit und Datenschutz

- Serverseitige Eigentümer-/Rollenprüfung für Datensätze, Referenzen, Listen und Exporte.
- CSRF, Blade-Escaping, ORM/Prepared Statements, Whitelists für Eingaben/Sortierung.
- Argon2id, zufällige temporäre Passwörter, Sitzungsregeneration, HttpOnly/SameSite, HTTPS-Secure-Cookies.
- Owner-/Zeilensperren und Revisionen für kritische Vorgänge; Passwortwechsel prüft den aktuellen Hash unter Sperre.
- PostgreSQL schützt historische Fahrten/Fahrzeuge/Abrechnungen, Regeln, Positionen und Audit zusätzlich. Host-/Datenbankadministratoren bleiben vertrauenswürdige Betriebsrollen.
- Begrenzte PNG/JPEG-Uploads werden als PNG neu geschrieben. Kein öffentlicher Storage-Symlink, keine SVG-Uploads oder externen PDF-Ressourcen.
- Technische Fehlerlogs enthalten Klasse, Code, Datei/Zeile und Route, keine Exception-Nachrichten, SQL-Bindings, Requestbodies oder Sessiondaten. Nginx/FPM-Accesslogs sind aus. Logrotation auf dem Host konfigurieren.
- Audit enthält erforderliche alte personenbezogene Werte, aber keine Passwörter/Hashes/Tokens und keine unnötigen IP-Adressen. Audit und Backups sind vertraulich.
- `php artisan fahrtwerk:retention-report` liefert Prüfkandidaten jenseits der konfigurierten Frist. Keine automatische historische Löschung; Archivierungs-/Löschentscheidungen organisatorisch festlegen. Der Scheduler entfernt stündlich abgelaufene Importdateien/Sitzungen.
- Verlorener Zugang: `docker compose exec app php artisan fahrtwerk:admin-reset admin`; private `admin-reset.txt` vertraulich auslesen und anschließend entfernen.

## Ordnerstruktur

```text
app/Contracts              Authentifizierungsadapter
app/Http                   Controller und Middleware
app/Models, app/Policies    Datenmodelle und Zugriffskontrolle
app/Services               Fachlogik, Berechnung, Audit, Import
database/migrations        Schema, Foreign Keys, PostgreSQL-Guards
database/seeders           Opt-in Demo
resources/views            Deutsche Blade-Oberfläche / PDF
public/assets, lang/de     CSS/JavaScript und Übersetzungen
docker, scripts            Containerbetrieb, Healthchecks, Backup/Restore
tests/Feature              Backend-/PostgreSQL-Tests
tests/Browser              Playwright-Workflows
docs                       Prüfnachweise
```

## Nachweis und verbleibende Umgebungseinschränkung

Ausgeführte Prüfungen stehen in `docs/VALIDATION.md`. In der bereitgestellten Windows-Umgebung wurden PHP, Composer und PostgreSQL lokal eingerichtet, die Anwendung gestartet und echte Backend-/Browsertests durchgeführt. Docker/WSL waren nicht eingerichtet. Compose wurde validiert, Build und Start wurden versucht, scheiterten aber am fehlenden Docker-Daemon. Container-Healthchecks sind deshalb hier **nicht als bestanden** zu verstehen. Vor Produktivfreigabe den dokumentierten Docker-/CI-Lauf abschließen.
