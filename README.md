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
der Zentrale. Die Zentrale greift umgekehrt nie auf sie zu. Dadurch braucht
keine der überwachten Installationen eine von außen erreichbare Schnittstelle.

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

Für einen neuen Kunden kommt ein Frontend-Benutzer im Ordner *Kunden* hinzu, der
über Schritt 3 seinen Projekten zugeordnet wird. Wer alle Projekte sehen soll,
kommt stattdessen in die Agentur-Gruppe; deren Nummer trägt man einmalig in den
Einstellungen der Erweiterung ein.

## Deploying

- Github Actions

## Liveserver

- Ubuntu 24.04.5 LTS
- Docker
