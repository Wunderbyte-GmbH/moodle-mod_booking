# Agent Workflow

Dieses Diagramm beschreibt den geplanten Ablauf fuer mutierende Agent-Tasks mit selbststaendiger Fehlerkorrektur und iterativer Neuplanung (mehrere Replan-Zyklen mit erneuter Bestaetigung je Zyklus).

```mermaid
%%{init: {
    'theme': 'base',
    'themeVariables': {
        'fontFamily': 'Segoe UI, Arial, sans-serif',
        'fontSize': '16px',
        'textColor': '#111827',
        'primaryTextColor': '#111827',
        'lineColor': '#4b5563',
        'actorTextColor': '#111827',
        'labelTextColor': '#111827',
        'signalTextColor': '#111827',
        'noteTextColor': '#111827',
        'sequenceNumberColor': '#111827',
        'labelBoxBkgColor': '#ffffff',
        'labelBoxBorderColor': '#9ca3af',
        'activationBorderColor': '#6b7280',
        'activationBkgColor': '#f8fafc'
    }
}}%%
sequenceDiagram
    autonumber
    participant U as User
    participant UI as Chat UI
    participant ASM as ai_send_message
    participant O as Orchestrator+Interpreter
    participant CS as conversation_store
    participant ACR as ai_confirm_run
    participant EX as Executor
    participant PRE as Preflight/Validation
    participant RP as Retry Planner

    rect rgb(245, 249, 255)
        Note over U,UI: Initiale Planphase (blau)
        U->>UI: "Erstelle Buchungsoption mit Max Mustermann als Trainer"
        UI->>ASM: send_message(message)
        ASM->>O: process(thread, cmid, userid)
        O-->>ASM: confirmation_request + commands(create_option)
        ASM->>CS: set_pending_intent(commands)
        ASM-->>UI: Review proposed changes (Confirmation #1)
    end

    rect rgb(255, 249, 240)
        Note over U,PRE: Erste Ausfuehrung nach Confirmation (orange)
        U->>UI: Confirm & Execute
        UI->>ACR: confirm_run(thread, commands)
        ACR->>CS: consume_pending_intent()
        ACR->>EX: execute_commands(commands)
        EX->>PRE: validate + preflight
    end

    alt Ausfuehrung erfolgreich
        rect rgb(243, 251, 244)
            Note over EX,UI: Happy Path (gruen)
            EX-->>ACR: execution_result(success)
            ACR-->>UI: final completion message
        end
    else Fehler bei mutierendem Task
        rect rgb(255, 245, 246)
            Note over EX,RP: Fehleranalyse (rot)
            EX-->>ACR: execution_result(error details)
            ACR->>RP: plan_repair(original_commands, error)
        end

        alt Fehler ist auto-korrigierbar
            rect rgb(249, 243, 252)
                Note over RP,UI: Auto-Reparatur + erneute Confirmation (violett)
                RP-->>ACR: repaired_commands + summary
                ACR->>CS: set_pending_intent(repaired_commands)
                ACR-->>UI: confirmation_request (naechster Zyklus)

                U->>UI: Confirm & Execute (retry)
                UI->>ACR: confirm_run(thread, repaired_commands)
                ACR->>CS: consume_pending_intent()
                ACR->>EX: execute_commands(repaired_commands)
                EX-->>ACR: execution_result
                Note over ACR,RP: Bei neuem Fehler wird erneut plan_repair berechnet
                ACR-->>UI: completion ODER neuer confirmation_request
            end
        else Nicht auto-korrigierbar
            rect rgb(255, 252, 233)
                Note over RP,UI: Kein Auto-Fix moeglich (gelb)
                RP-->>ACR: no_repair + clarification
                ACR-->>UI: clarification/error with next action for user
            end
        end
    end
```

## Alternative Pfade (Flow-Ansicht)

```mermaid
%%{init: {
    'theme': 'base',
    'themeVariables': {
        'fontFamily': 'Segoe UI, Arial, sans-serif',
        'fontSize': '16px',
        'textColor': '#111827',
        'primaryTextColor': '#111827',
        'lineColor': '#4b5563'
    }
}}%%
flowchart TD
    A[User Request: Mutierende Aktion] --> B[Confirmation Request #1]
    B --> C{User bestaetigt?}
    C -->|Nein| C1[Abbruch / Warten auf neue Eingabe]
    C -->|Ja| D[Execute Commands]
    D --> E{Ausfuehrung erfolgreich?}
    E -->|Ja| F[Final Completion]
    E -->|Nein| G[Retry Planner analysiert Fehler]
    G --> H{Auto-korrigierbar?}
    H -->|Nein| I[Clarification / Error fuer User]
    H -->|Ja| J[Repaired Plan erzeugen]
    J --> K[Confirmation Request #2]
    K --> L{User bestaetigt Retry?}
    L -->|Nein| L1[Abbruch / Manueller Follow-up]
    L -->|Ja| M[Execute Repaired Commands]
    M --> N{Ausfuehrung erfolgreich?}
    N -->|Ja| O[Final Completion]
    N -->|Nein| P[Retry Planner berechnet neuen Workflow]
    P --> Q{Auto-korrigierbar?}
    Q -->|Ja| K
    Q -->|Nein| I

    classDef init fill:#f3f8ff,stroke:#7aa8d8,color:#111827;
    classDef decision fill:#fff9f2,stroke:#caa46a,color:#111827;
    classDef success fill:#f2fbf3,stroke:#78b87b,color:#111827;
    classDef retry fill:#f8f2fc,stroke:#b18bc8,color:#111827;
    classDef fail fill:#fff4f5,stroke:#d58f98,color:#111827;
    classDef stop fill:#fffdf0,stroke:#d5be72,color:#111827;

    class A,B init;
    class C,E,H,L decision;
    class F,O success;
    class G,J,K,M,P retry;
    class N,Q decision;
    class D fail;
    class C1,I,L1 stop;
```

Legende:

- Blau: initiale Planung und erste Confirmation
- Orange: Entscheidungsstellen
- Gruen: erfolgreicher Abschluss
- Violett: Auto-Reparatur und Retry-Pfad
- Rot: Fehler-/Ausfuehrungsknoten
- Gelb: Abbruch/kein Auto-Fix

## Workflow-Regeln

- Mutierende Aktionen laufen immer erst ueber `confirmation_request`.
- Bei Fehlern wird nur fuer whitelisted, deterministische Fehlerarten eine Auto-Reparatur versucht.
- Jeder geaenderte Plan benoetigt zwingend eine neue Bestaetigung durch den User.
- In der Reparaturphase werden keine schreibenden Tasks ohne erneute Confirmation ausgefuehrt.
- Der Workflow kann mehrfach neu berechnet werden, solange die Fehler als auto-korrigierbar klassifiziert sind und jeder neue Plan erneut bestaetigt wird.
- Terminierung erfolgt, wenn: Erfolg erreicht, Fehler nicht auto-korrigierbar, User nicht bestaetigt, oder Sicherheitsgrenzen verletzt werden (z.B. kein Fortschritt zwischen Zyklen, identischer Plan ohne Aenderung).

## Test-Strategie (verpflichtend)

Alle neuen Funktionen in diesem Ablauf muessen durch Tests abgedeckt werden:

- Unit-Tests fuer Retry-Entscheidungslogik (repairable vs non-repairable).
- Unit-Tests fuer Command-Patching (z.B. teacherquery -> teacheremail).
- Integrationstests fuer Confirmation #1 -> Fehler -> Confirmation #2 -> Retry mit mehreren aufeinanderfolgenden Replan-Zyklen.
- Negativtests fuer Tamper/Mismatch und fehlende pending intents.
- Regressionstests, dass nicht-korrigierbare Fehler weiterhin in clarification/error enden.
- Guardrail-Tests fuer Abbruch bei fehlendem Fortschritt (z.B. gleicher Fehler + unveraenderter Plan).
