# Waitlist-Progression — Behavior-Test-Szenarien (Phase 3, End-to-End-Verifikation)

**Stand:** 2026-08-21 · **Zweck:** Verschachtelte, realistische End-to-End-Szenarien für die
Verifikation des neuen Mechanismus (`progression`, Trigger-Adapter, `waitlist_heartbeat_task`,
`expire_waitlist_offer_adhoc`, `upgrade_step`) — bewusst über isolierte Happy-Path-Tests
hinausgehend, mit Fokus auf Kombinationen mehrerer gleichzeitig wirksamer Bedingungen (Preis ×
Kapazität × Confirmation × Reihenfolge × Regeln). Ergänzt die bestehende PHPUnit-Suite
(`local/waitlist/*_test.php`, `waitlist_target_b*_test.php`) und die manuelle Checkliste
(`WAITLIST_REFACTOR_E2E_TEST_SCENARIOS_2026-08-21.md`) um die **Denkarbeit hinter der Auswahl** —
jedes Szenario hier ist kandidatentauglich für einen echten `progression_test.php`-artigen
PHPUnit-Test oder ein manuelles Nachvollziehen.

Referenzen (K1, K7, T4, W1, O2, P1, M1, ...) beziehen sich auf
`WAITLIST_REFACTOR_REQUIREMENTS_2026-08-04.md`. Bekannte Fehlerbilder (u:rise-Vorfall + während
dieses Refactorings selbst gefundene Bugs) sind explizit als eigene Kategorie markiert.

## Format pro Szenario

- **Ausgangssituation** — Optionskonfiguration, Wartelisten-Zustand, wer wartet mit welchem Preis/
  welcher Reihenfolge/welchem Confirmation-Bedarf.
- **Aktion(en)** — was konkret ausgelöst wird (Storno, Ablauf, manuelle Aktion, Zeitsprung, ...).
- **Erwartetes Verhalten** — die fachliche Beschreibung, was passieren soll.
- **Erwarteter Output/Status** — konkret prüfbar: welche `booking_waitlist_offers`-Zeilen mit
  welchem Status, welche `booking_answers.waitinglist`-Werte, welche Mails, welche
  `booking_waitlist_declines`-Einträge.
- **Verifiziert** — welcher Teil der neuen Architektur (K-/T-/O-/W-/P-/M-Nummer, Klasse) damit
  abgedeckt ist.

---

## A. Kernverhalten in Kombination (nicht mehr isolierter Happy Path)

### A1 — Dein Ausgangsbeispiel: gemischte Preise beim Batch-Nachrücken

- **Ausgangssituation:** Option mit 0 freien Plätzen (voll). Warteliste in Beitrittsreihenfolge:
  Person A (Preiskategorie kostenpflichtig, z. B. 80€), Person B (Preiskategorie kostenlos/0€).
  Eine aktive `send_mail_interval`-Regel, Bedingung "Immer".
- **Aktion:** Zwei Plätze werden gleichzeitig frei (z. B. zwei Stornierungen in einem Batch).
- **Erwartetes Verhalten:** K1 (Batch = min(N,M)) verarbeitet beide in einem Durchlauf, aber K3/K4
  entscheiden **pro Person unabhängig** nach ihrem jeweils aktuellen Preis — die Reihenfolge
  bestimmt nur *wer zuerst* behandelt wird, nicht *wie* behandelt wird.
- **Erwarteter Output/Status:** Person A: `booking_waitlist_offers`-Zeile Status `offered`,
  `expiresat` = jetzt + Regel-Intervall, `booking_answers.waitinglist` bleibt `WAITINGLIST`,
  eine Angebots-Mail mit dem Regel-Betreff. Person B: `booking_waitlist_offers`-Zeile Status
  `autobooked`, `booking_answers.waitinglist` = `BOOKED`, keine Angebots-Mail (stattdessen die
  normale Buchungsbestätigung).
- **Verifiziert:** K1 (Batch) × K3/K4 (Preis-Entscheidung) **in derselben Runde**, nicht nur
  einzeln. Bereits als `test_k1_batch_limits_to_free_capacity` teilweise abgedeckt, aber dort
  bewusst NICHT mit gemischten Preisen — echte Lücke, sollte ergänzt werden.

### A2 — Drei Plätze, drei unterschiedliche Preise, einer davon ohne auflösbare Preiskategorie (P2)

- **Ausgangssituation:** 3 freie Plätze. Warteliste: Person A (kostenpflichtig), Person B (keiner
  Preiskategorie zuordenbar, kein Fallback konfiguriert), Person C (kostenlos).
- **Aktion:** Reconcile wird ausgelöst.
- **Erwartetes Verhalten:** A bekommt ein Angebot (K4). B wird wie Preis 0 behandelt (P2-Fallback)
  und automatisch gebucht — **ohne PHP-Warnung im Log**. C wird ebenfalls automatisch gebucht (K3).
- **Erwarteter Output/Status:** 1× `offered` (A), 2× `autobooked` (B, C). Kein PHP-`Warning`/
  `Notice` im `debugging()`-Output während des gesamten Durchlaufs.
- **Verifiziert:** K1 × K3/K4 × P2 gemeinsam; P2 war bereits einzeln getestet
  (`price_based_decision_strategy_test.php`), hier zusätzlich im Batch-Kontext mit echten
  Nachbar-Kandidaten.

### A3 — K1-Batch, aber nur genug Plätze für die ersten zwei von drei

- **Ausgangssituation:** 2 freie Plätze, 3 Personen auf der Warteliste in fester
  Beitrittsreihenfolge (O1/O2), alle kostenpflichtig.
- **Aktion:** Reconcile wird ausgelöst.
- **Erwartetes Verhalten:** Nur die ersten zwei (O1/O2-Reihenfolge) bekommen ein Angebot, die
  dritte Person bleibt unbehandelt — **nicht** irgendeine zufällige Auswahl.
- **Erwarteter Output/Status:** 2× `offered` für die ersten beiden (nach `jointime ASC, baid ASC`),
  0 Zeilen für die dritte Person, sie erscheint weiterhin in
  `get_unbehandelte_waitinglist()`.
- **Verifiziert:** K1 (min(N,M)) × O1/O2 (Reihenfolge) — bereits als
  `test_k1_batch_limits_to_free_capacity` abgedeckt, hier als Referenzpunkt für die
  komplexeren Varianten unten (A4).

### A4 — Wie A3, aber die zweite Person verlässt die Warteliste genau während der Verarbeitung (K8)

- **Ausgangssituation:** wie A3 (2 freie Plätze, 3 Personen, alle kostenpflichtig).
- **Aktion:** Zwischen dem Laden der Kandidatenliste und der eigentlichen Behandlung storniert
  Person 2 (die eigentlich als Zweite behandelt würde) selbst ihre Wartelisten-Anmeldung (simulierbar
  im Test durch direktes Ändern von `booking_answers.waitinglist`, bevor `reconcile()` bei ihr
  ankommt — realistisch z. B. durch einen parallelen Request).
- **Erwartetes Verhalten:** Person 2 wird übersprungen, **ohne** dass ihr Überspringen einen freien
  Platz "verbraucht" (K8: kein `$free--` beim Überspringen) — Person 3 rückt in die zweite
  Behandlungs-Position nach und bekommt trotzdem noch ein Angebot.
- **Erwarteter Output/Status:** Person 1: `offered`. Person 2: keine Zeile (übersprungen). Person 3:
  `offered` — **nicht** unbehandelt, obwohl nominell nur 2 Plätze frei waren und Person 3 als
  Dritte in der Reihenfolge stand.
- **Verifiziert:** K8 (Live-Recheck via `is_still_on_waitinglist()`) × K1 (Kapazität wird nicht
  fälschlich für eine Nicht-Behandlung verbraucht) — im Bestand nur indirekt getestet, verdient
  einen expliziten Test.

### A5 — Mehrere aktive Regeln (K11) mit unterschiedlichen Bedingungen und Vorlagen

- **Ausgangssituation:** Eine Option mit zwei aktiven `send_mail_interval`-Regeln: Regel 1
  (Bedingung "Immer", Intervall 30 Min, Betreff "Angebot A"), Regel 2 (Bedingung "Warteliste voll",
  Intervall 60 Min, Betreff "Angebot B"). 2 freie Plätze, 2 Personen auf der Warteliste, Warteliste
  ist zum Zeitpunkt der Prüfung voll (Bedingung von Regel 2 erfüllt).
- **Aktion:** Reconcile wird ausgelöst.
- **Erwartetes Verhalten:** Beide Regeln sind gleichzeitig anwendbar (K11 unterstützt mehrere
  Regeln pro Instanz). Kapazität wird **einmal gemeinsam** über beide Regeln hinweg verbraucht,
  nicht pro Regel neu ausgeschöpft (sonst gäbe es 4 statt 2 Angebote).
- **Erwarteter Output/Status:** Genau 2 offene Angebote (nicht 4), jedes mit dem `ruleid` der
  Regel, die es tatsächlich ausgelöst hat, korrektem Betreff/Template aus der jeweiligen Regel,
  korrekter `expiresat` nach dem jeweiligen Regel-Intervall.
- **Verifiziert:** K11 (mehrere Regeln) × K1 (gemeinsamer Kapazitäts-Pool über Regeln hinweg) —
  bisher nur mit identischer Bedingung getestet (`rule_condition_checker_test.php`), nicht mit
  gemischten Bedingungen im vollen `reconcile()`-Durchlauf.

---

## B. Bekannte historische Fehlerbilder — gezielte Regressionstests

Diese Szenarien reproduzieren wörtlich die im u:rise-Vorfall gemeldeten Probleme sowie die
während dieser Session selbst gefundenen Bugs. Jedes davon MUSS nach dem Refactor anders
ausgehen als im dokumentierten Fehlerfall.

### B1 — u:rise-Original-Bug: erneute Zahlungsaufforderung nach Ablehnung

- **Ausgangssituation:** Person X lehnt ein Angebot für Option Y aktiv ab.
- **Aktion:** Zu einem **späteren** Zeitpunkt (anderer Trigger, z. B. eine andere Person storniert
  ihre Buchung) wird erneut ein Platz für Option Y frei, und Person X befindet sich immer noch
  (unverändert) auf der Warteliste.
- **Erwartetes Verhalten (Alt-Bug):** im Altsystem konnte Person X erneut die Zahlungsaufforderung
  bekommen, da der Ablehnungs-Zustand nirgends dauerhaft gespeichert war.
- **Erwartetes Verhalten (neu):** Person X wird **nie wieder** berücksichtigt, unabhängig davon,
  wie oft danach ein Platz frei wird.
- **Erwarteter Output/Status:** `booking_waitlist_declines`-Zeile für (optionid, userid) existiert
  dauerhaft. Person X taucht in **keinem** späteren `get_unbehandelte_waitinglist()`-Aufruf mehr
  auf, egal wie viele Runden `reconcile()` danach durchläuft.
- **Verifiziert:** K7 (permanente Sperre). Bereits abgedeckt
  (`test_k7_permanently_declined_user_is_excluded`), hier als **mehrrundiger** Test (2-3
  `reconcile()`-Aufrufe nacheinander mit dazwischen neu frei werdenden Plätzen) zur Absicherung
  gegen ein Wiederaufleben über mehrere Runden — die eigentliche u:rise-Beschwerde war ja gerade
  "es passiert irgendwann später wieder".

### B2 — u:rise-Original-Bug: kein Batch-Nachrücken bei mehreren freien Plätzen

- **Ausgangssituation:** Option mit 3 freien Plätzen (z. B. durch eine einmalige Kapazitätserhöhung
  von 0 auf 3), 3 Personen auf der Warteliste, alle kostenpflichtig.
- **Aktion:** Reconcile wird ausgelöst.
- **Erwartetes Verhalten (Alt-Bug):** im Altsystem wurde nur eine Person pro Intervall-Tick
  behandelt — bei 3 freien Plätzen hätte es 3 Intervall-Zyklen gebraucht (z. B. 3×60 Minuten),
  bis alle bedient waren.
- **Erwartetes Verhalten (neu):** alle 3 Personen bekommen **im selben Durchlauf, ohne
  Wartezeit dazwischen** ein Angebot.
- **Erwarteter Output/Status:** 3 `booking_waitlist_offers`-Zeilen mit Status `offered`, alle mit
  demselben `roundid`, alle mit `offeredat` innerhalb derselben Sekunde (mocked clock).
- **Verifiziert:** K1/T8. Bereits abgedeckt (`test_k1_batch_limits_to_free_capacity`,
  `waitlist_target_b2_batch_promotion_test.php`) — hier zur vollständigen Dokumentation als
  expliziter "so sah der Alt-Bug aus"-Vergleichstest gelistet.

### B3 — u:rise-Original-Bug: Prozess-Stillstand bei verpasstem Trigger

- **Ausgangssituation:** Option mit freiem Platz und wartender Person, aber **kein** Trigger wurde
  ausgelöst (simuliert z. B. durch direkten `$DB`-Write statt der echten Buchungs-API — kein
  Cron-Ausfall nötig für den Test, das Ergebnis ist dasselbe).
- **Aktion:** Kein manueller Eingriff — nur Zeit vergeht (mocked clock, ≥ konfiguriertes
  Heartbeat-Intervall).
- **Erwartetes Verhalten (Alt-Bug):** im Altsystem blieb die Option auf unbestimmte Zeit
  hängen, da keine periodische Selbstheilung existierte.
- **Erwartetes Verhalten (neu):** der nächste `waitlist_heartbeat_task`-Lauf findet die Option und
  reconciled sie automatisch.
- **Erwarteter Output/Status:** nach dem Heartbeat-Lauf: offene Angebots-Zeile für die wartende
  Person, obwohl nie ein expliziter Trigger-Adapter aufgerufen wurde.
- **Verifiziert:** T7. Bereits abgedeckt (`waitlist_target_b5_heartbeat_test.php`), hier als
  benannter Alt-Bug-Vergleich dokumentiert.

### B4 — u:rise-Original-Bug: falsche Reihenfolge bei maxanswers-Erhöhung

- **Ausgangssituation:** 3 Personen auf der Warteliste mit **identischem** `timemodified`
  (realistischer Fall: Massen-Import oder gleichzeitige Anmeldungen).
- **Aktion:** Kapazität wird erhöht, Reconcile ausgelöst, wiederholt in mehreren separaten
  Testläufen.
- **Erwartetes Verhalten (Alt-Bug):** bei identischem `timemodified` konnte die Behandlungs-
  Reihenfolge zwischen Läufen wechseln (kein stabiler Tie-Break).
- **Erwartetes Verhalten (neu):** die Reihenfolge ist **deterministisch** über beliebig viele
  Wiederholungen — Tie-Break über `baid` (bzw. `id`), nicht zufällig.
- **Erwarteter Output/Status:** bei 10 Wiederholungen desselben Setups (frische DB pro Lauf)
  identische Behandlungsreihenfolge in jedem einzelnen Lauf.
- **Verifiziert:** O2 (Tie-Break). Bereits abgedeckt
  (`test_o2_tiebreak_promotion_order_deterministic_with_identical_timemodified`).

### B5 — Session-eigener Fund: K4-Ablauf-Spam-Schleife beim einzigen Kandidaten

- **Ausgangssituation:** genau eine Person auf der Warteliste, bekommt ein Angebot, lässt es
  verstreichen. `waitlistrecycling` ist **deaktiviert** (Standard).
- **Aktion:** Das Angebot läuft ab (`expire_waitlist_offer_adhoc` feuert), was intern
  `reconcile()` erneut auslöst.
- **Erwartetes Verhalten (früher Zwischenstand dieser Session, NIE ausgeliefert):** ein zuerst
  vorgeschlagener, von Georg abgelehnter Entwurf hätte dieselbe Person sofort wieder angeboten
  bekommen — ein Endlos-Spam-Risiko.
- **Erwartetes Verhalten (final):** die Person bleibt dauerhaft gesperrt (K4=K7), bekommt **kein**
  zweites Angebot, auch nicht durch den durch ihren eigenen Ablauf ausgelösten `reconcile()`-Aufruf.
- **Erwarteter Output/Status:** nach dem Ablauf: `booking_waitlist_declines`-Zeile existiert,
  `get_open_offers()` liefert 0 Zeilen für diese Option, keine zweite Angebots-Mail an dieselbe
  Person.
- **Verifiziert:** K4=K7. Bereits abgedeckt
  (`test_execute_does_not_reoffer_the_sole_candidate_whose_own_offer_expired`).

### B6 — Session-eigener Fund: fehlende Buchungsberechtigung trotz Angebot (Confirmation-Gap)

- **Ausgangssituation:** Option mit `waitforconfirmation=1`, `confirmationonnotification=1`
  ("für alle"), 1 freier Platz, 1 kostenpflichtige Person auf der Warteliste.
- **Aktion:** Reconcile wird ausgelöst, Person versucht danach zu buchen.
- **Erwartetes Verhalten (Bug, während dieser Session gefunden, NIE ausgeliefert):** ein
  Zwischenstand von `progression::offer()` erzeugte zwar die Angebots-Mail, setzte aber nie das
  `confirmwaitinglist`-Flag — die Buchen-Schaltfläche wäre für die Person dauerhaft blockiert
  gewesen, obwohl sie ein gültiges Angebot hatte.
- **Erwartetes Verhalten (final):** die Person kann nach dem Angebot tatsächlich buchen.
- **Erwarteter Output/Status:** `booking_answers.json` enthält `confirmwaitinglist: 1` und ein
  ausreichendes `confirmationcount` direkt nach `reconcile()` — **vor** jeder weiteren manuellen
  Aktion. `bo_availability\conditions\onwaitinglist::is_available()` liefert `true` für diese
  Person.
- **Verifiziert:** W1-W3. Bereits abgedeckt
  (`test_k4_offer_grants_confirmation_when_required`) — **der wichtigste Regressionstest aus
  dieser ganzen Session**, unbedingt auch end-to-end über die echte Buchungs-UI nachvollziehen,
  nicht nur über die JSON-Flags.

### B7 — Session-eigener Fund: reentranter `reconcile()`-Aufruf bei Autobook

- **Ausgangssituation:** Option mit mehreren freien Plätzen, mehrere kostenlose Personen auf der
  Warteliste (K3-Pfad, führt zu `user_submit_response()`-Aufrufen innerhalb von `reconcile()`).
- **Aktion:** Reconcile wird ausgelöst.
- **Erwartetes Verhalten (Bug, während dieser Session gefunden, NIE ausgeliefert):** ein
  Zwischenstand von `booking_accepted_waitlist_adapter::accept()` rief innerhalb des laufenden
  `reconcile()`-Aufrufs erneut `reconcile()` auf (ausgelöst durch das interne
  `bookinganswer_movedupfromwaitinglist`-Event bei Autobook) — führte zu unkontrollierter
  Rekursion bis zum Speicherüberlauf.
- **Erwartetes Verhalten (final):** `reconcile()` läuft **genau einmal** pro Trigger durch, auch
  wenn intern mehrere Autobuchungen stattfinden.
- **Erwarteter Output/Status:** Prozess terminiert normal, Speicherverbrauch bleibt konstant,
  Anzahl der `booking_waitlist_offers`-Zeilen entspricht exakt der Anzahl der Kandidaten (keine
  doppelten Zeilen durch mehrfache Verarbeitung derselben Person).
- **Verifiziert:** internes Verhalten von `progression`/`booking_accepted_waitlist_adapter` — real
  nur indirekt testbar (z. B. über einen Timeout/Speicher-Grenzwert in einem dedizierten Test mit
  vielen gleichzeitigen kostenlosen Kandidaten), aber wichtig genug für einen eigenen,
  dokumentierten Test.

### B8 — Session-eigener Fund: MUC-Cache blieb nach rohem DB-Write veraltet

- **Ausgangssituation:** Kapazität einer Option wird **nicht** über die reguläre Options-Bearbeitung
  geändert, sondern direkt in der Datenbank (realistisch z. B. bei einem externen Datenimport/
  einer Migration).
- **Aktion:** Reconcile wird unmittelbar danach ausgelöst.
- **Erwartetes Verhalten (Bug, mehrfach während dieser Session gefunden):** `capacity_calculator`
  liest über `singleton_service`/MUC-Cache eine veraltete `maxanswers` und berechnet falsche freie
  Kapazität.
- **Erwartetes Verhalten (final):** solange die Aufrufer-Seite nach einem rohen Write
  `\cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid)` **und**
  `singleton_service::destroy_booking_option_singleton($optionid)` aufruft, sieht `reconcile()`
  sofort den korrekten Wert.
- **Erwarteter Output/Status:** `capacity_calculator::free_capacity()` liefert unmittelbar nach dem
  rohen Write + Cache-Purge den korrekten, neuen Wert.
- **Verifiziert:** kein eigener K-/T-Punkt, aber eine reale, mehrfach aufgetretene
  Infrastruktur-Falle dieser Session — eher eine Erinnerung/ein Hinweis für zukünftige Tests und
  Datenimport-Skripte als ein eigenständiges Verhalten des neuen Mechanismus.

---

## C. Verschachtelte Edge Cases / Mischfälle

### C1 — Confirmation-Modus 0 kombiniert mit unabhängiger manueller Freigabe (dein zweites Beispiel)

- **Ausgangssituation:** Option mit `waitforconfirmation=1`, `confirmationonnotification=0`
  ("keine automatische Freigabe"). 3 freie Plätze, 3 kostenpflichtige Personen auf der Warteliste.
- **Aktion:** Reconcile wird ausgelöst (alle 3 bekommen ein Angebot, aber **keine** automatische
  Freigabe, da Modus 0). Anschließend gibt ein:e Admin/Lehrende:r **manuell und unabhängig** die
  Freigabe für Person 2, **ohne** Person 1 vorher freizugeben.
- **Erwartetes Verhalten:** alle 3 bekommen ein offenes Angebot (Mail), aber niemand kann initial
  buchen. Die manuelle Freigabe für Person 2 wirkt sich **ausschließlich** auf Person 2 aus —
  Person 1 und 3 bleiben unverändert blockiert, unabhängig von der Reihenfolge, in der sie ihr
  Angebot bekommen haben.
- **Erwarteter Output/Status:** vor der manuellen Aktion: `confirmwaitinglist` bei keiner der 3
  Personen gesetzt. Nach der manuellen Freigabe von Person 2: nur bei Person 2
  `confirmwaitinglist: 1`, Personen 1 und 3 weiterhin ohne dieses Flag. Alle 3
  `booking_waitlist_offers`-Zeilen bleiben unverändert im Status `offered`.
- **Verifiziert:** W1 (Modus 0 = kein Auto-Grant, bereits abgedeckt via
  `test_k4_offer_does_not_grant_confirmation_when_notification_mode_is_off`) × die von dir
  explizit geforderte Unabhängigkeit der manuellen Freigabe — **noch nicht als eigener Test
  vorhanden**, sollte ergänzt werden (bestehende manuelle UI, aber nie im Zusammenspiel mit dem
  neuen Mechanismus end-to-end getestet).

### C2 — K7 (aktive Ablehnung) und K4-Recycling auf derselben Option gleichzeitig

- **Ausgangssituation:** Option mit `waitlistrecycling` **aktiviert**. 2 Personen auf der
  Warteliste: Person A bekommt ein Angebot und lehnt es **aktiv ab** (K7). Person B bekommt (in
  einer späteren Runde) ebenfalls ein Angebot und lässt es **verstreichen** (K4).
- **Aktion:** Der nächste Heartbeat-Lauf erkennt, dass die Warteliste "vollständig geflaggt" ist
  (beide gesperrt) und setzt die Sperren zurück, soweit das Recycling erlaubt.
- **Erwartetes Verhalten:** **nur** Person B (K4/expired) wird zurückgesetzt und erneut angeboten.
  Person A (K7/aktiv abgelehnt) bleibt **für immer** gesperrt, unabhängig vom Recycling-Setting.
- **Erwarteter Output/Status:** `booking_waitlist_declines`-Zeile für A bleibt mit `reason=3`
  (declined) bestehen. Die Zeile für B (`reason=4`, expired) wird gelöscht. Nach dem Heartbeat-Lauf:
  1 offenes Angebot (für B), Person A taucht in keiner `get_unbehandelte_waitinglist()`-Abfrage
  mehr auf.
- **Verifiziert:** K7 × K4-Recycling **gemeinsam auf derselben Option** — bisher nur einzeln
  getestet (`test_execute_never_recycles_an_actively_declined_candidate` deckt eine Person ab,
  nicht die Kombination "eine K7-Person UND eine K4-Person gleichzeitig auf derselben Liste").

### C3 — P1 (Affiliation-Wechsel) mitten in einem Batch-Nachrücken

- **Ausgangssituation:** 2 freie Plätze. Person A (aktuell: Preiskategorie kostenpflichtig) und
  Person B (aktuell: Preiskategorie kostenlos), beide auf der Warteliste.
- **Aktion:** **Bevor** `reconcile()` läuft, wechselt Person A ihr Profil-Feld (z. B. Statuswechsel
  Student → Mitarbeiter:in) so, dass sie jetzt ebenfalls kostenlos ist. Erst danach wird
  `reconcile()` ausgelöst.
- **Erwartetes Verhalten:** die Preis-Entscheidung wird **zum Behandlungszeitpunkt live**
  nachgeschlagen (P1), nicht gecacht — Person A wird jetzt ebenfalls automatisch gebucht, nicht
  mit einem Zahlungsangebot behandelt.
- **Erwarteter Output/Status:** beide Personen: Status `autobooked`, `booking_answers.waitinglist`
  = `BOOKED`. Keine Angebots-Mail an Person A trotz ursprünglich hinterlegter Preiskategorie.
- **Verifiziert:** P1 (Preis live zum Behandlungszeitpunkt) × K1 (Batch) — P1 bereits einzeln
  getestet, hier explizit im Mehrpersonen-Batch-Kontext.

### C4 — Kapazitätserhöhung während eine Migration noch nicht abgeschlossen war

- **Ausgangssituation:** eine alte, laufende Mail-Intervall-Kette (eine Person bereits behandelt,
  zwei weitere noch unbehandelt), `maxanswers=1` zum Zeitpunkt, als die Kette zuletzt aktiv war.
- **Aktion:** Vor dem `upgrade_step::run()`-Aufruf wird `maxanswers` auf 3 erhöht (z. B. weil
  zwischenzeitlich mehr Kursplätze verfügbar gemacht wurden). Danach `upgrade_step::run()` +
  `reconcile()`.
- **Erwartetes Verhalten:** die migrierte, bereits behandelte Person bleibt behandelt (belegt
  weiterhin einen der 3 "Plätze" als offenes Angebot). Die beiden vorher unbehandelten Personen
  werden vom neuen Mechanismus **beide gleichzeitig** aufgegriffen (K1), nicht nacheinander wie es
  die Alt-Kette getan hätte.
- **Erwarteter Output/Status:** 3 offene `booking_waitlist_offers`-Zeilen (1 aus der Migration
  rekonstruiert, 2 neu durch `reconcile()`), 0 Personen mehr unbehandelt.
- **Verifiziert:** M1 (Migration) × K1 (Batch-Nachholen von mehr Kapazität, als die Alt-Kette je
  kannte) — exakt das Szenario, das während dieser Session in C1 (`waitlist_migration_c1_running_chain_test.php`)
  gefunden und gefixt wurde; hier als eigenständiges, benanntes Konzept dokumentiert.

### C5 — Regel wird während eines offenen Angebots geändert (K9) — bereits laufendes Angebot vs. neue Behandlung

- **Ausgangssituation:** Person A hat ein offenes Angebot (Status `offered`, `expiresat` in der
  Zukunft), erzeugt durch Regel R mit Intervall 60 Minuten. Person B ist noch unbehandelt.
- **Aktion:** Die Regel R wird bearbeitet (z. B. Intervall auf 10 Minuten geändert) oder komplett
  deaktiviert, **bevor** Person A reagiert oder ihr Angebot abläuft.
- **Erwartetes Verhalten:** Person As **bereits bestehendes** Angebot bleibt unverändert gültig
  (eigene `expiresat`, unabhängig von der jetzt geänderten Regel) — `expire_waitlist_offer_adhoc`
  arbeitet ausschließlich mit der zum Anlage-Zeitpunkt eingefrorenen Frist. Für Person B (noch
  unbehandelt) gilt ab sofort die **neue** Regel-Konfiguration bzw. gar keine, falls die Regel
  deaktiviert wurde (K11 lässt sie dann außen vor).
- **Erwarteter Output/Status:** Person As Zeile: `expiresat` unverändert. Ein neuer
  `reconcile()`-Aufruf (ausgelöst durch irgendein Ereignis) bietet Person B **nicht** mehr an, falls
  die Regel deaktiviert wurde (K11: keine anwendbare Regel mehr) — oder mit dem neuen Intervall,
  falls sie nur geändert wurde.
- **Verifiziert:** K9 (Regeländerung mitten im Prozess) × K11 (Regel-Anwendbarkeit live geprüft) ×
  die Tatsache, dass bereits bestehende Angebote von späteren Regeländerungen unberührt bleiben —
  eine echte Lücke im bisherigen Testbestand, K9 wurde bisher nur gegen die ALTE Engine
  charakterisiert (Kategorie A), nie explizit gegen den neuen Mechanismus.

### C6 — Option wird gelöscht, während mehrere offene Angebote existieren (K10)

- **Ausgangssituation:** Option mit 2 offenen Angeboten (2 Personen, beide `offered`, jeweils
  eigener `expire_waitlist_offer_adhoc`-Task geplant).
- **Aktion:** Die Option wird gelöscht.
- **Erwartetes Verhalten:** weder das Ablaufen der Angebote noch ein späterer Heartbeat-Lauf oder
  ein versehentlich noch ausstehender `reconcile()`-Aufruf für diese Option dürfen eine Exception
  werfen.
- **Erwarteter Output/Status:** `expire_waitlist_offer_adhoc::execute()` für beide Angebote läuft
  ohne Fehler durch (no-op, da die zugehörige Option nicht mehr existiert).
  `progression::reconcile()` auf die gelöschte optionid liefert ebenfalls keinen Fehler.
- **Verifiziert:** K10 — teilweise bereits über C3 (`waitlist_migration_c3_orphaned_tasks_test.php`)
  für den Migrationsfall abgedeckt, hier als eigenständiges Szenario für den **laufenden Betrieb**
  (nicht nur Migration) empfohlen.

### C7 — Doppel-Trigger derselben Person in enger zeitlicher Folge (K5) kombiniert mit Batch (K1)

- **Ausgangssituation:** 2 freie Plätze, 2 Personen auf der Warteliste.
- **Aktion:** Derselbe auslösende Event (z. B. eine Stornierung) wird durch einen technischen
  Zufall zweimal in sehr kurzem Abstand verarbeitet (z. B. doppelter Webhook-Aufruf, doppelter
  Seitenaufruf).
- **Erwartetes Verhalten:** trotz zweifacher Auslösung werden die beiden Wartelisten-Personen
  jeweils nur **einmal** behandelt — keine doppelten Angebote, keine doppelten Mails.
- **Erwarteter Output/Status:** genau 2 `booking_waitlist_offers`-Zeilen (nicht 4), abgesichert
  über den `UNIQUE(optionid, roundid, userid)`-Constraint plus den Live-Recheck (K8), der die
  zweite Verarbeitung als "bereits behandelt" erkennt.
- **Verifiziert:** K5 (Idempotenz) × K1 (Batch) — K5 bisher nur mit einer einzelnen Person
  getestet (`test_k5_double_trigger_of_same_event_does_not_double_treat`, Kategorie A gegen die
  Alt-Engine), noch nicht gegen den neuen Mechanismus mit mehreren gleichzeitig betroffenen
  Personen.

---

## D. Freigabe/Confirmation — Feinheiten (W1-W3)

### D1 — Gemischte Confirmation-Anforderung auf derselben Warteliste

- **Ausgangssituation:** eine Option mit `waitforconfirmation=2` ("nur auf der Warteliste").
  Person A war ursprünglich direkt gebucht (kein Confirmation-Bedarf), storniert dann selbst und
  landet erneut auf der Warteliste — jetzt **mit** Confirmation-Bedarf, da `waitforconfirmation=2`
  nur für echte Wartelisten-Situationen gilt.
- **Aktion:** Ein Platz wird frei, Reconcile läuft.
- **Erwartetes Verhalten:** Person A wird wie jede andere aktuell wartende Person behandelt — ihr
  früherer Confirmation-freier Status als Direktbucher:in spielt keine Rolle mehr.
- **Erwarteter Output/Status:** Person A bekommt ein Angebot mit denselben W1-W3-Regeln wie alle
  anderen aktuell Wartenden.
- **Verifiziert:** korrektes Live-Lesen von `waitforconfirmation` zum Behandlungszeitpunkt, keine
  fälschliche Übernahme eines alten Zustands.

### D2 — Confirmation-Grant für autobook-Kandidat:innen (sollte NICHT passieren)

- **Ausgangssituation:** Option mit `waitforconfirmation=1`, `confirmationonnotification=1`, aber
  die wartende Person hat Preis 0 (K3-Pfad, nicht K4).
- **Aktion:** Reconcile wird ausgelöst.
- **Erwartetes Verhalten:** `grant_confirmation_if_required()` wird **nur** vom K4-Angebotspfad
  aufgerufen, nie vom K3-Autobook-Pfad — eine bereits automatisch gebuchte Person braucht keine
  nachträgliche "Freigabe", sie ist ja schon gebucht.
- **Erwarteter Output/Status:** `confirmwaitinglist` wird für diese Person **nicht** gesetzt (kein
  Aufruf von `write_user_answer_to_db(..., CONFIRMATION, ...)` für sie), da sie direkt über
  `user_submit_response()` gebucht wurde. Kein Fehler, keine doppelte/unnötige DB-Schreiboperation.
- **Verifiziert:** korrekte Abgrenzung zwischen K3- und K4-Pfad bezüglich W1-W3 — sollte als
  Negativ-Test ergänzt werden (bisher nur der Positiv-Fall D6/B6 getestet).

### D3 — Manuelles Unconfirm einer Person, die noch gar kein Angebot vom neuen Mechanismus hatte

- **Ausgangssituation:** eine Person mit einer sehr alten, noch aus der Zeit vor diesem Update
  stammenden Confirmation (kein `booking_waitlist_offers`-Eintrag, da sie nie durch `progression`
  gelaufen ist — realistisch bei Altbestand, der nie migriert wurde, z. B. weil sie außerhalb einer
  Kette manuell freigegeben wurde).
- **Aktion:** Admin entzieht die Freigabe manuell (Unconfirm).
- **Erwartetes Verhalten:** `unconfirm_waitlist_adapter::decline()` läuft ins Leere (kein offenes
  Angebot zum Deklinieren gefunden) — **kein Fehler**, die eigentliche Unconfirm-Aktion
  (JSON-Flag entfernen) funktioniert trotzdem ganz normal weiter.
- **Erwarteter Output/Status:** kein neuer `booking_waitlist_declines`-Eintrag (da kein Offer zum
  Transitionieren existierte), aber `confirmwaitinglist` wird trotzdem aus dem JSON entfernt.
  Nachfolgender `reconcile()`-Aufruf (via `check_if_free_to_book_again()`) behandelt die Person
  wieder ganz normal als unbehandelt.
- **Verifiziert:** Robustheit von `unconfirm_waitlist_adapter` gegenüber fehlendem Offer — eine
  echte Lücke, die bisher nicht explizit getestet ist (alle bisherigen Unconfirm-Tests gehen von
  einem existierenden Offer aus).

---

## E. Wartelisten-Recycling — Kombinationen (neues Feature dieser Session)

### E1 — Recycling aktiv, aber die Warteliste ist noch nicht "vollständig geflaggt"

- **Ausgangssituation:** `waitlistrecycling` aktiv, 2 Personen auf der Warteliste. Person A lässt
  ihr Angebot verstreichen (K4-gesperrt), Person B ist noch nie behandelt worden.
- **Aktion:** Heartbeat läuft.
- **Erwartetes Verhalten:** **kein** Reset — die Liste ist erst "vollständig geflaggt", wenn
  **alle** noch aktiven Kandidat:innen gesperrt sind. Person B ist noch offen, also greift
  Recycling noch nicht.
- **Erwarteter Output/Status:** Person A bleibt gesperrt, Person B bekommt stattdessen (falls
  Kapazität frei) ein normales, erstes Angebot — kein Zusammenhang mit Recycling in diesem Schritt.
- **Verifiziert:** die "vollständig geflaggt"-Bedingung in `find_recyclable_options()` — bereits
  abgedeckt (`test_find_recyclable_options_excludes_when_someone_still_unlocked`), hier als
  End-to-End-Ablauf mit echtem Heartbeat-Lauf dokumentiert statt nur Repository-Ebene.

### E2 — Recycling mit mehreren Zyklen hintereinander (spielt die Reihenfolge nach dem Reset wirklich "wie zuvor"?)

- **Ausgangssituation:** `waitlistrecycling` aktiv, 3 Personen (A, B, C in Beitrittsreihenfolge).
  Alle 3 lassen ihr jeweiliges Angebot nacheinander verstreichen (nach K1 werden zunächst nur so
  viele gleichzeitig angeboten, wie Plätze frei sind — bei 1 freiem Platz nacheinander A, dann B,
  dann C).
- **Aktion:** Nachdem C (die letzte) auch verstrichen ist, läuft der Heartbeat und setzt zurück.
- **Erwartetes Verhalten:** nach dem Reset wird wieder in der **ursprünglichen**
  Beitrittsreihenfolge (A, dann B, dann C) angeboten — nicht in der Reihenfolge, in der sie
  zuletzt gesperrt wurden, und nicht neu ans Ende einsortiert.
- **Erwarteter Output/Status:** nach dem Reset bekommt zuerst wieder Person A ein Angebot (obwohl
  sie zeitlich zuerst gesperrt wurde), passend zu `jointime ASC` aus `booking_answers`, die vom
  Reset unberührt bleibt.
- **Verifiziert:** die explizite Zusicherung "Reihenfolge nach Reset wie zuvor" aus der
  Policy-Diskussion dieser Session — bisher nur mit **einer** Person getestet
  (`test_execute_recycles_a_fully_flagged_option_when_recycling_enabled`), nie mit mehreren, was
  die Reihenfolgen-Garantie eigentlich erst richtig prüft.

---

## F. Migration + laufender Betrieb im Zusammenspiel

### F1 — Migration einer offenen Confirm-Freigabe (M2) plus sofort ein neuer Trigger

- **Ausgangssituation:** eine offene, nie ausgeführte `confirm_bookinganswer`-Direktaufgabe (M2,
  exklusiver Modus) für Person A. Person B ist ebenfalls auf der Warteliste, noch unbehandelt.
- **Aktion:** `upgrade_step::run()`, danach **unmittelbar** (noch in derselben Anfrage/demselben
  Deploy-Schritt) ein ganz normaler neuer Trigger (z. B. Storno einer dritten Person, ein
  zusätzlicher Platz wird frei).
- **Erwartetes Verhalten:** Person As migriertes, offenes Angebot bleibt exakt wie es war
  (Exklusivität gewahrt — sie war die einzige mit einer offenen Freigabe). Der neue Trigger
  verarbeitet **zusätzlich** Person B (jetzt gibt es ja einen neuen freien Platz), ohne Person As
  migrierten Zustand zu stören.
- **Erwarteter Output/Status:** 2 offene Angebote nach beiden Schritten (1 migriert, 1 neu),
  Person A weiterhin exakt mit ihrem ursprünglichen (aus der alten `nextruntime` übernommenen)
  `expiresat`.
- **Verifiziert:** M2 × T1 (normaler Trigger direkt nach der Migration) — bisher nur einzeln
  getestet, nie in dieser Abfolge.

### F2 — Migration einer bereits mehrfach behandelten Mail-Kette (usersalreadytreated mit >1 Eintrag)

- **Ausgangssituation:** eine Mail-Intervall-Kette, die (im Alt-System, vor der Migration) bereits
  **zwei** Personen nacheinander behandelt hat (`usersalreadytreated` mit 2 Einträgen), bevor das
  Update eingespielt wurde.
- **Aktion:** `upgrade_step::run()`.
- **Erwartetes Verhalten:** **beide** bereits behandelten Personen werden korrekt als offene
  Angebote rekonstruiert (nicht nur die zuletzt behandelte), mit einer sinnvollen `expiresat` für
  beide.
- **Erwarteter Output/Status:** 2 `booking_waitlist_offers`-Zeilen mit Status `offered` nach der
  Migration, keine der beiden taucht mehr in `get_unbehandelte_waitinglist()` auf.
- **Verifiziert:** M1 mit dem in dieser Session nur mit **einem** Eintrag getesteten
  `usersalreadytreated`-Array — der Mehrpersonen-Fall ist im bisherigen `upgrade_step`-Code
  bereits vorgesehen (die `foreach`-Schleife über das Array), aber nie mit einem echten,
  >1-Eintrag-Array end-to-end getestet worden. Sollte ergänzt werden.

---

## Priorisierungsempfehlung

Falls nicht alle auf einmal als PHPUnit-Tests umgesetzt werden: **zuerst B6 und B7** (die beiden
during-Session gefundenen, potenziell schwerwiegendsten Bugs — Buchungsblockade bzw.
Speicherüberlauf), danach **A1/A4/C1/C2** (die verschachtelten Mischfälle, die am ehesten reale
Produktionssituationen abbilden), dann der Rest nach Kapazität.

## Nächster Schritt

Sag Bescheid, welche dieser Szenarien wir als echte PHPUnit-Tests umsetzen sollen — die schreibe
ich wie gewohnt direkt (Tests sind von unserer Paste-Workflow-Regel ausgenommen).
