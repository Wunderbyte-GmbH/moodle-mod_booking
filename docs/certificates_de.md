# Zertifikatserstellung in mod_booking

Diese Dokumentation beschreibt, wie Teilnahmebestätigungen und Zertifikate in der Moodle-Buchungsaktivität (`mod_booking`) erstellt, konfiguriert und heruntergeladen werden können.

> **Kundenanforderung:** Es soll möglich sein, dass nach bestimmten Vorlagen Teilnahmebestätigungen bzw. Zertifikate erstellt werden, damit Nutzer\*innen sich diese herunterladen können.

---

## Inhaltsverzeichnis

1. [Voraussetzungen](#1-voraussetzungen)
2. [Globale Einstellungen aktivieren](#2-globale-einstellungen-aktivieren)
3. [PDF-Vorlage in tool_certificate erstellen](#3-pdf-vorlage-in-tool_certificate-erstellen)
4. [Verfügbare Platzhalter (Datenfelder) im Zertifikat](#4-verfügbare-platzhalter-datenfelder-im-zertifikat)
5. [Variante A – Einfache Zertifikatsoption (je Buchungsoption)](#5-variante-a--einfache-zertifikatsoption-je-buchungsoption)
6. [Variante B – Zertifikatsbedingungen (erweitert, PRO)](#6-variante-b--zertifikatsbedingungen-erweitert-pro)
7. [Trigger: Wann wird ein Zertifikat ausgestellt?](#7-trigger-wann-wird-ein-zertifikat-ausgestellt)
8. [Herunterladen und Versenden von Zertifikaten](#8-herunterladen-und-versenden-von-zertifikaten)
9. [Ablaufdatum für Zertifikate](#9-ablaufdatum-für-zertifikate)
10. [Manuelles Auslösen](#10-manuelles-auslösen)
11. [Häufige Fragen (FAQ)](#11-häufige-fragen-faq)

---

## 1. Voraussetzungen

| Anforderung | Details |
|---|---|
| **Moodle-Plugin** | `mod_booking` (Wunderbyte GmbH) |
| **Zertifikats-Plugin** | `tool_certificate` muss installiert sein |
| **Lizenz** | Booking **PRO**-Lizenz erforderlich |

Ohne `tool_certificate` und eine aktive PRO-Lizenz erscheinen die Zertifikats-Felder in der Buchungsoption nicht.

---

## 2. Globale Einstellungen aktivieren

Die Zertifikatsfunktion muss zuerst systemweit aktiviert werden:

1. Navigieren Sie zu **Website-Administration → Plugins → Aktivitätsmodule → Buchung → Einstellungen**.
2. Suchen Sie den Abschnitt **Moodle Zertifikat**.
3. Aktivieren Sie die Einstellung **„Zertifikatserstellung aktivieren"** (`certificateon`).

Weitere globale Optionen in diesem Abschnitt:

| Einstellung | Beschreibung |
|---|---|
| **Zertifikatsoptionen** (`certificateoptions`) | Wählt den Modus: *Einfache Zertifikatsoption* (je Buchungsoption) oder *Zertifikatsbedingungen* (regelbasiert, PRO). |
| **Zertifikate nur manuell auslösen** (`certificatemanualtrigger`) | Wenn aktiv, werden Zertifikate **nicht automatisch** erstellt – nur über die Schaltfläche „Zertifikat(e) generieren". |
| **Mehrere Zertifikate ausstellen** (`issuemultiplecertificates`) | Erlaubt das mehrfache Ausstellen desselben Zertifikats bei wiederholter Erfüllung einer Bedingung (nur im Modus *Zertifikatsbedingungen*). |
| **Zertifikatsausstellung mit Anwesenheitsstatus** (`presencestatustoissuecertificate`) | Nur im Modus *Einfache Zertifikatsoption*: Ein Zertifikat wird erst ausgestellt, wenn ein bestimmter Anwesenheitsstatus gesetzt wird (z. B. „Teilgenommen"). |

---

## 3. PDF-Vorlage in tool_certificate erstellen

`mod_booking` nutzt das Plugin `tool_certificate` für die eigentliche PDF-Erstellung.

1. Navigieren Sie zu **Website-Administration → Berichte → Zertifikatsvorlagen** (oder über den direkten Link in Ihrer Moodle-Installation).
2. Klicken Sie auf **„Neue Vorlage erstellen"**.
3. Fügen Sie Elemente hinzu (Texte, Bilder, Logo, Unterschrift etc.) und verwenden Sie dabei die unten beschriebenen Platzhalter.
4. Speichern Sie die Vorlage. Der **Name der Vorlage** erscheint danach in der Buchungsoptions-Konfiguration zur Auswahl.

> **Tipp:** Die Platzhalter werden beim Ausstellen des Zertifikats automatisch durch die tatsächlichen Werte der Buchungsoption ersetzt.

---

## 4. Verfügbare Platzhalter (Datenfelder) im Zertifikat

Die folgenden Felder können in einer `tool_certificate`-Vorlage als Platzhalter eingesetzt werden. Die genaue Syntax hängt von der Version von `tool_certificate` ab (üblicherweise `{bookingoptionid}` oder als *custom data*-Felder).

### 4.1 Standardfelder der Buchungsoption

| Platzhalter / Feldname | Inhalt |
|---|---|
| `bookingoptionid` | Interne ID der Buchungsoption |
| `bookingoptionname` | Titel der Buchungsoption (inkl. Präfix) |
| `bookingoptiondescription` | Beschreibung der Buchungsoption (ohne HTML-Tags) |
| `location` | Veranstaltungsort |
| `institution` | Institution / Veranstalter |
| `teachers` | Namen aller zugeordneten Trainer\*innen, zeilenweise |
| `sessions` | Formatierte Liste aller Termindaten (Start/Ende) |
| `duration` | Gesamtdauer der Buchungsoption (z. B. „8 Stunde(n) 30 Minuten") |
| `timeawarded` | Datum, an dem das Zertifikat verliehen wurde |
| `competencies` | Kurznamen der zugeordneten Moodle-Kompetenzen (kommagetrennt) |

### 4.2 Felder aus Zertifikatsbedingungen

| Platzhalter / Feldname | Inhalt |
|---|---|
| `conditionid` | Interne ID der ausgelösten Zertifikatsbedingung |
| `conditionname` | Name der ausgelösten Zertifikatsbedingung |

### 4.3 Benutzerdefinierte Felder (Custom Fields)

Alle **benutzerdefinierten Felder** der Buchungsoption (Typ `text`, `textarea` oder `textformat`) werden ebenfalls übergeben. Der Feldname im Zertifikat lautet:

```
cf<shortname>
```

Beispiel: Ein Custom Field mit dem Kurzname `kurs_nr` ist als `cfkurs_nr` verfügbar.

### 4.4 Zertifikat-URL-Platzhalter (für E-Mail-Regeln)

In **Buchungsregeln** (E-Mail-Vorlagen) steht der Platzhalter `{certificateurl}` zur Verfügung. Dieser gibt die direkte Download-URL des ausgestellten PDF-Zertifikats zurück und kann in automatischen Benachrichtigungs-E-Mails verwendet werden.

---

## 5. Variante A – Einfache Zertifikatsoption (je Buchungsoption)

Diese Variante ist die einfachste Einrichtung: Pro Buchungsoption wird genau eine Zertifikatsvorlage hinterlegt.

### Einrichtung

1. Öffnen Sie eine Buchungsoption zur Bearbeitung.
2. Navigieren Sie zum Abschnitt **„Moodle Zertifikat"**.
3. Wählen Sie im Feld **„Zertifikat"** die gewünschte `tool_certificate`-Vorlage aus.
4. Optional: Konfigurieren Sie ein **Ablaufdatum** (absolut oder relativ, siehe [Abschnitt 9](#9-ablaufdatum-für-zertifikate)).
5. Optional: Hinterlegen Sie unter **„Zertifikat nur bei zusätzlichem Abschluss folgender Optionen ausstellen"** weitere Buchungsoptionen, die ebenfalls abgeschlossen sein müssen.
6. Speichern Sie die Buchungsoption.

### Auslösung

Das Zertifikat wird ausgestellt, wenn:

- Der **Status** des Nutzers / der Nutzerin auf **„Teilgenommen"** (Anwesenheitsstatus `statusattending`, Wert 6) oder einen anderen konfigurierten Status wechselt (wenn `presencestatustoissuecertificate` gesetzt ist), **oder**
- Die Buchungsoption als **abgeschlossen** markiert wird (Ereignis `bookingoption_completed`) – sofern kein spezifischer Anwesenheitsstatus konfiguriert ist.

> **Hinweis:** Wenn die globale Einstellung `presencestatustoissuecertificate` aktiv ist, hat der Abschluss der Buchungsoption *keine* Auswirkung mehr – das Zertifikat wird ausschließlich beim Setzen des konfigurierten Anwesenheitsstatus ausgestellt.

---

## 6. Variante B – Zertifikatsbedingungen (erweitert, PRO)

Zertifikatsbedingungen erlauben eine regelbasierte, flexible Ausstellung von Zertifikaten: Eine Bedingung besteht aus einem optionalen **Filter**, einer **Bedingung (Logik)** und einer **Aktion**.

### 6.1 Verwaltung der Zertifikatsbedingungen

Die Zertifikatsbedingungen können auf zwei Ebenen verwaltet werden:

- **Systemweit:** Website-Administration → Plugins → Aktivitätsmodule → Buchung → **Zertifikatsbedingungen**
- **Je Buchungsinstanz:** Im Buchungsmodul unter **Einstellungen → Zertifikatsbedingungen** (mit dem Parameter `cmid`)

Beide Verwaltungsseiten nutzen die URL `/mod/booking/edit_certificateconditions.php`.

### 6.2 Aufbau einer Bedingung

| Komponente | Beschreibung |
|---|---|
| **Name** | Frei wählbarer Name für die Bedingung |
| **Aktiv** | Schalter, ob die Bedingung ausgewertet wird |
| **Filter (optional)** | Schränkt ein, *für wen* die Bedingung gilt (z. B. nach Nutzerprofilfeld) |
| **Bedingung (Logik)** | Definiert *wie* die Bedingung definiert ist und wann sie erfüllt ist|
| **Aktion** | Was passiert, wenn die Bedingung erfüllt ist (z. B. Zertifikat ausstellen) |

### 6.3 Verfügbare Filter

#### Filter: Nutzerprofilfeld (`userprofilefield`)

Schränkt die Ausstellung auf Nutzer\*innen ein, deren Profilfeld einen bestimmten Wert hat.

| Feld | Beschreibung |
|---|---|
| **Profilfeld** | Auswahl aus den verfügbaren benutzerdefinierten Profilfeldern |
| **Wert** | Der zu vergleichende Wert (Gleichheit oder Enthält) |

### 6.4 Verfügbare Bedingungen (Logik)

#### Bedingung: Buchungsoption (`bookingoption`)

Wird erfüllt, wenn eine oder mehrere bestimmte Buchungsoptionen abgeschlossen wurden.

| Feld | Beschreibung |
|---|---|
| **Buchungsoptionen** | Eine oder mehrere Buchungsoptionen (Mehrfachauswahl) |
| **Erforderliche Anzahl** | Wie viele der ausgewählten Optionen abgeschlossen sein müssen (Standard: 1) |

#### Bedingung: Getaggte Optionen (`taggedoptions`)

Bedingung für Buchungsoptionen, die direkt an die Bedingung „getaggt" (verknüpft) sind. Die Buchungsoptionen werden nicht in der Bedingung selbst, sondern im Formular der Buchungsoption zugewiesen (siehe unten).

| Feld | Beschreibung |
|---|---|
| **Erforderliche Anzahl** | Wie viele getaggte Optionen abgeschlossen sein müssen |

### 6.5 Verfügbare Aktionen

#### Aktion: Zertifikat erstellen (`createcertificate`)

| Feld | Beschreibung |
|---|---|
| **Zertifikatsvorlage** | Auswahl der `tool_certificate`-Vorlage |
| **Ablaufdatum** | Optionales absolutes oder relatives Ablaufdatum |

### 6.6 Buchungsoption einer Bedingung zuordnen (Tagging)

Im Bearbeitungsformular einer Buchungsoption (Abschnitt „Moodle Zertifikat") im Modus *Zertifikatsbedingungen* kann die Option einer oder mehreren Bedingungen vom Typ `taggedoptions` zugewiesen werden. Dadurch wird die Option als Abschluss-Voraussetzung für diese Bedingung registriert.

---

## 7. Trigger: Wann wird ein Zertifikat ausgestellt?

Die folgende Tabelle zeigt, durch welches Ereignis ein Zertifikat ausgestellt wird:

| Szenario | Auslösendes Ereignis | Bedingung |
|---|---|---|
| Buchungsoption abgeschlossen (Variante A, Standard) | `bookingoption_completed` | Kein `presencestatustoissuecertificate` konfiguriert, `certificatemanualtrigger` deaktiviert |
| Anwesenheitsstatus geändert (Variante A, Presence-Modus) | `bookinganswer_presencechanged` | `presencestatustoissuecertificate` ist auf einen bestimmten Status gesetzt (z. B. „Teilgenommen") |
| Zertifikatsbedingung erfüllt (Variante B) | `bookingoption_completed` | `certificateoptions = 1` (Bedingungsmodus), `certificatemanualtrigger` deaktiviert |
| Manuell ausgelöst | Schaltfläche „Zertifikat(e) generieren" | `certificatemanualtrigger` aktiviert oder jederzeit manuell |

### Anwesenheitsstatus „Teilgenommen"

Der Status **„Teilgenommen"** entspricht dem internen Wert `MOD_BOOKING_PRESENCE_STATUS_ATTENDING`. Er wird im Dropdown-Menü des Anwesenheitsstatus als „Teilgenommen" angezeigt.

Weitere verfügbare Anwesenheitsstatus:

| Wert | Interner Name | Deutsche Bezeichnung |
|---|---|---|
| 0 | `NOTSET` | (nicht gesetzt) |
| 1 | `COMPLETE` | Abgeschlossen |
| 2 | `INCOMPLETE` | Nicht abgeschlossen |
| 3 | `NOSHOW` | Nicht teilgenommen |
| 4 | `FAILED` | Nicht erfolgreich |
| 5 | `UNKNOWN` | Unbekannt |
| 6 | `ATTENDING` | **Teilgenommen** |
| 7 | `EXCUSED` | Entschuldigt |

In der globalen Einstellung **„Zertifikatsausstellung mit Anwesenheitsstatus"** wählen Sie den Status, der die Zertifikatsausstellung auslösen soll. Häufig wird hier „Teilgenommen" (Wert 6) verwendet.

---

## 8. Herunterladen und Versenden von Zertifikaten

### 8.1 Download durch Nutzer\*innen

Ausgestellte Zertifikate werden als **PDF-Dateien** im Moodle-Dateisystem gespeichert. Nutzer\*innen können ihr Zertifikat über folgende Wege herunterladen:

1. **Im eigenen Profil**
   Es gibt eine eigene Ansicht mit "Meine Zertifikate" wo die Zertifikate einsehbar und als PDF downloadbar sind.


2. **Per E-Mail-Link** (über Buchungsregeln)
   Über den Platzhalter `{certificateurl}` in einer Buchungsregel kann dem Nutzer / der Nutzerin automatisch eine E-Mail mit dem Download-Link zugesandt werden (z. B. beim Ereignis `certificate_issued`).

### 8.2 Download und Verwaltung durch Lehrende / Admins

In der **Teilnehmerliste** einer Buchungsoption (Manage Users) erscheint die Spalte „Aktuellstes Zertifikat" ebenfalls. Sie zeigt den Zertifikatscode mit einem klickbaren Link und ermöglicht so das Einsehen und ggf. direkte Öffnen des PDFs.

### 8.3 Übersicht aller Zertifikate (Admin)

Über die `all_userbookings`-Ansicht (persönliche Buchungsübersicht oder Kursübersicht) steht die Spalte `allusercertificates` zur Verfügung, die alle ausgestellten Zertifikate eines Nutzers / einer Nutzerin als Modal-Dialog rendert.

---

## 9. Ablaufdatum für Zertifikate

Beim Konfigurieren einer Zertifikatsvorlage in einer Buchungsoption oder einer Zertifikatsbedingung kann optional ein Ablaufdatum hinterlegt werden.

| Option | Beschreibung |
|---|---|
| **Kein Ablaufdatum** | Das Zertifikat gilt unbegrenzt. |
| **Absolutes Datum** | Das Zertifikat läuft zu einem festen Datum ab (z. B. 31.12.2026). |
| **Relatives Datum** | Das Ablaufdatum wird relativ zum Ausstelldatum berechnet (z. B. 365 Tage nach Ausstellung). |

> **Hinweis:** Wenn das berechnete Ablaufdatum bereits in der Vergangenheit liegt, wird kein neues Zertifikat ausgestellt.

---

## 10. Manuelles Auslösen

Wenn die Einstellung **„Zertifikate nur manuell auslösen"** (`certificatemanualtrigger`) aktiviert ist, werden Zertifikate **nicht automatisch** bei Abschluss oder Anwesenheitsänderung erstellt.

In diesem Fall können Trainer\*innen oder Admins in der **Teilnehmerliste** der Buchungsoption die Schaltfläche **„Zertifikat(e) generieren"** verwenden. Diese prüft alle Voraussetzungen (Abschluss, ggf. weitere Optionen) und stellt das Zertifikat bei Erfüllung aus.

---

## 11. Häufige Fragen (FAQ)

**F: Kein Zertifikats-Feld erscheint in der Buchungsoption – was tun?**
A: Prüfen Sie, ob (1) `tool_certificate` installiert ist, (2) die PRO-Lizenz aktiv ist und (3) in den globalen Einstellungen „Zertifikatserstellung aktivieren" gesetzt ist.

**F: Das Zertifikat wird nicht automatisch ausgestellt, obwohl alles konfiguriert ist.**
A: Überprüfen Sie, ob `certificatemanualtrigger` aktiviert ist – dann erfolgt die Ausstellung nur manuell. Prüfen Sie außerdem, ob eine `presencestatustoissuecertificate`-Einstellung gesetzt ist und der entsprechende Status tatsächlich gesetzt wurde.

**F: Kann ein Nutzer / eine Nutzerin mehrere Zertifikate für dieselbe Buchungsoption erhalten?**
A: Nur wenn im Modus *Zertifikatsbedingungen* die Einstellung **„Mehrere Zertifikate ausstellen"** (`issuemultiplecertificates`) aktiviert ist. Im Standardmodus wird pro Bedingung nur ein Zertifikat ausgestellt.

**F: Wie kann ich im PDF-Zertifikat den Vor- und Nachnamen des Nutzers / der Nutzerin anzeigen?**
A: `tool_certificate` stellt nutzerspezifische Felder wie Vor- und Nachname über die standardmäßigen Felder der Vorlage zur Verfügung (nicht über `mod_booking`-Platzhalter). Fügen Sie in der Vorlage ein „Nutzerfeld"-Element hinzu und wählen Sie „Vorname" / „Nachname".

**F: Wie kann ich das Zertifikat in einer automatischen E-Mail versenden?**
A: Erstellen Sie eine **Buchungsregel** mit dem Ereignis `bookingoption_completed`. Verwenden Sie in der E-Mail-Vorlage den Platzhalter `{certificateurl}`, der die direkte Download-URL des PDFs enthält.

**F: Was bedeutet „getaggte Optionen" bei den Zertifikatsbedingungen?**
A: Beim Typ `taggedoptions` werden die Buchungsoptionen nicht in der Bedingung selbst hinterlegt, sondern umgekehrt: Im Formular jeder Buchungsoption wählt man, welchen Bedingungen diese Option zugeordnet ist. So kann eine Bedingung von beliebig vielen Optionen ausgelöst werden, ohne die Bedingung selbst anpassen zu müssen.
