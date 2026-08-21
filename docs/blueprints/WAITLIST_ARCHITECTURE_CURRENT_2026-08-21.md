# Waitlist-Progression — Architektur (Laufzeit-Ansicht, Stand 2026-08-21)

Dieses Dokument zeigt, **wie das System heute tatsächlich arbeitet**, nach Abschluss von Phase 2
und dem größten Teil von Phase 3 (Clean-Cut-Switchover). Im Unterschied zum
Implementierungs-Fortschrittsgraphen (`WAITLIST_REFACTOR_IMPLEMENTATION_PROGRESS_2026-08-12.md`,
der den Bauzustand trackt) ist dies eine reine Laufzeit-/Verhaltensbeschreibung.

## Gesamtüberblick

```mermaid
flowchart TB
    subgraph Triggers["Auslöser (Business-Events)"]
        T1["Storno / maxanswers erhöht /<br/>Kampagne / generisch<br/>(alle 4 Alt-Stellen)"]
        T4["Manuelles Unconfirm"]
        T7["Zahlung/Buchung abgeschlossen"]
        T8["Heartbeat (alle 15 Min, min. 5 Min)"]
        T9["Offer-Frist abgelaufen"]
    end

    subgraph Adapters["mod_booking/classes/event/observer/"]
        FBA["freetobookagain_waitlist_adapter"]
        UCA["unconfirm_waitlist_adapter<br/>Offer -> declined (K7)"]
        BAA["booking_accepted_waitlist_adapter<br/>Offer -> accepted"]
    end

    subgraph TaskAdapters["mod_booking/classes/task/"]
        EXP["expire_waitlist_offer_adhoc<br/>Offer -> expired (K4/K7)"]
        HB["waitlist_heartbeat_task<br/>find_stalled_options()<br/>find_recyclable_options()"]
    end

    T1 --> FBA
    T4 --> UCA
    T7 --> BAA
    T9 --> EXP
    T8 --> HB

    FBA --> REC
    UCA --> REC
    BAA -.->|kein reconcile-Aufruf,<br/>vermeidet Rekursion| DONE1(("fertig"))
    EXP --> REC
    HB --> REC

    subgraph Core["progression::reconcile() - einziger Schreibpfad"]
        REC["reconcile(optionid, reason)"]
        K12{"K12: freie Plätze > 0?"}
        K11{"K11: passende Regel(n)?"}
        LOOP["pro Kandidat (O1/O2-Reihenfolge,<br/>K1: bis Kapazität erschöpft)"]
        DECIDE{"Preis = 0?"}
        AUTOBOOK["autobook()<br/>user_submit_response()<br/>Offer: autobooked"]
        OFFER["offer()<br/>Offer: offered + Frist<br/>+ Freigabe falls nötig (W1-W3)<br/>+ expire-Task planen"]

        REC --> K12
        K12 -->|nein| STOP1(("Ende, No-Op"))
        K12 -->|ja| K11
        K11 -->|keine| STOP2(("Ende, No-Op"))
        K11 -->|ja| LOOP
        LOOP --> DECIDE
        DECIDE -->|ja, K3| AUTOBOOK
        DECIDE -->|nein, K4| OFFER
    end

    REC -.liest.-> REPO[("db_waitlist_offer_repository<br/>booking_waitlist_offers<br/>booking_waitlist_declines")]
    REC -.liest.-> COND["rule_condition_checker<br/>K11: Regel-Bedingung erfuellt?"]
    REC -.liest.-> CAP["capacity_calculator<br/>K2: frei = max - gebucht - offen"]
    AUTOBOOK -.nutzt.-> DEC["price_based_decision_strategy<br/>K3/K4/P1/P2"]
    OFFER -.sendet ueber.-> MSG["moodle_messaging_gateway<br/>message_controller"]

    style Core fill:#f5f5f5,stroke:#333
```

## Migration (einmalig beim Produktiv-Update)

```mermaid
flowchart LR
    UP["db/upgrade.php<br/>Versionssprung"] --> US["upgrade_step::run()"]
    US -->|Inventur| TA[("task_adhoc<br/>Alt-Ketten-Tasks")]
    US -->|pro erkannte Zeile| RD1["legacy_chain_reader_send_mail_interval<br/>M1: Mail-Kette"]
    US -->|pro erkannte Zeile| RD2["legacy_chain_reader_confirm_bookinganswer<br/>M2: offene Confirm-Freigabe"]
    RD1 -->|legacy_chain_state| RC["Rekonstruktion:<br/>offered-Zeile + expire-Task<br/>(keine Mail - reine Historie)"]
    RD2 -->|legacy_chain_state| RC
    RC --> REPO2[("booking_waitlist_offers")]
    US -->|Bereinigung| DEL["alle Alt-Task-Zeilen loeschen<br/>(macht run() idempotent)"]
```

## Was bewusst NICHT mehr existiert (Phase 3, Legacy-Entfernung)

| Alt-Mechanismus | Status | Ersetzt durch |
|---|---|---|
| `send_mail_interval::execute()` (Ketten-Logik) | No-op, Klasse bleibt als Konfigurationsquelle | `progression::offer()` |
| `confirm_bookinganswer` / `confirm_bookinganswer_by_rule_adhoc` | No-op, Klassen bleiben für Alt-Regeln/verwaiste Tasks ladbar | `progression::grant_confirmation_if_required()` |
| `repeat`-Zweig in `send_mail_by_rule_adhoc` | entfernt | (nicht mehr nötig, kein Ketten-Neustart mehr) |
| Companion-Rules-Mechanik (`rules_info.php`) | entfernt | `waitlist_heartbeat_task` (T7, bis zu 15 Min statt sofort - siehe unten) |

**Offener Punkt:** die Companion-Rules-Mechanik war zugleich der einzige T5-Trigger-Pfad (später
Wartelisten-Beitritt bei zufällig schon freier Kapazität). Ohne dedizierten
`latejoiner_waitlist_adapter` fängt diesen Randfall aktuell nur noch der Heartbeat ab (Verzögerung
statt sofortiger Reaktion) - siehe `WAITLIST_REFACTOR_IMPLEMENTATION_PROGRESS_2026-08-12.md` für
den Stand dieser Entscheidung.

**Bleibt dauerhaft bestehen:** der `timemodified`-Einfrier-Sonderfall in
`booking_option::write_user_answer_to_db()`. Final entschieden (2026-08-21) - kein O3-Test
existiert, und der Sonderfall wird nicht nur von Alt-Code gebraucht, sondern auch vom eigenen
neuen `db_waitlist_offer_repository::get_unbehandelte_waitinglist()` (Runden-Reihenfolge via
`MIN(timemodified)`) sowie von der weiterhin aktiven, unabhängigen `sync_waiting_list()`-Logik,
`manageusers_table.php`s Rang-Anzeige/manuellem Umsortieren und `select_student_in_bo.php`s
"wer ist als Nächstes"-Regel-Bedingung. Details:
`WAITLIST_REFACTOR_IMPLEMENTATION_PROGRESS_2026-08-12.md`.

## Kernprinzipien (unverändert seit Phase 2)

- **Ein einziger Schreibpfad**: `progression::reconcile()` ist die einzige Stelle, die
  Wartelisten-Entscheidungen trifft und in `booking_waitlist_offers` schreibt.
- **Kapazitätsgesteuert, nicht zeitgesteuert** (K1/T8): so viele Kandidaten wie Plätze frei sind,
  nicht eine Person pro Intervall-Tick.
- **Permanente Sperre** (K7): einmal abgelehnt oder verstrichen, nie wieder angeboten - außer die
  neue, pro Option einstellbare Recycling-Option ist aktiv (`waitlistrecycling`).
- **`\core\clock`-DI überall**: keine bare `time()`-Aufrufe für terminierungsrelevante
  Entscheidungen, macht alles mit `mock_clock_with_frozen()` testbar.
- **Composition Root**: `progression_factory::get()` ist die einzige Stelle, die konkrete
  Implementierungen verdrahtet.
