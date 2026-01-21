# LEB Tool – Digitale Lernentwicklungsberichte

Das **LEB Tool** ist eine webbasierte Anwendung zur **strukturierten, datenschutzkonformen Erstellung von Lernentwicklungsberichten (LEB)** im Grundschulkontext.

Es deckt den **gesamten Workflow** ab – von der Datenerfassung über Zusammenarbeit mehrerer Lehrkräfte bis hin zu PDF-Exporten, KI-Unterstützung und revisionssicherer Nachvollziehbarkeit.

Das Tool ist explizit für **klassisches Shared-Webhosting ohne Shell-Zugriff** (z. B. Strato) konzipiert.

---

## Zielsetzung

- Einheitliche und nachvollziehbare Lernentwicklungsberichte
- Reduktion von Copy-&-Paste und manuellen Fehlern
- Klare Rollen- und Rechteverteilung
- Zusammenarbeit mehrerer Lehrkräfte (Delegationen)
- Transparenz durch Audit-Logging
- DSGVO-konforme Datenhaltung
- Hohe Anpassbarkeit an schulinterne Vorgaben

---

## Rollen & Funktionsumfang

### 🛠 Administrator (`/admin`)

Der Administrator hat **vollständigen Systemzugriff**.

**Funktionen:**
- Verwaltung von Lehrkräften
- Klassenverwaltung (aktiv / archiviert)
- Schülerverwaltung
- Zuordnung von Schülern zu Klassen
- Verwaltung von **Templates (Berichtsvorlagen)**
- Verwaltung von **Template-Feldern**
  - Feldtypen (Text, Option, Datum, Systembindung usw.)
  - Optionslisten & Optionslisten-Vorlagen
  - Gruppen & Filterbarkeit
- Globale Einstellungen & Feature-Flags
- **Audit-Log**:
  - Filter (User, Event, Zeitraum)
  - Pagination & Sortierung
  - Strukturierte JSON-Details
  - Auflösung technischer IDs in lesbare Namen
  - IP-Adresse optional einblendbar
- Vollständiges Löschen personenbezogener Daten (DSGVO)

**Besonderheiten:**
- Admin kann **alle Klassen und Delegationen** sehen und ändern
- Admin-Aktionen werden vollständig im Audit-Log erfasst

---

### 👩‍🏫 Lehrkräfte (`/teacher`)

Lehrkräfte arbeiten **klassenbezogen**.

**Funktionen:**
- Übersicht über eigene Klassen
- Schülerdaten verwalten (innerhalb der Klasse)
- Erfassung von Lernentwicklungsdaten:
  - strukturierte Felder
  - Optionsfelder
  - Freitexte
- Live-Vorschau der Berichte
- PDF-Export:
  - einzelner Schüler
  - Klassenexport (konfigurationsabhängig)
- **Delegationen**:
  - Fachbereiche an andere Lehrkräfte delegieren
  - Status einsehen (offen / in Bearbeitung / abgeschlossen)
  - Delegationen ändern oder zurücknehmen
- Filter & Suche innerhalb von Klassen
- Fortschrittsanzeigen (fehlende Felder, Vollständigkeit)

**Besonderheiten:**
- Lehrkräfte sehen nur **eigene Klassen und delegierte Inhalte**
- Delegierte Inhalte sind klar von eigenen Klassen getrennt
- Keine Änderung von System-Templates möglich

---

### 🧒 Schüler (`/student`)

Der Schülerbereich ist **passwortlos** und **stark reduziert**.

**Funktionen:**
- Login per **QR-Code**
- Selbsteinschätzung ausfüllen
- Nur explizit freigegebene Felder sichtbar
- Automatisches Speichern
- Kein Zugriff auf fremde Daten

**Technik:**
- Tokenbasierter Login
- Kein Benutzername / Passwort
- Ideal für Tablets im Klassenzimmer

---

### 👨‍👩‍👧 Eltern (`/parent`)

Der Parent-Bereich ist **optional** und klar vom System getrennt.

**Aktueller Stand:**
- Eltern-Feedback-Formular
- CSRF-geschützt
- Eigenes Routing
- Keine Einsicht in Verwaltungs- oder Schülerdaten

**In Arbeit:**
- Eigene Elternansicht der Berichte
- separates **Unterschriftenfeld mit Lehrkraftname (nur Elternansicht)**

---

## Grafische Lehrkraft-Unterschrift (Parent-Export)

Optional kann beim Beantragen der Elternzugänge eine handschriftliche Signatur erfasst werden. Diese wird **als Vektordaten verschlüsselt** gespeichert und beim Eltern-PDF über dem Unterschriften-Textfeld platziert.

**Einrichtung:**
- Migration ausführen: `migrations/2025_09_15_teacher_signatures.sql`
- Master-Key setzen (32 Bytes):
  - per ENV: `SIGNATURE_MASTER_KEY` (Hex mit 64 Zeichen **oder** Base64 mit 32 Byte Nutzlast)
  - oder in `config.php`:
    ```php
    'signature' => [
      'master_key' => '...',
    ],
    ```
- Feature-Flag (optional) in `config.php`:
  ```php
  'parent' => [
    'signature_enabled' => true,
  ],
  ```

**Deaktivieren:**
- `signature_enabled` auf `false` setzen. Der Eltern-PDF-Export bleibt dann unverändert.

---

## PDF- & Export-System

- Unterstützung ausfüllbarer PDF-Formulare (AcroForms)
- Platzhalter-System, z. B.:
{{student.firstname}}
{{student.lastname}}
{{class.label}}
- Systemfelder:
- formatierbar (z. B. Datum)
- mehrfach verwendbar
- Einheitliche Export-API (`/shared/export_*`)
- Rollenabhängige Zugriffskontrolle:
- Lehrkräfte: nur eigene Klassen
- Admin: alle Daten

---

## KI-Unterstützung (bereits implementiert, optional)

Das LEB Tool enthält eine **optionale KI-Unterstützung zur Texterstellung**.

**Funktionen:**
- Generierung von Textvorschlägen für Lernentwicklungsberichte
- Kontextsensitiv (Schülerdaten, Feldkontext, vorhandene Inhalte)
- Ergebnisse werden **nicht automatisch gespeichert**
- Lehrkräfte entscheiden aktiv über Übernahme

**Technik:**
- Serverseitige API-Anbindung
- Aktivierung über Konfiguration / Feature-Flag
- KI-Buttons erscheinen nur bei aktiver Konfiguration

---

## Fortschritts- & Vollständigkeitslogik

Das System berechnet automatisch den Bearbeitungsstand:

- fehlende Pflichtfelder
- vollständig ausgefüllte Berichte
- Fortschritt pro Schüler und Klasse

**Berücksichtigung:**
- Schülerfelder
- Lehrerfelder
- systemgebundene Felder werden korrekt ignoriert

Diese Logik wird genutzt für:
- Klassenübersichten
- Lehrer-UI
- Exporte

---

## Delegationen & Zusammenarbeit

Delegationen ermöglichen die Zusammenarbeit mehrerer Lehrkräfte an einer Klasse.

**Features:**
- Delegation pro **Klasse × Fachbereich**
- Status-Tracking
- Delegationen änder- und widerrufbar
- Anzeige delegierter *und* delegierender Klassen
- Admin kann jederzeit eingreifen

---

## Audit-Log (Nachvollziehbarkeit)

Alle relevanten Änderungen werden revisionssicher protokolliert.

**Erfasst werden:**
- Benutzer
- Aktion / Event
- Zeitstempel
- betroffene Entität
- strukturierte JSON-Details

**Funktionen:**
- Filter & Suche
- Pagination
- Sortierung
- Auflösung technischer IDs
- IP-Adresse optional einblendbar

---

## Mehrsprachigkeit (teilweise implementiert)

- Mehrsprachige Feldbezeichnungen
- UI-Übersetzungsfunktionen
- Sprachumschaltung ohne vollständigen Reload
- Fallback-Logik

---

## Aktuelle Entwicklung / Offene Themen (Issues)

Die folgenden Punkte befinden sich **aktuell aktiv in Arbeit** und sind im GitHub-Issue-Tracker dokumentiert:

- **Unterschriftenfeld mit Lehrkraftname nur für Elternansicht**
- **Übersicht über alle Berichte eines Schülers** (über alle Schuljahre, nur lesend)
- **Template-Testlauf** (Berichte ohne produktive Speicherung testen)
- **Schuljahres-Wechsel-Assistent**
- **Dashboard mit Gesamtbearbeitungsstand**:
- fertige Schülereingaben
- fertige Lehrereingaben
- geschätzte Restbearbeitungszeit
- neue Rückmeldungen aus Delegationen
- **Warnhinweis**, wenn Lehrkräfte Daten eingeben möchten, obwohl
- noch Schülerfelder fehlen
- diese übersichtlich aufgelistet werden
- **KI-Förderempfehlungs-Generator** (Ziel- und Förderungsvorschläge)
- **Verbesserte Tastaturnavigation** für Lehrkräfte bei der Dateneingabe
- **Sicherheitsüberprüfung und Schließen potenzieller Sicherheitslücken**

Diese Liste bildet den **tatsächlichen aktuellen Entwicklungsstand** ab und ersetzt eine abstrakte Roadmap.

---

## Datenschutz & Sicherheit

- Rollenbasierte Zugriffskontrolle
- CSRF-Schutz für alle schreibenden Aktionen
- QR-Token statt Passwörter (Schüler)
- Trennung von Stammdaten und Berichtsdaten
- Audit-Log für Nachvollziehbarkeit
- DSGVO-konforme Löschfunktionen

**Empfehlungen:**
- HTTPS erzwingen
- `install.php` nach Installation löschen
- Regelmäßige Backups

---

## Technik

**Backend**
- PHP (strict types)
- PDO (MySQL / MariaDB)
- Modularer Aufbau (`/shared`)

**Frontend**
- Server-rendered HTML
- JavaScript für Komfortfunktionen
- Keine Framework-Abhängigkeit

---

## Projektstruktur (vereinfacht)

/admin Administration & Systemverwaltung
/teacher Lehrkräftebereich
/student Schülerbereich (QR-Login)
/parent Elternbereich
/shared Gemeinsame Logik (Export, Helper, APIs)
/assets CSS / JS / Icons
/bootstrap.php
/config.sample.php
/install.php

---

## Installation

1. Dateien auf den Webserver kopieren
2. `install.php` im Browser aufrufen
3. Datenbank konfigurieren
4. Admin-Account anlegen
5. Installation abschließen
6. `install.php` löschen oder umbenennen

Ausgelegt für klassisches Shared-Hosting (z. B. Strato).

---

## Lizenz

Derzeit schulintern / privat genutzt.  
Eine formale Lizenz kann bei Bedarf ergänzt werden.
