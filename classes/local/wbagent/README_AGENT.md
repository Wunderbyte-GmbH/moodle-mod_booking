# WBAgent Workflow Uebersicht (mod_booking)

Diese Uebersicht zeigt den Weg von der Eingabe im Textfeld bis zur Ausgabe im Chat.

## End-to-End Flow

```mermaid
flowchart TD
    A[User tippt Nachricht im Textfeld] --> B[AMD mod_booking/aiinstructions.js sendMessage]
    B --> C[WS mod_booking_ai_send_message]
    C --> D[authorization_service: context + capability + sesskey]
    D --> E[conversation_store: user message speichern]
    E --> F[orchestrator.process]

    F --> G[core_ai manager + generate_text]
    G --> H[LLM Antwort roh]
    H --> I[interpreter]
    I --> I1[JSON parse + response_type check]
    I --> I2[Command schema + task validate]
    I --> I3[Semantik normalisieren z.B. self user Token]

    I3 --> J{Antworttyp}
    J -->|clarification| K[assistant message speichern]
    J -->|error| K
    J -->|task_call / confirmation_request| L{nur read-only tasks?}

    L -->|ja| M[executor.execute_commands sofort]
    L -->|nein| N[pending intent + confirm panel]

    N --> O[WS mod_booking_ai_confirm_run]
    O --> P{aiexecutionmode}
    P -->|direct| M
    P -->|adhoc| Q[queue execute_ai_run_adhoc]
    Q --> M

    M --> R[task_registry -> task_provider -> task->execute]
    R --> S[booking_task_support / services / DB updates]
    S --> T[run status + results speichern]
    T --> U[assistant result message speichern]

    U --> V[UI zeigt Antwortblase]
    N --> W[WS mod_booking_ai_render_command_preview]
    W --> V
    O --> X[WS mod_booking_ai_poll_run_status]
    X --> V
    V --> Y[WS mod_booking_ai_poll_thread fuer Historie]
```

## Wie fuehrt der Agent Aufgaben aus?

1. Das Frontend ruft mod_booking_ai_send_message auf.
2. Der Orchestrator baut den Prompt aus Systemprompt + letzter Thread-Historie.
3. Das Modell wird ueber core_ai generate_text angesprochen.
4. Der Interpreter ist die Trust-Boundary:
   - validiert JSON + response_type
   - validiert task/input gegen Registry
   - stoppt bei Ambiguitaet (clarification)
   - normalisiert Eingaben (z.B. self-reference)
5. Bei reinen Read-only-Commands wird sofort ausgefuehrt.
6. Bei mutierenden Commands kommt confirmation_request; Ausfuehrung erst nach Confirm.
7. Die Ausfuehrung passiert im Executor ueber task_registry auf konkrete Tasks.
8. Tasks delegieren Fachlogik (z.B. booking_task_support / mutation services), schreiben Resultate, und das UI pollt den Status.

## Welche Webservices werden verwendet?

### Vom Chat-UI genutzte mod_booking Webservice-Funktionen

- mod_booking_ai_send_message
- mod_booking_ai_confirm_run
- mod_booking_ai_poll_thread
- mod_booking_ai_poll_run_status
- mod_booking_ai_render_command_preview
- mod_booking_ai_list_candidate_options

### Innerhalb der Agent-Logik genutzte Services/Endpoints

- core_ai generate_text (ueber core_ai manager) fuer die Modellanfrage
- mod_booking\\external\\search_users::execute (in-process aufgerufen)
- mod_booking\\external\\search_courses::execute (in-process aufgerufen)

Wichtig: search_users/search_courses werden hier nicht als separater HTTP Call vom Browser aus benutzt, sondern serverseitig als wiederverwendete externe Klassenlogik aufgerufen.
