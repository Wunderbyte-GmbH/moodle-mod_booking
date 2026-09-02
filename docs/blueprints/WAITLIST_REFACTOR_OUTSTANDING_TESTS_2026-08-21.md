# Waitlist-Progression — Offene Tests (Stand 2026-08-21)

Konsolidierte Checkliste aller noch **ausstehenden** Tests aus
`WAITLIST_REFACTOR_E2E_TEST_SCENARIOS_2026-08-21.md` (manuell) und
`WAITLIST_REFACTOR_BEHAVIOR_TEST_SCENARIOS_2026-08-21.md` (PHPUnit-Kandidaten). Bereits
abgedeckte Szenarien sind hier bewusst weggelassen — Details/Begründung jeweils in den
Ursprungsdokumenten.

## Umsetzung: PHPUnit statt Behat, Zeit-Mocking-Entscheidung

Die Behavior-Test-Szenarien aus Teil 2 werden **nicht als Behat-Tests**, sondern als **PHPUnit-Tests**
umgesetzt (2026-08-21 mit Georg abgestimmt). Arbeitsweise: ein Test nach dem anderen, Claude schreibt
den Testcode, Georg verifiziert vor Merge.

**Zeit-Mocking: `\core\clock`-DI (`mock_clock_with_frozen()`/`mock_clock_with_incrementing()`), nicht
`tool_mocktesttime`.**

Ursprünglich wurde vorgeschlagen, `tool_mocktesttime` (`admin/tool/mocktesttime`, wie in
`local_taskflow` verwendet) zu nutzen. Code-Analyse ergab jedoch, dass `tool_mocktesttime` bei unserem
Refactor-Code **wirkungslos wäre**:

- `tool_mocktesttime::time_mock::init()` scannt beim Start alle PHP-Namespaces und definiert dort je
  eine lokale `time()`-Funktion (PHP löst unqualifizierte `time()`-Aufrufe zuerst im aktuellen
  Namespace auf). Das trifft ausschließlich **bare `time()`-Aufrufe**.
- Der komplette Waitlist-Refactor verwendet aber bewusst **kein bare `time()`** für
  terminierungsrelevante Entscheidungen, sondern durchgehend `\core\clock` per DI — genau das war eine
  der Kernentscheidungen aus dem Vorlauf zu diesem Refactoring (Testlauf-Befund: `tool_mocktesttime`
  und `\core\clock` sind zwei unsynchronisierte Uhren).
- Cores `system_clock::time()` (`lib/classes/system_clock.php`) ruft intern gar kein `time()` auf,
  sondern baut direkt `new \DateTimeImmutable("now", ...)` — dafür existiert konsequenterweise auch
  kein von `tool_mocktesttime` generiertes Override-File für den `core`-Namespace.
- `local_taskflow` selbst nutzt `\core\clock` nirgends (bestätigt per Suche) — dort ist die
  Inkompatibilität nie aufgefallen, weil sie dort nie relevant wurde.
- Ergebnis: `time_mock::set_mock_time()` hätte auf `progression`, `db_waitlist_offer_repository`,
  `expire_waitlist_offer_adhoc`, `waitlist_heartbeat_task` etc. **keinen Effekt** — diese lesen die Zeit
  ausschließlich über `\core\di::get(\core\clock::class)`. Tests damit würden je nach realer
  Wanduhrzeit beim Testlauf falsch grün oder falsch rot laufen.

**Entscheidung (Georg, 2026-08-21):** stattdessen Cores eigenen Mechanismus verwenden
(`lib/testing/classes/frozen_clock.php` / `incrementing_clock.php`, gebunden über
`$this->mock_clock_with_frozen()`/`mock_clock_with_incrementing()` in `advanced_testcase`) — das ist
der einzige Mechanismus, der `\core\di::set(\core\clock::class, ...)` tauscht und damit bei unserem
Code tatsächlich wirkt. Funktional identisch zu dem, was mit `tool_mocktesttime` erreicht werden
sollte (Zeit setzen/vorspulen innerhalb eines Tests), nur kompatibel mit der DI-Architektur dieses
Refactors. Bereits durchgängig so verwendet in `progression_test.php`,
`waitlist_heartbeat_task_test.php` und den `expire_waitlist_offer_adhoc`-Tests.

---

## Teil 1 — Manuelle End-to-End-Szenarien (alle 13 offen, echte Instanz nötig)

- [ ] **1. Grundfunktion:** gemischte Warteliste — kostenlose Person wird autobucht, kostenpflichtige bekommt Angebot.
- [ ] **2. K1:** mehrere freie Plätze → alle betroffenen Personen bekommen gleichzeitig ein Angebot, nicht nacheinander.
- [ ] **3. K4:** Frist verstreicht → automatisches Nachrücken der nächsten Person, keine manuelle Aktion nötig.
- [ ] **4. K7:** aktive Ablehnung → Person bleibt dauerhaft gesperrt, auch bei später erneut freiem Platz.
- [ ] **5. T4:** manuelles Unconfirm → sofortige Sperre + sofortiges Nachrücken der nächsten Person.
- [ ] **6. W1-W3 (höchste Priorität):** Freigabe-Modi 0/1/2 — geprüft werden muss, dass angebotene Personen tatsächlich buchen können (der in dieser Session gefundene kritische Bug).
- [ ] **7. K11:** zwei gleichzeitig aktive Regeln mit unterschiedlichem Betreff/Intervall — korrekte Zuordnung pro Angebot.
- [ ] **8. T7:** Heartbeat holt einen "verlorenen" Trigger nach (bis zu 15 Min Verzögerung).
- [ ] **9. Waitlist-Recycling:** aktiviert vs. deaktiviert — Verhalten nach vollständiger Sperrung der Liste.
- [ ] **10. K12:** Option komplett voll → Trigger ist absoluter No-Op.
- [ ] **11. P2:** fehlende Preiskategorie → wie Preis 0 behandelt, keine PHP-Warnungen.
- [ ] **12. Alt-Regel:** eine vor dem Update konfigurierte Regel funktioniert unverändert weiter.
- [ ] **13. Echte Migration:** nur beim eigentlichen Produktiv-Update testbar (Altbestand → `upgrade_step::run()`).

---

## Teil 2 — Behavior-Test-Szenarien (PHPUnit-Kandidaten, noch zu schreiben)

### Kategorie B — Regressionstests (höchste Priorität, Session-eigene Funde)

- [x] **B6:** Confirmation-Grant-Bug end-to-end über die echte Buchungs-UI verifizieren (bisher nur auf JSON-Flag-Ebene getestet). ✅ `tests/local/waitlist/b6_confirmation_grant_e2e_test.php` (98/98 Regression grün, wartet auf Review).
- [x] **B7:** dedizierter Test gegen den Rekursions-/Speicherüberlauf-Bug im Accept-Adapter. ✅ `tests/local/waitlist/b7_accept_adapter_no_recursion_test.php` (2 Tests: direkter Adapter-Test + echter 5-Kandidaten-K3-Batch über den realen Event-Chain; Bug testweise reintroduziert → Test schlägt korrekt fehl, dann zurückgesetzt; 100/100 Regression grün, wartet auf Review).
- [x] **B1 (mehrrundig):** K7-Sperre bleibt auch über mehrere spätere, unabhängige Runden hinweg bestehen (nicht nur die direkt nächste Runde). ✅ `tests/local/waitlist/b1_k7_lock_persists_across_rounds_test.php` (4 unabhängige Runden mit wechselnder Kapazität + neuem Kandidaten; Bug testweise reintroduziert → Test schlägt korrekt fehl, dann zurückgesetzt; 101/101 Regression grün, wartet auf Review).

### Kategorie A — Kernverhalten in Kombination

- [x] **A1:** gemischte Preise (kostenlos + kostenpflichtig) im selben K1-Batch-Durchlauf. ✅ `tests/local/waitlist/a1_mixed_price_batch_test.php` (Georgs eigenes Ausgangsbeispiel; 102/102 Regression grün, wartet auf Review).
- [x] **A2:** P2 (fehlende Preiskategorie) im Batch mit reibungslosen Preis-Nachbar-Kandidat:innen. ✅ `tests/local/waitlist/a2_p2_missing_price_category_batch_test.php` (106/106 Regression grün, wartet auf Review).
- [x] **A4:** K8-Skip (Person verlässt Liste mitten in der Verarbeitung) mitten im K1-Batch — Kapazität darf dabei nicht verbraucht werden. ✅ `tests/local/waitlist/a4_k8_skip_test.php` + `a4_leaves_mid_round_repository.php` (Repository-Decorator, da echte Nebenläufigkeit in einem PHPUnit-Prozess nicht simulierbar ist; Bug testweise reintroduziert → Test schlägt korrekt fehl, dann zurückgesetzt; 103/103 Regression grün, wartet auf Review).
- [x] **A5:** zwei Regeln mit unterschiedlicher Bedingung teilen sich einen gemeinsamen Kapazitäts-Pool (nicht doppelt verbraucht). ✅ `tests/local/waitlist/a5_shared_capacity_pool_across_rules_test.php` (107/107 Regression grün, wartet auf Review).

### Kategorie C — Verschachtelte Mischfälle

- [x] **C1:** Modus 0 (kein Auto-Grant) + unabhängige manuelle Freigabe von Person 2, während Person 1 noch wartet. ✅ `tests/local/waitlist/c1_manual_confirm_independence_test.php` (Georgs eigenes Drei-Personen-Beispiel; 104/104 Regression grün, wartet auf Review).
- [x] **C2:** K7 (Ablehnung) und K4-Recycling gleichzeitig auf derselben Warteliste — nur die K4-Person wird zurückgesetzt. ✅ `tests/local/waitlist/c2_mixed_k7_k4_recycling_test.php` (105/105 Regression grün, wartet auf Review).
- [x] **C3:** P1-Affiliationswechsel (Preis ändert sich live) mitten in einem Batch-Nachrücken. ✅ `tests/local/waitlist/c3_live_price_change_mid_batch_test.php` + `c3_mid_batch_affiliation_change_strategy.php` (Decision-Strategy-Decorator; 108/108 Regression grün, wartet auf Review).
- [x] **C5:** Regeländerung/-löschung (K9) gegen den *neuen* Mechanismus — bisher nur gegen die Alt-Engine (Kategorie A) getestet. ✅ `tests/local/waitlist/c5_rule_deleted_mid_flight_test.php` (109/109 Regression grün, wartet auf Review).
- [x] **C6:** Options-Löschung (K10) im laufenden Betrieb, nicht nur im Migrationsfall. ✅ `tests/local/waitlist/c6_option_deleted_live_test.php` (110/110 Regression grün, wartet auf Review).
- [x] **C7:** Doppel-Trigger (K5) mit mehreren gleichzeitig betroffenen Personen im selben Batch. ✅ `tests/local/waitlist/c7_double_trigger_multi_candidate_test.php` (111/111 Regression grün, wartet auf Review).

### Kategorie D — Confirmation-Feinheiten (komplett neu)

- [x] **D1:** Person mit früherem Direktbuchungs-Status landet erneut auf der Warteliste — korrektes Live-Verhalten, kein Rückfall auf alten Zustand. ✅ `tests/local/waitlist/d1_rejoin_after_previous_booking_test.php` (2 echte Funde zu bestehendem, nicht von uns verändertem `write_user_answer_to_db()`-Verhalten dokumentiert — json wird pro Schreibvorgang komplett neu aufgebaut statt gemergt, `confirmationcount` wird hochgezählt statt zurückgesetzt; 112/112 Regression grün, wartet auf Review).
- [x] **D2 (Negativ-Test):** Confirmation-Grant wird beim K3-Autobook-Pfad *nicht* fälschlich ausgelöst. ✅ `tests/local/waitlist/d2_no_confirmation_grant_on_autobook_test.php` (Differenztest, da naive JSON-Prüfung an einer echten Alt-Verhaltens-Überraschung scheiterte; Bug testweise reintroduziert → Test schlägt korrekt fehl, dann zurückgesetzt; 113/113 Regression grün, wartet auf Review).
- [x] **D3:** manuelles Unconfirm einer Person ohne existierendes Offer (Altbestand) — darf nicht crashen. ✅ `tests/local/waitlist/d3_unconfirm_without_existing_offer_test.php` (114/114 Regression grün, wartet auf Review).

### Kategorie E — Wartelisten-Recycling

- [x] **E1 (E2E-Variante):** "vollständig geflaggt"-Erkennung end-to-end über einen echten Heartbeat-Lauf (bisher nur auf Repository-Ebene getestet). ✅ `tests/local/waitlist/e1_heartbeat_recycling_e2e_test.php` (voll echte Kette: reconcile() → echter expire_waitlist_offer_adhoc-Task → echter waitlist_heartbeat_task, kein Schritt manuell erzwungen; 115/115 Regression grün, wartet auf Review).
- [x] **E2:** mehrere Personen (nicht nur eine) — Reihenfolge-Garantie nach dem Reset wirklich "wie zuvor". ✅ `tests/local/waitlist/e2_recycling_reset_order_multi_candidate_test.php` (116/116 Regression grün, wartet auf Review).

### Kategorie F — Migration + laufender Betrieb im Zusammenspiel

- [x] **F1:** Migration einer offenen Confirm-Freigabe (M2), unmittelbar gefolgt von einem neuen, regulären Trigger. ✅ `tests/booking_rules/waitlist_migration_f1_open_offer_then_new_trigger_test.php` (7/7 Migrations-Suite + 116/116 lokale Waitlist-Suite grün, wartet auf Review).
- [x] **F2:** Migration einer Mail-Kette mit *mehr als einem* Eintrag in `usersalreadytreated` (bisher nur mit einem Eintrag getestet). ✅ `tests/booking_rules/waitlist_migration_f2_multi_user_mail_chain_test.php` (14/14 Migrations-Suite grün, wartet auf Review).

---

**Status (2026-08-24): alle 20 Behavior-Test-Kandidaten aus Teil 2 sind geschrieben, einzeln
review-freigegeben und grün** (B6, B7, B1, A1, A2, A4, A5, C1, C2, C3, C5, C6, C7, D1, D2, D3, E1,
E2, F1, F2). Jeder Test wurde einzeln vorgelegt und von Georg freigegeben; mehrere Tests haben
dabei echte, dokumentierte Funde zu bestehendem (nicht refactor-eigenem) Verhalten zutage
gefördert (siehe D1, D2). Bei mehreren Tests wurde der jeweils geprüfte Fehlerfall testweise
reintroduziert, um zu verifizieren, dass der Test ihn wirklich erkennt (B1, B7, A4, D2), danach
sauber zurückgesetzt.

Offen bleibt laut `WAITLIST_REFACTOR_IMPLEMENTATION_PROGRESS_2026-08-12.md`: die 13 manuellen
E2E-Szenarien aus Teil 1 (Georgs eigene Aufgabe, echte Instanz nötig), sowie die separat
dokumentierten offenen Punkte `latejoiner_waitlist_adapter` (T5), A1/K7-Kategorie-A-Bereinigung
und Phase 4 (Nacharbeiten/Release-Notes).

---

## Teil 3 — Neues Feature (2026-08-24): Warteliste Typ 2 "offen nach Durchlauf"

Dritter Wert von `waitlistrecycling` (0 = einmalig, 1 = loop, **2 = offen nach Durchlauf**, neu).
Wenn die Warteliste einmal komplett durchgearbeitet wurde, ohne dass der frei gewordene Platz
beansprucht wurde, darf ab diesem Zeitpunkt **jede/r** (Warteliste oder nicht) den Platz direkt
buchen - außer K7-permanent Gesperrte (aktiv abgelehnt). K4-Gesperrte (Frist verstrichen) dürfen
in diesem Modus buchen. Sobald der Platz genommen ist, kehrt die Option automatisch in den
normalen Angebots-Modus zurück - neue Wartelisten-Beitritte danach werden wieder ganz normal per
Angebot (K1/K3/K4) behandelt, nicht rückwirkend die alte, bereits ausgeschiedene Kohorte.

**Architektur-Entscheidung:** Aktivierung UND Deaktivierung laufen ausschließlich über
`waitlist_heartbeat_task` (kein neuer Trigger-Pfad) - konsistent mit der bestehenden
T7-Selbstheilungs-Philosophie. Laufzeit-Flag `booking_options.waitlistopenmode` (0/1), gesetzt/
gelöscht von `db_waitlist_offer_repository::activate_open_mode()`/`deactivate_open_mode()`,
erkannt von `find_open_mode_activation_candidates()` (spiegelt `find_recyclable_options()`, nur
auf `waitlistrecycling=2` statt `=1` gefiltert) und `find_open_mode_options_to_deactivate()`
(spiegelt `find_stalled_options()`-Muster, filtert auf freie Kapazität <= 0). `progression::reconcile()`
bekommt dafür nur einen einzigen frühen Return am Anfang. Die eigentliche Buchen-Sperre wird in
`onwaitinglist::is_available()` umgangen (neuer Zweig zwischen der bestehenden
`waitforconfirmation`-Leerprüfung und der bestehenden `confirmationcount`-Prüfung).

Geänderte/neue Dateien: `db/install.xml`, `db/upgrade.php` (Savepoint 2026082401), `version.php`,
`classes/local/waitlist/waitlist_offer_repository.php` (+5 Interface-Methoden +
`is_actively_declined()`), `classes/local/waitlist/db_waitlist_offer_repository.php` (Implementierung),
`classes/local/waitlist/progression.php`, `classes/bo_availability/conditions/onwaitinglist.php`,
`classes/task/waitlist_heartbeat_task.php`, `classes/option/fields/waitlistrecycling.php`
(3. Dropdown-Option), `lang/en/booking.php` + `lang/de/booking.php`.

- [x] **G1 (Aktivierung + Gate):** Heartbeat aktiviert Open Mode für eine fully-flagged
  `waitlistrecycling=2`-Liste; echter `onwaitinglist::is_available()`-Gate öffnet sich für K4,
  bleibt zu für K7. ✅ `tests/local/waitlist/waitlist_openmode_heartbeat_activation_test.php`
  (echter Fund unterwegs: `is_permanently_declined()` unterscheidet nicht zwischen K7/K4 -
  dedizierte `is_actively_declined()`-Methode ergänzt; 117/117 Regression grün, freigegeben).

**Noch offen (Ideen für weitere Tests, noch nicht geschrieben):**
- [ ] **G2 (Deaktivierung):** sobald der offene Platz tatsächlich gebucht wird (freie Kapazität
  wieder 0), muss der nächste Heartbeat-Lauf `waitlistopenmode` zurücksetzen -
  `find_open_mode_options_to_deactivate()`/`deactivate_open_mode()`.
- [ ] **G3 (reconcile() pausiert):** solange Open Mode aktiv ist, darf `progression::reconcile()`
  keine neuen Angebote erzeugen, auch nicht bei einem neuen Trigger (z. B. ein Neuzugang auf der
  Warteliste).
- [ ] **G4 (frischer Kandidat nach Reset):** nach der Deaktivierung muss ein NEU beigetretener
  Kandidat ganz normal per Angebot behandelt werden - die alte, bereits ausgeschiedene Kohorte
  (K4/K7) wird dabei nicht erneut berücksichtigt (siehe Gespräch 2026-08-24).
- [ ] **G5 (Re-Aktivierung):** wenn die neue Kohorte aus G4 ihrerseits erschöpft, ohne den Platz zu
  nehmen, muss Open Mode erneut aktivieren - inklusive der alten K4-Personen aus der vorigen
  Kohorte, die weiterhin (nur) über Open Mode zugreifen dürfen, nicht über ein Angebot.
