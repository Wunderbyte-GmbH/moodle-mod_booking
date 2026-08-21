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
- [ ] **A2:** P2 (fehlende Preiskategorie) im Batch mit reibungslosen Preis-Nachbar-Kandidat:innen.
- [x] **A4:** K8-Skip (Person verlässt Liste mitten in der Verarbeitung) mitten im K1-Batch — Kapazität darf dabei nicht verbraucht werden. ✅ `tests/local/waitlist/a4_k8_skip_test.php` + `a4_leaves_mid_round_repository.php` (Repository-Decorator, da echte Nebenläufigkeit in einem PHPUnit-Prozess nicht simulierbar ist; Bug testweise reintroduziert → Test schlägt korrekt fehl, dann zurückgesetzt; 103/103 Regression grün, wartet auf Review).
- [ ] **A5:** zwei Regeln mit unterschiedlicher Bedingung teilen sich einen gemeinsamen Kapazitäts-Pool (nicht doppelt verbraucht).

### Kategorie C — Verschachtelte Mischfälle

- [x] **C1:** Modus 0 (kein Auto-Grant) + unabhängige manuelle Freigabe von Person 2, während Person 1 noch wartet. ✅ `tests/local/waitlist/c1_manual_confirm_independence_test.php` (Georgs eigenes Drei-Personen-Beispiel; 104/104 Regression grün, wartet auf Review).
- [x] **C2:** K7 (Ablehnung) und K4-Recycling gleichzeitig auf derselben Warteliste — nur die K4-Person wird zurückgesetzt. ✅ `tests/local/waitlist/c2_mixed_k7_k4_recycling_test.php` (105/105 Regression grün, wartet auf Review).
- [ ] **C3:** P1-Affiliationswechsel (Preis ändert sich live) mitten in einem Batch-Nachrücken.
- [ ] **C5:** Regeländerung/-löschung (K9) gegen den *neuen* Mechanismus — bisher nur gegen die Alt-Engine (Kategorie A) getestet.
- [ ] **C6:** Options-Löschung (K10) im laufenden Betrieb, nicht nur im Migrationsfall.
- [ ] **C7:** Doppel-Trigger (K5) mit mehreren gleichzeitig betroffenen Personen im selben Batch.

### Kategorie D — Confirmation-Feinheiten (komplett neu)

- [ ] **D1:** Person mit früherem Direktbuchungs-Status landet erneut auf der Warteliste — korrektes Live-Verhalten, kein Rückfall auf alten Zustand.
- [ ] **D2 (Negativ-Test):** Confirmation-Grant wird beim K3-Autobook-Pfad *nicht* fälschlich ausgelöst.
- [ ] **D3:** manuelles Unconfirm einer Person ohne existierendes Offer (Altbestand) — darf nicht crashen.

### Kategorie E — Wartelisten-Recycling

- [ ] **E1 (E2E-Variante):** "vollständig geflaggt"-Erkennung end-to-end über einen echten Heartbeat-Lauf (bisher nur auf Repository-Ebene getestet).
- [ ] **E2:** mehrere Personen (nicht nur eine) — Reihenfolge-Garantie nach dem Reset wirklich "wie zuvor".

### Kategorie F — Migration + laufender Betrieb im Zusammenspiel

- [ ] **F1:** Migration einer offenen Confirm-Freigabe (M2), unmittelbar gefolgt von einem neuen, regulären Trigger.
- [ ] **F2:** Migration einer Mail-Kette mit *mehr als einem* Eintrag in `usersalreadytreated` (bisher nur mit einem Eintrag getestet).

---

**Vorschlag Reihenfolge:** B6, B7 zuerst (höchstes Risiko) → A1, A4, C1, C2 (realistischste Mischfälle) → Rest nach Kapazität. Sag Bescheid, mit welchem ich anfangen soll.
