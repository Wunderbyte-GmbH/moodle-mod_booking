<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Strings for the German gender-fair variant 'de_gs' (Deutsch mit Genderslash).
 *
 * GENERATED FILE - do not edit by hand. It is derived from lang/de/booking.php (gender forms
 * rewritten to slash spelling, e.g. "Nutzer:innen" -> "Nutzer/innen"). Child language of 'de';
 * only differing strings are listed here, everything else is inherited.
 *
 * Regenerate with: php mod/booking/cli/generate_de_gs_lang.php
 * A unit test (mod_booking\local\genderslash_test) fails if this file is out of date.
 *
 * @package     mod_booking
 * @category    string
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activitycompletionsuccess'] = 'Alle Nutzer/innen wurden für den Aktivitätsabschluss ausgewählt';
$string['addtocalendardesc'] = 'Kurs-Kalenderevents können von ALLEN Kursteilnehmer/innen des Kurses gesehen werden. Falls Sie nicht möchten, dass Kurs-Kalenderevents
erstellt werden, können Sie diese Einstellung standardmäßig ausschalten und sperren. Keine Sorge: Normale Kalenderevents für gebuchte Optionen (User-Events) werden weiterhin erstellt.';
$string['addtogroup'] = 'Nutzer/innen automatisch in Gruppe des verknüpften Kurses einschreiben';
$string['addtogroup_help'] = 'Nutzer/innen automatisch in Gruppe des in der Buchungsoption verknüpften Kurses eintragen. Die Gruppe wird nach folgendem Schema automatisch erstellt: Aktivitätsname - Name der Buchungsoption';
$string['agent_booking_booked_users_label'] = 'Gebuchte Nutzer/innen';
$string['agent_booking_diagnose_cancel_other_user_permission_denied'] = 'Sie dürfen keine Stornodiagnose für andere Nutzer/innen ausführen.';
$string['agent_booking_diagnose_other_user_permission_denied'] = 'Sie dürfen keine Buchungsdiagnose für andere Nutzer/innen ausführen.';
$string['agent_booking_diagnose_reason_maxperuser_exceeded'] = 'Die maximale Anzahl an Buchungen pro Nutzer/in wurde erreicht ({$a} Buchungen erlaubt).';
$string['agent_booking_diagnose_reason_maxperuser_exceeded_other'] = 'Die ausgewählte Person hat die maximale Anzahl an Buchungen pro Nutzer/in erreicht ({$a} Buchungen erlaubt).';
$string['agent_booking_diagnose_reason_option_invisible'] = 'Diese Buchungsoption ist auf unsichtbar gestellt und für normale Nutzer/innen nicht sichtbar.';
$string['agent_booking_diagnose_reason_option_invisible_other'] = 'Die ausgewählte Buchungsoption ist auf unsichtbar gestellt und für normale Nutzer/innen nicht sichtbar.';
$string['agent_booking_resolve_user_ambiguous'] = 'Mehrere Nutzer/innen wurden gefunden: {$a}. Bitte geben Sie eine spezifischere Nutzerabfrage an (z. B. mit E-Mail oder Nutzer-ID).';
$string['ai_property_bookuserscompleted'] = 'Nutzer/innen buchen: als abgeschlossen markieren';
$string['ai_property_bookuserstimebooked'] = 'Nutzer/innen buchen: Buchungszeit';
$string['ai_property_bookusersupdateexisting'] = 'Nutzer/innen buchen: bestehende Buchungen aktualisieren';
$string['ai_property_selectusers'] = 'Bedingung ausgewählte Nutzer/innen';
$string['allbookingoptions'] = 'Nutzer/innen für alle Buchungsoptionen herunterladen';
$string['allcompetenciesmustbefound'] = 'Nutzer/in muss all diese Kompetenzen haben';
$string['allmoodleusers'] = 'Alle Nutzer/innen dieser Website';
$string['allowoverbookingheader_desc'] = 'Berechtigten Nutzer/innen erlauben, Kurse zu überbuchen.
 (Achtung: Dies kann zu unerwünschtem Verhalten führen. Nur aktivieren, wenn wirklich benötigt.)';
$string['allteachers'] = 'Alle Trainer/innen';
$string['allteacherspagebookinginstances'] = 'Auf der "Alle Trainer/innen"-Seite nur Trainer/innen aus den folgenden Buchungsintanzen anzeigen. (Wählen Sie "Keine Auswahl", um ALLE Trainer/innen anzuzeigen.)';
$string['allusersbooked'] = 'Alle {$a} Nutzer/innen wurden erfolgreich für diese Buchungsoption gebucht.';
$string['approvalsettings_desc'] = 'Booking unterstützt verschiedene Bestätigungsprozesse, wenn Nutzer/innen sich ihre Buchungen bestätigen lassen müssen. Im Standardprozess können Trainer/innen die Anfragen über die Warteliste bestätigen. Andere Prozesse können über Bookingextension Subplugins nachgeladen werden.';
$string['assignteachers'] = 'Lehrer/innen zuweisen:';
$string['autoenrol'] = 'Nutzer/innen automatisch in verknüpften Kurs einschreiben';
$string['autoenrol_help'] = 'Falls ausgewählt werden Nutzer/innen automatisch in den Kurs eingeschrieben sobald sie die Buchung durchgeführt haben und wieder ausgetragen, wenn die Buchung storniert wird.';
$string['availabilityconditionsstatecolumn_help'] = 'Wählen Sie aus, wie sich jede Verfügbarkeitsbedingung verhalten soll:<br><br>
<strong>Standard</strong>: Die Bedingung verhält sich normal. Sie wird während des Buchungsprozesses geprüft und kann von berechtigten Nutzer/innen im Optionsformular bearbeitet werden.<br><br>
<strong>Nur einfrieren</strong>: Die Bedingung wird weiterhin geprüft, aber ihre Formularfelder werden gesperrt (bzw. für Nutzer/innen ohne Berechtigung ausgeblendet). Das ist sinnvoll, wenn die Regel fest vorgegeben sein soll, aber weiterhin vollständig geprüft werden muss.<br><br>
<strong>Überspringen und einfrieren</strong>: Die Bedingung wird während des Buchungsprozesses nicht geprüft und ihre Formularfelder werden gesperrt (bzw. für Nutzer/innen ohne Berechtigung ausgeblendet). Das kann die Performance verbessern, jedoch schützt die übersprungene Regel dann nicht mehr.';
$string['banusernames'] = 'Nutzer/innennamen ausschließen';
$string['bocondallowedtobookininstanceanyways'] = 'Benutzer/innen dürfen auch ohne die Berechtigung \'<b>mod/booking:choose</b>\' buchen.<br>
<div class=\'text-danger\'>Hinweis: Sowohl dieses als auch das darüberliegende Kästchen müssen angehakt sein, um dies zu aktivieren.</div>';
$string['bocondalreadybookedfullavailable'] = 'Nutzer/in hat noch nicht gebucht';
$string['bocondcustomuserprofilefieldfullnotavailable'] = 'Nur Benutzer/innen, bei denen das benutzerdefinierte Profilfeld
 {$a->profilefield} auf den Wert {$a->value} gesetzt ist, dürfen buchen.<br>Sie haben aber das Recht dennoch zu buchen.';
$string['bocondenrolledincohorts'] = 'Benutzer/in ist in bestimmte(n) globale(n) Gruppe(n) eingeschrieben';
$string['bocondenrolledincohortsfullnotavailable'] = 'Nur Benutzer/innen, die in mindestens eine der folgenden globalen Grupppen eingeschrieben sind, dürfen buchen: {$a}
    <br>Sie haben aber das Recht dennoch zu buchen.';
$string['bocondenrolledincohortsfullnotavailableand'] = 'Nur Benutzer/innen, die in alle folgenden globalen Grupppen eingeschrieben sind, dürfen buchen: {$a}
<br>Sie haben aber das Recht dennoch zu buchen.';
$string['bocondenrolledincourse'] = 'Benutzer/in ist in bestimmte(n) Kurs(e) eingeschrieben';
$string['bocondenrolledincoursefullnotavailable'] = 'Nur Benutzer/innen, die in mindestens einen der folgenden Kurs(e) eingeschrieben sind, dürfen buchen: {$a}
    <br>Sie haben aber das Recht dennoch zu buchen.';
$string['bocondenrolledincoursefullnotavailableand'] = 'Nur Benutzer/innen, die in alle folgenden Kurs(e) eingeschrieben sind, dürfen buchen: {$a}
<br>Sie haben aber das Recht dennoch zu buchen.';
$string['bocondhascompetency'] = 'Benutzer/in hat bestimmte Kompetenzen';
$string['bocondhascompetencyfullnotavailable'] = 'Nur Benutzer/innen, die mind. eine der folgenden Kompetenzen haben, dürfen buchen: {$a}
    <br>Sie haben aber das Recht dennoch zu buchen.';
$string['bocondhascompetencyfullnotavailableand'] = 'Nur Benutzer/innen, die alle folgenden Kompetenzen haben, dürfen buchen: {$a}
<br>Sie haben aber das Recht dennoch zu buchen.';
$string['bocondmaxnumberofbookings'] = 'max_number_of_bookings: Maximum an Nutzer/innen erreicht, die dieser User buchen darf';
$string['bocondmaxnumberofbookingsfullnotavailable'] = 'Nutzer/in hat die max. Buchungsanzahl erreicht';
$string['bocondonnotifylistfullnotavailable'] = 'Ausgebucht - Nutzer/in ist auf der Benachrichtigungliste';
$string['bocondonwaitinglistfullnotavailable'] = 'Nutzer/in ist auf der Warteliste';
$string['bocondpreviouslybooked'] = 'Benutzer/in hat früher eine bestimmte Option gebucht';
$string['bocondpreviouslybookedfullnotavailable'] = 'Nur Benutzer/innen, die früher bereits <a href="{$a->url}">{$a->title}</a> gebucht haben, dürfen buchen.
 <br>Sie haben aber das Recht dennoch zu buchen.';
$string['bocondpreviouslybookednotavailable'] = 'Nur Benutzer/innen, die früher bereits <a href="{$a->url}">{$a->title}</a> gebucht haben, dürfen buchen.';
$string['bocondselectusers'] = 'Nur bestimmte Benutzer/in(nen) dürfen buchen';
$string['bocondselectusersfullnotavailable'] = 'Nur die folgenden Nutzer/innen können buchen:<br>{$a}';
$string['bocondselectusersrestrict'] = 'Nur bestimmte Benutzer/in(nen) dürfen buchen';
$string['bocondselectusersuserids'] = 'Benutzer/in(nen), die buchen dürfen';
$string['boconduserprofilefieldfullnotavailable'] = 'Nur Benutzer/innen, bei denen das Profilfeld
 {$a->profilefield} auf den Wert {$a->value} gesetzt ist, dürfen buchen.<br>Sie haben aber das Recht dennoch zu buchen.';
$string['bookallstudents'] = 'Alle Teilnehmer/innen buchen';
$string['bookanyoneswitchoff'] = '<i class="fa fa-user-times" aria-hidden="true"></i> Buchen von Nutzer/innen, die nicht eingeschrieben sind, nicht erlauben (empfohlen)';
$string['bookanyoneswitchon'] = '<i class="fa fa-user-plus" aria-hidden="true"></i> Buchen von Nutzer/innen, die nicht eingeschrieben sind, erlauben';
$string['bookanyonewarning'] = 'Achtung: Sie können nun beliebige Nutzer/innen buchen. Verwenden Sie diese Einstellung nur, wenn Sie genau wissen, was Sie tun.
 Das Buchen von Nutzer/innen, die nicht in den Kurs eingeschrieben sind, kann möglicherweise zu Problemen führen.';
$string['bookedteachersshowemails'] = 'E-Mail-Adressen von Trainer/innen, bei denen gebucht wurde, anzeigen';
$string['bookedteachersshowemails_desc'] = 'Wenn Sie diese Einstellung aktivieren, dann werden bereits gebuchten Benutzer/innen
die E-Mail-Adressen ihrer Trainer/innen angezeigt.';
$string['bookedusers'] = 'Gebuchte Nutzer/innen';
$string['booking:addeditownoption'] = 'Eigene Buchungsoptionen bearbeiten (eigene Buchungsoptionen sind solche,
die man entweder selbst angelegt hat oder bei denen man als Trainer/in zugewiesen ist)';
$string['booking:bookallstudents'] = 'Alle eingeschriebenen Teilnehmer/innen in eine Option buchen';
$string['booking:bookanyone'] = 'Darf alle Nutzer/innen buchen';
$string['booking:communicate'] = 'Kann kommunizieren (z.B. Nachrichten an gebuchte Nutzer/innen schicken)';
$string['booking:duplicateanycourse'] = 'Beliebigen Kurs als Duplizierungsvorlage auswählen (auch Kurse, auf die der/die Nutzer/in keinen Zugriff hat)';
$string['booking:managebookedusers'] = 'Buchungen von Nutzer/innen verwalten';
$string['booking:overrideboconditions'] = 'Nutzer/in darf buchen auch wenn Verfügbarkeit false zurückliefert.';
$string['booking:sendpollurltoteachers'] = 'Umfragelink and Trainer/innen senden';
$string['booking:skill_mod_booking_book_users'] = 'KI-Skill: Nutzer/innen in eine Option einbuchen';
$string['booking:skill_mod_booking_update_option_trainer'] = 'KI-Skill: Trainer/in einer Buchungsoption aktualisieren';
$string['booking:subscribeusers'] = 'Für andere Teilnehmer/innen Buchungen durchführen';
$string['bookinganswercancelled'] = 'Buchungsoption von/für Nutzer/in storniert';
$string['bookinganswerwaitingforconfirmationdesc'] = 'Nutzer/in mit id {$a->relateduserid} hat sich für die Buchungsoption mit ID {$a->objectid} vorangemeldet.';
$string['bookingdebugmode_desc'] = 'Der Booking-Debug-Modus sollte nur von Entwickler/innen aktiviert werden.';
$string['bookingfulldidntregister'] = 'Es wurden nicht alle Nutzer/innen übertragen, da die Option bereits ausgebucht ist!';
$string['bookingmanagererror'] = 'Der angegebene Nutzername ist ungültig. Entweder existiert der/die Nutzer/in nicht oder es gibt mehrere Nutzer/innen mit dem selben Nutzernamen (Dies ist zum Beispiel der Fall, wenn Sie MNET und lokale Authentifizierung gleichzeitig aktiviert haben)';
$string['bookingoptionbookedotheruserdesc'] = 'Nutzer/in mit ID {$a->userid} hat Nutzer/in mit ID {$a->relateduserid} für die Buchung der Option Nr. {$a->objectid} gebucht.';
$string['bookingoptionbookedotheruserwaitinglistdesc'] = 'Nutzer/in mit ID {$a->userid} hat Nutzer/in mit ID {$a->relateduserid} für die Buchung der Option Nr. {$a->objectid} auf die Warteliste gebucht.';
$string['bookingoptionbookedsameuserdesc'] = 'Nutzer/in mit ID {$a->userid} hat die Buchung der Option Nr. {$a->objectid} gebucht.';
$string['bookingoptionbookedsameuserwaitinglistdesc'] = 'Nutzer/in mit ID {$a->userid} hat die Buchung der Option Nr. {$a->objectid} auf die Warteliste gebucht.';
$string['bookingoptionbookedviaautoenroldesc'] = 'Nutzer/in mit ID {$a->userid} wurde in die Buchungsoption Nr. {$a->objectid} via Einschreibelink angemeldet';
$string['bookingoptionconfirmed:description'] = 'Nutzer/in mit ID {$a->userid} hat Nutzer/in mit ID {$a->relateduserid} für die Buchung der Option Nr. {$a->objectid} freigeschaltet.';
$string['bookingoptiondenied:description'] = 'Nutzer/in mit ID {$a->userid} hat Nutzer/in mit ID {$a->relateduserid} für die Buchung der Option Nr. {$a->objectid} verweigert.';
$string['bookingoptionupdateddesc'] = 'Nutzer/in mit ID "{$a->userid}" hat Buchungsoption "{$a->objectid}" aktualisiert.';
$string['bookingplacesinfotextsinfo'] = 'Wählen Sie aus, wie die Platzverfügbarkeit für Nutzer/innen angezeigt werden soll.';
$string['bookingpollurlteachers'] = 'Link zur Trainer/innen-Umfrage';
$string['bookingstrackersetrating'] = 'Nutzer/innen bewerten';
$string['bookotherusers'] = 'Buchung für andere Nutzer/innen durchführen';
$string['bookotheruserslimit'] = 'Max. Anzahl an Buchungen, die ein/e der Buchungsoption zugewiesene/r Trainer/in vornehmen kann';
$string['booktootherbooking'] = 'Nutzer/innen umbuchen / zu anderer Buchungsoption hinzufügen';
$string['bookusers'] = 'Feld für den Import, um Nutzer/innen zu buchen';
$string['bookwithcreditsactive_desc'] = 'Nutzer/innen mit Guthaben/Credits sehen keinen Preis, sondern können mit ihren Credits buchen.';
$string['bookwithcreditsprofilefield_desc'] = 'Um die Funktion nutzen zu können, muss es ein Profilfeld geben, in dem die Credits der Nutzer/innen hiinterlegt werden können.
<span class=\'text-danger\'><b>Achtung:</b> Dieses Feld sollte von den Nutzer/innen nicht bearbeitet werden können.</span>';
$string['bstparticipants'] = 'Teilnehmer/innen';
$string['bstteacher'] = 'Trainer/in(nen)';
$string['cachedef_bookedusertable'] = 'Gebuchte Nutzer/innen-Tabelle (Cache)';
$string['cachedef_bookforuser'] = 'Für Nutzer/innen buchen (Cache)';
$string['cachedef_usercompetenciescache'] = 'Kompetenzen von Nutzer/innen (Cache)';
$string['cacheturnoffforbookinganswers'] = 'Caching der Antworten (der Buchungen durch Nutzer/innen) abschalten';
$string['caladdascourseevent'] = 'Zum Kalender hinzufügen (nur für Teilnehmer/innen des Moodle-Kurses sichtbar)';
$string['caladdassiteevent'] = 'Zum Kalender hinzufügen (für alle Nutzer/innen sichtbar)';
$string['cancancelbookallow'] = 'Teilnehmer/innen dürfen Buchungen selbst stornieren';
$string['cancancelbookdays'] = 'Nutzer/innen können nur bis n Tage vor Kursstart stornieren. Negative Werte meinen n Tage NACH Kursstart.';
$string['cancancelbookdays:bookingclosingtime'] = 'Nutzer/innen können nur bis n Tage vor <b>Anmeldeschluss (Buchungsende)</b> stornieren. Negative Werte meinen n Tage NACH Anmeldeschluss.';
$string['cancancelbookdays:bookingopeningtime'] = 'Nutzer/innen können nur bis n Tage vor <b>Anmeldebeginn (Buchungsbeginn)</b> stornieren. Negative Werte meinen n Tage NACH Anmeldebeginn.';
$string['cancancelbookdays:coursestarttime'] = 'Nutzer/innen können nur bis n Tage vor <b>Kursbeginn (Start der Buchungoption)</b> stornieren. Negative Werte meinen n Tage NACH Anmeldebeginn.';
$string['cancancelbookdays:semesterstart'] = 'Nutzer/innen können nur bis n Tage vor <b>Semesterbeginn</b> stornieren. Negative Werte meinen n Tage NACH Semesterbeginn.';
$string['cancelallusers'] = 'Alle gebuchten Teilnehmer/innen stornieren';
$string['canceldependenton_desc'] = 'Wählen Sie aus, auf welches Datumsfeld sich die Einstellung
"Nutzer/innen können nur bis n Tage vor Kursstart stornieren. Negative Werte meinen n Tage NACH Kursstart."
beziehen soll.<br>Dadurch wird auch die <i>Serviceperiode</i> von Kursen im Warenkorb entsprechend festgelegt
(wenn Shopping Cart installiert ist). Dies betrifft auch die Ratenzahlung. Entfernen Sie das ausgewählte Semester, wenn Sie Kursstart anstelle von Semesterstart nutzen möchten.';
$string['certificateissueddesc'] = 'Nutzer/in mit ID {$a->userid} hat Zertifikat (ID {$a->objectid}) an Nutzer/in mit ID {$a->relateduserid} ausgestellt.';
$string['certificaterequiresotheroptions_help'] = 'Wählen Sie hier zusätzliche Buchungsoptionen aus, die Nutzer/innen abschließen müssen, um das Zertifikat zu erhalten. Wenn keine Buchungsoption ausgewählt ist, wird das Zertifikat ausgestellt, sobald die Buchungsoption abgeschlossen ist.';
$string['chooseusers'] = 'Nutzer/innen auswählen';
$string['circumventavailabilityconditions_desc'] = 'Wenn diese Einstellung gesetzt ist, können Einschränkungen von Buchungsoptionen, die das Benutzerprofilfeld betreffen, umgangen werden.
    Wenn Nutzer/innen die "optionview.php" Seite einmalig mit den richtigen Parametern aufrufen, kann die Buchungsoption trotz dieser Einschränkungen für sie buchbar werden.
    Parameter sind <b>cvfield=userfeldkurzname_Gewuenschterwert</b> und optional <b>cvpwd=passwort</b>.
    Die Umgehung der Einschränkung ist buchungsinstanzspezifisch und gilt nur für jene Instanz, bei der als letztes die optionview mit dem "cvfield" aufgerufen wurde.';
$string['completionmodule_help'] = 'Button zum Löschen aller Buchungen anzeigen, wenn eine andere Kursaktivität abgeschlossen wurde. Die Buchungen von Nutzer/innen werden mit einem Klick auf einen Button auf der Berichtsseite gelöscht! Nur Aktivitäten mit aktiviertem Abschluss können aus der Liste ausgewählt werden.';
$string['completionoptioncompletedcminfo'] = 'In mind. {$a} Buchungsoptionen auf "Abgeschlossen" gesetzt werden (von Trainer/in, Kursersteller/in oder Manager/in).';
$string['conditionselectbookingmanager'] = 'Verwalter/in der Buchungen wählen.';
$string['conditionselectbookingmanager_desc'] = 'Verwalter/in der Buchungen wird in den Einstellungen der Buchungs Modul Instanz ausgewählt';
$string['conditionselectstudentinbo_desc'] = 'Nutzer/innen der von der Regel betroffenen Buchungsoption wählen.';
$string['conditionselectteacherinbo_desc'] = 'Trainer/innen der von der Regel betroffenen Buchungsoption wählen.';
$string['conditionselectuserfromevent_desc'] = 'Nutzer/in, die mit dem Ereignis in Verbindung steht wählen';
$string['conditionselectusershoppingcart_desc'] = 'Nutzer/in mit Zahlungsverpflichtung ist ausgewählt';
$string['conditionselectusersuserids'] = 'Wähle die gewünschten Nutzer/innen';
$string['confirmationonnotificationyesforall'] = 'Ja, für alle benachrichtigten Benutzer/innen';
$string['confirmbookinganswer'] = 'Buchungsantwort bestätigen, wenn die Benachrichtigung für Benutzer/innen aktiviert ist.';
$string['connectedbooking_help'] = 'Buchung von der Teilnehmer/innen übernommen werden. Es kann bestimmt werden wie viele Teilnehmer/innen übernommen werden.';
$string['consumeatonce_help'] = 'Die Nutzer/innen haben nur einen einzigen Buchungsschritt, bei dem alle Wahlfächer gebucht werden müssen.';
$string['containsinarray'] = 'Teilnehmer/in hat einen dieser Werte zumindest teilweise (Komma getrennt)';
$string['containsnotinarray'] = 'Teilnehmer/in keinen dieser Werte auch nur teilweise (Komma getrennt)';
$string['coolingoffperiod_desc'] = 'Um zu vermeiden, dass Nutzer/innen z.B. irrtümlich durch zu schnelles Klicken auf den Buchen-Button wieder stornieren, kann eine Cooling Off Period in Sekunden eingestellt werden. In dieser Zeit ist Stornieren nicht möglich. Nicht mehr als wenige Sekunden einstellen, die Wartezeit wird den User/innen nicht extra angezeigt.';
$string['createnewmoodlecoursefromtemplate_help'] = 'Vorlagen können nur verwendet werden, wenn sie das in den Einstellugnen definierte Tag haben und wenn die Nutzer/in folgende Rechte auf den Vorlagen-Kurs besitzt:
<br>
Am einfachsten ist es, in den Vorlagen-Kurs als Lehrende eingeschrieben zu sein.
<br>
moodle/course:view
moodle/backup:backupcourse
moodle/restore:restorecourse
moodle/question:add
';
$string['createnewmoodlecoursefromtemplatewithusers'] = 'Übernehme die Nutzer/innen des Vorlagenkurses in den neuen Kurs';
$string['customformselectoptions'] = '<div class="alert alert-info" role="alert">
    <i class="fa fa-info-circle"></i>
    <span><b>Werte für Auswahl können folgendermaßen angeben werden:</b> <br>
    key => Anzeigename <br>
    Details und weitere optionale Werte: <br>
    key (<i>Sollte keine Abstände oder Sonderzeichen enthalten</i>) => <br>
    Anzeigename (<i>Wird den Nutzer/innen angezeigt</i>) => <br>
    Maximalanzahl der Buchungen (<i>Gesamtverfügbarkeit für alle Nutzer/innen gemeinsam, wird Nutzer/innen angezeigt</i>) => <br>
    Preis (<i>Kann mit dem definierten Preiskategoriefeld modifiziert werden, wird Nutzer/innen angezeigt</i>) => <br>
    Erlaubte Nutzer/innen (<i>Userids von jeden Personen, denen diese Option zur Verfügung steht</i>) <br>
    <b>Beispiel:</b> <br>
    choose => Auswählen... <br>
    singleroom => Einzelzimmer => 10 => 100 => 1,2,3,4,5 <br>
    doubleroom => Doppelzimmer => 5 => student:100,expert:200,default:150 => 1,2,3,4,5
    </span>
    </div>';
$string['customuserprofilefield_help'] = 'Wenn Sie ein Benutzerdefiniertes User Profilfeld auswählen, ist der Preis-Teil der Kampagne nur für Nutzer/innen wirksam, die auch einen bestimmten Wert in einem bestimmten Profilfeld haben.';
$string['daystonotifyteachers'] = 'Wie viele Tage vor Kursbeginn soll an die Trainer/innen eine Benachrichtigung gesendet werden?';
$string['deductionnotpossible'] = 'Da alle Trainer/innen bei diesem Termin anwesend waren kann kein Abzug eingetragen werden.';
$string['definedteacherrole'] = 'Rolle für Trainer/innen einer Buchungsoption festlegen';
$string['definedteacherrole_desc'] = 'Wird ein/e Trainer/in einer Buchungsoption hinzugefügt, erhält sie im zugehörigen Kurs die ausgewählte Rolle.';
$string['definefieldofstudy'] = 'Sie können hier alle Buchungsoptionen aus dem gesamten Studienbereich anzeigen lassen. Damit dies funktioniert,
 verwenden Sie Gruppen mit dem Namen Ihres Studiengangs. Bei einem Kurs, der in "Psychologie" und "Philosophie" verwendet wird,
 haben Sie zwei Gruppen, die nach diesen Studiengängen benannt sind. Folgen Sie diesem Schema für alle Ihre Kurse.
 Fügen Sie nun das benutzerdefinierte Buchungsoptionsfeld mit dem Shortname "recommendedin" hinzu, in das Sie die kommagetrennten
 Shortcodes derjenigen Kurse, in denen eine Buchungsoption empfohlen werden soll, eintragen. Wenn ein/e Benutzer/in Teil der
 Gruppe "Philosophie" ist, werden ihm/ihr alle Buchungsoptionen aus Kursen angezeigt, in denen mindestens einer der "Philosophie"-Kurse empfohlen wird.';
$string['deletedusers'] = 'Gelöschte Nutzer/innen';
$string['deleteuserfrombooking'] = 'Buchung für Nutzer/innen wirklich stornieren?';
$string['deputiesalreadyset'] = 'Ihre aktuellen Stellvertreter/in(nen):';
$string['disablebookingusers'] = 'Buchung von Teilnehmer/innen deaktivieren - "Jetzt buchen" Button unsichtbar schalten';
$string['displayemptyprice_desc'] = 'Wenn eine Buchungsoption Preise für einige Preiskategorien hat und für andere nicht, können Sie entscheiden, ob Nutzer/innen, für die die Option kostenlos ist, den Preis 0 angezeigt bekommen oder ob der Preis komplett ausgeblendet wird.';
$string['dontaddpersonaleventsdesc'] = 'Für jede Buchung und alle Termine werden eigene Einträge im persönlichen Kalender der Teilnehmer/innen erstellt. Für eine bessere Performance auf sehr intensiv genutzten Seiten kann diese Funktion deaktiviert werden.';
$string['downloadusersforthisoptionods'] = 'Nutzer/innen im .ods-Format herunterladen';
$string['downloadusersforthisoptionxls'] = 'Nutzer/innen im  .xls-Format herunterladen';
$string['duplicatemoodlecourses_desc'] = 'Wenn diese Einstellung aktiviert ist, dann wird beim Duplizieren einer Buchungsoption
auch der verbundene Moodle-Kurs dupliziert (Achtung: Nutzer/innen-Daten des Moodle-Kurses werden nicht mit-dupliziert!).
Da das Duplizieren asynchron über einen Adhoc-Task gemacht wird, stellen Sie bitte sicher, dass der CRON-Task regelmäßig läuft.';
$string['duplicationrestoreteachers'] = 'Trainer/innen inkludieren';
$string['easyavailabilityselectusers'] = 'Einfache Nutzer/innen Voraussetzung';
$string['editteacherslink'] = 'Lehrer/innen bearbeiten';
$string['enablecompletionmincompleted'] = 'Mindestanzahl an Buchungsoptionen, in denen der/die Nutzer/in auf "Abgeschlossen" gesetzt werden muss';
$string['enablecompletionmincompleted_help'] = 'Ein/e Nutzer/in muss in mindestens so vielen Buchungsoptionen auf "Abgeschlossen" gesetzt werden, wie Sie hier angeben,
um die Buchungsaktivität (Buchungsinstanz) abzuschließen.
Um die Nutzer/innen als abgeschlossen markieren zu können, fügen Sie unter dem Punkt "Spalten und Felder anpassen" bei "Buchungen verwalten" das Feld "Abgeschlossen" hinzu.
Danach können die Optionen auf der Berichtsseite als abgeschlossen markiert werden. Das kann von Trainer/in, Kursersteller/in oder Manager/in durchgeführt werden.';
$string['enablefavoritestoggle_desc'] = 'Ermöglicht es Nutzer/innen, Buchungsoptionen als Favoriten zu markieren. Wenn aktiviert, erscheint bei jeder Buchungsoption ein Stern-Symbol, mit dem Nutzer/innen die Option zu ihrer persönlichen Favoritenliste hinzufügen oder daraus entfernen können. In den Einstellungen jeder Buchungsinstanz kann dann ein eigener Tab "Meine Favoriten" hinzugefügt werden.
<span class="text-danger">Bitte denken Sie daran, den Tab "Meine Favoriten" in den Einstellungen Ihrer Buchungsinstanzen hinzuzufügen, nachdem Sie diese Funktion aktiviert haben.</span>';
$string['enforceorder_help'] = 'Nutzer/innen werden erst nach Abschluss des vorangegangene Kurses in den nächsten Kurs eingeschrieben.';
$string['enrolledusers'] = 'In den Kurs eingeschriebene Nutzer/innen';
$string['enrolmentstatus'] = 'Nutzer/innen erst zu Kursbeginn in den Kurs einschreiben (Standard: Nicht angehakt &rarr; sofort einschreiben.)';
$string['enrolmentstatus_help'] = 'Achtung: Damit die automatische Einschreibung funktioniert,
müssen Sie in den Einstellungen der Buchungsinstanz "Nutzer/innen automatisch einschreiben" auf "Ja" setzen.';
$string['enrolmultipleusers'] = 'Mehrere Nutzer/innen einschreiben';
$string['enrolmultipleusersformmode'] = 'Verhalten des Formular-Elements "Mehrere Nutzer/innen einschreiben"';
$string['enrolmultipleusersformmode_desc'] = 'Setzen Sie das Verhalten des Formular-Elements "Mehrere Nutzer/innen einschreiben".
Sie finden dieses Element im Bearbeitungsformular von Buchungsoptionen unter "Verfügbarkeit einschränken" &gt; "Formular muss vor der Buchung ausgefüllt werden"
&gt; Element "Mehrere Nutzer/innen einschreiben"';
$string['enrolusersaction:alert'] = '<div class="alert alert-info" role="alert">
<i class="fa fa-info-circle" aria-hidden="true"></i>
<span>
Geben Sie unter <b>Wert</b> die Standard-Anzahl der Nutzer/innen ein, für die gebucht werden soll (kann von der buchenden Person geändert werden).
Diese Funktion bezieht sich auch auf den ausgewählten Kurs im Bereich Moodle Kurse.
</span>
</div>';
$string['enroluserstowaitinglist'] = 'Buchende Nutzer/innen auf die Warteliste setzen und erst nach Bestätigung einschreiben?';
$string['enteruserprofilefield'] = 'Wähle Nutzer/innen nach eingegebenem Wert für Profilfeld. Achtung! Das betrifft ALLE Nutzer/innen auf der Plattform.';
$string['error:installmentdatefieldcondition'] = 'Das Datumsfeld "Ratenzahlung" kann nur in Kombination mit der Bedingung "Wähle Nutzer/in, die Ratenzahlung zu leisten hat" gewählt werden.';
$string['error:invalidredirecturl'] = 'Die URL scheint ungültig zu sein. Bitte kontaktieren Sie eine/n Entwickler/in.';
$string['error:reasonfornoteacher'] = 'Geben Sie einen Grund an, warum an diesem Termin kein/e Trainer/in anwesend war.';
$string['error:wrongteacherid'] = 'Fehler: Für die angegebene "teacherid" wurde kein/e Nutzer/in gefunden.';
$string['eventdesc:bookinganswercancelled'] = 'Nutzer/in "{$a->user}" hat Nutzer/in "{$a->relateduser}" aus "{$a->title}" storniert.';
$string['eventdesc:bookinganswercancelledself'] = 'Nutzer/in "{$a->user}" hat "{$a->title}" storniert.';
$string['eventdesc:bookinganswercustomformconditionsdeleted'] = 'Nutzer/in "{$a->user}" hat die Daten zu Customform Bedingungen von {$a->relateduser} der Buchungsantwort mit ID "{$a->bookinganswerid}" gelöscht.';
$string['eventdesc:bookinganswerupdated'] = 'Nutzer/in "{$a->user}" hat bei "{$a->title}" Werte der Spalte "{$a->column}" geändert.';
$string['eventteacheradded'] = 'Trainer/in hinzugefügt';
$string['eventteacherremoved'] = 'Trainer/in entfernt';
$string['existingsubscribers'] = 'Vorhandene Nutzer/innen';
$string['feedbackurl_help'] = 'Link zu einem Feedback-Formular, das an Teilnehmer/innen gesendet werden soll.
 Verwenden Sie in E-Mails den Platzhalter <b>{pollurl}</b>.';
$string['feedbackurlteachers'] = 'Trainer/innen Umfragelink';
$string['feedbackurlteachers_help'] = 'Link zu einem Feedback-Formular, das an Trainer/innen gesendet werden soll.
Verwenden Sie in E-Mails den Platzhalter <b>{pollurlteachers}</b>.';
$string['fieldofstudycohortoptions'] = 'Shortcode um alle Buchungsoptionen eines Studiengangs anzuzeigen.
 Wird dadurch definiert, dass die Nutzer/innen in allen Kursen in die Gruppe mit dem gleichen Namen
 eingeschrieben sind. Buchungsoptionen werden über das \'recommendedin\' customfield zugeordnet.';
$string['globalnotifyemail'] = 'Teilnehmer/innen-Benachrichtigung vor dem Beginn (globale Vorlage) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['globalnotifyemailteachers'] = 'Trainer/innen-Benachrichtigung vor dem Beginn (globale Vorlage) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['globalpollurlteacherstext'] = 'Link zum Absender der Umfrage für Trainer/innen (globale Vorlage) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['groupiddisplay_help'] = '<i class="fa fa-lightbulb-o" aria-hidden="true"></i>&nbsp;Bei Buchung werden Nutzer/innen automatisch in diese Kurs-Gruppe eingeschrieben<span class="text-small"></span>';
$string['importteacheremail'] = 'E-Mail Adressen von Nutzerinnen auf der Plattform, die als Lehrer/innen in den Buchungsoptionen hinterlegt werden können. Bei mehreren e-mail Adressen Komma als Trennzeichen verwenden (aufpassen auf "Escape" bei Komma getrennten CSV!)';
$string['inarray'] = 'Teilnehmer/in hat einen dieser Werte (Komma getrennt)';
$string['includeteachers'] = 'Trainer/innen in Unterschriftenliste anführen';
$string['info:teachersforoptiondates'] = 'Wechseln Sie zum <a href="{$a}" target="_self">Trainingsjournal</a>, um die Trainer/innen für spezifische Termine zu protokollieren.';
$string['iselective_help'] = 'Damit können Nutzer/innen gezwungen werden, mehrere Buchungen auf einmal in einer
 bestimmten Reihenfolge und in gewissen Beziehungen zueinander vorzunehmen, außerdem kann der Verbrauch von Credits erzwungen werden.';
$string['isempty'] = 'Teilnehmer/in hat keinen Wert gesetzt';
$string['isnotempty'] = 'Teilnehmer/in hat einen Wert gesetzt';
$string['keepusersbookedonreducingmaxanswers'] = 'Benutzer/innen bei Limit-Reduktion gebucht lassen';
$string['keepusersbookedonreducingmaxanswers_desc'] = 'Benutzer/innen weiterhin im Status "gebucht" lassen,
auch wenn das Limit der verfügbaren Plätze reduziert wird. Beispiel: Ein Kurs hat 5 Plätze.
Das Limit wird auf 3 reduziert. Die 5 Nutzer/innen, die schon gebucht haben, bleiben trotzdem im Status "gebucht".';
$string['lblnumofusers'] = 'Bezeichnung für: Nutzer/innenanzahl';
$string['lblsputtname'] = 'Alternative Bezeichnung für "Umfragelink an Trainer/innen senden" verwenden';
$string['lblteachname'] = 'Alternative Bezeichnung für "Trainer/in" verwenden';
$string['limitanswers_help'] = 'Bei Änderung dieser Einstellung und vorhandenen Buchungen, werden die Buchungen für die betroffenen Nutzer/innen ohne Benachrichtigung entfernt.';
$string['linktoteachersinstancereport'] = '<p><a href="{$a}" target="_self">&gt;&gt; Zum Trainer/innen-Gesamtbericht für die Buchungsinstanz</a></p>';
$string['matchuserprofilefield'] = 'Wähle Nutzer/innen nach gleichem Wert in Buchungsoption und Profil.';
$string['maxperuser_help'] = 'Die maximale Anzahl an Buchungen, die ein/e Nutzer/in auf einmal buchen kann.
<b>Achtung:</b> In den Booking-Plugin-Einstellungen können Sie auswählen, ob Nutzer/innen, die teilgenommen
oder abgeschlossen haben und ob Buchungsoptionen, die bereits vorbei sind, mitgezählt werden sollen oder nicht.';
$string['maxperuserdontcountcompleted_desc'] = 'Abgeschlossene Buchungen und Teilnehmer/innen mit Anwesenheitsstatus "Teilgenommen" oder "Abgeschlossen"
bei der Berechnung der maximalen Anzahl an Buchungen nicht mitzählen';
$string['maxperuserdontcountnoshow_desc'] = 'Abwesende Teilnehmer/innen mit Anwesenheitsstatus "Nicht aufgetaucht"
bei der Berechnung der maximalen Anzahl an Buchungen nicht mitzählen';
$string['mod/booking:expertoptionform'] = 'Buchungsoption für Expert/innen';
$string['nodirectbookingbecauseofprice'] = 'Das Buchen von anderen ist bei dieser Buchungsoption nur eingeschränkt möglich. Die Gründe dafür sind folgende:
<ul>
<li>ein Preis ist hinterlegt</li>
<li>das Shopping Cart Modul ist installiert</li>
<li>die Warteliste ist global nicht deaktiivert</li>
</ul>
Der Zweck dieses Verhaltens ist es, "gemischte" Buchungen mit und ohne Warenkorb zu verhindern. Bitte verwenden Sie die Kassierfunktion des Warenkorbs, um Benutzer/innen zu buchen.';
$string['nosubscribers'] = 'Keine Trainer/innen zugewiesen!';
$string['notallbooked'] = 'Folgende Nutzer/innen konnten aufgrund nicht mehr verfügbarer Plätze oder durch das Überschreiten des vorgegebenen Buchungslimits pro Nutzer/in nicht gebucht werden: {$a}';
$string['noteacherset'] = 'Kein/e Trainer/in';
$string['notifyemail'] = 'Teilnehmer/innen-Benachrichtigung vor dem Beginn';
$string['notifyemailteachers'] = 'Trainer/innen-Benachrichtigung vor dem Beginn';
$string['notifyemailteachersmessage'] = 'Ihre Buchung startet demnächst:
{$a->bookingdetails}
Sie haben <b>{$a->numberparticipants} gebuchte Teilnehmer/innen</b> und <b>{$a->numberwaitinglist} Personen auf der Warteliste</b>.
Um eine Übersicht über alle Buchungen zu erhalten, klicken Sie auf den folgenden Link:
{$a->bookinglink}
Hier geht\'s zum Kurs:  {$a->courselink}
';
$string['notifymelistdeleted'] = 'Nutzer/in von der Benachrichtigungsliste gelöscht';
$string['notinarray'] = 'Teilnehmer/in hat keinen dieser Werte (Komma getrennt)';
$string['nouserfound'] = 'Kein/e Benutzer/in gefunden: ';
$string['onecompetencymustbefound'] = 'Nutzer/in muss mind. eine dieser Kompetenzen haben';
$string['openbookingdetailinsametab_desc'] = 'Wählen Sie, wie die Detailansicht geöffnet wird, wenn eine/ein Nutzer/in in der Kursliste auf den Titel einer Buchungsoption klickt.';
$string['optiondatesteacheradded'] = 'Trainer/in wurde zu Einzeltermin hinzugefügt';
$string['optiondatesteacherdeleted'] = 'Trainer/in wurde von Einzeltermin entfernt';
$string['optiondatesteachersreport_desc'] = 'In diesem Report erhalten Sie eine Übersicht, welche/r Trainer/in an welchem Termin geleitet hat.<br>
Standardmäßig werden alle Termine mit dem/den eingestellten Trainer/innen der Buchungsoption befüllt. Sie können einzelne Termine mit Vertretungen überschreiben.';
$string['optionformconfiggetpro'] = 'Mit Booking <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span> haben Sie die Möglichkeit, mit Drag & Drop individuelle Formulare für bestimmte Nutzer/innen-Gruppen und Kontexte
(z.B. nur für eine bestimmte Buchungsinstanz) anzulegen.';
$string['optionformconfiginfotext'] = 'Mit diesem PRO-Feature können Sie sich mit Drag & Drop und den Checkboxen beliebige Buchungsoptionsformulare zusammenstellen.
Die einzelnen Formulare werden auf bestimmten Kontext-Ebenen (z.B. pro Buchungsinstanz, Systemweit...) definiert. Den jeweiligen Nutzer/innen sind die Formulare nur zugänglich,
wenn Sie die jeweils entsprechende Berechtigung haben.';
$string['optionvisibility_help'] = 'Stellen Sie ein, ob die Buchungsoption für jede/n sichtbar sein soll oder nur für berechtigte Nutzer/innen.';
$string['organizatorname_help'] = 'Sie können den Namen des Organisators/der Organisatorin manuell eingeben oder aus einer Liste von
früheren Organisator/innen auswählen. Sie können nur eine/n Organisator/in angeben. Sobald
Sie speichern, wird der/die Organisator/in zur Liste hinzugefügt.';
$string['otherbookinglimit_help'] = 'Anzahl der Nutzer/innen die von dieser Buchungsoption akzeptiert werden. 0 bedeutet unlimitiert.';
$string['otherbookingnumber'] = 'Nutzer/innen-Anzahl';
$string['otherbookingoptions'] = 'Nutzer/innen dieser Buchungsoption zulassen';
$string['participant'] = 'Nutzer/in Name';
$string['pollurlteachers'] = 'Trainer/innen Umfragelink';
$string['pollurlteacherstemplate'] = 'Vorlage für Trainer/innen Umfragelink';
$string['pollurlteacherstext'] = 'Umfragetext für Trainer/innen';
$string['potentialsubscribers'] = 'Mögliche Nutzer/innen';
$string['pricecategorychoosehighest_desc'] = 'Hat ein/e Nutzer/in mehrere Preiskategorie-Identifier in seinem Userprofil hinterlegt, wird die am höchsten gereihte Preiskategorie zuerst gewählt. Standard ist die niedrigste.';
$string['privacy:metadata:bookingaimessages:role'] = 'Rolle der Nachricht: Nutzer/in, Assistent oder System.';
$string['privacy:metadata:bookingaithreads'] = 'KI-Konversations-Threads, die von Nutzer/innen für Buchungsinstanzen erstellt wurden.';
$string['problemsofcohortorgroupbooking'] = '<br><p>Es konnten nicht alle Buchungen durchgeführt werden:</p>
<ul>
<li>{$a->notenrolledusers} Nutzer/innen sind nicht in den Kurs eingeschrieben</li>
<li>{$a->notsubscribedusers} Nutzer/innen konnten aus anderen Gründen nicht gebucht werden</li>
</ul>
<p>Der Grund ist wahrscheinlich, dass die zu Buchenden nicht in diesen Kurs eingeschrieben sind und Sie nicht das Recht mod_booking:bookanyone haben</p>';
$string['profeatures:enablefavoritestoggle'] = '<ul>
<li>Nutzer/innen können Buchungsoptionen mit einem Stern-Symbol als Favoriten markieren.</li>
<li>Pro Buchungsinstanz kann ein persönlicher Tab "Meine Favoriten" aktiviert werden.</li>
</ul>';
$string['profeatures:teachers'] = '<ul>
<li><b>Fügen Sie Links zu Trainer/innen-Seiten hinzu</b></li>
<li><b>Einloggen für Trainer/innen-Seiten nicht notwendig</b></li>
<li><b>Allen Nutzer/innen werden immer die E-Mail-Adressen der Trainer/innen angezeigt</b></li>
<li><b>E-Mail-Adressen von Trainer/innen, bei denen gebucht wurde, anzeigen</b></li>
<li><b>Trainer/innen können mit ihrem eigenen E-Mail-Client E-Mails an gebuchte Nutzer/innen senden</b></li>
<li><b>Rolle für Trainer/innen einer Buchungsoption festlegen</b></li>
</ul>';
$string['profeatures:unenroluserswithoutaccess'] = '<ul>
<li><b>Buchungen von Nutzer/innen löschen, die keinen Zugang zum Kurs mehr haben, in dem sich die Buchung befindet.</b></li>
</ul>';
$string['recreategroup'] = 'Gruppe erneut anlegen und Nutzer/innen der Gruppe zuordnen';
$string['reminderteachersent'] = 'Benachrichtigung an Trainer/in versendet';
$string['reserveddeleted'] = 'Reservierte Nutzer/in gelöscht';
$string['responsesfields'] = 'Felder in der Teilnehmer/innen-Liste';
$string['responsiblecontactcanedit_desc'] = 'Aktivieren Sie diese Einstellung, um es Kontaktpersonen zu erlauben,
die Buchungsoptionen, bei denen Sie eingetragen sind, zu editieren und Teilnehmer/innen-Listen einzusehen.<br>
<b>Wichtig:</b> Die Kontaktperson braucht zusätzlich das Recht <b>mod/booking:addeditownoption</b>.';
$string['responsiblecontactshowfirstteacher'] = 'Auf der Detailseite die erste Trainer/in als Kontaktperson anzeigen, falls keine Kontaktperson gesetzt ist.';
$string['resultofcohortorgroupbooking'] = '<p>Die Buchung der globalen Gruppen hat folgendes Ergebnis gebracht:</p>
<ul>
<li>{$a->sumcohortmembers} Nutzer/innen in den ausgewählten globalen Gruppen gefunden</li>
<li>{$a->sumgroupmembers} Nutzer/innen in den ausgewählten Kursgruppen gefunden</li>
<li>{$a->subscribedusers} Nutzer/innen wurden erfolgreich für die Option gebucht</li>
</ul>';
$string['rulesendmailcpf'] = '[Vorschau] E-Mail versenden an User/in mit benutzerdefiniertem Feld';
$string['rulesendmailcpf_desc'] = 'Wählen Sie ein Event aus, auf das reagiert werden soll. Legen Sie eine E-Mail-Vorlage an
(Sie können auch Platzhalter wie {bookingdetails} verwenden) und legen Sie fest, an welche Nutzer/innen die E-Mail versendet werden soll.
Beispiel: Alle Nutzer/innen, die im benutzerdefinierten Feld "Studienzentrumsleitung" den Wert "SZL Wien" stehen haben.';
$string['ruletemplatetrainerreminderbody'] = 'Ihre Kurs startet in einigen Tagen:<br>{bookingdetails}<br>Sie haben {numberparticipants} gebuchte Teilnehmer/innen und {numberwaitinglist} Personen auf der Warteliste.<br>Um eine Übersicht über alle Buchungen zu erhalten, klicken Sie auf den folgenden Link:<br>{bookinglink}<br>Hier geht\'s zum Kurs: {courselink}';
$string['screstoreitemfromreserved_desc'] = 'Dadurch werden Artikel nach dem Löschen des Caches wieder automatisch in den Warenkorb der Nutzer/innen gelegt';
$string['selectallusers'] = 'Alle Nutzer/innen auswählen';
$string['selectbookingmanager'] = 'Wähle Verwalter/in der Buchungen';
$string['selectstudentinbo'] = 'Wähle Nutzer/innen einer Buchungsoption';
$string['selectteacherinbo'] = 'Wähle Trainer/innen einer Buchungsoption';
$string['selectteacherswithprofilefieldonly'] = 'Trainer/innen-Auswahl einschränken';
$string['selectteacherswithprofilefieldonlydesc'] = 'Nur Benutzer/innen, mit einem bestimmten Wert in einem definierten Nutzerprofilfeld können als Trainer/innen ausgewählt werden.<br>
<span class="text-danger">Hinweis: <b>Speichern und Seite neu laden</b>, um das Profilfeld zu wählen und den Wert anzugeben.</span>';
$string['selectteacherswithprofilefieldonlyfield'] = '⤷ Nutzerprofilfeld für Trainer/innen wählen';
$string['selectuserfromevent'] = 'Wähle Nutzer/in vom Ereignis';
$string['selectusers'] = 'Nutzer/innen direkt auswählen';
$string['selectusersfromuserfieldofeventuser'] = 'Wähle Nutzer/in aus Profilfeld von Person des Events';
$string['selectusershoppingcart'] = 'Wähle Nutzer/in die Ratenzahlung zu leisten hat';
$string['selflearningcoursealert'] = 'Wenn ein Moodle-Kurs verbunden ist, dann werden bei Buchungsoptionen vom Typ "{$a}" die Benutzer/innen immer <b>direkt nach der Buchung</b> eingeschrieben. Die angegebene Dauer legt fest, wie lange der/die Benutzer/in im Kurs eingeschrieben bleibt.<br><br> <b>Achtung:</b> Sie können keine Termine angeben, jedoch ein <b>Sortierdatum</b> (im Abschnitt "Termine"), das für die Sortierung verwendet wird.';
$string['selflearningcoursesettingsheaderdesc'] = 'Dieses Feature erlaubt es Ihnen Buchungsoptionen ohne Termine, jedoch mit einer fixen Dauer anzulegen. Die Benutzer/innen werden bei der Buchung für die festgelegte Dauer in den verknüpften Moodle-Kurs eingeschrieben.';
$string['sendmailheading'] = 'E-Mail an alle Trainer/innen der ausgewählten Buchungsoptionen senden';
$string['sendmailinterval'] = 'Eine Nachricht zeitversetzt an mehrere Nutzer/innen schicken';
$string['sendmailtoallbookedusers'] = 'E-Mail an alle gebuchten Nutzer/innen senden';
$string['sendmailtobooker_help'] = 'Diese Option aktivieren, um Buchungsbestätigungsmails anstatt an die gebuchten Nutzer/innen zu senden an den/die Nutzer/in senden, die die Buchung durchgeführt hat. Dies betrifft nur Buchungen, die auf der Seite "Buchung für andere Nutzer/innen durchführen" getätigt wurden';
$string['sendmailtoteachers'] = 'E-Mail an Trainer/innen senden';
$string['sendmessagesforinvisibleoptions_desc'] = 'Aktivieren Sie diese Einstellung, um Nachrichten auch bei unsichtbaren Buchungsoptionen zu versenden (Vorsicht: Dies könnte dazu führen, dass Benutzer/innen unerwünschte E-Mails erhalten.)';
$string['sendmessagetoteachers'] = 'E-Mail an Trainer/innen';
$string['showallteachers'] = '&gt;&gt; Alle Trainer/innen anzeigen';
$string['showbookingdetailstoall_desc'] = 'Auch Gäste und ausgeloggte Nutzer/innen können Buchungsdetails sehen.';
$string['showpriceifnotloggedin'] = 'Preis(e) anzeigen, wenn Nutzer/innen nicht eingeloggt sind';
$string['showteachersmailinglist'] = 'E-Mail-Liste für alle Trainer/innen anzeigen...';
$string['slot_add_examiners_to_slots'] = 'Pruefer/innen zu Slots hinzufuegen';
$string['slot_allow_self_rebooking_help'] = 'Wenn aktiviert, können Teilnehmer/innen ihre eigenen gebuchten Slots selbst auf einen anderen freien Slot umbuchen. Es können nur Slots abgegeben werden, die noch nicht begonnen haben, und es können nur Slots in der Zukunft als Ziel gewählt werden. In dieser ersten Version ist das Umbuchen auf preisgleiche Slots beschränkt.';
$string['slot_booked_event_description'] = 'Benutzer/in mit ID {$a->adminid} hat die Slot-Buchungsantwort {$a->baid} (Option {$a->optionid}) für Benutzer/in {$a->userid} mit {$a->slotcount} Slot(s) erstellt.';
$string['slot_calendar_teachers'] = 'Gebuchte Pruefer/innen';
$string['slot_cancelled_event_description'] = 'Benutzer/in mit ID {$a->adminid} hat die Slot-Buchungsantwort {$a->baid} (Option {$a->optionid}) für Benutzer/in {$a->userid} mit {$a->slotcount} Slot(s) storniert.';
$string['slot_change_deadline_minutes_help'] = 'Bis wann Teilnehmer/innen einen gebuchten Slot umbuchen oder stornieren dürfen, relativ zum Start des jeweiligen Slots. Jeder Slot wird einzeln geprüft. „Standard verwenden" erbt den Wert der Buchungsinstanz bzw. der Website.';
$string['slot_custom_duration_step_minutes_help'] = 'Granularität der wählbaren Slot-Länge zwischen minimaler und maximaler Slot-Dauer. Beispiel: Bei einem Minimum von 15 Minuten, einem Maximum von 30 Minuten und einer Schrittweite von 5 Minuten können Nutzer/innen eine Dauer von 15, 20, 25 oder 30 Minuten wählen.';
$string['slot_error_teacher_required'] = 'Bitte waehlen Sie eine Pruefer/in aus.';
$string['slot_examiners_per_slot'] = 'Pruefer/innen pro Slot';
$string['slot_max_participants_per_slot'] = 'Max. Teilnehmer/innen pro Slot';
$string['slot_max_slots_per_user'] = 'Max. Slots pro Nutzer/in';
$string['slot_move_event_description_multi'] = 'Benutzer/in mit ID {$a->adminid} hat die Buchungsantwort {$a->baid} (Option {$a->optionid}) für Benutzer/in {$a->userid} verschoben. Verschobene Slots von {$a->oldslots} auf {$a->newslots}. Grund: {$a->reason}';
$string['slot_move_event_description_single'] = 'Benutzer/in mit ID {$a->adminid} hat die Buchungsantwort {$a->baid} (Option {$a->optionid}) für Benutzer/in {$a->userid} verschoben. Verschobener Slot von {$a->oldslots} auf {$a->newslots}. Grund: {$a->reason}';
$string['slot_nosubscribe'] = 'Da für diese Option die Slot-Buchung aktiviert ist, können hier keine Nutzer/innen gebucht werden.';
$string['slot_nosubscribe_unenrol'] = 'Nutzer/innen können in der Liste auf der Buchungen-Seite von dieser Option abgemeldet werden.';
$string['slot_rebook_notification_teacher_body'] = 'Teilnehmer/in {$a->participant} hat von {$a->oldtime} auf {$a->newtime} umgebucht.';
$string['slot_rebook_notification_teacher_subject'] = 'Eine/r Teilnehmer/in hat einen Termin umgebucht';
$string['slot_report_teachers'] = 'Zugewiesene Pruefer/innen';
$string['slot_student_teacher_assignments'] = 'Pruefer/innen-Zuweisung pro Teilnehmer/in';
$string['slot_student_teacher_assignments_desc'] = 'Weisen Sie jeder eingeschriebenen Person eine oder mehrere Pruefer/innen aus dem Pruefer/innen-Pool dieser Option zu.';
$string['slot_student_teacher_assignments_no_teachers'] = 'In dieser Option sind keine Pruefer/innen im Pruefer/innen-Pool konfiguriert.';
$string['slot_student_teacher_assignments_saved'] = 'Pruefer/innen-Zuweisungen wurden gespeichert.';
$string['slot_student_teacher_assignments_teachers'] = 'Zugewiesene Pruefer/innen';
$string['slot_teacher_pool'] = 'Pruefer/innen-Pool';
$string['slot_teacher_unavailability'] = 'Pruefer/innen-Abwesenheit';
$string['slot_teachers_required'] = 'Benoetigte Pruefer/innen pro Slot';
$string['slot_unavailability_scope_system'] = 'System (alle Buchungen mit dieser/diesem Prüfer/in)';
$string['studentbooked'] = 'Nutzer/innen, die gebucht haben';
$string['studentbookedandwaitinglist'] = 'Nutzer/innen, die gebucht haben oder auf der Warteliste sind';
$string['studentdeleted'] = 'Nutzer/innen, die bereits entfernt wurden';
$string['studentnotificationlist'] = 'Nutzer/innen auf der Benachrichtigungsliste';
$string['studentwaitinglist'] = 'Nutzer/innen auf der Warteliste';
$string['subbookingadditemformlink_help'] = 'Wählen Sie das Formularelement, das Sie mit dieser Zusatzbuchung verbinden wollen. Die Zusatzbuchung wird nur angezeigt, wenn die Nutzer/in davor den entsprechenden Wert im Formular gewählt hat.';
$string['subscribersto'] = 'Trainer/innen für \'{$a}\'';
$string['subscribetocourse'] = 'Nutzer/innen in den Kurs einschreiben';
$string['subscribetocoursebody'] = 'Wollen Sie die ausgewählten Nutzer/innen wirklich in den mit dieser Buchungsoption verbundenen Kurs einschreiben?';
$string['switchtemplates'] = 'Nutzer/innen können die Ansicht wechseln';
$string['switchtemplates_help'] = 'Aktivieren Sie diese Einstellung, um es Nutzer/innen zu ermöglichen zwischen verschiedenen Ansichten zu wechseln.
Definieren Sie im nächsten Schritt die Ansichten zwischen denen gewechselt werden kann.';
$string['switchtemplatesselection_help'] = 'Wählen Sie die Ansichten aus, zwischen denen Nutzer/innen wechseln können.';
$string['syncconditionpolicy_respect'] = 'Beachten (blockierte Nutzer/innen überspringen)';
$string['syncdeletemodeunenrol'] = 'Betroffene Nutzer/innen austragen (Buchungsantworten auf gelöscht setzen)';
$string['syncenrolaction'] = 'Nutzer/innen einbuchen, wenn sie zur Quelle hinzugefügt werden';
$string['syncreasonalreadyenrolled'] = 'Nutzer/in ist bereits in dieser Option eingebucht';
$string['syncruleactivateretroactivedesc'] = 'Aktuelle Quellenmitglieder einbuchen und Nutzer/innen austragen, die noch dieser Regel gehören, aber nicht mehr in der aktuellen Kohorte/Gruppe sind.';
$string['syncunenrolaction'] = 'Nutzer/innen ausbuchen, wenn sie von der Quelle entfernt werden';
$string['tableheaderteacher'] = 'Trainer/in(nen)';
$string['tabwhatsnew_desc'] = 'Sie können diesen Tab verwenden, um Benutzer/innen alle neuen Buchungen anzuzeigen,
die innerhalb der letzten X Tage (die Anzahl können Sie hier angeben) auf sichtbar gesetzt ODER erstellt wurden.
<span class="text-danger">Denken Sie daran, den Tab in den Einstellungen Ihrer Buchungsinstanz hinzuzufügen, nachdem Sie ihn aktiviert haben.</span>';
$string['teacher'] = 'Trainer/in';
$string['teachernotfound'] = 'Trainer/in konnte nicht gefunden werden oder existiert nicht.';
$string['teacherpageshiddenbookingids'] = 'Buchungsinstanzen, die auf Trainer/innen-Seiten nicht angezeigt werden sollen';
$string['teacherpagevisibilitymode'] = 'Sichtbarkeit versteckter Optionen für zugewiesene Trainer/innen auf dem eigenen Trainerprofil';
$string['teacherpagevisibilitymode:both'] = 'Auf dem eigenen Trainerprofil können zugewiesene Trainer/innen vollständig unsichtbare und nur über direkten Link sichtbare Optionen sehen';
$string['teacherpagevisibilitymode:directlinkonly'] = 'Auf dem eigenen Trainerprofil können zugewiesene Trainer/innen nur über direkten Link sichtbare Optionen sehen';
$string['teacherpagevisibilitymode:fullyinvisible'] = 'Auf dem eigenen Trainerprofil können zugewiesene Trainer/innen vollständig unsichtbare Optionen sehen';
$string['teacherpagevisibilitymode_desc'] = 'Bestimmt, welche versteckten Buchungsoptionen einbezogen werden, wenn ein Sichtbarkeits-Override-Modus verwendet wird. Aktueller Anwendungsfall: zugewiesene Trainer/innen auf dem eigenen öffentlichen Trainerprofil. Diese Einstellung gilt nicht beim Anzeigen anderer Trainerprofile, und es werden nur Optionen angezeigt, bei denen die Trainer/in zugewiesen ist. Dieselben Sichtbarkeitsmodi können künftig auch in anderen Listen-Kontexten wiederverwendet werden. Diese Einstellung beeinflusst nicht Benutzer/innen mit der Berechtigung \'canseeinvisibleoptions\', die immer alle versteckten Optionen sehen können';
$string['teachers'] = 'Trainer/innen';
$string['teachersallowmailtobookedusers'] = 'Trainer/innen erlauben, eine Direkt-Mail an gebuchte Nutzer/innen zu senden';
$string['teachersallowmailtobookedusers_desc'] = 'Wenn Sie diese Einstellung aktivieren, können Trainer/innen eine Direktnachricht
mit ihrem eigenen Mail-Programm an gebuchte Nutzer/innen senden - die E-Mail-Adressen der gebuchten Nutzer/innen werden dadurch sichtbar.
<span class="text-danger"><b>Achtung:</b> Dies könnte ein Datenschutz-Problem darstellen. Aktivieren Sie dies nur,
wenn es die Datenschutzbestimmungen Ihrer Organisation erlauben.</span>';
$string['teachersbookingoptionsfromcondition'] = 'Referent/innen: ';
$string['teachersettings'] = 'Trainer/innen <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['teachersettings_desc'] = 'Trainer/innen-spezifische Einstellungen.';
$string['teachersforoption'] = 'Trainer/innen';
$string['teachersforoption_help'] = '<b>ACHTUNG:</b> Wenn Sie hier Trainer/innen hinzufügen werden diese im Training-Journal <b>zu JEDEM ZUKÜNFTIGEN Termin hinzugefügt</b>.
Wenn Sie hier Trainer/innen löschen, werden diese im Training-Journal <b>von JEDEM ZUKÜNFTIGEN Termin entfernt</b>.';
$string['teachersinstancereport'] = 'Trainer/innen-Gesamtbericht';
$string['teacherslinkonteacher'] = 'Links zu Trainer/innen-Seiten hinzufügen';
$string['teacherslinkonteacher_desc'] = 'Sind bei einer Buchungsoption Trainer/innen definiert, so werden die Namen automatisch mit einer Überblicksseite für diese Trainer/innen verknüpft.';
$string['teachersnologinrequired'] = 'Einloggen bei Trainer/innen-Seiten nicht notwendig';
$string['teachersnologinrequired_desc'] = 'Wenn Sie diese Einstellung aktivieren, dann können die Trainer/innen-Seiten auch von
nicht-eingeloggten Benutzer/innen gesehen werden.';
$string['teachersshowemails'] = 'E-Mail-Adressen von Trainer/innen immer anzeigen';
$string['teachersshowemails_desc'] = 'Wenn Sie diese Einstellung aktivieren, dann werden allen Benutzer/innen die E-Mail-Adressen der Trainer/innen
angezeigt, selbst wenn diese nicht eingeloggt sind. <span class="text-danger"><b>Achtung:</b> Dies könnte ein Datenschutz-Problem darstellen. Aktivieren Sie dies nur,
wenn es die Datenschutzbestimmungen Ihrer Organisation erlauben.</span>';
$string['teachingreportforinstance'] = 'Trainer/innen-Gesamtbericht für ';
$string['teachingreportfortrainer'] = 'Leistungs-Report für Trainer/in';
$string['toomuchusersbooked'] = 'Maximale Anzahl an Nutzer/innen, die Sie buchen können: {$a}';
$string['transferconfirmlabel'] = 'Ich habe die obenstehenden Warnungen verstanden und möchte die ausgewählten Nutzer/innen trotzdem umbuchen.';
$string['transferheading'] = 'Ausgewählte Nutzer/innen in die ausgewählte Buchungsoption umbuchen';
$string['transferoptionsuccess'] = 'Die Buchungsoption und die registrierten Nutzer/innen wurden erfolgreich umgebucht';
$string['transferproblem'] = 'Die folgenden Nutzer/innen konnten aufgrund einer limitierten Anzahl an Plätzen der Buchungsoption oder aufgrund individueller Limitierungen seitens des/der Nutzer/in nicht umgebucht werden: {$a}';
$string['transfersameoption'] = 'Bitte wählen Sie eine andere Buchungsoption als jene, in der die Nutzer/innen aktuell gebucht sind.';
$string['transfersuccess'] = 'Die Nutzer/innen wurden erfolgreich umgebucht';
$string['transfertargetoption_help'] = 'Suchen Sie die Buchungsoption, in die Sie die ausgewählten Nutzer/innen umbuchen möchten. Die Vorschläge zeigen den Titel der Buchungsoption (mit Präfix), die Options-ID und die Buchungsinstanz, zu der die Option gehört. Sie können auch in Optionen anderer Buchungsinstanzen umbuchen.';
$string['transferusers'] = 'Nutzer/innen umbuchen';
$string['transferwarningcustomform'] = 'Mindestens eine/r der ausgewählten Nutzer/innen hat für die aktuelle Buchungsoption ein individuelles Formular ausgefüllt. Diese Formulardaten gehen beim Umbuchen verloren.';
$string['unenroluserswithoutaccess'] = 'Abmelden von Nutzer/innen ohne Zugang';
$string['unenroluserswithoutaccess_desc'] = 'Melde Nutzer/innen automatisch ab, die keinen Zugang mehr zu einem Moodle-Kurs oder einer Buchungsaktivität haben.
<div class="text-danger">Achtung: Damit wird die Nachverfolgung womöglich erschwert. Nach Aktivierung dieses Häkchens wird einmalig systemweit überprüft,
ob es zu löschende Buchungen gibt. Das Löschen der Buchungen geschieht immer asynchron mit ca. 15 Minuten Verzögerung.
Wenn Sie also ein/e/n Nutzer/in irrtümlich ausschreiben, haben Sie noch einige Minuten Zeit, um dieses Häkchen zu entfernen und das automatische Löschen somit zu verhindern.</div>';
$string['unenroluserswithoutaccessareyousure'] = 'Möchten Sie wirklich "Abmelden von Nutzer/innen ohne Zugang" aktivieren?';
$string['unenroluserswithoutaccessheader_desc'] = 'Melde Nutzer/innen automatisch ab, die keinen Zugang mehr zu einem Moodle-Kurs oder einer Buchungsaktivität haben.
(<b>Achtung</b>: Dies kann zu unerwünschtem Verhalten führen. Nur aktivieren, wenn wirklich benötigt.)';
$string['unsubscribe:errorotheruser'] = 'Es ist nicht erlaubt, E-Mail-Abmeldungen für fremde Benutzer/innen durchzuführen!';
$string['useraffectedbyevent'] = 'Vom Ereignis betroffene/r Nutzer/in';
$string['usercalendarurl'] = 'Nutzer/innen Kalender';
$string['userdownload'] = 'Nutzer/innenliste herunterladen';
$string['usersmatching'] = 'Gefundene Nutzer/innen';
$string['usersonlist'] = 'Nutzer/innen';
$string['userspecificcampaignwarning'] = 'Wenn Sie ein unten ein Benutzerdefiniertes User Profilfeld auswählen, wird die Kampagne nur für jene Nutzer/innen wirksam, die in diesem Feld den angegebenen Wert haben (oder nicht haben).';
$string['userssuccessfullenrolled'] = 'Alle Nutzer/innen wurden erfolgreich eingeschrieben!';
$string['userssuccessfullybooked'] = 'Alle Nutzer/innen wurden erfolgreich in die andere Buchungsoption eingeschrieben.';
$string['userssucesfullygetnewpresencestatus'] = 'Anwesenheitsstatus für ausgewählte Nutzer/innen erfolgreich aktualisiert';
$string['userwhotriggeredevent'] = 'Nutzer/in, die das Ereignis ausgelöst hat';
$string['waitinglistinfotextsinfo'] = 'Wählen Sie aus, wie die Platzverfügbarkeit für die Warteliste den Nutzer/innen angezeigt werden soll.';
$string['waitinglistshowplaceonwaitinglistinfo'] = 'Warteliste: Zeige den Platz der Nutzer/innen auf der Warteliste an.
Sie können die Reihenfolge der Nutzer/innen auf der Warteliste per Drag & Drop anpassen.';
$string['waitinglistusers'] = 'Nutzer/innen auf der Warteliste';
$string['warningonlyteachersofselectedinstances'] = 'Hinweis: Hier werden aktuell nur Trainer/innen angezeigt,
die Trainer/innen in einer der in der <a href="{$a}" target="_blank">globalen Einstellung "allteacherspagebookinginstances"</a>
ausgewählten Buchungsinstanzen sind.';
$string['withselected'] = 'Ausgewählte Nutzer/innen';
$string['xusersarebooked'] = '{$a} Nutzer/innen sind gebucht';
