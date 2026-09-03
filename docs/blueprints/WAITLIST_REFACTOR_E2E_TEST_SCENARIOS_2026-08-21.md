# Waitlist-Progression — End-to-End-Testszenarien (manuell, Phase 3)

**Stand:** 2026-08-21 · **Zweck:** Manuelle Verifikation des neuen Mechanismus (`progression`,
Trigger-Adapter, `waitlist_heartbeat_task`, `expire_waitlist_offer_adhoc`) auf einer echten
Moodle-Instanz, ergänzend zur automatisierten PHPUnit-Suite (siehe
`WAITLIST_REFACTOR_IMPLEMENTATION_PROGRESS_2026-08-12.md`). Referenzen (K1, K4, T7, ...) beziehen
sich auf `WAITLIST_REFACTOR_REQUIREMENTS_2026-08-04.md`.

Jedes Szenario: Setup → Aktion → erwartetes Ergebnis. Alle Szenarien setzen eine Buchungsoption mit
`maxanswers` klein genug (1-2) voraus, um schnell "voll" zu werden, plus eine aktive
`send_mail_interval`-Regel (`rule_react_on_event`, Bedingung "Immer" reicht für die meisten Fälle).

---

## 1. Grundfunktion — gemischte Warteliste (dein Beispiel)

**Setup:** Option mit 1 freiem Platz, bereits voll gebucht. 2 Preiskategorien: eine mit Preis 0
(oder eine Nutzergruppe ohne zugeordnete Preiskategorie), eine mit Preis > 0.
- Nutzer A (Preiskategorie kostenlos) auf Warteliste.
- Nutzer B (Preiskategorie kostenpflichtig) auf Warteliste, nach A beigetreten.

**Aktion:** Storniere die/den bereits gebuchte(n) Person, sodass 1 Platz frei wird.

**Erwartung:**
- Nutzer A wird **automatisch gebucht** (K3), keine Mail mit "Angebot", sondern eine normale
  Buchungsbestätigung.
- Falls noch ein zweiter Platz frei ist/wird: Nutzer B bekommt ein **Angebot** (K4) mit Frist,
  keine automatische Buchung.
- Falls nur 1 Platz frei war: Nutzer B bleibt unbehandelt auf der Warteliste (A hat den einzigen
  Platz bekommen).

## 2. K1 — Batch-Nachrücken bei mehreren freien Plätzen

**Setup:** Option mit 3 gebuchten Plätzen, 3 Personen auf der Warteliste (alle kostenpflichtig).

**Aktion:** Storniere alle 3 gebuchten Plätze auf einmal (oder in schneller Folge).

**Erwartung:** Alle 3 Wartelisten-Personen bekommen **gleichzeitig** ein Angebot (nicht
nacheinander im Intervall-Takt wie im alten System) — das ist der eigentliche Kernfix (K1/T8).

## 3. K4 — Harte Frist, automatisches Nachrücken

**Setup:** Option, 1 Platz frei, 2 kostenpflichtige Personen auf der Warteliste. Regel-Intervall
kurz stellen (z. B. 2 Minuten) für den Test.

**Aktion:** Erste Person lässt die Frist verstreichen (nicht buchen).

**Erwartung:** Nach Ablauf der Frist (`expire_waitlist_offer_adhoc`, per Cron) wird ihr Angebot
automatisch als abgelaufen markiert, **die zweite Person bekommt automatisch ein neues Angebot** —
ohne manuelles Eingreifen. Erste Person bleibt danach dauerhaft gesperrt für diese Option (K7).

## 4. K7 — Aktive Ablehnung sperrt dauerhaft

**Setup:** Option, 1 Platz frei, 2 Personen auf der Warteliste.

**Aktion:** Erste Person lehnt ihr Angebot aktiv ab (z. B. über die entsprechende UI-Aktion/Link).

**Erwartung:** Zweite Person bekommt sofort ein Angebot. Gib danach den Platz künstlich wieder frei
(z. B. zweite Person storniert auch) — **die erste (abgelehnte) Person darf nie wieder ein Angebot
für diese Option bekommen**, auch nicht in einer späteren Runde.

## 5. T4 — Manuelles Unconfirm

**Setup:** Option mit `waitforconfirmation` aktiv, eine Person hat bereits ein akzeptiertes/
freigegebenes Angebot.

**Aktion:** Admin/Lehrende:r entzieht die Freigabe manuell (Unconfirm-Aktion in der
Teilnehmer:innen-Verwaltung).

**Erwartung:** Die Person wird wie bei K7 dauerhaft gesperrt (Offer→declined), **und die nächste
Person auf der Warteliste bekommt sofort ein neues Angebot** — ohne auf den nächsten Heartbeat
warten zu müssen.

## 6. Freigabe-Modi (W1-W3) — der reparierte Bug aus dieser Session

**Setup:** Option mit `waitforconfirmation=1`, `confirmationonnotification=1` ("für alle"),
2+ kostenpflichtige Personen auf der Warteliste, 2+ Plätze frei.

**Aktion:** Gib beide Plätze gleichzeitig frei.

**Erwartung:** **Beide** Personen bekommen ein Angebot **und können tatsächlich buchen** (die
Buchen-Schaltfläche ist nicht mehr blockiert). Das ist der kritische Bug, der in dieser Session
gefunden und behoben wurde (`progression::grant_confirmation_if_required()`) — unbedingt gezielt
testen, da hier vorher ein stiller Totalausfall der Confirmation-Funktion drohte.

**Variante:** dieselbe Situation mit `confirmationonnotification=0` ("keine automatische
Freigabe") — hier dürfen die Personen trotz Angebots-Mail **nicht** automatisch buchen können;
die Freigabe muss weiterhin manuell über die Teilnehmer:innen-Verwaltung erfolgen.

**Variante:** manuell die Freigabe für die zweite Person setzen, **bevor** die erste Person
reagiert hat (über die bestehende manuelle UI) — muss unabhängig funktionieren, wie von dir
gefordert.

## 7. K11 — Mehrere gleichzeitige Regeln pro Instanz

**Setup:** Eine Buchungsoption, zwei aktive `send_mail_interval`-Regeln mit unterschiedlichem
Intervall/unterschiedlicher Bedingung (z. B. eine "Immer", eine "Warteliste voll").

**Aktion:** Löse den Trigger aus (Platz wird frei).

**Erwartung:** Beide Regeln werden korrekt ausgewertet, Angebote gehen mit dem jeweils richtigen
Betreff/Template der zutreffenden Regel raus, keine Vermischung.

## 8. T7 — Heartbeat-Selbstheilung

**Setup:** Option mit freiem Platz und wartender Person, aber **kein** Trigger ausgelöst (z. B.
Platz wurde über einen Weg frei, der nicht durch die Trigger-Adapter läuft, oder simuliere durch
kurzzeitiges Deaktivieren von Cron).

**Aktion:** Warte auf den nächsten Heartbeat-Lauf (Standard: alle 15 Minuten, Mindestabstand 5
Minuten, einstellbar unter Standort-Administration → Plugins → Aktivitäten → Booking).

**Erwartung:** Die Option wird automatisch nachgeholt reconciled, die wartende Person bekommt ihr
Angebot, ohne dass jemand manuell eingegriffen hat.

## 9. Warteliste-Recycling (neues Feature dieser Session)

**Setup:** Option mit `waitlistrecycling` aktiviert ("Erneut durchgehen"), 1 Person auf der
Warteliste.

**Aktion:** Person lässt ihr Angebot verstreichen (K4-Ablauf) — sie ist jetzt gesperrt, aber
niemand sonst wartet mehr.

**Erwartung:** Beim nächsten Heartbeat-Lauf wird die Sperre zurückgesetzt, die Person bekommt
**erneut** ein Angebot für denselben Platz.

**Variante:** dieselbe Situation mit `waitlistrecycling` deaktiviert (Standard) — die Person bleibt
dauerhaft gesperrt, auch nach beliebig vielen Heartbeat-Läufen.

## 10. K12 — Harter Stopp bei voller Kapazität

**Setup:** Option komplett voll (keine freien Plätze), 1+ Person(en) auf der Warteliste.

**Aktion:** Löse den `bookingoption_freetobookagain`-Trigger künstlich aus (z. B. über eine
irrelevante Änderung, die den Event feuert), ohne dass tatsächlich ein Platz frei wurde.

**Erwartung:** Nichts passiert — keine Angebote, keine Mails, absoluter No-Op.

## 11. P2 — Fehlende Preiskategorie

**Setup:** Person auf der Warteliste, deren Profil keiner konfigurierten Preiskategorie zugeordnet
werden kann (und kein Fallback konfiguriert).

**Aktion:** Platz wird frei.

**Erwartung:** Person wird wie bei Preis 0 automatisch gebucht (K3-Pfad), **keine PHP-Warnungen**
im Log/Debug-Modus.

## 12. Bereits vor dem Umstieg bestehende Regel

**Setup:** Eine `send_mail_interval`-Regel, die **vor** diesem Update konfiguriert wurde (falls auf
einer Instanz mit Altbestand getestet — sonst einfach eine normale, aktuelle Regel verwenden).

**Aktion:** Normaler Trigger (Storno).

**Erwartung:** Die Regel funktioniert unverändert — exakter Betreff/Text aus der
Regel-Konfiguration, Intervall wird korrekt als Angebots-Frist übernommen.

## 13. Migration von echtem Altbestand (nur relevant beim eigentlichen Produktiv-Update)

**Setup:** Eine Instanz mit noch laufenden Alt-Ketten-Tasks (Mail-Intervall oder Confirm) im
`task_adhoc`, **vor** dem Deployment dieses Updates.

**Aktion:** Deploy durchführen (löst `upgrade_step::run()` aus).

**Erwartung:** Bereits behandelte Personen bleiben behandelt, noch nicht behandelte werden vom
neuen Mechanismus übernommen (ggf. sofort mehrere gleichzeitig, K1). Keine doppelten Mails, keine
verlorenen Personen. **Nur einmal testbar** (beim echten Produktiv-Update) — auf einer Kopie der
Produktivdatenbank vorab simulieren, falls möglich.

---

## Priorisierung, falls Zeit knapp ist

Am wichtigsten zuerst: **6** (der reparierte kritische Bug), **1**/**2** (Kernverhalten), **3**/**4**
(Fristablauf + Sperre), **8** (Heartbeat). Die restlichen sind wichtig, aber weniger risikobehaftet.
