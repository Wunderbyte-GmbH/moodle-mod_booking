# Waitlist-Progression — Architektur (Laufzeit-Ansicht, Stand 2026-08-21)

> Ergänzt 2026-09-11: Abschnitt [Die vier Wartelisten-Typen](#die-vier-wartelisten-typen-waitlistrecycling) inklusive des neuen Typs 4 „Bei Ablauf des Angebots von der Warteliste entfernen“.

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
        EXP["expire_waitlist_offer_adhoc<br/>Offer -> expired (K4/K7)<br/>Typ 4: von Warteliste entfernen"]
        HB["waitlist_heartbeat_task<br/>find_stalled_options()<br/>find_recyclable_options()<br/>find_expired_waiters_to_remove()<br/>find_open_mode_...()"]
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

## Die vier Wartelisten-Typen (`waitlistrecycling`)

Pro Buchungsoption einstellbar im Optionsformular unter **„Erweiterte Einstellungen“**, Feld
**„Warteliste nach vollständigem Durchlauf“** (`classes/option/fields/waitlistrecycling.php`,
gespeichert in `booking_options.waitlistrecycling`). Die Einstellung legt fest, was mit Personen
passiert, deren Angebot **verstrichen** ist (K4). Wer ein Angebot **aktiv ablehnt** (K7), bleibt bei
allen vier Typen dauerhaft gesperrt.

| Typ | Wert | Bezeichnung (de / en) | Greift wann | Wirkung |
|---|---|---|---|---|
| 1 | `0` (Default) | Stopp / Stop | nie | Verstrichene bleiben dauerhaft gesperrt auf der Warteliste. |
| 2 | `1` | Erneut durchgehen / Go through again | Heartbeat, sobald die Liste vollständig geflaggt ist | Ablauf-Sperren werden gelöscht, die Liste wird in der ursprünglichen Reihenfolge erneut angefragt. |
| 3 | `2` | Nach vollständigem Durchlauf öffnen / Open up after full pass-through | Heartbeat, sobald die Liste vollständig geflaggt ist | Der freie Platz ist direkt buchbar, für alle außer aktiv Ablehnende, bis jemand bucht. Danach arbeitet die Warteliste wieder normal. |
| 4 | `3` | Bei Ablauf des Angebots von der Warteliste entfernen / Remove from waiting list when offer expires | **sofort** beim einzelnen Ablauf (expire-Task); Altbestand beim nächsten Heartbeat | Die Person wird von der Warteliste entfernt, inklusive einer Warenkorb-Reservierung, ohne Storno-Event oder Storno-Mails. Die Sperre wird gelöscht, ein Neueintrag ist ein Neustart am Ende der Liste. |

**Vollständig geflaggt** heißt: Mindestens eine Person steht auf der Warteliste, niemand hat ein
offenes Angebot (`pending`/`offered`), und alle Wartenden haben eine Sperrzeile in
`booking_waitlist_declines`.

> **Benennung im Code:** Code-Kommentare zählen nicht einheitlich. „Typ 2 / Type 2 (open after full
> pass)“ meint den **Wert** `2` (in dieser Tabelle Typ 3), „Type 4“ meint den Wert `3`. Maßgeblich ist
> immer der Wert von `waitlistrecycling`.

### Ablauf, wenn ein Angebot verstreicht

Gilt für alle Typen. Nur Typ 4 greift hier sofort ein, die Typen 2 und 3 wirken erst über den
Heartbeat (nächster Abschnitt).

```mermaid
flowchart TB
    OFR["progression::offer()<br/>Offer: offered + expiresat<br/>plant expire_waitlist_offer_adhoc<br/>mit nextruntime = expiresat"]
    OFR -->|Frist erreicht| EXE

    subgraph EXPT["classes/task/expire_waitlist_offer_adhoc.php"]
        EXE["execute()"]
        K5{"K5: Offer noch offered?"}
        TRN["repository->transition(expired)<br/>schreibt Sperrzeile reason = 4<br/>in booking_waitlist_declines"]
        IS4{"waitlistrecycling = 3?"}
        RMV["booking_option::<br/>remove_from_waitinglist_after_offer_expiry()"]
        UNL["repository->remove_expired_lock()<br/>erst NACH dem Entfernen"]
        RCN["progression::reconcile('offer:expired')"]
    end

    EXE --> K5
    K5 -->|nein, schon accepted/declined/expired| NOP(("No-Op"))
    K5 -->|ja| TRN
    TRN --> IS4
    IS4 -->|ja, Typ 4| RMV
    RMV --> UNL
    UNL --> RCN
    IS4 -->|nein, Typ 1 bis 3| RCN
    RCN --> NXT["nächste Person bekommt das Angebot<br/>Gesperrte sind ausgeschlossen"]
```

Die Reihenfolge bei Typ 4 ist wichtig: Beim Entfernen einer Warenkorb-Reservierung läuft intern
`sync_waiting_list()` und damit womöglich schon ein `reconcile()`. In diesem Moment muss die
entfernte Person noch gesperrt sein, sonst würde sie sofort wieder angefragt.

### Heartbeat: was pro Typ periodisch passiert

```mermaid
flowchart TB
    CRN["Cron alle 5 Min<br/>db/tasks.php"] --> HBT["waitlist_heartbeat_task::execute()<br/>gedrosselt auf waitlistheartbeatinterval<br/>Default 15 Min, min. 5 Min"]

    HBT --> ST1["alle Typen:<br/>find_stalled_options()"]
    ST1 --> ST1R["reconcile('heartbeat')<br/>verlorene Trigger nachholen, T7"]

    HBT --> RC1["Typ 2, Wert 1:<br/>find_recyclable_options()"]
    RC1 --> RC1A["reset_expired_locks()<br/>löscht nur reason = 4"]
    RC1A --> RC1R["reconcile('waitlist:recycled')<br/>Liste erneut in Originalreihenfolge"]

    HBT --> BL4["Typ 4, Wert 3:<br/>find_expired_waiters_to_remove()<br/>Altbestand vor der Umstellung"]
    BL4 --> BL4A["booking_option::create_option_from_optionid()<br/>remove_from_waitinglist_after_offer_expiry()"]
    BL4A --> BL4B["remove_expired_lock()<br/>kein reconcile nötig"]

    HBT --> OP1["Typ 3, Wert 2:<br/>find_open_mode_activation_candidates()"]
    OP1 --> OP1A["activate_open_mode()<br/>booking_options.waitlistopenmode = 1"]

    HBT --> OP2["Typ 3, Wert 2:<br/>find_open_mode_options_to_deactivate()<br/>Platz wieder belegt"]
    OP2 --> OP2A["deactivate_open_mode()<br/>waitlistopenmode = 0"]
```

Solange der Offen-Modus aktiv ist, bricht `progression::reconcile()` sofort ab
(`is_open_mode_active()`), und `bo_availability/conditions/onwaitinglist.php` lässt die direkte
Buchung zu, außer für aktiv Ablehnende (`is_actively_declined()`).

### Weg einer wartenden Person, je Typ

```mermaid
flowchart LR
    JOIN["Eintrag auf Warteliste<br/>booking_answers.waitinglist = 1"] --> WAIT["wartet"]
    WAIT -->|reconcile: Platz frei und Regel passt| OFFR["hat Angebot<br/>offered"]
    OFFR -->|bezahlt oder bestätigt| BOOK["gebucht<br/>accepted"]
    OFFR -->|aktiv abgelehnt| DECL["gesperrt, K7<br/>reason = 3, für immer"]
    OFFR -->|Frist verstrichen| EXPD{"Wert von<br/>waitlistrecycling"}

    EXPD -->|0 Stopp| T1["bleibt gesperrt<br/>auf der Warteliste"]
    EXPD -->|1 Erneut durchgehen| T2["gesperrt, bis die Liste<br/>vollständig geflaggt ist"]
    T2 -->|Heartbeat: Sperre gelöscht| WAIT
    EXPD -->|2 Öffnen| T3["gesperrt, bis die Liste<br/>vollständig geflaggt ist"]
    T3 -->|Heartbeat: Offen-Modus| OPEN["Platz direkt buchbar<br/>für alle außer K7"]
    EXPD -->|3 Entfernen| T4["sofort entfernt<br/>Antwort gelöscht, Sperre gelöscht"]
    T4 -->|Neueintrag| JOIN
```

### Typ 4 im Detail: Aufrufe über die Klassen

```mermaid
sequenceDiagram
    autonumber
    participant EXP as expire_waitlist_offer_adhoc
    participant REP as db_waitlist_offer_repository
    participant BO as booking_option
    participant SC as local_shopping_cart shopping_cart
    participant SP as shopping_cart service_provider
    participant BA as booking_answers
    participant EV as bookinganswer_removedfromwaitinglist
    participant RUL as rules_info und rule_react_on_event
    participant PRG as progression

    EXP->>REP: transition(offer, expired) schreibt Sperre reason 4
    EXP->>BO: remove_from_waitinglist_after_offer_expiry(userid)
    opt Reservierung im Warenkorb vorhanden
        BO->>SC: delete_item_from_cart(mod_booking, option, optionid, userid)
        SC->>SP: unload_cartitem()
        SP->>BO: answer_booking_option NOTBOOKED ruft user_delete_response mit cancelreservation
        Note over BO: löscht die RESERVED-Antwort, kein Storno-Event
    end
    BO->>BA: delete_answer_record() für jede Wartelisten-Antwort
    BO->>BO: booking_history_insert(WAITINGLIST_DELETED)
    BO->>BO: purge_cache_for_answers()
    BO->>EV: trigger() mit objectid = optionid, relateduserid = userid
    EV-->>RUL: Regeln mit diesem Event, z. B. Mail an die Person
    EXP->>REP: remove_expired_lock(optionid, userid)
    EXP->>PRG: reconcile(optionid, offer:expired)
    PRG-->>EXP: nächste Person bekommt das Angebot
```

Bewusst **nicht** über `booking_option::user_delete_response()`: Das würde die Entfernung als Storno
behandeln. Es feuert `bookinganswer_cancelled`, verschickt die alten Storno-Mails
(`MSGPARAM_CANCELLED_BY_TEACHER_OR_SYSTEM`), führt die „cancel“-After-Actions aus und fasst
Einschreibung und Abschluss an. Nichts davon passt zu jemandem, der nie einen Platz hatte.

### Beteiligte Dateien und Klassen

```mermaid
flowchart LR
    subgraph UI["Einstellung und Anzeige"]
        FLD["option/fields/waitlistrecycling.php<br/>Dropdown 0 bis 3"]
        SET["booking_option_settings.php<br/>waitlistrecycling, waitlistopenmode"]
        LNG["lang/en, lang/de, lang/de_gs<br/>waitlistrecycling*, bookinganswerremovedfromwaitinglist*"]
    end

    subgraph TSK["classes/task/"]
        EXPF["expire_waitlist_offer_adhoc.php<br/>alle Typen, Typ 4 sofort"]
        HBF["waitlist_heartbeat_task.php<br/>Typ 2, 3 und 4-Altbestand"]
    end

    subgraph CORE["classes/local/waitlist/"]
        PRGF["progression.php<br/>reconcile, offer, autobook"]
        REPI["waitlist_offer_repository.php<br/>Interface"]
        REPF["db_waitlist_offer_repository.php<br/>Sperren, Offen-Modus, Abfragen"]
    end

    subgraph BOOK["Buchung"]
        BOF["booking_option.php<br/>remove_from_waitinglist_after_offer_expiry"]
        AVF["bo_availability/conditions/onwaitinglist.php<br/>Typ 3: Direktbuchung im Offen-Modus"]
        SPF["shopping_cart/service_provider.php<br/>unload_cartitem"]
    end

    subgraph EVT["Events und Regeln"]
        EVF["event/bookinganswer_removedfromwaitinglist.php"]
        RRE["booking_rules/rules/rule_react_on_event.php<br/>Event in allowedeventkeys"]
    end

    subgraph DB["Tabellen"]
        TBO[("booking_options<br/>waitlistrecycling, waitlistopenmode")]
        TOF[("booking_waitlist_offers<br/>Angebote und Status")]
        TDC[("booking_waitlist_declines<br/>Sperren, reason 3 oder 4")]
        TBA[("booking_answers")]
        TBH[("booking_history")]
    end

    FLD --> TBO
    SET -.liest.-> TBO
    EXPF --> REPF
    EXPF --> BOF
    EXPF --> PRGF
    HBF --> REPF
    HBF --> BOF
    HBF --> PRGF
    PRGF --> REPI
    REPF -. implementiert .-> REPI
    REPF --> TOF
    REPF --> TDC
    REPF --> TBO
    BOF --> SPF
    BOF --> TBA
    BOF --> TBH
    BOF --> EVF
    EVF -.-> RRE
    AVF -.fragt.-> REPF
```

### Tests

| Typ | Tests |
|---|---|
| 1 Stopp | `tests/task/expire_waitlist_offer_adhoc_test.php` (`test_stop_mode_keeps_the_expired_candidate_locked_on_the_list`, `test_execute_does_not_reoffer_the_sole_candidate_whose_own_offer_expired`), `tests/task/waitlist_heartbeat_task_test.php` (`test_execute_does_not_recycle_when_recycling_disabled`) |
| 2 Erneut durchgehen | `tests/task/waitlist_heartbeat_task_test.php` (`test_execute_recycles_a_fully_flagged_option_when_recycling_enabled`, `test_execute_never_recycles_an_actively_declined_candidate`), `tests/local/waitlist/e1_heartbeat_recycling_e2e_test.php`, `e2_recycling_reset_order_multi_candidate_test.php`, `c2_mixed_k7_k4_recycling_test.php` |
| 3 Öffnen | `tests/local/waitlist/waitlist_openmode_fresh_candidate_after_reset_test.php`, `tests/local/waitlist/waitlist_openmode_heartbeat_activation_test.php`, `tests/local/waitlist/waitlist_openmode_heartbeat_deactivation_test.php`, `tests/local/waitlist/waitlist_openmode_reconcile_noop_test.php` |
| 4 Entfernen | `tests/task/expire_waitlist_offer_adhoc_test.php` (5 × `test_type4_*`), `tests/task/waitlist_heartbeat_task_test.php` (`test_execute_removes_the_type4_backlog`, `test_execute_leaves_an_expired_waiter_alone_when_not_type4`), `tests/local/waitlist/db_waitlist_offer_repository_test.php` (`test_remove_expired_lock_*`, `test_find_expired_waiters_to_remove_scoping`) |

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
  pro Option einstellbare Einstellung `waitlistrecycling` sagt für *verstrichene* Angebote etwas
  anderes (Typ 2 erneut durchgehen, Typ 3 öffnen, Typ 4 entfernen - siehe
  [Die vier Wartelisten-Typen](#die-vier-wartelisten-typen-waitlistrecycling)).
- **`\core\clock`-DI überall**: keine bare `time()`-Aufrufe für terminierungsrelevante
  Entscheidungen, macht alles mit `mock_clock_with_frozen()` testbar.
- **Composition Root**: `progression_factory::get()` ist die einzige Stelle, die konkrete
  Implementierungen verdrahtet.
