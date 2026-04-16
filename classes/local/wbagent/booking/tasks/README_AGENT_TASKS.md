# Agent Guide: Neue Tasks in booking/tasks

Ziel dieser Datei:
- Praktische Anleitung fuer Agenten/Entwickler, wie neue Tasks sauber hinzugefuegt werden.
- Klare Guardrail: Agent-Framework nicht anfassen, solange es nicht explizit angefordert ist.

## Harte Guardrails

1. Standardfall: Nur Task-Klassen und Task-nahe Tests aendern.
2. Nicht aendern ohne expliziten Auftrag:
   - external/ai_send_message.php
   - local/wbagent/orchestrator.php
   - local/wbagent/interpreter.php
   - local/wbagent/task_registry.php
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
