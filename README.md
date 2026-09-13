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
einsehen — dort allerdings nur als Ampel mit Anzahl der anstehenden Updates,
nicht als vollständige Paketliste. Der Grund ist bewusst gewählt: Eine
detaillierte Aufstellung verwundbarer Versionen wäre eine fertige Anleitung für
einen Angriff, falls ein Kundenzugang einmal in falsche Hände gerät.

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

1. Im Backend einen Datensatz *Überwachtes Projekt* anlegen
2. Beim Speichern erscheint einmalig ein Zugangsschlüssel. Gespeichert wird nur
   dessen Prüfsumme — später lässt er sich nicht erneut anzeigen
3. Diesen Schlüssel zusammen mit der Adresse der Zentrale in der Konfiguration
   des Agents auf der zu überwachenden Seite eintragen
4. Im Reiter *Zugriff* festlegen, welche Frontend-Benutzer das Projekt sehen
   dürfen

Anschließend im Scheduler beider Systeme je eine Aufgabe anlegen: auf der
überwachten Seite das Melden, in der Zentrale den Abgleich. Zusätzlich meldet
sich der Agent von selbst, sobald eine Erweiterung installiert oder entfernt
wird.

## Deploying

- Github Actions

## Liveserver

- Ubuntu 24.04.5 LTS
- Docker
