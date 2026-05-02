# Agent Guide: Neue Tasks in booking/tasks

Ziel dieser Datei:
- Praktische Anleitung fuer Agenten/Entwickler, wie neue Tasks sauber hinzugefuegt werden.
- Klare Guardrail: Agent-Framework nicht anfassen, solange es nicht explizit angefordert ist.

## Harte Guardrails

1. Standardfall: Nur Task-Klassen und Task-nahe Tests aendern.
2. Nicht aendern ohne expliziten Auftrag:
   - external/ai_send_message.php
   - external/ai_confirm_run.php
   - external/ai_poll_run_status.php
   - amd/src/aiinstructions.js
   - local/wbagent/orchestrator.php
   - local/wbagent/interpreter.php
   - local/wbagent/task_registry.php
   - local/wbagent/booking/support/execution_repair_service.php
   - Prompt-Framework-Dateien unter local/wbagent/prompts/
3. Trigger- und Intent-Logik NICHT ueber sprachabhaengige Textlisten im Framework loesen.
4. Domainenregeln gehoeren in Task-Klassen (validate/issues/schema), nicht in globale Flow-Hacks.

## Wann ist eine neue Task noetig?

Neue Task anlegen, wenn mindestens einer der Punkte zutrifft:
- Neuer fachlicher Use Case mit eigener Validierung/Ausfuehrung.
- Bestehende Task wird unuebersichtlich wegen stark abweichender Regeln.
- Eigene Sicherheits-/Bestaetigungslogik (issues/remedy_options) wird benoetigt.

Keine neue Task anlegen, wenn:
- Es nur ein kleiner Parameter im bestehenden Task-Schema ist.
- Es nur Prompt-Formulierung betrifft, aber keine neue Fachlogik.

## Wann MUSS ausnahmsweise auch der Workflow geaendert werden?

Wenn eine Aenderung den bestaetigungsbasierten Lauf nach execute betrifft, reichen reine Task-Aenderungen nicht aus.
Typische Beispiele:
- Zweite Confirmation nach Ausfuehrungsfehler fehlt.
- Repair-Plan soll erzeugt oder abgeschaltet werden.
- Follow-up Confirmation wird im UI nicht angezeigt.

Dann muessen die drei Ebenen konsistent sein:
1. Producer: external/ai_confirm_run.php (setzt pending intent nach Repair).
2. Transport: external/ai_poll_run_status.php (liefert followup*-Felder).
3. Consumer: amd/src/aiinstructions.js (zeigt showConfirmPanel fuer Follow-up).

Hinweis: issue_codes bleiben task-nahe und in ai_send_message relevant, steuern aber die zweite Confirmation nach Execute-Fehler nicht alleine.

## Schritt-fuer-Schritt: Neue Task hinzufuegen

1. Datei in booking/tasks anlegen, z. B. my_new_task.php.
2. Klasse erstellen:
   - extends base_booking_task
   - im Konstruktor read-only korrekt setzen (true/false)
3. Pflichtmethoden implementieren:
   - get_name
   - get_schema
   - validate
   - execute
4. Validation zuerst domain-sicher machen:
   - fehlende Pflichtfelder
   - Ambiguitaeten
   - issues mit code/severity/user_question/remedy_options
5. Execute schlank halten:
   - Fachlogik an Support-/Service-Klassen delegieren
   - konsistente Result-Formate liefern
6. Bei Bedarf Kontext-Guidance ergaenzen:
   - get_contextual_prompt_packs
7. Bei Bedarf Task-Trigger ergaenzen:
   - task_trigger_provider_interface implementieren
   - get_message_triggers liefern

## Trigger-Regeln fuer Tasks

Task-spezifische Trigger sind sinnvoll, wenn mindestens einer der Punkte zutrifft:
- Mehrere klare Follow-up-Zweige (z. B. duplicate bestaetigen vs. abbrechen).
- Sicherheitskritische Mutation (z. B. bulk update fuer alle).
- Kontextaufloesung mit wiederkehrenden Schritten (z. B. preview-basierte Auswahl).

Task-spezifische Trigger sind meist NICHT noetig bei:
- reinen read-only Listen/Introspection ohne Branching.
- einfachen Such-Tasks ohne riskante Seiteneffekte.

Namenskonvention:
- Prefix immer booking.
- Klar und aktionsorientiert, z. B. booking.bulk_update_apply_to_all_confirmed.
- Keine Sprache im Triggernamen.

## Validation Pattern (empfohlen)

- Nutze strukturierte issues statt freiem Text-Flow:
  - code: stabile maschinenlesbare Kennung
  - severity: needs_confirmation oder needs_clarification
  - user_question: kurze, konkrete Rueckfrage
  - remedy_options: klare naechste Optionen
- Nutze override-Tokens nur explizit und nachvollziehbar.

## Zweistufige Bedingungspruefung (Soft-Override-Pattern)

Manche Buchungsbedingungen sind nur fuer den buchenden Nutzer selbst bindend ("soft blockers"),
koennen aber von einem Admin/Manager im Namen des Nutzers uebergangen werden.
Typische Beispiele: `selectuser` (Option nur fuer ausgewaehlte Nutzer buchbar).

### Unterschied hard vs. soft blocker

`bo_info::get_condition_results($optionid, $userid, $onlyhardblock)`:
- `false` (zweites Argument) = alle Blocker (soft + hard)
- `true`                    = nur echte Hard-Blocker (`hard_block()` der Bedingungsklasse muss `true` liefern)

Selectuser z.B. liefert `hard_block() = false` → erscheint nur im Soft-Scan.

### Ablauf in book_users_task

1. validate() laeuft mit `$onlyhardblock=false` → alle Blocker.
2. Gibt es Blocker: validate() laeuft erneut mit `$onlyhardblock=true` → nur Hard-Blocker.
3. Ergebnis:
   - Keine Blocker (Schritt 1) → direkt ausloesen.
   - Nur Soft-Blocker (Schritt 1 hat Blocker, Schritt 2 nicht) → Issue `SOFT_BOOKING_OVERRIDE_CONFIRM_REQUIRED` mit `severity=needs_confirmation`.
   - Hard-Blocker vorhanden → Fehler, keine Ausfuehrung.

### Issue Code und Confirmation Flow

Issue code: `SOFT_BOOKING_OVERRIDE_CONFIRM_REQUIRED`

Dieser Code ist in `interpreter.php::CONFIRMABLE_ISSUE_CODES` registriert.
Der Interpreter:
1. Erkennt den Code als confirmable → erzeugt `confirmation_request` mit pending intent.
2. Injiziert automatisch `confirmed=true` in das gespeicherte Command (zweiter Durchlauf soll nicht erneut fragen).
3. Bevorzugt den Validator-Text des Tasks als Bestaetigungsnachricht (statt generischem LLM-Text).

Ergebnis: Der Nutzer sieht die konkrete Warnung (z.B. "Option nur fuer ausgewaehlte Nutzer"), bestaetigt,
und der naechste Durchlauf (confirmed=true) fuehrt direkt aus.

### Wenn ein neuer Issue Code dieses Musters benoetigt wird

1. In der Task-validate()-Methode: Issue mit `code=MEIN_CODE`, `severity=needs_confirmation` zurueckgeben.
2. In `interpreter.php`: Code zu `CONFIRMABLE_ISSUE_CODES` hinzufuegen.
3. Falls das zugehoerige Input-Feld gesetzt werden muss (z.B. `confirmed=true`): Im `validate_commands()`-Block
   nach `if ($code === 'MEIN_CODE')` die Injektion ergaenzen (analog zu `SOFT_BOOKING_OVERRIDE_CONFIRM_REQUIRED`).
4. Test: Bestaetigungsflow-Test ergaenzen (siehe Abschnitt Test-Checkliste).

### booking_task_support::book_users_for_option

`booking_task_support::book_users_for_option(int $optionid, array $userids, array $meta): array`

Oeffentliche statische Methode fuer den zweistufigen Bookit-Ablauf.
Wird von `book_users_task::execute()` aufgerufen.
Der interne Pre-Check filtert Confirmation-Flow-Bedingungen (id ≤ 1) heraus und
blockt nur bei echten Hard-Blockern (id > 1).

## Test-Checkliste

Mindestens diese Tests ergaenzen/aktualisieren:
1. Task-Validation Test fuer neue Regeln/Issues.
2. Interpreter/Flow-Test, falls neue confirmable issue codes entstehen.
3. Trigger-Registry-Test, falls get_message_triggers hinzugefuegt wurde.

Empfohlene lokale Checks:
- php -l auf geaenderten Dateien
- fokussierte phpunit-Laeufe fuer betroffene Tests

## Definition of Done

Eine Task-Erweiterung ist fertig, wenn:
- Schema, Validation, Execute konsistent sind.
- Keine sprachabhaengigen Trigger-Textlisten im Framework noetig sind.
- Tests die neuen Regeln und Trigger abdecken.
- Aenderungen auf Task-Ebene bleiben (ausser explizit anders beauftragt).
- Bei Repair-/Follow-up-Features sind Producer, Transport und Consumer gemeinsam getestet.
