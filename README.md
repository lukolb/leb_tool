# LEB Tool – Lernentwicklungsberichte digital erstellen

Das **LEB Tool** ist eine webbasierte Anwendung zur **strukturierten, datenschutzkonformen Erstellung von Lernentwicklungsberichten (LEB)** für die Grundschule.  
Es richtet sich an **Lehrkräfte, Schüler:innen und Administratoren** und vereinfacht den gesamten Prozess von der Datenerfassung bis zum ausgefüllten PDF.

---

## ✨ Ziel des Projekts

Ziel des LEB Tools ist es,

- Lernentwicklungsberichte **einheitlich, zeitsparend und fehlerfrei** zu erstellen
- die **Schülerbeteiligung** (Selbsteinschätzung) sinnvoll einzubinden
- **PDF-Formulare automatisiert** und reproduzierbar zu befüllen
- den administrativen Aufwand für Schulen deutlich zu reduzieren

Das Tool wurde speziell für den **Grundschulkontext** entwickelt (Klassen, Fächer, Kompetenzraster, Textbausteine).

---

## 🧩 Zentrale Funktionen

### 👩‍🏫 Lehrkräfte
- Klassen anlegen und verwalten
- Schüler:innen Klassen zuordnen
- Lernstands- und Kompetenzdaten erfassen
- Textbausteine und Freitexte kombinieren
- Vorschau der Berichte direkt im Browser
- Automatische Befüllung von PDF-Vorlagen

### 👧 Schüler:innen
- Login per **QR-Code** (ohne Passwort)
- Ausfüllen von Selbsteinschätzungen
- Kindgerechte, reduzierte Oberfläche
- Kein Zugriff auf fremde Daten

### 🛠️ Administration
- Verwaltung von:
  - Klassen
  - Schüler:innen
  - Lehrkräften
  - Templates (PDF-Formulare)
- Mapping von Stammdaten & Formularfeldern
- Platzhalter-System für flexible Textfelder
- Ein Platzhalter kann mehrere PDF-Felder befüllen
- Filter- und sortierbare Übersichten
- Vollständiges Löschen von Schülerdaten (DSGVO)

---

## 📄 PDF-Template-System

- Unterstützung von **ausfüllbaren PDF-Formularen**
- Feld-Mapping über Platzhalter (z. B. `{{VORNAME}}`, `{{NACHNAME}}`, `{{KLASSE}}`)
- Freie Kombination von Text + Platzhaltern
- Ein Platzhalter → mehrere Formularfelder möglich
- Live-Vorschau mit hervorgehobenen PDF-Feldern

---

## 🔐 Datenschutz & Sicherheit

- Rollenbasiertes Zugriffssystem (Admin / Lehrkraft / Schüler)
- CSRF-Schutz für alle schreibenden Aktionen
- QR-Token statt Klartext-Passwörter für Schüler
- Möglichkeit zur **vollständigen Datenlöschung**
- Trennung von Stammdaten und Berichtsinhalt

---

## 🧱 Technischer Aufbau

### Backend
- PHP (strict types)
- PDO (MySQL / MariaDB)
- Serverseitige PDF-Verarbeitung

### Frontend
- Server-rendered HTML
- JavaScript (AJAX für Admin- & Vorschau-Funktionen)
- Fokus auf einfache, robuste Bedienung

### Projektstruktur (Auszug)
/admin
/ajax
/templates
/student
/teacher
/templates
/bootstrap.php
/install.php


---

## 🚀 Installation

1. Repository auf den Webserver kopieren
2. Browser öffnen und `install.php` aufrufen
3. Datenbankzugang eintragen
4. Admin-Account anlegen
5. Installation abschließen

Nach erfolgreicher Installation kann `install.php` aus Sicherheitsgründen gelöscht oder umbenannt werden.

> Getestet auf klassischem Webhosting (z. B. Strato, ohne Shell-Zugriff)

### KI-Vorschläge aktivieren

- In `config.php` den Abschnitt `ai` ergänzen und einen API-Schlüssel hinterlegen (z. B. für OpenAI/ChatGPT). Alternativ kann die Umgebungsvariable `OPENAI_API_KEY` genutzt werden.
- Optional Modell/Base-URL/Timeout anpassen, falls ein kompatibler Endpoint genutzt wird.
- Der KI-Button erscheint nur, wenn die Funktion aktiviert ist und ein Schlüssel hinterlegt wurde (sonst ausgeblendet).
- Admins können die KI-Funktion samt API-Key bei der Installation oder später unter „Einstellungen“ ein- bzw. ausschalten.

---

## 🎒 Schüler-Login

Schüler:innen loggen sich **ohne Benutzername oder Passwort** ein.

**Ablauf:**
1. Lehrkraft oder Admin erstellt für eine Klasse die Schüler-QR-Codes
2. Jeder QR-Code enthält einen individuellen Login-Token
3. Der QR-Code wird mit einem Tablet oder Smartphone gescannt
4. Der Link führt direkt zur Schüleroberfläche (`/student/login.php`)
5. Nach dem Scan ist der/die Schüler:in automatisch eingeloggt

Der Login ist:
- zeitlich unbegrenzt gültig (konfigurierbar)
- an einen einzelnen Schüler gebunden
- nicht erratbar (Token-basiert)

---

## 🧠 Pädagogisches Konzept

- Klare Kompetenzbereiche statt Notenfokus
- Trennung von Beobachtung und Bewertung
- Transparenz für Schüler:innen
- Wiederverwendbarkeit von Textbausteinen
- Anpassbar an schulinterne LEB-Vorgaben

---

## 🛣️ Roadmap (Ausblick)

- Mehrsprachige Lernentwicklungsberichte
- Export kompletter Klassen
- Versionshistorie von Berichten
- Zusammenarbeit mehrerer Lehrkräfte pro Klasse
- Optionale Kommentarfunktion
- Automatische KI-Textvorschläge für Ziele u.Ä., basierend auf Skalenwerten und vorherigen Feldern; Lehrkräfte können Vorschläge übernehmen, anpassen oder löschen (manuelle Kontrolle, Zeitersparnis)

---

## 📜 Lizenz

Dieses Projekt wird aktuell **schulintern / privat** entwickelt.  
Eine Open-Source-Lizenz kann bei Bedarf ergänzt werden.

---

## 🙌 Motivation

Das LEB Tool ist aus der **praktischen Arbeit im Schulalltag** entstanden –  
mit dem Ziel, Lehrkräften Zeit zu sparen und gleichzeitig qualitativ hochwertige, individuelle Lernentwicklungsberichte zu ermöglichen.
