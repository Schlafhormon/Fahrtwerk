# Ausgeführte Abschlussprüfung

Stand: 9. September 2026. Geprüft wurde der Arbeitsstand in der bereitgestellten Windows-Umgebung. Die nachstehenden Ergebnisse unterscheiden native Laufzeitprüfungen von noch ausstehenden Containerprüfungen.

| Prüfung | Ergebnis |
|---|---|
| PHP / Framework / Datenbank | PHP 8.4.25, Laravel 13.31.0, PostgreSQL 17.11 lokal gestartet |
| Automatisierte Anwendungstests | 35 Tests, 213 Assertions bestanden |
| Browser | Zwei Playwright-Workflows mit Microsoft Edge bestanden; Desktop und Smartphone |
| Migrationen | Alle fünf Migrationen auf leerer PostgreSQL-Datenbank ausgeführt; wiederholter Neuaufbau der isolierten Testdatenbank erfolgreich |
| HTTP | `/health` und `/anmelden` liefern 200; lokale Instanz unter http://127.0.0.1:8080 |
| Zugriffsschutz | Rollen, fremde IDs, Listen-/Exportfilter, Pflichtpasswortwechsel, deaktivierte Konten und tatsächliche CSRF-Ablehnung geprüft |
| Fachlogik | Kilometer, Kontinuität, Überlappungen, historische Sätze, Snapshots, Statuswechsel, Sperren, Audit und atomarer CSV-Import geprüft |
| Oberfläche und Exporte | Desktop/Mobil visuell geprüft; kein horizontaler Seitenüberlauf bei 390 px; PDF gerendert und geprüft, XLSX heruntergeladen und Formel-Injection regressionstestet |
| Backup/Restore | Nativen PostgreSQL-Custom-Dump in separate leere Datenbank mit `pg_restore --single-transaction` eingespielt; Referenzzählungen 50 Fahrten, 8 Abrechnungen und 1306 Audit-Einträge erhalten |
| Abhängigkeiten | `composer validate` erfolgreich; Composer- und npm-Audit ohne gemeldete Schwachstellen zum Prüfzeitpunkt |
| Formatierung | Laravel Pint ausgeführt |
| Compose | Produktions- und Testkonfiguration mit Compose v2 syntaktisch validiert |
| Docker-Build und Start | Versucht, durch fehlenden Docker-Daemon blockiert; keine erfolgreich gebauten Images oder gestarteten Container behauptet |

Die Browsertests prüfen Login, gegebenenfalls Pflichtpasswortwechsel, Navigation, Entwurf anlegen/löschen, mobile Darstellung, Adminzugriffsschutz, PDF/XLSX-Downloads und Cookie-/CSRF-Verhalten. Das Frontend verwendet lokale Assets ohne Build-Pipeline. Testdaten und Zugangsdaten sind nicht Bestandteil des versionierbaren Repository-Inhalts.

## Sicherheitsreview und behobene Probleme

Der selbst erzeugte Anwendungscode wurde mit unabhängigem Architektur- und Quellcodereview sowie anschließender Validierung geprüft. Drei relevante Probleme wurden während der Entwicklung behoben und durch Regressionstests abgesichert:

1. **Parallelität beim Passwortwechsel:** Ein zwischenzeitlicher Adminreset konnte mit einem bereits geladenen Benutzermodell kollidieren. Der Passwortwechsel lädt und sperrt jetzt den aktuellen Datensatz, prüft aktuellen Hash und Sitzungsrevision und aktualisiert beides atomar.
2. **Historische Fahrzeugdaten:** Eine nachträglich geänderte Fahrzeugart konnte die spätere Erstattungsberechnung beeinflussen. Controller und PostgreSQL-Trigger sperren identifizierende und abrechnungsrelevante Fahrzeugfelder, sobald entsprechende historische Fahrten vorliegen. Aktivierung und Standardauswahl bleiben möglich.
3. **XLSX-Formeln:** Automatische Zelltypbestimmung konnte Nutzereingaben mit `=` als Formel schreiben. Exportwerte werden ausdrücklich als Textzellen erzeugt. Der Test untersucht das tatsächliche XLSX-XML auf Formelelemente. CSV erhält ebenfalls einen Schutz vor Formelinterpretation.

Technische Fehlerlogs protokollieren nur Fehlerklasse, Code, Datei/Zeile und Route; keine Exception-Nachrichten, SQL-Bindings oder Requestinhalte. Das fachliche Audit ist getrennt und gegen normale Änderungen in PostgreSQL geschützt. Historische Abrechnungspositionen und Identitätssnapshots bleiben unverändert. Host- und Datenbankadministratoren sind vertrauenswürdige Betriebsrollen; Datenbankbesitzer können Datenbankregeln technisch administrieren.

## Grenze der ausgeführten Prüfung

Docker Engine/Docker Desktop und WSL waren nicht verfügbar. Die nachinstallierte CLI kann allein keine Linux-Container starten. Build und Start scheitern an der fehlenden Named Pipe `//./pipe/docker_engine`. Deshalb sind tatsächlicher Imagebuild, Nginx/PHP-FPM-Zusammenspiel, Container-Healthchecks, Volume-Berechtigungen sowie die Docker-Backup-/Restore-Skripte in dieser Umgebung **nicht laufzeitverifiziert**. Der native Datenbank-Restore ersetzt keinen vollständigen Container-Restore-Test.

Die mitgelieferte GitHub-CI enthält isolierte PostgreSQL-Tests sowie Build, Start und Healthcheck des Produktionsstacks auf einem Linux-Runner. Diese CI wurde hier nicht auf einem externen Server ausgelöst. Vor Produktivfreigabe auf einem Docker-fähigen Host ausführen:

```sh
docker compose -f docker-compose.test.yml up --build --abort-on-container-exit --exit-code-from test
docker compose -f docker-compose.test.yml down -v
docker compose up -d --build --wait
docker compose ps
docker compose logs --since=5m
curl --fail http://localhost:8080/health
```

Anschließend Bootstrap-Login, HTTPS-Konfiguration, Einreichung/Freigabe und einen Backup-/Restore-Probelauf in einer isolierten Zielinstallation prüfen. Aufbewahrung wird als konfigurierbarer Prüfbericht vorbereitet; eine automatische Löschung historischer Abrechnungen ist bewusst nicht implementiert. LDAP/AD ist als Adapter-Erweiterung vorbereitet, lokale Anmeldung ist vollständig implementiert.

Der versiegelte Reviewbericht und seine kanonischen Artefakte liegen unter [security/report.md](security/report.md). Er dokumentiert keine verbleibenden bestätigten Findings, aber ausdrücklich teilweise Abdeckung wegen der fehlenden Containerlaufzeitprüfung. Annahmen im Architekturteil beschreiben den statischen Prüfschritt; die anschließend vom Hauptprüfer ausgeführten nativen Laufzeitprüfungen sind in diesem Dokument und im Scope des Berichts festgehalten. Der ursprüngliche Scan-Snapshot entstand vor den dokumentierten Korrekturen; diese wurden am aktuellen Arbeitsstand nachgeprüft.
