# TypoVigil

## Worum es geht

Wer mehrere TYPO3-Seiten betreut, verliert leicht den Überblick: Welche Instanz
läuft auf welcher Version? Welche Erweiterung ist veraltet? Und vor allem — bei
welchem dieser Updates geht es nicht um neue Funktionen, sondern um eine
bekannte Sicherheitslücke?

Diese Fragen einzeln pro Projekt zu beantworten, kostet jedes Mal Zeit. Genau
das nimmt TypoVigil ab: Jede überwachte Seite meldet ihren Stand an eine
zentrale Stelle, die den Bestand automatisch mit den offiziellen
Sicherheitsmeldungen von TYPO3 und Packagist abgleicht. Auf einen Blick sichtbar
wird dadurch, wo Handlungsbedarf besteht und wie dringend er ist.

## Wie es aufgebaut ist

Das System besteht aus zwei Teilen, die klar getrennt sind.

Auf jeder überwachten Seite läuft eine kleine Erweiterung, der **Agent**. Er
sammelt die installierte TYPO3-Version und alle aktiven Erweiterungen und
schickt diese Liste an die Zentrale. Mehr tut er nicht — er liest nur, greift
nirgends ein und öffnet selbst keinen Zugang von außen.

Die **Zentrale** ist eine eigene TYPO3-Installation. Sie nimmt die Meldungen
entgegen, vergleicht die gemeldeten Versionen mit den offiziellen Quellen und
stellt das Ergebnis dar.

Wichtig ist die Richtung: Die überwachten Seiten melden sich von sich aus bei
der Zentrale. Für das Monitoring greift die Zentrale nie auf sie zu — keine
überwachte Installation braucht dafür eine von außen erreichbare
Schnittstelle.

Zwei Ausnahmen gibt es, beide nur auf ausdrückliche Anforderung und nur für
Projekte, bei denen das eingerichtet wurde: Für ein Backup verbindet sich die
Zentrale zur Datenbank des Projekts, und für ein Update startet sie einen
Workflow in dessen Repository. Die laufende Installation selbst wird auch
dabei nicht angefasst.

### Warum TYPO3 als Grundlage

Die Zentrale hätte auch eine eigenständige Anwendung sein können. TYPO3 bringt
aber alles schon mit, was ein solches Werkzeug braucht: Benutzerverwaltung,
Anmeldung, Rechtevergabe, Schutz vor gängigen Angriffen und eine fertige
Oberfläche zum Pflegen von Datensätzen. Auf dieser Basis blieb nur die
eigentliche Fachlogik zu bauen — nichts von der Sicherheitsinfrastruktur musste
selbst entwickelt werden.

## Zwei Sichten, bewusst unterschiedlich

Im **Backend** sieht die Agentur die vollständige Liste: jede Erweiterung mit
installierter und verfügbarer Version, sortiert nach Dringlichkeit.

Im **Frontend** können sich Kunden anmelden und den Zustand ihrer eigenen Seite
einsehen: als Übersicht mit Ampel je Installation und in der Detailansicht mit
allen Komponenten als eingefärbte Kennzeichen samt Versionsnummer.

Dass dort die vollständige Liste steht, ist eine bewusste Entscheidung des
Betreibers und nicht selbstverständlich: Sie nennt die genaue Version jeder
Komponente, auch der verwundbaren. Wer das nicht möchte, blendet die Liste im
Kundenbereich aus und belässt es bei den Kennzahlen.

Jeder Kunde sieht ausschließlich die ihm zugeordneten Projekte. Diese Prüfung
findet im Programmcode selbst statt, nicht nur über den Seitenschutz — sonst
ließe sich durch Ändern der Adresszeile ein fremdes Projekt aufrufen.

## Einstufung

- **kritisch** — für die installierte Version ist eine Sicherheitslücke bekannt,
  oder beim TYPO3-Kern liegt ein neueres Sicherheitsrelease vor
- **veraltet** — eine neuere Version ist verfügbar, ohne bekannte Lücke
- **aktuell** — nichts zu tun

Wenn sich eine Seite längere Zeit nicht meldet, wird das eigens angezeigt. Ein
ausgefallener Agent darf nicht wie „alles in Ordnung" aussehen.

## Datenquellen

- get.typo3.org — Kernversionen samt Kennzeichnung als Sicherheitsrelease
- Packagist — neueste Version je Paket
- Packagist Security Advisories — bekannte Sicherheitslücken
- TYPO3 Extension Repository — Rückfallebene für Erweiterungen ohne
  Composer-Paket

## Stack

- TYPO3 v14.3
- PHP 8.4
- MariaDB 11.8
- Apache
- Symfony Messenger (Doctrine-Transport, eigene Warteschlangentabelle) für den
  Backup-Worker

## Eigene Erweiterungen

- typovigil — Datenmodell, Schnittstelle, Abgleich, Backend-Modul
- typovigil_sitepackage — Frontend für den Kundenzugang
- typovigil_agent — läuft auf den überwachten Seiten

## Lokale Entwicklung

- DDEV

```bash
ddev start
ddev exec vendor/bin/typo3 extension:setup
```

Prüfroutinen:

```bash
ddev exec php packages/typovigil/Tests/SeverityResolverTest.php
ddev exec php packages/typovigil/Tests/BackupBeforeUpdateServiceTest.php
ddev exec php packages/typovigil/Tests/access-separation-check.php
ddev exec php packages/typovigil/Tests/config-check.php
ddev exec php packages/typovigil/Tests/autoload-check.php
```

Die ersten beiden decken die heiklen Stellen ab: die Einstufung der
Dringlichkeit und die Trennung der Kundenzugänge.

## Projekt einrichten

In der Zentrale:

1. Im Backend einen Datensatz *Überwachtes Projekt* anlegen
2. Beim Speichern erscheint einmalig ein Zugangsschlüssel. Gespeichert wird nur
   dessen Prüfsumme — später lässt er sich nicht erneut anzeigen
3. Im Reiter *Zugriff* festlegen, welche Frontend-Benutzer das Projekt sehen
   dürfen

Auf der zu überwachenden Seite den Agent installieren und in
`config/system/settings.php` eintragen:

```php
'typovigil_agent' => [
    'hubUrl' => 'https://zentrale.example.org',
    'token' => '<der kopierte Schlüssel>',
],
```

Danach dort im Scheduler die Aufgabe *TypoVigil: send report* anlegen, täglich.

Anschließend in der Zentrale den Abgleich als Scheduler-Aufgabe einrichten.
Zusätzlich meldet sich der Agent von selbst, sobald eine Erweiterung installiert
oder entfernt wird.

Eine reine Versionsänderung eines bereits installierten Pakets — genau das, was
ein von TypoVigil beauftragtes Update ist — löst dagegen keines dieser
Ereignisse aus. Ohne weiteres Zutun bliebe der neue Stand bis zum nächsten
täglichen Bericht unsichtbar, im schlechtesten Fall fast 24 Stunden. Deshalb
gehört in den Docker-Entrypoint jeder überwachten Seite (ab `typovigil-agent`
v1.0.3), direkt nach `extension:setup`, dieser zusätzliche Aufruf:

```bash
php vendor/bin/typo3 typovigil-agent:report || true
```

`|| true` ist Pflicht: ein Backend, das gerade erst hochfährt oder noch keine
Netzwerkverbindung hat, darf den Deploy nicht zum Scheitern bringen. Ohne
diesen Eintrag funktioniert das Monitoring weiterhin — nur eben verzögert bis
zum nächsten Scheduler-Lauf.

Für einen neuen Kunden kommt ein Frontend-Benutzer im Ordner *Kunden* hinzu, der
über Schritt 3 seinen Projekten zugeordnet wird. Wer alle Projekte sehen soll,
kommt stattdessen in die Agentur-Gruppe; deren Nummer trägt man einmalig in den
Einstellungen der Erweiterung ein.

Damit läuft das Monitoring. Soll das Projekt darüber hinaus auch gesichert und
aktualisiert werden können, kommen die Reiter *Datenbank* und *Repository*
hinzu — siehe nächster Abschnitt.

## Updates: Freigabe, Backup, Pull Request

Ein Update anzustoßen ist an drei Bedingungen geknüpft, die nacheinander
erfüllt sein müssen. Der Knopf *Update beauftragen* bleibt gesperrt, solange
eine davon offen ist, und nennt jeweils, welche.

**1. Die KI-Berichte sind freigegeben.** Zu jedem kritischen Fund schreibt
TypoVigil eine Risikoeinschätzung. Solange eine davon nicht gelesen und
freigegeben wurde, geht es nicht weiter — die Freigabe ist die Stelle, an der
ein Mensch die Einschätzung bestätigt.

**2. Ein Backup wurde gemacht.** Nicht geplant, nicht geprüft: gemacht, auf
Knopfdruck. TypoVigil erstellt den Datenbank-Dump selbst und stößt zusätzlich
das Backup des Storage-Volumes auf der Hosting-Plattform an, falls dort eines
verknüpft ist. Erst wenn beides erfolgreich war, zählt es.

Der Knopf löst das nicht mehr selbst aus, sondern reiht den Auftrag über
Symfony Messenger ein und kehrt sofort zurück — ein Dump kann Minuten dauern,
in denen vorher der Tab eingefroren war. Die Projektansicht zeigt währenddessen
den Fortschritt (`Backup in Warteschlange…` → `Datenbank wird gesichert…` →
ggf. `Storage-Backup wird angestoßen…`) und pollt dafür alle zwei Sekunden
`backupStatus`, bis der Lauf abgeschlossen ist.

Das Backup gilt für den Paketstand, gegen den es gemacht wurde. Meldet der
Agent danach andere Versionen, ist es verbraucht und muss erneut angestoßen
werden — ein Backup, das den zu aktualisierenden Zustand nicht enthält, nützt
beim Zurückrollen nichts.

**3. Dann erst das Update.** TypoVigil startet einen Workflow im Repository
des Projekts, der `composer update` ausführt, einen Pull Request mit der
geänderten `composer.lock` öffnet und ihn sofort selbst mergt — eine zweite
Freigabe für den Merge gibt es nicht, der Klick auf *Update beauftragen* war
sie bereits. Der Merge löst den Deploy-Workflow des Projekts aus; erst dabei
ändert sich die laufende Installation.

Das ist der entscheidende Unterschied zu einem Update direkt auf dem Server:
Git bleibt die maßgebliche Quelle. Eine lokale Arbeitskopie holt denselben
Stand mit `git pull`, und beim nächsten Deploy wird nichts überschrieben.

### Einrichten

Am Projekt, Reiter *Datenbank*: Host, Port, Name, Benutzer und Passwort der
überwachten Installation. Das Passwort wird verschlüsselt gespeichert
(AES-256-GCM, Schlüssel ist `TYPO3_ENCRYPTION_KEY`) und ist nötig, weil
TypoVigil den Dump selbst erstellt — die Hosting-Plattform kann
Datenbank-Backups nur planen, nicht sofort auslösen.

Reiter *Hosting-Plattform*, optional: Application-UUID und Volume-UUID, wenn
zusätzlich das Dateiverzeichnis gesichert werden soll. Die Volume-UUID stammt
aus der API (`GET /applications/{uuid}/storages`), nicht aus dem in der
Oberfläche angezeigten Namen. Ohne diese Angaben wird nur die Datenbank
gesichert — was ein `composer update` ohnehin allein betrifft.

Reiter *Repository*: `owner/repo` des Projekts. In diesem Repository muss
`.github/workflows/typovigil-update.yml` liegen (Vorlage unter
`Documentation/examples/`), und unter Settings → Actions → General muss
*Allow GitHub Actions to create and approve pull requests* aktiv sein. Die
Vorlage merged den Pull Request selbst, ohne zweite Freigabe — ändert sich
dort etwas, muss die Kopie in jedem bereits eingebundenen Projekt-Repository
manuell nachgezogen werden, TypoVigil aktualisiert sie nicht automatisch.

Auf der Zentrale als Umgebungsvariablen: `TYPOVIGIL_GITHUB_TOKEN` (Fine-grained
PAT mit *Actions: Read and write*), für das Storage-Backup zusätzlich
`TYPOVIGIL_COOLIFY_API_URL` und `TYPOVIGIL_COOLIFY_API_TOKEN`.

Alle Zugangsdaten gehören in die Umgebung, nicht in die
Extension-Konfiguration: `config/system` wird bei jedem Container-Deploy neu
aus dem Image erzeugt, dort eingetragene Werte sind danach weg.

### Wohin die Dumps gehen

Standardmäßig nach `var/backups` im Container — und damit beim nächsten Deploy
verloren. Für den ernsthaften Betrieb gehört dort ein eingebundenes Volume hin;
der Pfad ist über `backupDirectory` in der Extension-Konfiguration einstellbar.

### Auf der Kommandozeile

```bash
php vendor/bin/typo3 typovigil:backup <projekt-uid>
```

Läuft synchron und direkt, ohne die Warteschlange — für den Knopf im Backend
gilt das Folgende.

Backups laufen ausschließlich auf Anforderung. Der stündliche Abgleich
(`typovigil:check-and-analyze`) prüft Versionen und schreibt KI-Berichte — er
sichert nichts.

### Der Backup-Worker

Der Knopf *Jetzt sichern* schreibt nur einen Auftrag in die Warteschlange
(eigene Tabelle `tx_typovigil_backup_queue`, ein Symfony-Messenger-Transport).
Abgearbeitet wird er von einem eigenen Hintergrundprozess:

```bash
php vendor/bin/typo3 messenger:consume backup
```

Im Container-Deploy läuft das bereits automatisch — `docker-entrypoint.sh`
startet neben Apache eine Endlosschleife, die diesen Befehl mit
`--time-limit=55` neu startet, sobald er (planmäßig, als Schutz vor
Speicherlecks in einem lange laufenden PHP-Prozess) beendet wurde. Logs stehen
in `/var/log/typovigil-backup-worker.log`.

**Wichtig für andere Umgebungen:** Ohne laufenden Worker bleibt ein
angestoßenes Backup dauerhaft auf `Backup in Warteschlange…` stehen — es
passiert einfach nichts. Wer TypoVigil nicht über dieses Docker-Image betreibt
(klassisches Hosting, eigener Server ohne den Entrypoint), muss den Worker
selbst dauerhaft am Laufen halten, z. B. als systemd-Service oder Supervisor,
der bei Absturz automatisch neu startet. Ein Scheduler-Task, der den Befehl nur
minütlich kurz anstößt, geht ebenfalls, verzögert dann aber jedes Backup um bis
zu eine Minute.

Der Status des letzten Backups steht danach in der Projektansicht, samt
Hinweis, ob er noch zum aktuellen Paketstand passt.

## Deploying

- Github Actions

## Liveserver

- Ubuntu 24.04.5 LTS
- Docker
