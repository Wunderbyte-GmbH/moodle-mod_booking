<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * German translation of the booking module
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

global $CFG;

// General strings.
$string['accept'] = 'Akzeptieren';
$string['age'] = 'Alter';
$string['allowupdatedays'] = 'Tage vor Referenzdatum';
$string['areyousure:book'] = 'Nochmal klicken, um die Buchung zu bestätigen';
$string['areyousure:cancel'] = 'Nochmal klicken, um die Buchung zu stornieren';
$string['assignteachers'] = 'Lehrer:innen zuweisen:';
$string['alreadypassed'] = 'Bereits vergangen';
$string['bookingopeningtime'] = 'Buchbar ab';
$string['bookingclosingtime'] = 'Buchbar bis';
$string['bookingoption'] = 'Buchungsoption';
$string['bookingoptionnamewithoutprefix'] = 'Name (ohne Präfix)';
$string['bookings'] = 'Buchungen';
$string['cancelallusers'] = 'Storniere Buchung für alle Nutzer:innen';
$string['cancelmyself'] = 'Wieder abmelden';
$string['cancelsign'] = '<i class="fa fa-ban" aria-hidden="true"></i>';
$string['canceluntil'] = 'Stornieren nur bis zu bestimmtem Zeitpunkt erlauben';
$string['close'] = 'Schließen';
$string['confirmoptioncreation'] = 'Wollen Sie diese Buchungsoption splitten sodass aus jedem Einzeltermin eine eigene
 Buchungsoption erstellt wird?';
$string['createoptionsfromoptiondate'] = 'Für jeden Einzeltermin eine neue Buchungsoption erstellen';
$string['customformnotchecked'] = 'Noch nicht akzeptiert.';
$string['updatebooking'] = 'Update Buchung';
$string['booking:manageoptiontemplates'] = "Buchungsoptionsvorlagen verwalten";
$string['booking:cantoggleformmode'] = 'Nutzer:in darf alle Einstellungen verwalten';
$string['booking:overrideboconditions'] = 'Nutzer:in darf buchen auch wenn Verfügbarkeit false zurückliefert.';
$string['collapsedescriptionoff'] = 'Beschreibungen nicht einklappen';
$string['collapsedescriptionmaxlength'] = 'Beschreibungen einklappen (Zeichenanzahl)';
$string['collapsedescriptionmaxlength_desc'] = 'Geben Sie die maximale Anzahl an Zeichen, die eine Beschreibung haben darf, ein.
Beschreibungen, die länger sind werden eingeklappt.';
$string['confirmchangesemester'] = 'JA, ich möchte wirklich alle Termine der Buchungsinstanz löschen und neue erstellen.';
$string['course'] = 'Moodle-Kurs';
$string['courses'] = 'Kurse';
$string['course_s'] = 'Kurs(e)';
$string['date_s'] = 'Termin(e)';
$string['dayofweek'] = 'Wochentag';
$string['deduction'] = 'Abzug';
$string['deductionreason'] = 'Grund für den Abzug';
$string['deductionnotpossible'] = 'Da alle Trainer:innen bei diesem Termin anwesend waren kann kein Abzug eingetragen werden.';
$string['defaultoptionsort'] = 'Standardsortierung nach Spalte';
$string['doyouwanttobook'] = 'Wollen Sie <b>{$a}</b> buchen?';
$string['from'] = 'Ab';
$string['gotomanageresponses'] = '&lt;&lt; Buchungen verwalten';
$string['gotomoodlecourse'] = 'Zum Moodle-Kurs';
$string['limitfactor'] = 'Buchungslimit-Faktor';
$string['messageprovider:bookingconfirmation'] = "Buchungsbestätigungen";
$string['name'] = 'Name';
$string['noselection'] = 'Keine Auswahl';
$string['optionsfield'] = 'Buchungsoptionsfeld';
$string['optionsfields'] = 'Buchungsoptionsfelder';
$string['optionsiteach'] = 'Von mir geleitet';
$string['placeholders'] = 'Platzhalter';
$string['pricefactor'] = 'Preisfaktor';
$string['responsesfields'] = 'Felder in der Teilnehmer:innen-Liste';
$string['responsible'] = 'Zuständig';
$string['responsiblecontact'] = 'Zuständige Kontaktperson';
$string['responsiblecontact_help'] = 'Geben Sie eine zuständige Kontaktperson an. Dies sollte jemand anderer als der/die Lehrer/in sein.';
$string['reviewed'] = 'Kontrolliert';
$string['rowupdated'] = 'Zeile wurde aktualisiert.';
$string['search'] = 'Suche...';
$string['semesterid'] = 'SemesterID';
$string['sendmailtoallbookedusers'] = 'E-Mail an alle gebuchten Nutzer:innen senden';
$string['showmore'] = 'Zeige mehr';
$string['sortorder'] = 'Sortierreihenfolge';
$string['sortorder:asc'] = 'A&rarr;Z';
$string['sortorder:desc'] = 'Z&rarr;A';
$string['teachers'] = 'Trainer:innen';
$string['thankyoubooked'] = '<i class="fa fa-3x fa-calendar-check-o text-success" aria-hidden="true"></i><br><br>
Vielen Dank! Sie haben <b>{$a}</b> erfolgreich gebucht.';
$string['thankyoucheckout'] = '<i class="fa fa-3x fa-shopping-cart text-success" aria-hidden="true"></i><br><br>
Vielen Dank! Sie haben <b>{$a}</b> in den Warenkorb gelegt. Klicken Sie nun auf <b>"Weiter zur Bezahlung"</b>
 um den Buchungsvorgang fortzusetzen.';
$string['thankyouwaitinglist'] = '<i class="fa fa-3x fa-clock-o text-primary" aria-hidden="true"></i><br><br>
Sie wurden zur Warteliste von <b>{$a}</b> hinzugefügt. Sollte jemand ausfallen, rücken Sie automatisch nach.';
$string['thankyouerror'] = '<i class="fa fa-3x fa-frown-o text-danger" aria-hidden="true"></i><br>
Leider ist bei der Buchung von <b>{$a}</b> ein Fehler aufgetreten.';
$string['timefilter:coursetime'] = 'Kurszeiten';
$string['timefilter:bookingtime'] = 'Anmeldezeiten';
$string['toomanytoshow'] = 'Zu viele gefunden...';
$string['unsubscribe:successnotificationlist'] = 'Sie wurden erfolgreich von den E-Mail-Benachrichtigungen für "{$a}" abgemeldet.';
$string['unsubscribe:errorotheruser'] = 'Es ist nicht erlaubt, E-Mail-Abmeldungen für fremde Benutzer:innen durchzuführen!';
$string['unsubscribe:alreadyunsubscribed'] = 'Sie sind bereits abgemeldet.';
$string['until'] = 'Bis';
$string['userprofilefield'] = "Profilfeld";
$string['usersmatching'] = 'Gefundene Nutzer:innen';
$string['allmoodleusers'] = 'Alle Nutzer:innen dieser Website';
$string['enrolledusers'] = 'In den Kurs eingeschriebene Nutzer:innen';
$string['nopriceisset'] = 'Kein Preis für Preiskategorie {$a} vorhanden';

// Badges.
$string['badge:pro'] = '<span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['badge:experimental'] = '<span class="badge bg-danger text-light"><i class="fa fa-flask" aria-hidden="true"></i> Experimentell</span>';

// Errors.
$string['error:choosevalue'] = 'Sie müssen hier einen Wert auswählen.';
$string['error:confirmthatyouaresure'] = 'Bitte bestätigen Sie, dass Sie wissen, was Sie tun.';
$string['error:taskalreadystarted'] = 'Sie haben bereits einen Task gestartet!';
$string['error:entervalue'] = 'Sie müssen hier einen Wert eingeben.';
$string['error:negativevaluenotallowed'] = 'Bitte einen positiven Wert eingeben.';
$string['error:pricemissing'] = 'Bitte geben Sie einen Preis ein.';
$string['error:missingcapability'] = 'Erforderliche Berechtigung fehlt. Bitte wenden Sie sich an einen Administrator.';

// Index.php.
$string['week'] = "Woche";
$string['question'] = "Frage";
$string['answer'] = "Antwort";
$string['topic'] = "Thema";

// Teachers.
$string['teacher'] = 'Trainer:in';
$string['allteachers'] = 'Alle Trainer:innen';
$string['showallteachers'] = '&gt;&gt; Alle Trainer:innen anzeigen';
$string['showcoursesofteacher'] = 'Kurse';
$string['messagebutton'] = 'Nachricht';
$string['messagingteacherimpossible'] = 'Sie können dieser Trainerin / diesem Trainer keine Nachrichten senden,
 weil Sie in keinen Kurs von ihr/ihm eingeschrieben sind.';
$string['sendmail'] = 'Mail';
$string['teachernotfound'] = 'Trainer:in konnte nicht gefunden werden oder existiert nicht.';
$string['notateacher'] = 'Die ausgewählte Person unterrichtet keine buchbaren Kurse und kann daher nicht angezeigt werden.';
$string['showteachersmailinglist'] = 'E-Mail-Liste für alle Trainer:innen anzeigen...';

// Teacher_added.php.
$string['eventteacher_added'] = 'Trainer:in hinzugefügt';
$string['eventteacher_removed'] = 'Trainer:in entfernt';

// Renderer.php.
$string['myinstitution'] = 'Meine Institution';
$string['visibleoptions'] = 'Sichtbare Buchungsoptionen';
$string['invisibleoptions'] = 'Unsichtbare Buchungsoptionen';

// View.php.
$string['addmorebookings'] = 'Buchungen hinzufügen';
$string['allowupdate'] = 'Buchungen dürfen gelöscht/aktualisiert werden';
$string['answered'] = 'Beantwortet';
$string['dontaddpersonalevents'] = 'Keine Einträge im persönlichen Kalender erstellen.';
$string['dontaddpersonaleventsdesc'] = 'Für jede Buchung und alle Termine werden eigene Einträge im persönlichen Kalender der TeilnehmerInnen erstellt. Für eine bessere Performance auf sehr intensiv genutzten Seiten kann diese Funktion deaktiviert werden.';
$string['attachical'] = 'Einen iCal Kalendereintrag für die gesamte Dauer einer Buchung als Dateianhang in der E-Mail Benachrichtigung einfügen';
$string['attachicaldesc'] = 'E-Mail Benachrichtigungen im iCal Kalenderformat hinzufügen, wenn diese Option aktiviert wird. Es wir entweder der in den Buchungsoptionen festgelegte
Termin eingefügt oder ein Event mit dem Anfangsdatum des ersten Termins einer Terminserie und dem Enddatum des letzten Termins der Terminserie.';
$string['attachicalsess'] = 'Von einer Terminserie alle Einzeltermine im iCal Anhang hinzufügen';
$string['attachicalsessdesc'] = 'Im iCal-Anhang der E-Mail Benachrichtigungen werden alle einzelnen Termine einer Terminserie angeführt.';
$string['icalcancel'] = 'Einen iCal Anhang in die Benachrichtigungsmail einfügen, wenn eine Buchung storniert wurde.';
$string['icalcanceldesc'] = 'Wenn ein User eine Buchung storniert oder von der Buchungsliste entfernt wurde, ein iCal-Event mit dem stornierten Event anhängen. (Das fügt den Termin als abgesagten Termin in den Kalender ein bzw. berichtigt den Termin)';
$string['booking'] = 'Buchung';
$string['bookinginstance'] = 'Buchungsinstanz';
$string['booking:addinstance'] = 'Neue Buchungsinstanz anlegen';
$string['booking:choose'] = 'Buchen';
$string['booking:deleteresponses'] = 'Buchungen löschen';
$string['booking:downloadresponses'] = 'Buchungen herunterladen';
$string['booking:readresponses'] = 'Buchungen ansehen';
$string['booking:rate'] = 'Rate chosen booking options';
$string['booking:sendpollurl'] = 'Umfragelink senden';
$string['booking:sendpollurltoteachers'] = 'Umfragelink and Trainer:innen senden';
$string['booking:subscribeusers'] = 'Für andere Teilnehmer:innen Buchungen durchführen';
$string['booking:updatebooking'] = 'Buchungen verwalten';
$string['booking:viewallratings'] = 'Alle Bewertungen sehen';
$string['booking:viewanyrating'] = 'Alle Bewertungen sehen';
$string['booking:viewrating'] = 'Gesamtbewertung sehen';
$string['booking:addeditownoption'] = 'Neue Buchungsoptionen anlegen und eigene bearbeiten.';
$string['booking:canseeinvisibleoptions'] = 'Unsichtbare Buchungsoptionen sehen.';
$string['booking:changelockedcustomfields'] = 'Kann gesperrte benutzerdefinierte Buchungsoptionsfelder verändern.';
$string['manageoptiontemplates'] = 'Kann Buchungsoptionsvorlagen erstellen';
$string['bookingfull'] = 'Ausgebucht';
$string['bookingname'] = 'Buchungsinstanzname';
$string['bookingopen'] = 'Offen';
$string['bookingoptionsmenu'] = 'Buchungsoptionen';
$string['bookingtext'] = 'Buchungsbeschreibung';
$string['choose...'] = 'Auswählen...';
$string['datenotset'] = 'Datum nicht angegeben';
$string['daystonotify'] = 'Wie viele Tage vor Kursbeginn soll an die Teilnehmenden eine Benachrichtigung gesendet werden?';
$string['daystonotify_help'] = "Funktioniert nur, wenn ein Beginn- und Enddatum für die Buchungsoption gesetzt sind. Wenn Sie 0 eingeben, wird die Benachrichtigung deaktiviert.";
$string['daystonotify2'] = 'Zweite Teilnehmerbenachrichtigung vor Veranstaltungsbeginn';
$string['daystonotifyteachers'] = 'Wie viele Tage vor Kursbeginn soll an die Trainer:innen eine Benachrichtigung gesendet werden? ' . $string['badge:pro'];
$string['bookinganswer_cancelled'] = 'Buchungsoption von/für Nutzer:in storniert';

// Booking option events.
$string['bookingoption_cancelled'] = "Buchungsoption storniert";
$string['bookingoption_booked'] = 'Buchungsoption durchgeführt';
$string['bookingoption_completed'] = 'Buchungsoption abgeschlossen';
$string['bookingoption_created'] = 'Buchungsoption angelegt';
$string['bookingoption_updated'] = 'Buchungsoption upgedatet';
$string['bookingoption_deleted'] = 'Buchungsoption gelöscht';
$string['bookinginstance_updated'] = 'Buchungsinstanz upgedated';
$string['records_imported'] = 'Buchungsoptionen importiert via CSV';
$string['records_imported_description'] = '{$a} Buchungsoptionen importiert via CSV';

$string['eventreport_viewed'] = 'Report angesehen';
$string['eventuserprofilefields_updated'] = 'Nutzerprofil aktualisiert';
$string['existingsubscribers'] = 'Vorhandene Nutzer:innen';
$string['expired'] = 'Diese Aktivität wurde leider am {$a} beendet und steht nicht mehr zur Verfügung';
$string['fillinatleastoneoption'] = 'Geben Sie mindestens 2 mögliche Buchungen an.';
$string['full'] = 'Ausgebucht';
$string['infonobookingoption'] = 'Um eine Buchungsoption zu erstellen, nutzen Sie den Block Einstellungen oder das Einstellungs-Icon';
$string['infotext:prolicensenecessary'] = 'Sie benötigen Booking PRO, um dieses Feature nutzen zu können.
 <a href="https://wunderbyte.at/kontakt" target="_blank">Kontaktieren Sie Wunderbyte</a>, wenn Sie eine Lizenz erwerben möchten.';
$string['limit'] = 'Maximale Anzahl';
$string['modulename'] = 'Buchung';
$string['modulenameplural'] = 'Buchungen';
$string['mustchooseone'] = 'Sie müssen eine Option auswählen';
$string['nofieldchosen'] = 'Kein Feld ausgewählt';
$string['noguestchoose'] = 'Gäste dürfen keine Buchungen vornehmen';
$string['noresultsviewable'] = 'Die Ergebnisse sind momentan nicht einsehbar';
$string['nosubscribers'] = 'Keine Trainer:innen zugewiesen!';
$string['notopenyet'] = 'Diese Aktivität ist bis {$a} nicht verfügbar';
$string['pluginadministration'] = 'Booking administration';
$string['pluginname'] = 'Booking';
$string['potentialsubscribers'] = 'Mögliche Nutzer:innen';
$string['proversiononly'] = 'Nur in der PRO-Version verfügbar.';
$string['removeresponses'] = 'Alle Buchungen löschen';
$string['responses'] = 'Buchungen';
$string['responsesto'] = 'Buchungen zu {$a} ';
$string['spaceleft'] = 'Platz verfügbar';
$string['spacesleft'] = 'Plätze verfügbar';
$string['subscribersto'] = 'Trainer:innen für \'{$a}\'';
$string['taken'] = 'gebucht';
$string['teachers'] = 'Trainer:innen';
$string['teacher_s'] = 'Trainer:in(nen)';
$string['timerestrict'] = 'Buchungsoption auf diesen Zeitraum beschränken: Diese Option ist veraltet und sollte nicht mehr verwendet werden. Stattdessen verwenden Sie bitte die Optionen unter "Voraussetzungen", um die Buchungen für einen bestimmten Zeitraum zu beschränken.';
$string['restrictanswerperiodopening'] = 'Buchen erst ab einem bestimmten Zeitpunkt ermöglichen';
$string['restrictanswerperiodclosing'] = 'Buchen nur bis zu einem bestimmten Zeitpunkt ermöglichen';

$string['to'] = 'bis';
$string['viewallresponses'] = '{$a} Buchungen verwalten';
$string['yourselection'] = 'Ihre Auswahl';

// Subscribeusers.php.
$string['cannotremovesubscriber'] = 'Um die Buchung zu stornieren, muss zuvor der Aktivitätsabschluss entfernt werden. Die Buchung wurde nicht storniert';
$string['allchangessaved'] = 'Alle Änderungen wurden gespeichert.';
$string['backtoresponses'] = '&lt;&lt; Zurück zu den Buchungen';
$string['allusersbooked'] = 'Alle {$a} Nutzer:innen wurden erfolgreich für diese Buchungsoption gebucht.';
$string['notallbooked'] = 'Folgende Nutzer:innen konnten aufgrund nicht mehr verfügbarer Plätze oder durch das Überschreiten des vorgegebenen Buchungslimits pro Nutzer:in nicht gebucht werden: {$a}';
$string['onlyusersfrominstitution'] = 'Sie können nur Nutzerinnen von dieser Instition hinzufügen: {$a}';
$string['resultofcohortorgroupbooking'] = '<p>Die Buchung der globalen Gruppen hat folgendes Ergebnis gebracht:</p>
<ul>
<li>{$a->sumcohortmembers} Nutzer:innen in den ausgewählten globalen Gruppen gefunden</li>
<li>{$a->sumgroupmembers} Nutzer:innen in den ausgewählten Kursgruppen gefunden</li>
<li>{$a->subscribedusers} Nutzer:innen wurden erfolgreich für die Option gebucht</li>
</ul>';
$string['problemsofcohortorgroupbooking'] = '<br><p>Es konnten nicht alle Buchungen durchgeführt werden:</p>
<ul>
<li>{$a->notenrolledusers} Nutzer:innen sind nicht in den Kurs eingeschrieben</li>
<li>{$a->notsubscribedusers} Nutzer:innen konnten aus anderen Gründen nicht gebucht werden</li>
</ul>';
$string['nogrouporcohortselected'] = 'Sie müssen mindestens eine Gruppe oder globale Gruppe auswählen.';
$string['bookanyoneswitchon'] = '<i class="fa fa-user-plus" aria-hidden="true"></i> Buchen von Nutzer:innen, die nicht eingeschrieben sind, erlauben';
$string['bookanyoneswitchoff'] = '<i class="fa fa-user-times" aria-hidden="true"></i> Buchen von Nutzer:innen, die nicht eingeschrieben sind, nicht erlauben (empfohlen)';
$string['bookanyonewarning'] = 'Achtung: Sie können nun beliebige Nutzer:innen buchen. Verwenden Sie diese Einstellung nur, wenn Sie genau wissen, was Sie tun.
 Das Buchen von Nutzer:innen, die nicht in den Kurs eingeschrieben sind, kann möglicherweise zu Problemen führen.';

// Subscribe_cohort_or_group_form.php.
$string['scgfcohortheader'] = 'Globale Gruppe (Kohorte) buchen';
$string['scgfgroupheader'] = 'Gruppe aus dem Kurs buchen';
$string['scgfselectcohorts'] = 'Globale Gruppe(n) wählen';
$string['scgfbookgroupscohorts'] = 'Globale Gruppe(n) oder Gruppe(n) buchen';
$string['scgfselectgroups'] = 'Gruppe(n) auswählen';


// Bookingform.
$string['address'] = 'Adresse';
$string['general'] = 'Allgemein';
$string['advancedoptions'] = 'Erweiterte Einstellungen';
$string['btnbooknowname'] = 'Bezeichnung des Buttons "Jetzt buchen"';
$string['btncacname'] = 'Bezeichnung des Buttons "Aktivitätsabschluss bestätigen"';
$string['btncancelname'] = 'Bezeichnung des Buttons "Buchung stornieren"';
$string['description'] = 'Beschreibung';
$string['disablebookingusers'] = 'Buchung von Teilnehmer:innen deaktivieren - "Jetzt buchen" Button unsichtbar schalten.';
$string['disablecancel'] = "Stornieren dieser Buchungsoption nicht möglich";
$string['disablecancelforinstance'] = "Stornieren für die gesamte Instanz deaktivieren.
(Wenn Sie diese Einstellung aktivieren können Buchungsoptionen, die sich in dieser Instanz befinden, nicht storniert werden.)";
$string['bookotheruserslimit'] = 'Max. Anzahl an Buchungen, die ein:e der Buchungsoption zugewiesene:r Trainer:in vornehmen kann';
$string['department'] = 'Abteilung';
$string['institution'] = 'Institution';
$string['institution_help'] = 'Sie können den Namen der Institution manuell eingeben oder aus einer Liste von
                            früheren Institutionen auswählen. Sie können nur eine Institution angeben. Sobald
                            Sie speichern, wird die Institution zur Liste hinzugefügt.';
$string['lblsputtname'] = 'Alternative Bezeichnung für "Umfragelink an Trainer:innen senden" verwenden';
$string['lblteachname'] = 'Alternative Bezeichnung für "Trainer/in" verwenden';
$string['limitanswers_help'] = 'Bei Änderung dieser Einstellung und vorhandenen Buchungen, werden die Buchungen für die betroffenen Nutzer:innen ohne Benachrichtigung entfernt.';
$string['location'] = 'Ort';
$string['location_help'] = 'Sie können den Namen des Orts manuell eingeben oder aus einer Liste von
                            früheren Orten auswählen. Sie können nur einen Ort angeben. Sobald
                            Sie speichern, wird der Ort zur Liste hinzugefügt.';
$string['removeafterminutes'] = 'Aktivitätsabschluss nach N Minuten entfernen';
$string['banusernames'] = 'Nutzer:innennamen ausschließen';
$string['banusernames_help'] = 'Komma getrennte Liste von Usernamen, die nicht teilnehmen können. Um Usernamen mit bestimmten Endungen auszuschließen, kann man folgendes eingeben: gmail.com, yahoo.com';
$string['completionmodule'] = 'Aktiviere Massenlöschung von getätigten Buchungen basierend auf den Aktivitätsabschluss einer Kursaktivität';
$string['completionmodule_help'] = 'Button zum Löschen aller Buchungen anzeigen, wenn eine andere Kursaktivität abgeschlossen wurde. Die Buchungen von Nutzer:innen werden mit einem Klick auf einen Button auf der Berichtsseite gelöscht! Nur Aktivitäten mit aktiviertem Abschluss können aus der Liste ausgewählt werden.';
$string['teacherroleid'] = 'Wähle folgende Rolle, um Lehrkräfte in den Kurs einzuschreiben.';
$string['bookingoptionname'] = 'Bezeichnung der Buchungsoption';
$string['bookingoptiontitle'] = 'Bezeichnung der Buchungsoption';
$string['addastemplate'] = 'Als Vorlage hinzufügen';
$string['notemplate'] = 'Nicht als Vorlage benutzen';
$string['astemplate'] = 'Als Vorlage in diesem Kurs hinzufügen';
$string['asglobaltemplate'] = 'Als globale Vorlage hinzufügen';
$string['templatedeleted'] = 'Vorlage wurde gelöscht!';

// Calendar.php.
$string['usercalendarentry'] = 'Sie haben <a href="{$a}">diese Option</a> gebucht.';
$string['bookingoptioncalendarentry'] = '<a href="{$a}" class="btn btn-primary">Jetzt buchen...</a>';

// Categories.
$string['categoryheader'] = '[VERALTET] Kategorie';
$string['category'] = 'Kategorie';
$string['categories'] = 'Kategorien';
$string['addcategory'] = 'Kategorien bearbeiten';
$string['forcourse'] = 'für Kurs';
$string['addnewcategory'] = 'Neue Kategorie hinzufügen';
$string['categoryname'] = 'Kategoriename';
$string['rootcategory'] = 'Übergeordnete Kategorie';
$string['selectcategory'] = 'Übergeordnete Kategorie auswählen';
$string['editcategory'] = 'Bearbeiten';
$string['deletecategory'] = 'Löschen';
$string['deletesubcategory'] = 'Löschen Sie zuerst alle Unterkategorien dieser Kategorie!';
$string['usedinbooking'] = 'Das Löschen dieser Kategorie/n ist nicht möglich, da sie verwendet werden!';
$string['successfulldeleted'] = 'Kategorie wurde erfolgreich gelöscht!';

// Events.
$string['bookingoptiondate_created'] = 'Termin erstellt';
$string['bookingoptiondate_updated'] = 'Termin geändert';
$string['bookingoptiondate_deleted'] = 'Termin gelöscht';
$string['custom_field_changed'] = 'Benutzerdefiniertes Feld geändert';
$string['pricecategory_changed'] = 'Preiskategorie geändert';
$string['reminder1_sent'] = 'Erste Benachrichtigung versendet';
$string['reminder2_sent'] = 'Zweite Benachrichtigung versendet';
$string['reminder_teacher_sent'] = 'Benachrichtigung an Trainer:in versendet';
$string['optiondates_teacher_added'] = 'Vertretung wurde eingetragen';
$string['optiondates_teacher_deleted'] = 'Trainer:in wurde aus Trainingsjournal entfernt';
$string['booking_failed'] = 'Buchung gescheitert';

// View.php.
$string['bookingpolicyagree'] = 'Ich habe die Buchungsbedingungen gelesen und erkläre mich damit einverstanden.';
$string['bookingpolicynotchecked'] = 'Sie haben die Buchungsbedingungen nicht akzeptiert.';
$string['allbookingoptions'] = 'Nutzer:innen für alle Buchungsoptionen herunterladen';
$string['attachedfiles'] = 'Dateianhänge';
$string['availability'] = 'Noch verfügbar ';
$string['available'] = 'Plätze verfügbar';
$string['booked'] = 'Gebucht';
$string['fullybooked'] = 'Ausgebucht';
$string['notifyme'] = 'Benachrichtigen wenn frei';
$string['alreadyonlist'] = 'Sie werden benachrichtigt';
$string['bookedpast'] = 'Gebucht (Kurs wurde bereits beendet)';
$string['bookingdeleted'] = 'Ihre Buchung wurde erfolgreich storniert';
$string['bookingmeanwhilefull'] = 'Leider hat inzwischen jemand anderer den letzten Platz gebucht';
$string['bookingsaved'] = '<b>Vielen Dank für Ihre Buchung!</b> <br /> Ihre Buchung wurde erfolgreich gespeichert und ist somit abgeschlossen. Sie können nun weitere Online-Seminare buchen oder bereits getätigte Buchungen verwalten';
$string['booknow'] = 'Jetzt buchen';
$string['bookotherusers'] = 'Buchung für andere Nutzer:innen durchführen';
$string['cancelbooking'] = 'Buchung stornieren';
$string['closed'] = 'Buchung beendet';
$string['confirmbookingoffollowing'] = 'Bitte bestätigen Sie folgende Buchung';
$string['confirmdeletebookingoption'] = 'Möchten Sie diese Buchung wirklich löschen?';
$string['coursedate'] = 'Kurstermin';
$string['createdbywunderbyte'] = 'Dieses Buchungsmodul wurde von der Wunderbyte GmbH entwickelt';
$string['deletebooking'] = 'Wollen Sie wirklich folgende Buchung stornieren? <br /><br /> <b>{$a} </b>';
$string['deletethisbookingoption'] = 'Diese Buchungsoption löschen';
$string['deleteuserfrombooking'] = 'Buchung für Nutzer:innen wirklich stornieren?';
$string['download'] = 'Download';
$string['downloadusersforthisoptionods'] = 'Nutzer:innen im .ods-Format herunterladen';
$string['downloadusersforthisoptionxls'] = 'Nutzer:innen im  .xls-Format herunterladen';
$string['endtimenotset'] = 'Kursende nicht festgelegt';
$string['eventduration'] = 'Dauer';
$string['eventpoints'] = 'Punkte';
$string['mailconfirmationsent'] = 'Sie erhalten in Kürze ein Bestätigungsmail an die in Ihrem Profil angegebene E-Mail Adresse';
$string['managebooking'] = 'Verwalten';
$string['maxperuserwarning'] = 'Sie haben zur Zeit ein Limit von {$a->count}/{$a->limit} Buchungen';
$string['mustfilloutuserinfobeforebooking'] = 'Bevor Sie buchen, füllen Sie bitte noch Ihre persönlichen Buchungsdaten aus';
$string['nobookingselected'] = 'Keine Buchungsoption ausgewählt';
$string['norighttobook'] = 'Sie haben zur Zeit keine Berechtigung Buchungen vorzunehmen. Loggen Sie sich ein, schreiben Sie sich in diesen Kurs ein oder kontaktieren Sie den/die Administrator/in.';
$string['notbooked'] = 'Noch nicht gebucht';
$string['onwaitinglist'] = 'Sie sind auf der Warteliste';
$string['organizatorname'] = 'Name des Organisators';
$string['organizatorname_help'] = 'Sie können den Namen des Organisators/der Organisatorin manuell eingeben oder aus einer Liste von
                                    früheren Organisator:innen auswählen. Sie können nur eine/n Organisator/in angeben. Sobald
                                    Sie speichern, wird der/die Organisator/in zur Liste hinzugefügt.';
$string['availableplaces'] = 'Verfügbare Plätze: {$a->available} von {$a->maxanswers}';
$string['pollurl'] = 'Link zur Umfrage';
$string['pollurlteachers'] = 'Trainer:innen Umfragelink';
$string['feedbackurl'] = 'Link zur Umfrage';
$string['feedbackurlteachers'] = 'Trainer:innen Umfragelink';
$string['select'] = 'Auswahl';
$string['activebookingoptions'] = 'Aktuelle Buchungsoptionen';
$string['showallbookingoptions'] = 'Alle Buchungsoptionen';
$string['starttimenotset'] = 'Kursbeginn nicht festgelegt';
$string['subscribetocourse'] = 'Nutzer:innen in den Kurs einschreiben';
$string['subscribeuser'] = 'Wollen Sie diese User wirklich in diesen Kurs einschreiben';
$string['tagtemplates'] = 'Schlagwort Vorlagen';
$string['unlimitedplaces'] = 'Unbegrenzt';
$string['userdownload'] = 'Nutzer:innenliste herunterladen';
$string['waitinglist'] = 'Warteliste';
$string['waitingplacesavailable'] = 'Verfügbare Wartelistenplätze:  {$a->overbookingavailable} von {$a->maxoverbooking}';
$string['waitspaceavailable'] = 'Wartelistenplätze verfügbar';
$string['duplicatebooking'] = 'Diese Buchungsoption duplizieren';
$string['showmybookingsonly'] = 'Meine Buchungen';
$string['showmyfieldofstudyonly'] = "Mein Studiengang";
$string['moveoptionto'] = 'Buchungsoption in andere Buchungsinstanz verschieben';

// Tag templates.
$string['cancel'] = 'Abbrechen';
$string['addnewtagtemplate'] = 'Hinzufügen';
$string['addnewtagtemplate'] = 'Neue Schlagwort-Vorlage hinzufügen';
$string['savenewtagtemplate'] = 'Speichern';
$string['tagtag'] = 'Schlagwort';
$string['tagtext'] = 'Schlagwort-Text';
$string['wrongdataallfields'] = 'Bitte alle Felder ausfüllen!';
$string['tagsuccessfullysaved'] = 'Schlagwort erfolgreich gespeichert.';
$string['edittag'] = 'Bearbeiten';

// Mod_booking\all_options.
$string['showdescription'] = 'Beschreibung anzeigen';
$string['hidedescription'] = 'Beschreibung verstecken';
$string['cancelallusers'] = 'Alle gebuchten Teilnehmer:innen stornieren';

// Mod_form.
$string['signinlogoheader'] = 'Logo in der Kopfzeile auf der Unterschriftenliste';
$string['signinlogofooter'] = 'Logo in der Fußzeile auf der Unterschriftenliste';
$string['textdependingonstatus'] = 'Statusabhängiger Buchungsoptionstext';
$string['beforebookedtext'] = 'Vor der Buchung';
$string['beforecompletedtext'] = 'Nach der Buchung';
$string['beforecompletedtext_help'] = 'Text der vor dem Abschluss angezeigt wird';
$string['aftercompletedtext'] = 'Nach Aktivitätsabschluss';
$string['aftercompletedtext_help'] = 'Text, der nach dem Abschluss angezeigt wird';
$string['connectedbooking'] = '[VERALTET] Vorgeschaltete Buchung';
$string['errorpagination'] = 'Geben Sie ein Zahl ein, die größer als 0 ist';
$string['notconectedbooking'] = 'Nicht vorgeschaltete Buchung';
$string['connectedbooking_help'] = 'Buchung von der Teilnehmer:innen übernommen werden. Es kann bestimmt werden wie viele Teilnehmer:innen übernommen werden.';
$string['allowbookingafterstart'] = 'Buchen nach Kursbeginn erlauben';
$string['cancancelbook'] = 'Teilnehmer:innen dürfen Buchungen selbst stornieren';
$string['cancancelbookdays'] = 'Nutzer:innen können nur bis n Tage vor Kursstart stornieren. Negative Werte meinen n Tage NACH Kursstart.';
$string['cancancelbookdays:semesterstart'] = 'Nutzer:innen können nur bis n Tage vor <b>Semesterbeginn</b> stornieren. Negative Werte meinen n Tage NACH Semesterbeginn.';
$string['cancancelbookdays:bookingopeningtime'] = 'Nutzer:innen können nur bis n Tage vor <b>Anmeldebeginn (Buchungsbeginn)</b> stornieren. Negative Werte meinen n Tage NACH Anmeldebeginn.';
$string['cancancelbookdays:bookingclosingtime'] = 'Nutzer:innen können nur bis n Tage vor <b>Anmeldeschluss (Buchungsende)</b> stornieren. Negative Werte meinen n Tage NACH Anmeldeschluss.';
$string['cancancelbookdaysno'] = 'Kein Limit';
$string['addtocalendar'] = 'Zum Kurs-Kalender hinzufügen';
$string['caleventtype'] = 'Kalenderereignis ist sichtbar für';
$string['caldonotadd'] = 'Nicht zum Kurs-Kalender hinzufügen';
$string['caladdascourseevent'] = 'Zum Kalender hinzufügen (nur für Kursteilnehmer:innen sichtbar)';
$string['caladdassiteevent'] = 'Zum Kalender hinzufügen (für alle Nutzer:innen sichtbar)';
$string['limitanswers'] = 'Teilnehmeranzahl beschränken';
$string['maxparticipantsnumber'] = 'Maximale Teilnehmeranzahl';
$string['maxoverbooking'] = 'Maximale Anzahl der Wartelistenplätze';
$string['minanswers'] = 'Mindestteilnehmerzahl';
$string['defaultbookingoption'] = 'Standardeinstellungen für Buchungsoptionen';
$string['activatemails'] = 'E-Mails aktivieren (Bestätigungen, Erinnerungen etc.)';
$string['copymail'] = 'Eine Kopie der Bestätigungsmail an den Buchungsverwalter senden';
$string['bookingpolicy'] = 'Buchungsbedingungen - Booking Policy';

$string['eventslist'] = 'Letzte Bearbeitungen';
$string['showrecentupdates'] = 'Zeige die letzten Bearbeitungen';

$string['error:semestermissingbutcanceldependentonsemester'] = 'Die Einstellung zur Berechnung der
Stornierungsfrist ab Semesterbeginn ist aktiv, aber das Semester fehlt!';

$string['page:bookingpolicy'] = 'Buchungsbedingungen';
$string['page:bookitbutton'] = 'Buchen';
$string['page:subbooking'] = 'Zusätzliche Buchungen';
$string['page:confirmation'] = 'Buchung abgeschlossen';
$string['page:checkout'] = 'Zur Bezahlung';
$string['page:customform'] = 'Formular ausfüllen';

$string['confirmationmessagesettings'] = 'Buchungsbestätigungseinstellungen';
$string['usernameofbookingmanager'] = 'Buchungsverwalter/in auswählen';
$string['usernameofbookingmanager_help'] = 'Nutzername des/der Nutzer/in, der als Absender/in der Buchungsbestätigunsmitteilungen angeführt wird. Wenn die Option "Eine Kopie des Bestätigungsmail an Buchungsverwalter senden" aktiviert ist, wird die Kopie der Buchungsbestätigung an diese/n Nutzer/in gesendet.';
$string['bookingmanagererror'] = 'Der angegebene Nutzername ist ungültig. Entweder existiert der/die Nutzer/in nicht oder es gibt mehrere Nutzer:innen mit dem selben Nutzernamen (Dies ist zum Beispiel der Fall, wenn Sie MNET und lokale Authentifizierung gleichzeitig aktiviert haben)';
$string['autoenrol'] = 'Nutzer:innen automatisch einschreiben';
$string['autoenrol_help'] = 'Fals ausgewählt werden Nutzer:innen automatisch in den Kurs eingeschrieben sobald sie die Buchung durchgeführt haben und wieder ausgetragen, wenn die Buchung storniert wird.';
$string['bookedtext'] = 'Buchungsbestätigung';
$string['userleave'] = 'Nutzer/in hat Buchung storniert (0 eingeben zum Ausschalten)';
$string['waitingtext'] = 'Wartelistenbestätigung';
$string['statuschangetext'] = 'Statusänderungsbenachrichtigung';
$string['deletedtext'] = 'Stornierungsbenachrichtigung (0 eingeben zum Ausschalten)';
$string['bookingchangedtext'] = 'Benachrichtigung bei Änderungen an der Buchung (geht nur an User, die bereits gebucht haben). Verwenden Sie den Platzhalter {changes} um die Änderungen anzuzeigen. 0 eingeben um Änderungsbenachrichtigungen auszuschalten.';
$string['comments'] = 'Kommentare';
$string['nocomments'] = 'Kommentare deaktiviert';
$string['allcomments'] = 'Jede/r kann kommentieren';
$string['enrolledcomments'] = 'Nur Eingeschriebene können kommentieren';
$string['completedcomments'] = 'Nur diejenigen, die Aktivität abgeschlossen haben';
$string['ratings'] = 'Bewertung der Buchungsoption';
$string['noratings'] = 'Bewertungen deaktiviert';
$string['allratings'] = 'Jede/r kann bewerten';
$string['enrolledratings'] = 'Nur Eingeschriebene können bewerten';
$string['completedratings'] = 'Nur diejenigen, die Aktivität abgeschlossen haben';
$string['shorturl'] = 'Verkürzter Link zu dieser Buchungsoption';
// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
/* $string['generatenewurl'] = 'Neue Kurz-URL generieren'; */
$string['notes'] = 'Anmerkungen zur Buchung';
$string['uploadheaderimages'] = 'Header-Bilder für Buchungsoptionen';
$string['bookingimagescustomfield'] = 'Benutzerdefiniertes Feld von Buchungsoptionen, mit dem die Header-Bilder gematcht werden';
$string['bookingimages'] = 'Header-Bilder für Buchungsoptionen hochladen - diese müssen exakt den selben Namen haben, wie der jeweilige Wert, den das ausgewählte benutzerdefinierte Feld in der jeweiligen Buchungsoption hat.';
$string['emailsettings'] = 'E-Mail-Einstellungen';

// Mail templates (Instanz-spezifisch oder global).
$string['mailtemplatesadvanced'] = 'Erweiterte Einstelllungen für E-Mail-Vorlagen aktivieren';
$string['mailtemplatessource'] = 'Quelle von E-Mail-Vorlagen festlegen ' . $string['badge:pro'];
$string['mailtemplatessource_help'] = '<b>Achtung:</b> Wenn Sie globale E-Mail-Vorlagen wählen, werden die Instanz-spezifischen
E-Mail-Vorlagen nicht verwendet, sondern die E-Mail-Vorlagen, die in den Einstellungen des Buchungs-Plugins angelegt
wurden. <br><br>Bitte stellen Sie sicher, dass zu allen E-Mail-Typen eine Vorlage vorhanden ist.';
$string['mailtemplatesinstance'] = 'E-Mail-Vorlagen aus dieser Buchungsinstanz verwenden (Standard)';
$string['mailtemplatesglobal'] = 'Globale E-Mail-Vorlagen aus den Plugin-Einstellungen verwenden';

$string['feedbackurl_help'] = 'Link zu einem Feedback-Formular, das an Teilnehmer:innen gesendet werden soll.
 Verwenden Sie in E-Mails den Platzhalter <b>{pollurl}</b>.';

$string['feedbackurlteachers_help'] = 'Link zu einem Feedback-Formular, das an Trainer:innen gesendet werden soll.
Verwenden Sie in E-Mails den Platzhalter <b>{pollurlteachers}</b>.';

$string['bookedtext_help'] = 'Lassen Sie dieses Feld leer, um den Standardtext der Website zu verwenden. Folgende Platzhalter können im Text verwendet werden:
<ul>
<li>{bookingdetails} - Zusammenfassung der Buchung (inkl. Sessions und Link zur Buchungsoption)</li>
<li>{gotobookingoption} - Link zur Buchungsoption</li>
<li>{status} - Buchungsstatus</li>
<li>{participant}</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name der ersten Trainer:in</li>
<li>{teachers} - Liste aller Trainer:innen</li>
<li>{teacherN} - Name eines spezifischen Trainers. Z.B. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - QR Code der Userid einfügen</li>
<li>{qr_username} - QR Code des Usernamen einfügen</li>
<li>{dates} - Sessions (bei mehreren Terminen)</li>
<li>{shorturl} - Verkürzte URL der Buchungsoption</li>
<li>{usercalendarurl} - Link zum Abonnieren des User-Kalenders (persönliche Ereignisse)</li>
<li>{coursecalendarurl} - Link zum Abonnieren des Kurs-Kalenders (Kurs-Ereignisse)</li>
</ul>';

$string['userleave_help'] = 'Lassen Sie dieses Feld leer, um den Standardtext der Website zu verwenden. Folgende Platzhalter können im Text verwendet werden:
<ul>
<li>{bookingdetails} - Zusammenfassung der Buchung (inkl. Sessions und Link zur Buchungsoption)</li>
<li>{gotobookingoption} - Link zur Buchungsoption</li>
<li>{status} - Buchungsstatus</li>
<li>{participant}</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name der ersten Trainer:in</li>
<li>{teachers} - Liste aller Trainer:innen</li>
<li>{teacherN} - Name eines spezifischen Trainers. Z.B. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - QR Code der Userid einfügen</li>
<li>{qr_username} - QR Code des Usernamen einfügen</li>
<li>{dates} - Sessions (bei mehreren Terminen)</li>
<li>{shorturl} - Verkürzte URL der Buchungsoption</li>
<li>{usercalendarurl} - Link zum Abonnieren des User-Kalenders (persönliche Ereignisse)</li>
<li>{coursecalendarurl} - Link zum Abonnieren des Kurs-Kalenders (Kurs-Ereignisse)</li>
</ul>';

$string['waitingtext_help'] = 'Lassen Sie dieses Feld leer, um den Standardtext der Website zu verwenden. Folgende Platzhalter können im Text verwendet werden:
<ul>
<li>{bookingdetails} - Zusammenfassung der Buchung (inkl. Sessions und Link zur Buchungsoption)</li>
<li>{gotobookingoption} - Link zur Buchungsoption</li>
<li>{status} - Buchungsstatus</li>
<li>{participant}</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name der ersten Trainer:in</li>
<li>{teachers} - Liste aller Trainer:innen</li>
<li>{teacherN} - Name eines spezifischen Trainers. Z.B. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - QR Code der Userid einfügen</li>
<li>{qr_username} - QR Code des Usernamen einfügen</li>
<li>{dates} - Sessions (bei mehreren Terminen)</li>
<li>{shorturl} - Verkürzte URL der Buchungsoption</li>
<li>{usercalendarurl} - Link zum Abonnieren des User-Kalenders (persönliche Ereignisse)</li>
<li>{coursecalendarurl} - Link zum Abonnieren des Kurs-Kalenders (Kurs-Ereignisse)</li>
</ul>';

$string['notifyemail_help'] = 'Lassen Sie dieses Feld leer, um den Standardtext der Website zu verwenden. Folgende Platzhalter können im Text verwendet werden:
<ul>
<li>{bookingdetails} - Zusammenfassung der Buchung (inkl. Sessions und Link zur Buchungsoption)</li>
<li>{gotobookingoption} - Link zur Buchungsoption</li>
<li>{status} - Buchungsstatus</li>
<li>{participant}</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name der ersten Trainer:in</li>
<li>{teachers} - Liste aller Trainer:innen</li>
<li>{teacherN} - Name eines spezifischen Trainers. Z.B. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - QR Code der Userid einfügen</li>
<li>{qr_username} - QR Code des Usernamen einfügen</li>
<li>{dates} - Sessions (bei mehreren Terminen)</li>
<li>{shorturl} - Verkürzte URL der Buchungsoption</li>
<li>{usercalendarurl} - Link zum Abonnieren des User-Kalenders (persönliche Ereignisse)</li>
<li>{coursecalendarurl} - Link zum Abonnieren des Kurs-Kalenders (Kurs-Ereignisse)</li>
</ul>';

$string['notifyemailteachers_help'] = 'Lassen Sie dieses Feld leer, um den Standardtext der Website zu verwenden. Folgende Platzhalter können im Text verwendet werden:
<ul>
<li>{bookingdetails} - Zusammenfassung der Buchung (inkl. Sessions und Link zur Buchungsoption)</li>
<li>{gotobookingoption} - Link zur Buchungsoption</li>
<li>{numberparticipants} - Anzahl der Teilnehmer:innen (ohne Warteliste)</li>
<li>{numberwaitinglist} - Anzahl der Teilnehmer:innen auf der Warteliste</li>
<li>{status} - Buchungsstatus</li>
<li>{participant}</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name der ersten Trainer:in</li>
<li>{teachers} - Liste aller Trainer:innen</li>
<li>{teacherN} - Name eines spezifischen Trainers. Z.B. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - QR Code der Userid einfügen</li>
<li>{qr_username} - QR Code des Usernamen einfügen</li>
<li>{dates} - Sessions (bei mehreren Terminen)</li>
<li>{shorturl} - Verkürzte URL der Buchungsoption</li>
<li>{usercalendarurl} - Link zum Abonnieren des User-Kalenders (persönliche Ereignisse)</li>
<li>{coursecalendarurl} - Link zum Abonnieren des Kurs-Kalenders (Kurs-Ereignisse)</li>
</ul>';

$string['statuschangetext_help'] = 'Lassen Sie dieses Feld leer, um den Standardtext der Website zu verwenden. Folgende Platzhalter können im Text verwendet werden:
<ul>
<li>{bookingdetails} - Zusammenfassung der Buchung (inkl. Sessions und Link zur Buchungsoption)</li>
<li>{gotobookingoption} - Link zur Buchungsoption</li>
<li>{status} - Buchungsstatus</li>
<li>{participant}</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name der ersten Trainer:in</li>
<li>{teachers} - Liste aller Trainer:innen</li>
<li>{teacherN} - Name eines spezifischen Trainers. Z.B. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - QR Code der Userid einfügen</li>
<li>{qr_username} - QR Code des Usernamen einfügen</li>
<li>{dates} - Sessions (bei mehreren Terminen)</li>
<li>{shorturl} - Verkürzte URL der Buchungsoption</li>
<li>{usercalendarurl} - Link zum Abonnieren des User-Kalenders (persönliche Ereignisse)</li>
<li>{coursecalendarurl} - Link zum Abonnieren des Kurs-Kalenders (Kurs-Ereignisse)</li>
</ul>';

$string['deletedtext_help'] = 'Lassen Sie dieses Feld leer, um den Standardtext der Website zu verwenden. Folgende Platzhalter können im Text verwendet werden:
<ul>
<li>{bookingdetails} - Zusammenfassung der Buchung (inkl. Sessions und Link zur Buchungsoption)</li>
<li>{gotobookingoption} - Link zur Buchungsoption</li>
<li>{status} - Buchungsstatus</li>
<li>{participant}</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name der ersten Trainer:in</li>
<li>{teachers} - Liste aller Trainer:innen</li>
<li>{teacherN} - Name eines spezifischen Trainers. Z.B. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - QR Code der Userid einfügen</li>
<li>{qr_username} - QR Code des Usernamen einfügen</li>
<li>{dates} - Sessions (bei mehreren Terminen)</li>
<li>{shorturl} - Verkürzte URL der Buchungsoption</li>
<li>{usercalendarurl} - Link zum Abonnieren des User-Kalenders (persönliche Ereignisse)</li>
<li>{coursecalendarurl} - Link zum Abonnieren des Kurs-Kalenders (Kurs-Ereignisse)</li>
</ul>';

$string['bookingchangedtext_help'] = '0 eingeben um Änderungsbenachrichtigungen auszuschalten.

Folgende Platzhalter können im Text verwendet werden:
<ul>
<li>{changes} - Was hat sich geändert?</li>
<li>{bookingdetails} - Zusammenfassung der Buchung (inkl. Sessions und Link zur Buchungsoption)</li>
<li>{gotobookingoption} - Link zur Buchungsoption</li>
<li>{status} - Buchungsstatus</li>
<li>{participant}</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name der ersten Trainer:in</li>
<li>{teachers} - Liste aller Trainer:innen</li>
<li>{teacherN} - Name eines spezifischen Trainers. Z.B. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - QR Code der Userid einfügen</li>
<li>{qr_username} - QR Code des Usernamen einfügen</li>
<li>{dates} - Sessions (bei mehreren Terminen)</li>
<li>{shorturl} - Verkürzte URL der Buchungsoption</li>
<li>{usercalendarurl} - Link zum Abonnieren des User-Kalenders (persönliche Ereignisse)</li>
<li>{coursecalendarurl} - Link zum Abonnieren des Kurs-Kalenders (Kurs-Ereignisse)</li>
</ul>';

$string['pollurltext_help'] = 'Lassen Sie dieses Feld leer, um den Standardtext der Website zu verwenden. Folgende Platzhalter können im Text verwendet werden:
<ul>
<li>{bookingdetails} - Zusammenfassung der Buchung (inkl. Sessions und Link zur Buchungsoption)</li>
<li>{gotobookingoption} - Link zur Buchungsoption</li>
<li>{status} - Buchungsstatus</li>
<li>{participant}</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name der ersten Trainer:in</li>
<li>{teachers} - Liste aller Trainer:innen</li>
<li>{teacherN} - Name eines spezifischen Trainers. Z.B. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - QR Code der Userid einfügen</li>
<li>{qr_username} - QR Code des Usernamen einfügen</li>
<li>{dates} - Sessions (bei mehreren Terminen)</li>
<li>{shorturl} - Verkürzte URL der Buchungsoption</li>
<li>{usercalendarurl} - Link zum Abonnieren des User-Kalenders (persönliche Ereignisse)</li>
<li>{coursecalendarurl} - Link zum Abonnieren des Kurs-Kalenders (Kurs-Ereignisse)</li>
</ul>';

$string['pollurlteacherstext_help'] = 'Lassen Sie dieses Feld leer, um den Standardtext der Website zu verwenden. Folgende Platzhalter können im Text verwendet werden:
<ul>
<li>{bookingdetails} - Zusammenfassung der Buchung (inkl. Sessions und Link zur Buchungsoption)</li>
<li>{gotobookingoption} - Link zur Buchungsoption</li>
<li>{status} - Buchungsstatus</li>
<li>{participant}</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name der ersten Trainer:in</li>
<li>{teachers} - Liste aller Trainer:innen</li>
<li>{teacherN} - Name eines spezifischen Trainers. Z.B. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - QR Code der Userid einfügen</li>
<li>{qr_username} - QR Code des Usernamen einfügen</li>
<li>{dates} - Sessions (bei mehreren Terminen)</li>
<li>{shorturl} - Verkürzte URL der Buchungsoption</li>
<li>{usercalendarurl} - Link zum Abonnieren des User-Kalenders (persönliche Ereignisse)</li>
<li>{coursecalendarurl} - Link zum Abonnieren des Kurs-Kalenders (Kurs-Ereignisse)</li>
</ul>';

$string['activitycompletiontext_help'] = 'Lassen Sie dieses Feld leer, um den Standardtext der Website zu verwenden. Folgende Platzhalter können im Text verwendet werden:
<ul>
<li>{bookingdetails} - Zusammenfassung der Buchung (inkl. Sessions und Link zur Buchungsoption)</li>
<li>{gotobookingoption} - Link zur Buchungsoption</li>
<li>{status} - Buchungsstatus</li>
<li>{participant}</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name der ersten Trainer:in</li>
<li>{teachers} - Liste aller Trainer:innen</li>
<li>{teacherN} - Name eines spezifischen Trainers. Z.B. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - QR Code der Userid einfügen</li>
<li>{qr_username} - QR Code des Usernamen einfügen</li>
<li>{dates} - Sessions (bei mehreren Terminen)</li>
<li>{shorturl} - Verkürzte URL der Buchungsoption</li>
<li>{usercalendarurl} - Link zum Abonnieren des User-Kalenders (persönliche Ereignisse)</li>
<li>{coursecalendarurl} - Link zum Abonnieren des Kurs-Kalenders (Kurs-Ereignisse)</li>
</ul>';

$string['notificationtext_help'] = 'Lassen Sie dieses Feld leer, um den Standardtext der Website zu verwenden. Folgende Platzhalter können im Text verwendet werden:
<ul>
<li>{bookingdetails} - Zusammenfassung der Buchung (inkl. Sessions und Link zur Buchungsoption)</li>
<li>{gotobookingoption} - Link zur Buchungsoption</li>
<li>{status} - Buchungsstatus</li>
<li>{participant}</li>
<li>{title}</li>
<li>{duration}</li>
<li>{starttime}</li>
<li>{endtime}</li>
<li>{startdate}</li>
<li>{enddate}</li>
<li>{courselink}</li>
<li>{bookinglink}</li>
<li>{pollurl}</li>
<li>{pollurlteachers}</li>
<li>{location}</li>
<li>{institution}</li>
<li>{address}</li>
<li>{eventtype}</li>
<li>{teacher} - Name der ersten Trainer:in</li>
<li>{teachers} - Liste aller Trainer:innen</li>
<li>{teacherN} - Name eines spezifischen Trainers. Z.B. {teacher1}</li>
<li>{pollstartdate}</li>
<li>{qr_id} - QR Code der Userid einfügen</li>
<li>{qr_username} - QR Code des Usernamen einfügen</li>
<li>{dates} - Sessions (bei mehreren Terminen)</li>
<li>{shorturl} - Verkürzte URL der Buchungsoption</li>
<li>{usercalendarurl} - Link zum Abonnieren des User-Kalenders (persönliche Ereignisse)</li>
<li>{coursecalendarurl} - Link zum Abonnieren des Kurs-Kalenders (Kurs-Ereignisse)</li>
</ul>';

$string['placeholders_help'] = 'Lassen Sie dieses Feld leer, um den Standardtext der Website zu verwenden. Folgende Platzhalter können im Text verwendet werden:
  <ul>
  <li>{bookingdetails} - Zusammenfassung der Buchung (inkl. Sessions und Link zur Buchungsoption)</li>
  <li>{gotobookingoption} - Link zur Buchungsoption</li>
  <li>{status} - Buchungsstatus</li>
  <li>{participant}</li>
  <li>{profilepicture} - Profilbild</li>
  <li>{title}</li>
  <li>{duration}</li>
  <li>{starttime}</li>
  <li>{endtime}</li>
  <li>{startdate}</li>
  <li>{enddate}</li>
  <li>{courselink}</li>
  <li>{bookinglink}</li>
  <li>{pollurl}</li>
  <li>{pollurlteachers}</li>
  <li>{location}</li>
  <li>{institution}</li>
  <li>{address}</li>
  <li>{eventtype}</li>
  <li>{teacher} - Name der ersten Trainer:in</li>
  <li>{teachers} - Liste aller Trainer:innen</li>
  <li>{teacherN} - Name eines spezifischen Trainers. Z.B. {teacher1}</li>
  <li>{pollstartdate}</li>
  <li>{qr_id} - QR Code der Userid einfügen</li>
  <li>{qr_username} - QR Code des Usernamen einfügen</li>
  <li>{dates} - Sessions (bei mehreren Terminen)</li>
  <li>{shorturl} - Verkürzte URL der Buchungsoption</li>
  <li>{usercalendarurl} - Link zum Abonnieren des User-Kalenders (persönliche Ereignisse)</li>
  <li>{coursecalendarurl} - Link zum Abonnieren des Kurs-Kalenders (Kurs-Ereignisse)</li>
  </ul>';

$string['helptext:placeholders'] = '<p>
<a data-toggle="collapse" href="#collapsePlaceholdersHelptext" role="button" aria-expanded="false" aria-controls="collapsePlaceholdersHelptext">
  <i class="fa fa-question-circle" aria-hidden="true"></i><span>&nbsp;Sie können die folgenden Platzhalter verwenden...</span>
</a>
</p>
<div class="collapse" id="collapsePlaceholdersHelptext">
  <div class="card card-body">
    <ul>
        <li>{title} - Titel der Buchungsoption</li>
        <li>{bookingdetails} - Zusammenfassung der Buchung (inkl. Sessions und Link zur Buchungsoption)</li>
        <li>{gotobookingoption} - Link zur Buchungsoption</li>
        <li>{journal} - Link zu "Vertretungen & Absagen" (Trainings-Journal)</li>
        <li>{status} - Buchungsstatus</li>
        <li>{participant}</li>
        <li>{duration}</li>
        <li>{starttime}</li>
        <li>{endtime}</li>
        <li>{startdate}</li>
        <li>{enddate}</li>
        <li>{courselink}</li>
        <li>{bookinglink}</li>
        <li>{pollurl}</li>
        <li>{pollurlteachers}</li>
        <li>{location}</li>
        <li>{institution}</li>
        <li>{address}</li>
        <li>{eventtype}</li>
        <li>{teacher} - Name der ersten Trainer:in</li>
<li>{teachers} - Liste aller Trainer:innen</li>
        <li>{teacherN} - Name eines spezifischen Trainers. Z.B. {teacher1}</li>
        <li>{pollstartdate}</li>
        <li>{qr_id} - QR Code der Userid einfügen</li>
        <li>{qr_username} - QR Code des Usernamen einfügen</li>
        <li>{dates} - Sessions (bei mehreren Terminen)</li>
        <li>{shorturl} - Verkürzte URL der Buchungsoption</li>
        <li>{usercalendarurl} - Link zum Abonnieren des User-Kalenders (persönliche Ereignisse)</li>
        <li>{coursecalendarurl} - Link zum Abonnieren des Kurs-Kalenders (Kurs-Ereignisse)</li>
    </ul>
  </div>
</div>';

$string['configurefields'] = 'Spalten und Felder anpassen';
$string['manageresponsespagefields'] = 'Buchungen verwalten - Seite';
$string['manageresponsesdownloadfields'] = 'Buchungen verwalten - Download (CSV, XLSX...)';
$string['optionspagefields'] = 'Buchungsübersicht - Seite';
$string['optionsdownloadfields'] = 'Buchungsübersicht - Download (CSV, XLSX...)';
$string['signinsheetfields'] = 'Auf der Unterschriftenliste (PDF-Download)';
$string['signinonesession'] = 'Termin(e) im Header anzeigen';
$string['signinaddemptyrows'] = 'Leeren Zeilen hinzufügen';
$string['signinextrasessioncols'] = 'Extra-Spalten für Termine hinzufügen';
$string['signinadddatemanually'] = 'Datum händisch eintragen';
$string['signinhidedate'] = 'Termine ausblenden';
$string['includeteachers'] = 'Trainer:innen in Unterschriftenliste anführen';
$string['choosepdftitle'] = 'Wählen Sie einen Titel für die Unterschriftenliste';
$string['additionalfields'] = 'Zusätzliche Felder';
$string['addtogroup'] = 'Nutzer:innen automatisch in Gruppe einschreiben';
$string['addtogroup_help'] = 'Nutzer:innen automatisch in Gruppe eintragen. Die Gruppe wird nach folgendem Schema automatisch erstellt: Aktivitätsname - Name der Buchungsoption';
$string['bookingattachment'] = 'Anhänge';
$string['bookingcategory'] = 'Kategorie';
$string['bookingduration'] = 'Dauer';
$string['bookingorganizatorname'] = 'Name des Veranstalters';
$string['bookingpoints'] = 'Kurspunkte';
$string['bookingpollurl'] = 'Link zur Umfrage';
$string['bookingpollurlteachers'] = 'Link zur Trainer:innen-Umfrage';
$string['bookingtags'] = 'Schlagwörter';
$string['customlabelsdeprecated'] = '[VERALTET] Benutzerdefinierte Bezeichnungen';
$string['editinstitutions'] = 'Institutionen bearbeiten';
$string['entervalidurl'] = 'Bitte geben Sie eine gültige URL an!';
$string['eventtype'] = 'Art des Ereignisses';
$string['eventtype_help'] = 'Sie können den Namen der Ereignisart manuell eingeben oder aus einer Liste von
                            früheren Ereignisarten auswählen. Sie können nur eine Ereignisart angeben. Sobald
                            Sie speichern, wird die Ereignisart zur Liste hinzugefügt.';
$string['groupname'] = 'Gruppenname';
$string['lblacceptingfrom'] = 'Bezeichnung für: Annehmen von';
$string['lblbooking'] = 'Bezeichnung für: Buchung';
$string['lblinstitution'] = 'Bezeichnung für: Institution';
$string['lbllocation'] = 'Bezeichnung für: Ort';
$string['lblname'] = 'Bezeichnung für: Name';
$string['lblnumofusers'] = 'Bezeichnung für: Nutzer:innenanzahl';
$string['lblsurname'] = 'Bezeichnung für: Nachname';
$string['maxperuser'] = 'Maximale Anzahl an Buchungen pro User';
$string['maxperuser_help'] = 'Die maximale Anzahl an Buchungen, die ein/e Nutzer/in auf einmal buchen kann. Nach dem Ende des gebuchten Kurses, zählt dieser nicht mehr zum Buchungslimit.';
$string['notificationtext'] = 'Benachrichtigungstext';
$string['numgenerator'] = 'Automatische Seitennummerierung aktivieren?';
$string['paginationnum'] = 'Anzahl der Einträge pro Seite';
$string['pollurlteacherstext'] = 'Umfragetext für Trainer:innen';
$string['pollurltext'] = 'Umfragelink senden';
$string['reset'] = 'Zurücksetzen';
$string['searchtag'] = 'Schlagwortsuche';
$string['showinapi'] = 'In API anzeigen?';
$string['whichview'] = 'Standardansicht in der Buchungsoptionsübersicht';
$string['whichviewerror'] = 'Die Standardansicht muss auch in den Ansichten der Buchungsoptionsübersicht ausgewählt werden';
$string['showviews'] = 'Ansichten der Buchungsoptionsübersicht';
$string['enablepresence'] = 'Präsenzstatus aktivieren';
$string['removeuseronunenrol'] = 'Nutzer/in von Buchungsoption autom. entfernen wenn diese/r aus dem dazugehörenden Moodle-Kurs ausgetragen wurde?';

// Editoptions.php.
$string['editbookingoption'] = 'Buchungsoption bearbeiten';
$string['createnewbookingoption'] = 'Neue Buchungsoption';
$string['createnewbookingoptionfromtemplate'] = 'Neue Buchungsoption von Vorlage erstellen';
$string['connectedmoodlecourse'] = 'Verbundener Moodle-Kurs';
$string['connectedmoodlecourse_help'] = 'Wählen Sie "Neuen Kurs erstellen...", wenn Sie wollen, dass ein neuer Moodle-Kurs für diese Buchungsoption angelegt werden soll.';
$string['courseendtime'] = 'Kursende';
$string['coursestarttime'] = 'Kursbeginn';
$string['newcourse'] = 'Neuen Kurs erstellen...';
$string['donotselectcourse'] = 'Kein Kurs ausgewählt';
$string['donotselectinstitution'] = 'Keine Institution ausgewählt';
$string['donotselectlocation'] = 'Kein Ort ausgewählt';
$string['donotselecteventtype'] = 'Keine Ereignisart ausgewählt';
$string['importcsvbookingoption'] = 'Buchungsoptionen via CSV-Datei importieren';
$string['importexcelbutton'] = 'Aktivitätsabschluss importieren';
$string['activitycompletiontext'] = 'Nachricht an Nutzer/in, wenn Buchungsoption abgeschlossen ist';
$string['activitycompletiontextsubject'] = 'Buchungsoption abgeschlossen';
$string['changesemester'] = 'Termine für Semester neu erstellen';
$string['changesemester:warning'] = '<strong>Achtung:</strong> Durch Klicken auf "Änderungen speichern" werden alle bisherigen Termine gelöscht und durch die Termine
im ausgewählten Semester ersetzt.';
$string['changesemesteradhoctaskstarted'] = 'Erfolg. Sobald CRON das nächste Mal läuft, werden die Termine neu erstellt. Dies kann einige Minuten dauern.';
$string['activitycompletiontextmessage'] = 'Sie haben die folgende Buchungsoption abgeschlossen:

{$a->bookingdetails}

Zum Kurs: {$a->courselink}
Alle Buchungsoptionen ansehen: {$a->bookinglink}';
$string['sendmailtobooker'] = 'Buchung für andere User durchführen: Mail an User, der Buchung durchführt, anstatt an gebuchte User senden';
$string['sendmailtobooker_help'] = 'Diese Option aktivieren, um Buchungsbestätigungsmails anstatt an die gebuchten Nutzer:innen zu senden an den/die Nutzer/in senden, die die Buchung durchgeführt hat. Dies betrifft nur Buchungen, die auf der Seite "Buchung für andere Nutzer:innen durchführen" getätigt wurden';
$string['startendtimeknown'] = 'Kursbeginn und Kursende sind bekannt';
$string['submitandadd'] = 'Speichern und neue';
$string['submitandstay'] = 'Speichern';
$string['waitinglisttaken'] = 'Auf der Warteliste';
$string['groupexists'] = 'Die Gruppe existiert bereits im Zielkurs. Bitte verwenden Sie einen anderen Namen für die Buchungsoption';
$string['groupdeleted'] = 'Diese Buchung erstellt automatisch Gruppen im Zielkurs. Aber die Gruppe wurde im Zielkurs manuell gelöscht. Aktivieren Sie folgende Checkbox, um die Gruppe erneut zu erstellen';
$string['recreategroup'] = 'Gruppe erneut anlegen und Nutzer:innen der Gruppe zuordnen';
$string['copy'] = ' - Kopie';
$string['enrolmentstatus'] = 'Nutzer:innen erst zu Kursbeginn in den Kurs einschreiben (Standard: Nicht angehakt &rarr; sofort einschreiben.)';
$string['enrolmentstatus_help'] = 'Achtung: Damit die automatische Einschreibung funktioniert,
 müssen Sie in den Einstellungen der Buchungsinstanz "Nutzer:innen automatisch einschreiben" auf "Ja" setzen.';
$string['duplicatename'] = 'Diese Bezeichnung für eine Buchungsoption existiert bereits. Bitte wählen Sie eine andere.';
$string['newtemplatesaved'] = 'Neue Buchungsoptionsvorlage wurde gespeichert.';
$string['option_template_not_saved_no_valid_license'] = 'Buchungsoption konnte nicht als Vorlage gespeichert werden.
                                                  Holen Sie sich die PRO-Version, um beliebig viele Vorlagen erstellen
                                                  zu können.';
$string['toggleformmode_simple'] = '<i class="fa fa-compress" aria-hidden="true"></i> Wechsle zu Einfach-Modus';
$string['toggleformmode_expert'] = '<i class="fa fa-expand" aria-hidden="true"></i> Wechsle zu Experten-Modus';

// Option_form.php.
$string['bookingoptionimage'] = 'Bild hochladen';
$string['submitandgoback'] = 'Speichern und zurück';
$string['bookingoptionprice'] = 'Preis';

// We removed this, but keep it for now as we might need these strings again.
// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
/*$string['er_saverelationsforoptiondates'] = 'Entity auch für jeden Termin speichern';
$string['confirm:er_saverelationsforoptiondates'] = '<span class="text-danger">
<b>Achtung:</b> Diese Buchungsoption hat Termine mit unterschiedlichen Entities.
Wollen Sie wirklich ALLE Termine mit der ausgewählten Entity überschreiben?</span>';
$string['error:er_saverelationsforoptiondates'] = 'Bitte bestätigen Sie, dass Sie abweichende Entities überschreiben wollen.';*/

$string['pricecategory'] = 'Preiskategorie';
$string['pricecurrency'] = 'Währung';
$string['optionvisibility'] = 'Sichtbarkeit';
$string['optionvisibility_help'] = 'Stellen Sie ein, ob die Buchungsoption für jede_n sichtbar sein soll oder nur für berechtigte Nutzer:innen.';
$string['optionvisible'] = 'Für alle sichtbar (Standard)';
$string['optioninvisible'] = 'Vor normalen Nutzer:innen verstecken (nur für berechtigte Personen sichtbar)';
$string['invisibleoption'] = '<i class="fa fa-eye-slash" aria-hidden="true"></i> Unsichtbar';
$string['optionannotation'] = 'Interne Anmerkung';
$string['optionannotation_help'] = 'Fügen Sie interne Notizen bzw. Anmerkungen hinzu. Diese werden NUR in DIESEM Formular und sonst nirgendwo angezeigt.';
$string['optionidentifier'] = 'Identifikator';
$string['optionidentifier_help'] = 'Geben Sie einen eindeutigen Identifikator für diese Buchungsoption an.';
$string['titleprefix'] = 'Präfix';
$string['titleprefix_help'] = 'Fügen Sie ein Präfix hinzu, das vor dem Titel angezeigt wird, z.B. "BB42".';
$string['error:identifierexists'] = 'Wählen Sie einen anderen Identifikator. Dieser existiert bereits.';

// Optionview.php.
$string['invisibleoption:notallowed'] = 'Sie sind nicht berechtigt, diese Buchungsoption zu sehen.';

// Importoptions.php.
$string['csvfile'] = 'CSV Datei';
$string['dateerror'] = 'Falsche Datumsangabe in Zeile {$a}: ';
$string['dateparseformat'] = 'Datumsformat';
$string['dateparseformat_help'] = 'Bitte Datum so wie es im CSV definiert wurde verwenden. Hilfe unter <a href="http://php.net/manual/en/function.date.php">Datumsdokumentation</a> für diese Einstellung.';
$string['defaultdateformat'] = 'j.n.Y H:i:s';
$string['importcsvtitle'] = 'CSV-Datei importieren';
$string['importfinished'] = 'Importieren beendet!';
$string['noteacherfound'] = 'Die Nutzer/in die in Zeile {$a} in der Spalte für teacher angeführt wurde, existiert nicht auf der Plattform';
$string['nouserfound'] = 'Kein/e User/in gefunden: ';
$string['import_failed'] = 'Der CSV-Import wurde aufgrund folgendes Fehlers nicht durchgeführt: ';
$string['import_partial'] = 'Der CSV-Import wurde nur teilweise durchgeführt. Bei folgenden Zeilen traten Fehler auf und sie wurden nicht importiert: ';
$string['importinfo'] = 'Import info: Folgende Spalten können importiert werden (Erklärung des Spaltennamens in Klammern)';
$string['coursedoesnotexist'] = 'Die Kursnummer {$a} existiert nicht';

// New importer.
$string['importcsv'] = 'CSV Importer';
$string['import_identifier'] = 'Einzigartiger Identifikator einer Buchungsoption';
$string['import_tileprefix'] = 'Prefix (z.b. Kursnummer)';
$string['import_title'] = 'Titel einer Buchungsoption';
$string['import_text'] = 'Titel einer Buchungsoption (Synonym zu text)';
$string['import_location'] = 'Ort einer Buchungsoption. Wird automatisch bei 100% Übereinstimmung mit dem Klarnamen einer "Entity" (local_entities) verknüpft. Auch die ID Nummer einer Entity kann hier eingegeben werden.';
$string['import_identifier'] = 'Einzigartiger Identifikator einer Buchungsoption';
$string['import_maxanswers'] = 'Maximale Anzahl von Buchungen pro Buchungsoption';
$string['import_maxoverbooking'] = 'Maximale Anzahl an Wartelistenplätzen pro Buchungsoption';
$string['import_coursenumber'] = 'Moodle ID Nummer eines Moodle Kurses, in den die Buchenden eingeschrieben werden';
$string['import_courseshortname'] = 'Kurzname eines Moodle Kurses, in den die Buchenden eingeschrieben werden';
$string['import_addtocalendar'] = 'Zum Moodle Kalender hinzufügen';
$string['import_dayofweek'] = 'Wochentag einer Buchungsoption, z.B. Montag';
$string['import_dayofweektime'] = 'Wochentag und Zeit einer Buchungsoption, z.B. Montag, 10:00 - 12:00';
$string['import_dayofweekstarttime'] = 'Anfangszeit eines Kurses, z.B. 10:00';
$string['import_dayofweekendtime'] = 'Endzeit eines Kurses, z.B. 12:00';
$string['import_description'] = 'Beschreibung der Buchungsoption';
$string['import_default'] = 'Standardpreis einer Buchungsoption. Nur wenn der Standardpreis gesetzt ist, können weitere Preise angegeben werden. Die Spalten müssen dafür den Kurznamen der Buchungskategorien entsprechen.';
$string['import_teacheremail'] = 'E-Mail Adressen von Nutzerinnen auf der Plattform, die als LehrerInnen in den Buchungsoptionen hinterlegt werden können. Bei mehreren e-mail Adressen Komma als Trennzeichen verwenden (aufpassen auf "Escape" bei Komma getrennten CSV!)';
$string['import_useremail'] = 'E-Mail Adressen von Nutzerinnen auf der Plattform, die diese Buchungsoption gebucht haben. Bei mehreren e-mail Adressen Komma als Trennzeichen verwenden (aufpassen auf "Escape" bei Komma getrennten CSV!)';

$string['importsuccess'] = 'Import war erfolgreich. Es wurden {$a} Datensatz/Datensätze bearbeitet.';
$string['importfailed'] = 'Import fehlgeschlagen.';
$string['dateparseformat'] = 'Format des Datums';
$string['dateparseformat_help'] = 'Bitte Datum so wie es im CSV definiert wurde verwenden. Hilfe unter <a href="http://php.net/manual/en/function.date.php">Datumsdokumentation</a> für diese Einstellung.';
$string['defaultdateformat'] = 'j.n.Y H:i:s';
$string['importcolumnsinfos'] = 'Informationen zu Importfeldern:';
$string['mandatory'] = 'verpflichtend';
$string['optional'] = 'optional';
$string['format'] = 'Format';
$string['openformat'] = 'offenes Format';
$string['downloaddemofile'] = 'Demofile herunterladen';
$string['updatedrecords'] = '{$a} Eintrag/Einträge aktualisiert.';
$string['addedrecords'] = '{$a} Eintrag/Einträge hinzugefügt.';
$string['callbackfunctionnotdefined'] = 'Callback Funktion nicht definiert.';
$string['callbackfunctionnotapplied'] = 'Callback Funktion konnte nicht angewandt werden.';
$string['ifdefinedusedtomatch'] = 'Wenn angegeben findet der Abgleich über diesen Wert statt.';
$string['fieldnamesdontmatch'] = 'Die importierten Spaltennamen entsprechen nicht der Vorgabe.';
$string['checkdelimiteroremptycontent'] = 'Überprüfen Sie ob Daten vorhanden und durch das angegebene Zeichen getrennt sind.';
$string['wronglabels'] = 'Die importierten Spaltennamen entsprechen nicht der Vorgabe. {$a} kann nicht importiert werden.';
$string['missinglabel'] = 'Im importierten File fehlt die verpflichtede Spalte {$a}. Daten können nicht importiert werden.';
$string['nolabels'] = 'Keine Spaltennamen definiert.';
$string['checkdelimiter'] = 'Überprüfen Sie die Spaltennamen durch das angegebene Zeichen getrennt sind.';
$string['dataincomplete'] = 'Der Datensatz mit "componentid" {$a->id} ist unvollständig und konnte nicht gänzlich eingefügt werden. Überprüfen Sie das Feld "{$a->field}".';
$string['modelinformation'] = 'Dieses Feld ist notwendig, um Fragen vollständig zu erfassen. Ist das Feld leer, kann die Frage lediglich einer Skala zugeordnet werden.';

// Confirmation mail.
$string['days'] = '{$a} Tage';
$string['hours'] = '{$a} Stunden';
$string['minutes'] = '{$a} Minuten';

$string['deletedtextsubject'] = 'Storno von {$a->title}, User: {$a->participant}';
$string['deletedtextmessage'] = 'Folgende Buchung wurde storniert: {$a->title}

Nutzer/in: {$a->participant}
Titel: {$a->title}
Datum: {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
Kurs: {$a->courselink}
Link: {$a->bookinglink}


';

$string['bookedtextsubject'] = 'Buchungsbestätigung für {$a->title}';
$string['bookedtextsubjectbookingmanager'] = 'Neue Buchung für {$a->title} von {$a->participant}';
$string['bookedtextmessage'] = 'Ihre Buchung wurde registriert:

{$a->bookingdetails}
<p>##########################################</p>
Buchungsstatus: {$a->status}
Teilnehmer:   {$a->participant}

Zur Buchungsübersicht: {$a->bookinglink}
Hier geht\'s zum dazugehörigen Kurs: {$a->courselink}
';
$string['waitingtextsubject'] = 'Buchung auf Warteliste für {$a->title}';
$string['waitingtextsubjectbookingmanager'] = 'Wartelistenbuchung für {$a->title} von {$a->participant}';

$string['waitingtextmessage'] = 'Sie sind nun auf der Warteliste von:

{$a->bookingdetails}
<p>##########################################</p>
Buchungsstatus: {$a->status}
Teilnehmer:   {$a->participant}

Zur Buchungsübersicht: {$a->bookinglink}
Hier geht\'s zum dazugehörigen Kurs: {$a->courselink}
';

$string['notifyemailsubject'] = 'Ihre Buchung startet demnächst';
$string['notifyemailmessage'] = 'Ihre Buchung startet demnächst:

{$a->bookingdetails}

Name:   {$a->participant}

Um eine Übersicht über alle Buchungen zu erhalten, klicken Sie auf den folgenden Link:
{$a->bookinglink}

Hier geht\'s zum Kurs:  {$a->courselink}
';
$string['notifyemail'] = 'Teilnehmer:innen-Benachrichtigung vor dem Beginn';

$string['notifyemailteacherssubject'] = 'Ihre Buchung startet demnächst';
$string['notifyemailteachersmessage'] = 'Ihre Buchung startet demnächst:

{$a->bookingdetails}

Sie haben <b>{$a->numberparticipants} gebuchte Teilnehmer:innen</b> und <b>{$a->numberwaitinglist} Personen auf der Warteliste</b>.

Um eine Übersicht über alle Buchungen zu erhalten, klicken Sie auf den folgenden Link:
{$a->bookinglink}

Hier geht\'s zum Kurs:  {$a->courselink}
';
$string['notifyemailteachers'] = 'Trainer:innen-Benachrichtigung vor dem Beginn ' . $string['badge:pro'];

$string['userleavesubject'] = 'Sie wurden erfolgreich abgemeldet von: {$a->title}';
$string['userleavemessage'] = 'Hallo {$a->participant},

Sie wurden erfolgreich von {$a->title} abgemeldet.
';

$string['statuschangetextsubject'] = 'Buchungstatus für {$a->title} geändert';
$string['statuschangetextmessage'] = 'Guten Tag, {$a->participant}!

Ihr Buchungsstatus hat sich geändert.

Ihr Buchungsstatus: {$a->status}

Teilnehmer/in:   {$a->participant}
Buchungsoption: {$a->title}
Termin:  {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}

Link zur Buchungsoption: {$a->gotobookingoption}
';

$string['deletedbookingusersubject'] = 'Stornobestätigung für {$a->title}';
$string['deletedbookingusermessage'] = 'Guten Tag {$a->participant},

Die Buchung für {$a->title} wurde erfolgreich storniert
';

$string['bookingchangedtextsubject'] = 'Änderungsbenachrichtigung für {$a->title}';
$string['bookingchangedtextmessage'] = 'Ihre Buchung "{$a->title}" hat sich geändert.

Das ist neu:
{changes}

Klicken Sie auf den folgenden Link um die Änderung(en) und eine Übersicht über alle Buchungen zu sehen: {$a->bookinglink}
';

$string['error:failedtosendconfirmation'] = 'Folgender User hat kein Bestätigungsmail erhalten
Die Buchung wurde erfolgreich durchgeführt, das Senden des Bestätigungsmails ist aber fehlgeschlagen.

Buchungsstatus: {$a->status}
User:   {$a->participant}
Gebuchte Buchungsoption: {$a->title}
Kurstermin: {$a->date}
Link: {$a->bookinglink}

';

$string['pollurltextsubject'] = 'Bitte nehmen Sie an der Umfrage teil';
$string['pollurltextmessage'] = 'Bitte nehmen Sie an der Umfrage teil:

Link zur Umfrage: <a href="{pollurl}" target="_blank">{pollurl}</a>
';

$string['pollurlteacherstextsubject'] = 'Bitte nehmen Sie an der Umfrage teil';
$string['pollurlteacherstextmessage'] = 'Bitte nehmen Sie an der Umfrage teil:

Link zur Umfrage: <a href="{pollurlteachers}" target="_blank">{pollurlteachers}</a>
';

$string['reportremindersubject'] = 'Erinnerung: Ihr gebuchter Kurs';
$string['reportremindermessage'] = '{$a->bookingdetails}';

// Report.php and bookingmanagusers.class.php.
$string['allmailssend'] = 'Alle Benachrichtigungen wurden erfolgreich versandt!';
$string['associatedcourse'] = 'Dazu gehörender Kurs';
$string['bookedusers'] = 'Gebuchte Nutzer:innen';
$string['deletedusers'] = 'Gelöschte Nutzer:innen';
$string['reservedusers'] = 'Nutzer:innen mit kurzfristigen Reservierungen';
$string['bookingfulldidntregister'] = 'Es wurden nicht alle Nutzer:innen übertragen, da die Option bereits ausgebucht ist!';
$string['booktootherbooking'] = 'Nutzer:innen umbuchen / zu anderer Buchungsoption hinzufügen';
$string['downloadallresponses'] = 'Alle Buchungen herunterladen';
$string['editotherbooking'] = 'Andere Buchungsoptionen';
$string['generaterecnum'] = "Eintragsnummern erstellen";
$string['generaterecnumareyousure'] = "Neue Nummern erstellen und die alten verwerfen!";
$string['generaterecnumnotification'] = "Neue Nummern erfolgreich erstellt.";
$string['gotobooking'] = '&lt;&lt; Zu den Buchungen';
$string['lblbooktootherbooking'] = 'Bezeichnung für den Button "Zu anderer Buchungsoption hinzufügen"';
$string['no'] = 'Nein';
$string['nocourse'] = 'Kein Kurs für Buchungsoption ausgewählt';
$string['nousers'] = 'Keine Nutzer:innen!';
$string['numrec'] = "Eintragsnummer.";
$string['onlythisbookingoption'] = 'Nur diese Buchungsoption';
$string['optionid'] = 'Option ID';
$string['optiondatesmanager'] = 'Termine verwalten';
$string['optionmenu'] = 'Diese Buchungsoption';
$string['ratingsuccessful'] = 'Die Bewertungen wurden erfolgreich aktualisiert';
$string['searchdate'] = 'Datum';
$string['searchname'] = 'Vorname';
$string['searchsurname'] = 'Nachname';
$string['selectatleastoneuser'] = 'Mindestens 1 Nutzer/in auswählen!';
$string['selectanoption'] = 'Wählen Sie eine Buchungsoption aus!';
$string['selectoptionid'] = 'Eine Auswahl treffen';
$string['sendcustommessage'] = 'Persönliche Nachricht senden';
$string['sendreminderemailsuccess'] = 'Benachrichtung wurde per E-Mail versandt';
$string['sign_in_sheet_download'] = 'Unterschriftenliste herunterladen';
$string['status_complete'] = "Abgeschlossen";
$string['status_incomplete'] = "Nicht abgeschlossen";
$string['status_noshow'] = "Nicht aufgetaucht";
$string['status_failed'] = "Nicht erfolgreich";
$string['status_unknown'] = "Unbekannt";
$string['status_attending'] = "Teilgenommen";
$string['presence'] = "Anwesenheit";
$string['toomuchusersbooked'] = 'Maximale Anzahl an Nutzer:innen, die Sie buchen können: {$a}';
$string['transfer'] = 'Umbuchen';
$string['transferheading'] = 'Ausgewählte Nutzer:innen in die ausgewählte Buchungsoption umbuchen';
$string['transfersuccess'] = 'Die Nutzer:innen wurden erfolgreich umgebucht';
$string['transferoptionsuccess'] = 'Die Buchungsoption und die registrierten Nutzer:innen wurden erfolgreich umgebucht';
$string['transferproblem'] = 'Die folgenden Nutzer:innen konnten aufgrund einer limitierten Anzahl an Plätzen der Buchungsoption oder aufgrund individueller Limitierungen seitens des/der Nutzer/in nicht umgebucht werden: {$a}';
$string['userssuccessfullenrolled'] = 'Alle Nutzer:innen wurden erfolgreich eingeschrieben!';
$string['userssuccessfullybooked'] = 'Alle Nutzer:innen wurden erfolgreich in die andere Buchungsoption eingeschrieben.';
$string['waitinglistusers'] = 'Nutzer:innen auf der Warteliste';
$string['withselected'] = 'Ausgewählte Nutzer:innen';
$string['yes'] = 'Ja';
$string['signature'] = 'Unterschrift';
$string['userssucesfullygetnewpresencestatus'] = 'Anwesenheitsstatus für ausgewählte Nutzer:innen erfolgreich aktualisiert';
$string['copytotemplate'] = 'Buchungsoption als Vorlage speichern';
$string['copytotemplatesucesfull'] = 'Buchungsoption erfolgreich als Vorlage gespeichert';

// Send message.
$string['booking:cansendmessages'] = 'Kann Nachrichten schicken.';
$string['messageprovider:sendmessages'] = 'Kann Nachrichten schicken';
$string['activitycompletionsuccess'] = 'Alle Nutzer:innen wurden für den Aktivitätsabschluss ausgewählt';
$string['booking:communicate'] = 'Can communicate';
$string['confirmoptioncompletion'] = 'Abschluss bestätigen';
$string['enablecompletion'] = 'Es muss mindestens eine der Buchungen als abgeschlossen markiert werden.';
$string['enablecompletiongroup'] = 'Aktivitätsabschluss';
$string['messagesend'] = 'Die Nachricht wurde erfolgreich versandt.';
$string['messagesubject'] = 'Betreff';
$string['messagetext'] = 'Nachricht';
$string['sendmessage'] = 'Nachricht senden';

// Teachers_handler.php.
$string['teachersforoption'] = 'Trainer:innen';
$string['teachersforoption_help'] = '<b>ACHTUNG:</b> Wenn Sie hier Trainer:innen hinzufügen werden diese im Training-Journal <b>zu JEDEM ZUKÜNFTIGEN Termin hinzugefügt</b>.
Wenn Sie hier Trainer:innen löschen, werden diese im Training-Journal <b>von JEDEM ZUKÜNFTIGEN Termin entfernt</b>.';
$string['info:teachersforoptiondates'] = 'Wechseln Sie zum <a href="{$a}" target="_self">Trainingsjournal</a>, um die Trainer:innen für spezifische Termine zu protokollieren.';

// Lib.php.
$string['pollstrftimedate'] = '%Y-%m-%d';
$string['sessionremindermailsubject'] = 'Erinnerung: Sie haben demnächst einen Kurstermin';
$string['sessionremindermailmessage'] = '<p>Erinnerung: Sie haben den folgenden Termin gebucht:</p>
<p>{$a->optiontimes}</p>
<p>##########################################</p>
<p>{$a->sessiondescription}</p>
<p>##########################################</p>
<p>Buchungsstatus: {$a->status}</p>
<p>Teilnehmer: {$a->participant}</p>
';

// All_users.php.
$string['completed'] = 'Abgeschlossen';
$string['usersonlist'] = 'Nutzer:innen';
$string['fullname'] = 'Voller Name';
$string['timecreated'] = 'Erstellungsdatum';

// Importexcel.php.
$string['importexceltitle'] = 'Aktivitätsabschluss importieren';

// Importexcel_file.php.
$string['excelfile'] = 'CSV Datei mit Aktivitätsabschluss';

// Instancetemplateadd.php.
$string['saveinstanceastemplate'] = 'Buchung als Vorlage hinzufügen';
$string['thisinstance'] = 'Diese Buchung';
$string['instancetemplate'] = 'Buchungsinstanz-Vorlage';
$string['instancesuccessfullysaved'] = 'Diese Buchung wurde erfolgreich als Vorlage gespeichert.';
$string['instance_not_saved_no_valid_license'] = 'Buchung konnte nicht als Vorlage gespeichert werden.
                                                  Holen Sie sich die PRO-Version, um beliebig viele Vorlagen erstellen
                                                  zu können.';
$string['bookinginstancetemplatessettings'] = 'Buchung: Vorlagen für Buchungsinstanzen';
$string['bookinginstancetemplatename'] = 'Name der Buchungsinstanz-Vorlage';
$string['managebookinginstancetemplates'] = 'Buchungsinstanz-Vorlagen verwalten';
$string['populatefromtemplate'] = 'Mit Vorlage ausfüllen';

// Institutions.php.
$string['institutions'] = 'Institutionen';

// Otherbooking.php.
$string['otherbookingoptions'] = 'Nutzer:innen dieser Buchungsoption zulassen';
$string['otherbookingnumber'] = 'Nutzer:innen-Anzahl';
$string['otherbookingaddrule'] = 'Neue Buchungsoption hinzufügen';
$string['editrule'] = "Bearbeiten";
$string['deleterule'] = 'Löschen';
$string['deletedrule'] = 'Buchungsoption erfolgreich gelöscht';

// Otherbookingaddrule_form.php.
$string['selectoptioninotherbooking'] = "Auswahl";
$string['otherbookinglimit'] = "Limit";
$string['otherbookinglimit_help'] = "Anzahl der Nutzer:innen die von dieser Buchungsoption akzeptiert werden. 0 bedeutet unlimitiert.";
$string['otherbookingsuccessfullysaved'] = 'Buchungsoption gespeichert!';

// Optiondates.php.
$string['optiondatestime'] = 'Termine';
$string['optiondatesmessage'] = 'Termin {$a->number}: {$a->date} <br> Von: {$a->starttime} <br> Bis: {$a->endtime}';
$string['optiondatessuccessfullysaved'] = "Termin wurde bearbeitet";
$string['optiondatessuccessfullydelete'] = "Termin wurde gelöscht";
$string['leftandrightdate'] = '{$a->leftdate} bis {$a->righttdate}';
$string['editingoptiondate'] = 'Sie bearbeiten gerade diesen Termin';
$string['newoptiondate'] = 'Neuen Termin anlegen...';

// Optiondatesadd_form.php.
$string['dateandtime'] = 'Datum und Uhrzeit';
$string['sessionnotifications'] = 'E-Mail-Benachrichtigungen für Einzeltermine';
$string['customfields'] = 'Benutzerdefinierte Felder';
$string['addcustomfieldorcomment'] = 'Kommentar oder benutzerdefiniertes Feld hinzufügen';
$string['customfieldname'] = 'Feldname';
$string['customfieldname_help'] = 'Sie können einen beliebigen Feldnamen angeben. <br>
                                    Die Spezial-Feldnamen
                                    <ul>
                                        <li>TeamsMeeting</li>
                                        <li>ZoomMeeting</li>
                                        <li>BigBlueButtonMeeting</li>
                                    </ul> zeigen in Kombination mit einem Link im Feld "Wert" einen Button mit dem Link an,
                                    der nur während des Meetings (und kurz davor) sichtbar ist.';
$string['customfieldvalue'] = 'Wert';
$string['customfieldvalue_help'] = 'Sie können einen beliebigen Wert für das Feld angeben (Text, Zahl oder HTML).<br>
                                    Sollten Sie einen der Spezial-Feldnamen
                                    <ul>
                                        <li>TeamsMeeting</li>
                                        <li>ZoomMeeting</li>
                                        <li>BigBlueButtonMeeting</li>
                                    </ul> verwendet haben, geben Sie den vollständigen Link zum Meeting beginnend mit https:// oder http:// an.';
$string['deletecustomfield'] = 'Feld löschen?';
$string['deletecustomfield_help'] = 'Achtung: Wenn Sie diese Checkbox aktivieren, wird das zugehörige Feld beim Speichern gelöscht!';
$string['erroremptycustomfieldname'] = 'Name des Felds darf nicht leer sein.';
$string['erroremptycustomfieldvalue'] = 'Wert des Felds darf nicht leer sein.';
$string['daystonotifysession'] = 'Benachrichtigung n Tage vor Beginn';
$string['daystonotifysession_help'] = "Wie viele Tage vor Beginn dieser Session soll an die Teilnehmenden eine Benachrichtigung gesendet werden?
Geben Sie 0 ein, um die E-Mail-Benachrichtigung für diese Session zu deaktivieren.";
$string['nocfnameselected'] = "Nichts ausgewählt. Tippen Sie einen neuen Namen oder wählen Sie einen aus der Liste.";
$string['bigbluebuttonmeeting'] = 'BigBlueButton-Meeting';
$string['zoommeeting'] = 'Zoom-Meeting';
$string['teamsmeeting'] = 'Teams-Meeting';
$string['addcomment'] = 'Kommentar hinzufügen...';

// File: settings.php.
$string['bookingsettings'] = 'Buchung: Einstellungen';
$string['globalcurrency'] = 'Währung';
$string['globalcurrencydesc'] = 'Wählen Sie die Währung für Preise von Buchungsoptionen aus';
$string['globalmailtemplates'] = 'Globale Mailvorlagen ' . $string['badge:pro'];
$string['globalmailtemplates_desc'] = 'Nach der Aktivierung können Sie in den Einstellungen jeder beliebigen Buchungsinstanz die Quelle der Mailvorlagen auf global setzen.';
$string['globalbookedtext'] = 'Buchungsbestätigung (globale Vorlage)';
$string['globalwaitingtext'] = 'Wartelistenbestätigung (globale Vorlage)';
$string['globalnotifyemail'] = 'Teilnehmer:innen-Benachrichtigung vor dem Beginn (globale Vorlage)';
$string['globalnotifyemailteachers'] = 'Trainer:innen-Benachrichtigung vor dem Beginn (globale Vorlage)';
$string['globalstatuschangetext'] = 'Benachrichtigung über Statusänderung (globale Vorlage)';
$string['globaluserleave'] = 'Nutzer/in hat Buchung storniert (globale Vorlage)';
$string['globaldeletedtext'] = 'Stornierungsbenachrichtigung (globale Vorlage)';
$string['globalbookingchangedtext'] = 'Benachrichtigung bei Änderungen an der Buchung (geht nur an User, die bereits gebucht haben). Verwenden Sie den Platzhalter {changes} um die Änderungen anzuzeigen. 0 eingeben um Änderungsbenachrichtigungen auszuschalten. (Globale Vorlage)';
$string['globalpollurltext'] = 'Umfragelink versenden (globale Vorlage)';
$string['globalpollurlteacherstext'] = 'Link zum Absender der Umfrage für Trainer:innen (globale Vorlage)';
$string['globalactivitycompletiontext'] = 'Nachricht an Nutzer/in, wenn Buchungsoption abgeschlossen ist (globale Vorlage)';
$string['licensekeycfg'] = 'PRO-Version aktivieren';
$string['licensekeycfgdesc'] = 'Mit einer PRO-Lizenz können Sie so viele Buchungsvorlagen erstellen wie Sie wollen und PRO-Features wie z.B. globale Mailvorlagen, Info-Texte für Wartelistenplätze und Benachrichtigungen für Trainer:innen nutzen.';
$string['licensekey'] = 'PRO-Lizenz-Schlüssel';
$string['licensekeydesc'] = 'Laden Sie hier einen gültigen Schlüssel hoch, um die PRO-Version zu aktivieren.';
$string['license_activated'] = 'PRO-Version wurde erfolgreich aktiviert.<br>(Läuft ab am: ';
$string['license_invalid'] = 'Ungültiger Lizenz-Schlüssel.';
$string['icalcfg'] = 'iCal-Attachments konfigurieren';
$string['icalcfgdesc'] = 'Konfigurieren Sie die iCal.ics-Dateien, die an E-Mails angehängt werden. Mit diesen Dateien können Sie Buchungstermine zum persönlichen Kalender hinzufügen.';
$string['icalfieldlocation'] = 'Text, der im iCal-Feld angezeigt werden soll';
$string['icalfieldlocationdesc'] = 'Wählen Sie aus der Dropdown-Liste, welcher Text für das Kalender-Feld verwendet werden soll.';
$string['customfield'] = 'Benutzerdefiniertes Feld, dessen Wert in den Buchungsoptionseinstellungen angegeben wird und in der Buchungsoptionsübersicht angezeigt wird';
$string['customfielddesc'] = 'Definieren Sie den Wert dieses Feldes in den Buchungsoptionseinstellungen.';
$string['customfieldconfigure'] = 'Buchung: Benutzerdefinierte Buchungsoptionsfelder';
$string['customfielddef'] = 'Benutzerdefiniertes Buchungsoptionsfeld';
$string['customfieldtype'] = 'Feldtyp';
$string['textfield'] = 'Eingabezeile';
$string['delcustfield'] = 'Dieses Feld und alle dazugehörenden Einstellungen in den Buchungsoptionen löschen';
$string['signinlogo'] = 'Logo für die Unterschriftenliste';
$string['cfgsignin'] = 'Einstellungen für die Unterschriftenliste';
$string['cfgsignin_desc'] = 'Konfiguration der Unterschriftenliste';
$string['pdfportrait'] = 'Hochformat';
$string['pdflandscape'] = 'Querformat';
$string['signincustfields'] = 'Anzuzeigende Profilfelder';
$string['signincustfields_desc'] = 'Wählen Sie die Profilfelder, die auf der Unterschriftenliste abgedruckt werden sollen';
$string['showcustomfields'] = 'Anzuzeigende benutzerdefnierte Buchungsoptionsfelder';
$string['showcustomfields_desc'] = 'Wählen Sie die benutzerdefinierte Buchungsoptionfelder, die auf der Unterschriftenliste abgedruckt werden sollen';

$string['showlistoncoursepage'] = 'Extra-Info auf Kursseite anzeigen';
$string['showlistoncoursepage_help'] = 'Wenn Sie diese Einstellung aktivieren, werden der Kursname, eine Kurzinfo
 und ein Button, der auf die verfügbaren Buchungsoptionen verlinkt, angezeigt.';
$string['hidelistoncoursepage'] = 'Nein, Extra-Info nicht auf Kursseite anzeigen (Standard)';
$string['showcoursenameandbutton'] = 'Kursnamen, Kurzinfo und einen Button, der die verfügbaren Buchungsoptionen öffnet, anzeigen';

$string['coursepageshortinfolbl'] = 'Kurzinfo';
$string['coursepageshortinfolbl_help'] = 'Geben Sie den Kurzinfo-Text ein, der auf der Kursseite angezeigt werden soll.';
$string['coursepageshortinfo'] = 'Wenn Sie diesen Kurs buchen wollen, klicken Sie auf "Verfügbare Optionen anzeigen", treffen Sie eine Auswahl und klicken Sie auf "Jetzt buchen".';

$string['btnviewavailable'] = "Verfügbare Optionen anzeigen";

$string['signinextracols_heading'] = 'Zusätzliche Spalten auf der Unterschriftenliste';
$string['signinextracols'] = 'Extra Spalte auf der Unterschriftenliste';
$string['signinextracols_desc'] = 'Sie können bis zu 3 extra Spalten auf der Unterschriftenliste abbilden. Geben Sie den Titel der Spalte ein, oder lassen Sie das Feld leer, um keine extra Spalte anzuzeigen';
$string['numberrows'] = 'Zeilen nummerieren';
$string['numberrowsdesc'] = 'Nummerierung der Zeilen in der Unterschriftenliste aktivieren. Die Nummer wird links des Namens dargestellt';

$string['availabilityinfotexts_heading'] = 'Beschreibungstexte für verfügbare Buchungs- und Wartelistenplätze ' . $string['badge:pro'];
$string['bookingplacesinfotexts'] = 'Beschreibungstexte für verfügbare Buchungsplätze anzeigen';
$string['bookingplacesinfotexts_info'] = 'Kurze Infotexte anstatt der konkreten Zahl verfügbarer Buchungsplätze anzeigen.';
$string['waitinglistinfotexts'] = 'Beschreibungstexte für verfügbare Wartelistenplätze anzeigen';
$string['waitinglistinfotexts_info'] = 'Kurze Infotexte anstatt der konkreten Zahl verfügbarer Wartelistenplätze anzeigen.';
$string['bookingplaceslowpercentage'] = 'Buchungsplätze: Prozentsatz für "Nur noch wenige Plätze verfügbar"-Nachricht';
$string['bookingplaceslowpercentagedesc'] = 'Wenn die Anzahl verfügbarer Buchungsplätze diesen Prozentsatz erreicht oder unter diesen Prozentsatz sinkt, wird eine Nachricht angezeigt, dass nur noch wenige Plätze verfügbar sind.';
$string['waitinglistlowpercentage'] = 'Warteliste: Prozentsatz für "Nur noch wenige Plätze verfügbar"-Nachricht';
$string['waitinglistlowpercentagedesc'] = 'Wenn die Anzahl verfügbarer Wartelistenplätze diesen Prozentsatz erreicht oder unter diesen Prozentsatz sinkt, wird eine Nachricht angezeigt, dass nur noch wenige Plätze verfügbar sind.';
$string['waitinglistlowmessage'] = 'Nur noch wenige Wartelistenplätze!';
$string['waitinglistenoughmessage'] = 'Noch Wartelistenplätze verfügbar.';
$string['waitinglistfullmessage'] = 'Warteliste ist voll.';
$string['bookingplaceslowmessage'] = 'Nur noch wenige Plätze verfügbar!';
$string['bookingplacesenoughmessage'] = 'Noch Plätze verfügbar.';
$string['bookingplacesfullmessage'] = 'Ausgebucht.';
$string['eventalreadyover'] = 'Diese Veranstaltung ist bereits vorüber.';
$string['nobookingpossible'] = 'Keine Buchung möglich.';

$string['pricecategories'] = 'Buchung: Preiskategorien';

$string['bookingpricesettings'] = 'Preis-Einstellungen';
$string['bookingpricesettings_desc'] = 'Individuelle Einstellungen für die Preise von Buchungen.';

$string['bookwithcreditsactive'] = "Buchen mit Guthaben/Credits";
$string['bookwithcreditsactive_desc'] = "Nutzer:innen mit Guthaben/Credits sehen keinen Preis, sondern können mit ihren Credits buchen.";

$string['bookwithcreditsprofilefieldoff'] = 'Nicht anzeigen';
$string['bookwithcreditsprofilefield'] = "Benutzerdefiniertes Profilfeld für Guthaben/Credits";
$string['bookwithcreditsprofilefield_desc'] = "Um die Funktion nutzen zu können, muss es ein Profilfeld geben, in dem die Credits der Nutzer:innen hiinterlegt werden können.
<span class='text-danger'><b>Achtung:</b> Dieses Feld sollte von den Nutzer:innen nicht bearbeitet werden können.</span>";

$string['cfcostcenter'] = "Benutzerdefiniertes Buchungsoptionsfeld für die Kostenstelle";
$string['cfcostcenter_desc'] = "Wenn Sie Kostenstellen verwenden, müssen Sie hier angeben,
in welchem benutzerdefinierten Buchungsoptionsfeld diese gespeichert werden.";

$string['priceisalwayson'] = 'Preise immer aktiviert';
$string['priceisalwayson_desc'] = 'Wenn Sie dieses Häkchen aktivieren, können Preise für einzelne Buchungsoptionen NICHT abgeschalten werden.
 Es ist aber dennoch möglich, 0 EUR als Preis einzustellen.';

$string['bookingpricecategory'] = 'Preiskategorie"';
$string['bookingpricecategory_info'] = 'Definieren Sie den Namen der Preiskategorie, zum Beispiel "Studierende"';

$string['addpricecategory'] = 'Neue Preiskategorie hinzufügen';
$string['addpricecategory_info'] = 'Sie können eine weitere Preiskategorie definieren.';

$string['userprofilefieldoff'] = 'Nicht anzeigen';
$string['pricecategoryfield'] = 'Nutzerprofilfeld für die Preiskategorie';
$string['pricecategoryfielddesc'] = 'Wählen Sie ein Nutzerprofilfeld aus, in dem für jede/n Nutzer/in der Identifikator der Preiskategorie gesichert wird.';

$string['useprice'] = 'Nur mit Preis buchbar';

$string['teachingreportfortrainer'] = 'Leistungs-Report für Trainer:in';
$string['educationalunitinminutes'] = 'Länge einer Unterrichtseinheit (Minuten)';
$string['educationalunitinminutes_desc'] = 'Hier können Sie die Länge einer Unterrichtseinheit in Minuten angeben. Diese wird zur Berechnung der geleisteten UEs herangezogen.';

$string['duplicationrestore'] = 'Duplizieren, Backup und Wiederherstellen';
$string['duplicationrestoredesc'] = 'Hier können Sie einstellen, welche Informationen beim Duplizieren bzw. beim Backup / Wiederherstellen von Buchungsinstanzen inkludiert werden sollen.';
$string['duplicationrestoreteachers'] = 'Trainer:innen inkludieren';
$string['duplicationrestoreprices'] = 'Preise inkludieren';
$string['duplicationrestoreentities'] = 'Entities inkludieren';
$string['duplicationrestoresubbookings'] = 'Zusatzbuchungen inkludieren ' . $string['badge:pro'];

$string['waitinglistheader'] = 'Warteliste';
$string['waitinglistheader_desc'] = 'Hier können Sie Einstellungen zum Verhalten der Warteliste vornehmen.';
$string['turnoffwaitinglist'] = 'Warteliste global deaktivieren';
$string['turnoffwaitinglist_desc'] = 'Aktivieren Sie diese Einstellung, um die Warteliste auf der gesamten
 Plattform auszuschalten (z.B. weil Sie nur die Benachrichtigungsliste verwenden möchten).';
$string['turnoffwaitinglistaftercoursestart'] = 'Automatisches Nachrücken von der Warteliste ab Beginn der Buchungsoption deaktivieren.';

$string['notificationlist'] = 'Benachrichtigungsliste';
$string['notificationlistdesc'] = 'Wenn es bei einer Buchungsoption keine verfügbaren Plätze mehr gibt,
 können sich Teilnehmer:innnen registrieren lassen, um eine Benachrichtung zu erhalten, sobald wieder
 Plätze verfügbar sind.';
$string['usenotificationlist'] = 'Verwende Benachrichtigungsliste';

$string['subbookings'] = 'Zusatzbuchungen ' . $string['badge:pro'];
$string['subbookings_desc'] = 'Schalten Sie Zusatzbuchungen wie z.B. zusätzlich buchbare Items oder Slot-Buchungen für bestimmte Zeiten (z.B. für Tennisplätze) frei.';
$string['showsubbookings'] = 'Zusatzbuchungen aktivieren';

$string['progressbars'] = 'Fortschrittsbalken für bereits vergangene Zeit ' . $string['badge:pro'];
$string['progressbars_desc'] = 'Mit diesem Feature erhalten Sie eine visuelle Darstellung der bereits vergangenen Zeit von Buchungsoptionen.';
$string['showprogressbars'] = 'Fortschrittsbalken für bereits vergangene Zeit anzeigen';
$string['progressbarscollapsible'] = 'Fortschrittsbalken können ausgeklappt werden';

$string['bookingoptiondefaults'] = 'Standard-Einstellungen für Buchungsoptionen';
$string['bookingoptiondefaultsdesc'] = 'Hier können Sie Standardwerte für die Erstellung von Buchungsoptionen setzen und diese gegebenenfalls sperren.';
$string['addtocalendardesc'] = 'Kurs-Kalenderevents können von ALLEN Kursteilnehmer:innen des Kurses gesehen werden. Falls Sie nicht möchten, dass Kurs-Kalenderevents
erstellt werden, können Sie diese Einstellung standardmäßig ausschalten und sperren. Keine Sorge: Normale Kalenderevents für gebuchte Optionen (User-Events) werden weiterhin erstellt.';

$string['automaticcoursecreation'] = 'Automatische Erstellung von Moodle-Kursen ' . $string['badge:pro'];
$string['newcoursecategorycfield'] = 'Benutzerdefiniertes Buchungsoptionsfeld für Kurskategorie';
$string['newcoursecategorycfielddesc'] = 'Wählen Sie ein benutzerdefiniertes Buchungsoptionsfeld, das verwendet werden soll,
 um die Kurskategorie von automatisch erstellten Kursen festzulegen. Kurse können mit dem Eintrag "Neuen Kurs erstellen..." im Menü "Einen Kurs auswählen"
 des Formulars zum Anlegen von Buchungsoptionen automatisch erstellt werden.';

$string['allowoverbooking'] = 'Überbuchen erlauben';
$string['allowoverbookingheader'] = 'Buchungsoptionen überbuchen ' . $string['badge:pro'];
$string['allowoverbookingheader_desc'] = 'Berechtigten Nutzer:innen erlauben, Kurse zu überbuchen.
 (Achtung: Dies kann zu unerwünschtem Verhalten führen. Nur aktivieren, wenn wirklich benötigt.)';

$string['appearancesettings'] = 'Darstellung ' . $string['badge:pro'];
$string['appearancesettings_desc'] = 'Passen Sie die Darstellung des Buchungsplugins an.';
$string['turnoffwunderbytelogo'] = 'Wunderbyte Logo und Link nicht anzeigen';
$string['turnoffwunderbytelogo_desc'] = 'Wenn diese Einstellung aktiviert ist, werden das Wunderbyte Logo und der Link zur Wunderbyte-Website nicht angezeigt.';

$string['turnoffmodals'] = "Keine Modale verwenden.";
$string['turnoffmodals_desc'] = "Für manche Schritte vor dem Buchen werden aktuell Modale verwendet. Diese Einstellung führt dazu, dass der ganze Prozess direkt in der Seite, ohne Modale, abläuft.";

$string['collapseshowsettings'] = "Klappe Terminanzeige bei mehr als x Terminen zu.";
$string['collapseshowsettings_desc'] = "Um auf der Überblicksseite nicht zu viele Termine auf einmal anzuzeigen, kann hier ein Limit definiert werden, ab dem die Anzeige standardmäßig eingeklappt ist.";

$string['teachersettings'] = 'Trainer:innen ' . $string['badge:pro'];
$string['teachersettings_desc'] = 'Trainer:innen-spezifische Einstellungen.';

$string['teacherslinkonteacher'] = 'Links zu Trainer:innen-Seiten hinzufügen';
$string['teacherslinkonteacher_desc'] = 'Sind bei einer Buchungsoption Trainer:innen definiert, so werden die Namen automatisch mit einer Überblicksseite für diese Trainer:innen verknüpft.';

$string['teachersnologinrequired'] = 'Einloggen bei Trainer:innen-Seiten nicht notwendig';
$string['teachersnologinrequired_desc'] = 'Wenn Sie diese Einstellung aktivieren, dann können die Trainer:innen-Seiten auch von
 nicht-eingeloggten Benutzer:innen gesehen werden.';
$string['teachersshowemails'] = 'E-Mail-Adressen von Trainer:innen immer anzeigen';
$string['teachersshowemails_desc'] = 'Wenn Sie diese Einstellung aktivieren, dann werden allen Benutzer:innen die E-Mail-Adressen der Trainer:innen
  angezeigt, selbst wenn diese nicht eingeloggt sind. <span class="text-danger"><b>Achtung:</b> Dies könnte ein Datenschutz-Problem darstellen. Aktivieren Sie dies nur,
  wenn es die Datenschutzbestimmungen Ihrer Organisation erlauben.</span>';
$string['teachersallowmailtobookedusers'] = 'Trainer:innen erlauben, eine Direkt-Mail an gebuchte Nutzer:innen zu senden';
$string['teachersallowmailtobookedusers_desc'] = 'Wenn Sie diese Einstellung aktivieren, können Trainer:innen eine Direktnachricht
    mit ihrem eigenen Mail-Programm an gebuchte Nutzer:innen senden - die E-Mail-Adressen der gebuchten Nutzer:innen werden dadurch sichtbar.
    <span class="text-danger"><b>Achtung:</b> Dies könnte ein Datenschutz-Problem darstellen. Aktivieren Sie dies nur,
    wenn es die Datenschutzbestimmungen Ihrer Organisation erlauben.</span>';

$string['cancellationsettings'] = 'Stornierungseinstellungen ' . $string['badge:pro'];
$string['canceldependenton'] = 'Stornierungsfristen abhängig von';
$string['canceldependenton_desc'] = 'Wählen Sie aus, auf welches Datumsfeld sich die Einstellung
"Nutzer:innen können nur bis n Tage vor Kursstart stornieren. Negative Werte meinen n Tage NACH Kursstart."
beziehen soll.<br>Dadurch wird auch die <i>Serviceperiode</i> von Kursen im Warenkorb entsprechend festgelegt
(wenn Shopping Cart installiert ist).';
$string['cdo:coursestarttime'] = 'Beginn der Buchungsoption (coursestarttime)';
$string['cdo:semesterstart'] = 'Semesterstart';
$string['cdo:bookingopeningtime'] = 'Buchungsbeginn (bookingopeningtime)';
$string['cdo:bookingclosingtime'] = 'Anmeldeschluss (bookingclosingtime)';

// Optiontemplatessettings.php.
$string['optiontemplatessettings'] = 'Buchungsoptionsvorlagen';
$string['defaulttemplate'] = 'Standard-Vorlage';
$string['defaulttemplatedesc'] = 'Standard-Vorlage für neue Buchungsoptionen';
$string['dontuse'] = 'Vorlage nicht verwenden';
$string['manageoptiontemplates'] = 'Buchungsoptionsvorlagen verwalten';
$string['usedinbookinginstances'] = 'Die Vorlage wird in folgenden Buchungsinstanzen verwendet';
$string['optiontemplatename'] = 'Vorlagenname der Buchungsoption';

// Locallib.php.
$string['signinsheetdate'] = 'Termin(e): ';
$string['signinsheetaddress'] = 'Adresse: ';
$string['signinsheetlocation'] = 'Ort: ';
$string['signinsheetdatetofillin'] = 'Datum: ';
$string['linkgotobookingoption'] = 'Buchung anzeigen: {$a}</a>';

// Custom report templates.
$string['managecustomreporttemplates'] = 'Vorlagen für benutzerdefinierte Berichte verwalten';
$string['customreporttemplates'] = 'Vorlagen für benutzerdefinierte Berichte';
$string['customreporttemplate'] = 'Vorlage für benutzerdefinierten Bericht';
$string['addnewreporttemplate'] = 'Vorlage für Bericht hinzufügen';
$string['templatefile'] = 'Datei für Vorlage';
$string['templatesuccessfullysaved'] = 'Vorlage wurde gespeichert';
$string['customdownloadreport'] = 'Bericht herunterladen';
$string['bookingoptionsfromtemplatemenu'] = 'Neue Buchungsoption aus Vorlage erstellen';

// Automatic option creation.
$string['autcrheader'] = '[VERALTET] Automatisches Erstellen von Buchungsoptionen';
$string['autcrwhatitis'] = 'If this option is enabled it automatically creates a new booking option and assigns
 a user as booking manager / teacher to it. Users are selected based on a custom user profile field value.';
$string['enable'] = 'Enable';
$string['customprofilefield'] = 'Custom profile field to check';
$string['customprofilefieldvalue'] = 'Custom profile field value to check';
$string['optiontemplate'] = 'Option template';

// Link.php.
$string['bookingnotopenyet'] = 'Ihr Event startet erst in {$a} Minuten. Dieser Link wird Sie ab 15 Minuten vor dem Event weiterleiten.';
$string['bookingpassed'] = 'Dieses Event ist nicht mehr aktiv.';
$string['linknotvalid'] = 'Dieser Link / dieses Event ist derzeit nicht verfügbar.
Bitte probieren Sie es kurz vor Beginn noch einmal, wenn Sie dieses Event gebucht haben.';

// Booking_utils.php.
$string['linknotavailableyet'] = 'Der Link auf die Konferenz ist nur zwischen 15 Minuten vor dem Meeting und dem Enddatum hier verfügbar.';
$string['changeinfochanged'] = ' hat sich geändert:';
$string['changeinfoadded'] = ' wurde hinzugefügt:';
$string['changeinfodeleted'] = ' wurde gelöscht:';
$string['changeinfocfchanged'] = 'Ein Feld hat sich geändert:';
$string['changeinfocfadded'] = 'Ein Feld wurde hinzugefügt:';
$string['changeinfocfdeleted'] = 'Ein Feld wurde gelöscht:';
$string['changeinfosessionadded'] = 'Ein Termin wurde hinzugefügt:';
$string['changeinfosessiondeleted'] = 'Ein Termin wurde gelöscht:';

// Bookingoption_changes.mustache.
$string['changeold'] = '[GELÖSCHT] ';
$string['changenew'] = '[NEU] ';

// Bookingoption_description.php.
$string['gotobookingoption'] = 'Buchungsoption anzeigen';
$string['dayofweektime'] = 'Tag & Uhrzeit';
$string['showdates'] = 'Zeige Termine';

// Bookingoptions_simple_table.php.
$string['bsttext'] = 'Buchungsoption';
$string['bstcoursestarttime'] = 'Datum / Uhrzeit';
$string['bstlocation'] = 'Ort';
$string['bstinstitution'] = 'Institution';
$string['bstparticipants'] = 'Teilnehmer:innen';
$string['bstteacher'] = 'Trainer/in(nen)';
$string['bstwaitinglist'] = 'Auf Warteliste';
$string['bstmanageresponses'] = 'Buchungen verwalten';
$string['bstcourse'] = 'Kurs';
$string['bstlink'] = 'Anzeigen';

// All_options.php.
$string['infoalreadybooked'] = '<div class="infoalreadybooked"><i>Sie haben diese Option bereits gebucht.</i></div>';
$string['infowaitinglist'] = '<div class="infowaitinglist"><i>Sie sind auf der Warteliste für diese Option.</i></div>';

$string['tableheader_text'] = 'Kursbezeichnung';
$string['tableheader_teacher'] = 'Trainer:in(nen)';
$string['tableheader_maxanswers'] = 'Verfügbare Plätze';
$string['tableheader_maxoverbooking'] = 'Wartelistenplätze';
$string['tableheader_minanswers'] = 'Mindestteilnehmerzahl';
$string['tableheader_coursestarttime'] = 'Kursbeginn';
$string['tableheader_courseendtime'] = 'Kursende';

// Customfields.
$string['booking_customfield'] = 'Benutzerdefinierte Felder für Buchungsoptionen';

// Optiondates_only.mustache.
$string['sessions'] = 'Termin(e)';

// Message_sent.php.
$string['message_sent'] = 'Nachricht gesendet';

// Price.php.
$string['nopricecategoriesyet'] = 'Es wurden noch keine Preiskategorien angelegt.';
$string['priceformulaisactive'] = 'Beim Speichern Preise mit Preisformel neu berechnen (aktuelle Preise werden überschrieben).';
$string['priceformulainfo'] = '<a data-toggle="collapse" href="#priceformula" role="button" aria-expanded="false" aria-controls="priceformula">
<i class="fa fa-code"></i> Preisformel-JSON anzeigen...
</a>
<div class="collapse" id="priceformula">
<samp>{$a->formula}</samp>
</div><br>
<a href="' . $CFG->wwwroot . '/admin/settings.php?section=modsettingbooking" target="_blank"><i class="fa fa-edit"></i> Formel bearbeiten...</a><br><br>
Unterhalb können Sie zusätzlich einen manuellen Faktor (Multiplikation) und einen Absolutwert (Addition) hinzufügen.';
$string['priceformulamultiply'] = 'Manueller Faktor';
$string['priceformulamultiply_help'] = 'Zusätzlicher Wert mit dem das Ergebnis <strong>multipliziert</strong> werden soll.';
$string['priceformulaadd'] = 'Absolutwert';
$string['priceformulaadd_help'] = 'Zusätzlicher Wert, der zum Ergebnis <strong>addiert</strong> werden soll.';
$string['priceformulaoff'] = 'Neuberechnung der Preise verhindern';
$string['priceformulaoff_help'] = 'Aktivieren Sie diese Option, um zu verhindern, dass die Funktion "Alle Preise der Instanz mit Formel neu berechnen"
 die Preise für diese Buchungsoption neu berechnet.';

// Pricecategories_form.php.
$string['price'] = 'Preis';
$string['additionalpricecategories'] = 'Preiskategorien hinzufügen oder bearbeiten';
$string['defaultpricecategoryname'] = 'Standardpreiskategorie (Name)';
$string['nopricecategoryselected'] = 'Geben Sie den Namen einer neuen Preiskategorie ein';
$string['pricecategoryidentifier'] = 'Identifikator der Preiskategorie';
$string['pricecategoryidentifier_help'] = 'Geben Sie einen Kurztext ein mit dem die Preiskategorie identifiziert werden soll, z.B. "stud" oder "akad".';
$string['pricecategoryname'] = 'Bezeichnung der Preiskategorie';
$string['pricecategoryname_help'] = 'Geben Sie den Namen der Preiskategorie ein, der in Buchungsoptionen angezeigt wird, z.B. "Akademikerpreis".';
$string['defaultvalue'] = 'Standardpreis';
$string['defaultvalue_help'] = 'Geben Sie einen Standardpreis für jeden Preis in dieser Kategorie ein. Natürlich kann dieser Wert später überschrieben werden.';
$string['pricecatsortorder'] = 'Sortierung (Zahl)';
$string['pricecatsortorder_help'] = 'Geben Sie eine ganze Zahl ein. "1" bedeutet, dass die Kategorie auf Platz 1 angezeigt wird, "2" an zweiter Stelle usw.';
$string['disablepricecategory'] = 'Deaktiviere Preiskategorie';
$string['disablepricecategory_help'] = 'Wenn Sie eine Preiskategorie deaktivieren, kann diese nicht mehr benützt werden.';
$string['addpricecategory'] = 'Preiskategorie hinzufügen';
$string['erroremptypricecategoryname'] = 'Name der Preiskategorie darf nicht leer sein.';
$string['erroremptypricecategoryidentifier'] = 'Identifikator der Preiskategorie darf nicht leer sein.';
$string['errorduplicatepricecategoryidentifier'] = 'Identifikatoren von Preiskategorien müssen eindeutig sein.';
$string['errorduplicatepricecategoryname'] = 'Namen von Preiskategorien müssen eindeutig sein.';
$string['errortoomanydecimals'] = 'Sie können maximal 2 Nachkommastellen angeben.';
$string['pricecategoriessaved'] = 'Preiskategorien wurden gespeichert';
$string['pricecategoriessubtitle'] = '<p>Hier können Sie unterschiedliche Kategorien von Preisen definieren,
    z.B. eigene Preiskategorien für Studierende, Mitarbeitende oder Externe.
    <b>Achtung:</b> Sobald Sie eine Kategorie erstellt haben, können Sie diese nicht mehr löschen.
    Sie können Kategorien aber umbenennen oder deaktivieren.</p>';

// Price formula.
$string['defaultpriceformula'] = "Preisformel";
$string['priceformulaheader'] = 'Preisformel ' . $string['badge:pro'];
$string['priceformulaheader_desc'] = "Eine Preisformel verwenden, um Preise automatisch berechnen zu können.";
$string['defaultpriceformuladesc'] = "Das JSON Objekt erlaubt die Konfiguation der automatischen Preisberechnung.";

// Semesters.
$string['booking:semesters'] = 'Buchung: Semester';
$string['semester'] = 'Semester';
$string['semesters'] = 'Semester';
$string['semesterssaved'] = 'Semester wurden gespeichert';
$string['semesterssubtitle'] = 'Hier können Sie <strong>Semester, Ferien und Feiertage</strong> anlegen, ändern und löschen.
    Die Einträge werden nach dem Speichern nach ihrem <strong>Start-Datum abwärts</strong> sortiert.';
$string['addsemester'] = 'Semester hinzufügen';
$string['semesteridentifier'] = 'Identifikator';
$string['semesteridentifier_help'] = 'Kurztext zur Identifikation des Semesters, z.B. "ws22".';
$string['semestername'] = 'Bezeichnung';
$string['semestername_help'] = 'Geben Sie den vollen Namen des Semesters ein, z.B. "Wintersemester 2021/22"';
$string['semesterstart'] = 'Semesterbeginn';
$string['semesterstart_help'] = 'An welchem Tag beginnt das Semester?';
$string['semesterend'] = 'Semesterende';
$string['semesterend_help'] = 'An welchem Tag endet das Semester?';
$string['deletesemester'] = 'Semester löschen';
$string['erroremptysemesteridentifier'] = 'Identifikator des Semesters fehlt.';
$string['erroremptysemestername'] = 'Name des Semesters wurde nicht angegeben.';
$string['errorduplicatesemesteridentifier'] = 'Der Semesteridentifikator muss eindeutig sein.';
$string['errorduplicatesemestername'] = 'Der Name des Semesters muss eindeutig sein.';
$string['errorsemesterstart'] = 'Semesterstart muss vor dem Semesterende sein.';
$string['errorsemesterend'] = 'Semesterende muss nach dem Semesterstart sein.';
$string['choosesemester'] = "Semester auswählen";
$string['choosesemester_help'] = "Wählen Sie das Semester aus, für das der oder die Feiertag(e) erstellt werden sollen.";
$string['holidays'] = "Ferien und Feiertage";
$string['holiday'] = "Ferien / Feiertag(e)";
$string['holidayname'] = "Name (optional)";
$string['holidaystart'] = 'Feiertag / Beginn';
$string['holidayend'] = 'Ende';
$string['holidayendactive'] = 'Ende nicht am selben Tag';
$string['addholiday'] = 'Ferien(tag) hinzufügen';
$string['errorholidaystart'] = 'Ferienbeginn darf nicht nach dem Ferienende liegen.';
$string['errorholidayend'] = 'Ferienende darf nicht vor dem Ferienbeginn liegen.';
$string['deleteholiday'] = 'Eintrag löschen';

// Caches.
$string['cachedef_bookingoptions'] = 'Buchungsoptionen (Cache)';
$string['cachedef_bookingoptionsanswers'] = 'Buchungen von Buchungsoptionen (Cache)';
$string['cachedef_bookingoptionstable'] = 'Tabelle mit gesamten SQL-Abfragen (Cache)';
$string['cachedef_cachedpricecategories'] = 'Preiskategorien in Booking (Cache)';
$string['cachedef_cachedprices'] = 'Standardpreise in Booking (Cache)';
$string['cachedef_cachedbookinginstances'] = 'Buchungsinstanzen (Cache)';
$string['cachedef_bookingoptionsettings'] = 'Settings für Buchungsoptionen (Cache)';
$string['cachedef_cachedsemesters'] = 'Semester (Cache)';
$string['cachedef_cachedteachersjournal'] = 'Vertretungen & Absagen (Cache)';
$string['cachedef_subbookingforms'] = 'Subbooking Forms (Cache)';
$string['cachedef_conditionforms'] = 'Condition Forms (Cache)';
$string['cachedef_confirmbooking'] = 'Buchung bestätigt (Cache)';
$string['cachedef_electivebookingorder'] = 'Elective booking order (Cache)';

// Dates_handler.php.
$string['chooseperiod'] = 'Zeitraum auswählen';
$string['chooseperiod_help'] = 'Wählen Sie den Zeitraum innerhalb dessen die Terminserie erstellt werden soll.';
$string['dates'] = 'Termine';
$string['reoccurringdatestring'] = 'Wochentag, Start- und Endzeit (Tag, HH:MM - HH:MM)';
$string['reoccurringdatestring_help'] = 'Geben Sie einen Text in folgendem Format ein:
    "Tag, HH:MM - HH:MM", z.B. "Montag, 10:00 - 11:00" oder "So 09:00-10:00" oder "Block" bzw. "Blockveranstaltung.';

// Weekdays.
$string['monday'] = 'Montag';
$string['tuesday'] = 'Dienstag';
$string['wednesday'] = 'Mittwoch';
$string['thursday'] = 'Donnerstag';
$string['friday'] = 'Freitag';
$string['saturday'] = 'Samstag';
$string['sunday'] = 'Sonntag';

// Dynamicoptiondateform.php.
$string['add_optiondate_series'] = 'Terminserie erstellen';
$string['reoccurringdatestringerror'] = 'Geben Sie einen Text in folgendem Format ein:
    Tag, HH:MM - HH:MM oder "Block" bzw. "Blockveranstaltung."';
$string['customdatesbtn'] = '<i class="fa fa-plus-square"></i> Benutzerdefinierte Termine...';
$string['aboutmodaloptiondateform'] = 'Hier können Sie benutzerdefinierte Termine anlegen
(z.B. bei Block-Veranstaltungen oder wenn einzelne Termine von der Terminserie abweichen).';
$string['modaloptiondateformtitle'] = 'Benutzerdefinierte Termine';
$string['optiondate'] = 'Termin';
$string['addoptiondate'] = 'Termin hinzufügen';
$string['deleteoptiondate'] = 'Termin entfernen';
$string['optiondatestart'] = 'Beginn';
$string['optiondateend'] = 'Ende';
$string['erroroptiondatestart'] = 'Terminbeginn muss vor dem Terminende liegen.';
$string['erroroptiondateend'] = 'Terminende muss nach dem Terminbeginn liegen.';

// Optiondates_teachers_report.php & optiondates_teachers_table.php.
$string['accessdenied'] = 'Zugriff verweigert';
$string['nopermissiontoaccesspage'] = '<div class="alert alert-danger" role="alert">Sie sind nicht berechtigt, auf diese Seite zuzugreifen.</div>';
$string['optiondatesteachersreport'] = 'Vertretungen & Absagen';
$string['optiondatesteachersreport_desc'] = 'In diesem Report erhalten Sie eine Übersicht, welche:r Trainer:in an welchem Termin geleitet hat.<br>
Standardmäßig werden alle Termine mit dem/den eingestellten Trainer:innen der Buchungsoption befüllt. Sie können einzelne Termine mit Vertretungen überschreiben.';
$string['linktoteachersinstancereport'] = '<p><a href="{$a}" target="_self">&gt;&gt; Zum Trainer:innen-Gesamtbericht für die Buchungsinstanz</a></p>';
$string['noteacherset'] = 'Kein/e Trainer/in';
$string['reason'] = 'Grund';
$string['error:reasonfornoteacher'] = 'Geben Sie einen Grund an, warum an diesem Termin kein/e Trainer/in anwesend war.';
$string['error:reasontoolong'] = 'Grund ist zu lange, geben Sie einen kürzeren Text ein.';
$string['error:reasonforsubstituteteacher'] = 'Geben Sie einen Grund für die Vertretung an.';
$string['error:reasonfordeduction'] = 'Geben Sie einen Grund für den Abzug an.';

// Teachers_instance_report.php.
$string['teachers_instance_report'] = 'Trainer:innen-Gesamtbericht';
$string['error:invalidcmid'] = 'Der Bericht kann nicht geöffnet werden, weil keine gültige Kursmodul-ID (cmid) übergeben wurde. Die cmid muss auf eine Buchungsinstanz verweisen!';
$string['teachingreportforinstance'] = 'Trainer:innen-Gesamtbericht für ';
$string['teachersinstancereport:subtitle'] = '<strong>Hinweis:</strong> Die Anzahl der UE berechnet sich anhand des gesetzten Terminserien-Textfeldes (z.B. "Mo, 16:00-17:30")
 und der in den <a href="' . $CFG->wwwroot . '/admin/settings.php?section=modsettingbooking" target="_blank">Einstellungen festgelegten Dauer</a> einer UE. Für Blockveranstaltungen oder
 Buchungsoptionen bei denen das Feld nicht gesetzt ist, können die UE nicht berechnet werden!';
$string['units'] = 'UE';
$string['sum_units'] = 'Summe UE';
$string['units_courses'] = 'Kurse / UE';
$string['units_unknown'] = 'Anzahl UE unbekannt';
$string['missinghours'] = 'Fehlstunden';
$string['substitutions'] = 'Vertretung(en)';

// Optionformconfig.php / optionformconfig_form.php.
$string['optionformconfig'] = 'Buchung: Formular für Buchungsoptionen anpassen';
$string['optionformconfigsaved'] = 'Konfiguration für das Buchungsoptionsformular gespeichert.';
$string['optionformconfigsubtitle'] = '<p>Hier können Sie nicht benötigte Funktionalitäten entfernen, um das Formular für die Erstellung von Buchungsoptionen übersichtlicher zu gestalten.</p>
<p><strong>ACHTUNG:</strong> Deaktivieren Sie nur Felder, von denen Sie sicher sind, dass Sie sie nicht benötigen!</p>';
$string['optionformconfig:nobooking'] = 'Sie müssen zumindest eine Buchungsinstanz anlegen, bevor Sie dieses Formular nutzen können!';

// Tasks.
$string['task_adhoc_reset_optiondates_for_semester'] = 'Adhoc task: Termine zurücksetzen und neu erstellen';
$string['task_remove_activity_completion'] = 'Booking: Activitätsabschluss entfernen';
$string['task_enrol_bookedusers_tocourse'] = 'Booking: Gebuchte User in Kurs einschreiben';
$string['task_send_completion_mails'] = 'Booking: Abschluss-Mails versenden';
$string['task_send_confirmation_mails'] = 'Booking: Bestätigungs-Mails versenden';
$string['task_send_notification_mails'] = 'Booking: Benachrichtigungs-Mails versenden';
$string['task_send_reminder_mails'] = 'Booking: Erinnerungs-Mails versenden';
$string['task_send_mail_by_rule_adhoc'] = 'Booking: Mail via Regel versenden (Adhoc-Task)';
$string['task_clean_booking_db'] = 'Booking: Datenbank aufräumen';
$string['task_purge_campaign_caches'] = 'Booking: Caches für Buchungskampagne leeren';
$string['optionbookabletitle'] = '{$a->title} wieder buchbar';
$string['optionbookablebody'] = 'Sie können {$a->title} ab sofort wieder buchen. Klicken Sie <a href="{$a->url}">hier</a>, um direkt zur Buchungsoption zu gelangen.<br><br>
(Sie erhalten diese Nachricht, da Sie bei der Buchungsoption auf den Benachrichtigungs-Button geklickt haben.)<br><br>
<a href="{$a->unsubscribelink}">Von Erinnerungs-E-Mails für "{$a->title}" abmelden.</a>';

// Calculate prices.
$string['recalculateprices'] = 'Preise mit Formel neu berechnen';
$string['recalculateall'] = 'Alle Preise der Instanz mit Formel neu berechnen';
$string['alertrecalculate'] = '<b>Vorsicht!</b> Alle Preise der Instanz werden mit der eingetragenen Formel neu berechnet und alle alten Preise werden überschrieben.';
$string['successfulcalculation'] = 'Preise erfolgreich neu berechnet!';
$string['nopriceformulaset'] = 'Sie müssen zuerst eine Formel in den Buchungseinstellungen eintragen. <a href="{$a->url}" target="_blank">Formel hier bearbeiten.</a>';
$string['applyunitfactor'] = 'Einheitenfaktor anwenden';
$string['applyunitfactor_desc'] = 'Wenn diese Einstellung aktiviert ist, wird die Länge der oben gesetzten Unterrichtseinheiten (z.B. 45 min) zur Berechnung der Anzahl der Einheiten
 herangezogen und als Faktor für die Preisformel verwendet. Beispiel: Eine Buchungsoption hat die Terminserie "Mo, 15:00 - 16:30". Sie dauert also 2 UE von
 jeweils 45 min. Auf die Preisformel wird also der Einheitenfaktor von 2 angewendet. (Einheitenfaktor wird nur bei vorhandener Preisformel angewendet.)';
$string['roundpricesafterformula'] = 'Preise runden (Preisformel)';
$string['roundpricesafterformula_desc'] = 'Preise auf ganze Zahlen runden (mathematisch), nachdem die <strong>Preisformel</strong> angewandt wurde.';

// Col_availableplaces.mustache.
$string['manageresponses'] = 'Buchungen verwalten';

// Bo conditions.
$string['availabilityconditions'] = 'Verfügbarkeit einschränken';
$string['apply'] = 'Anwenden';
$string['delete'] = 'Löschen';

$string['bo_cond_alreadybooked'] = 'alreadybooked: Von diesem User bereits gebucht';
$string['bo_cond_alreadyreserved'] = 'alreadyreserved: Von diesem User bereits in den Warenkorb gelegt';
$string['bo_cond_selectusers'] = 'Nur bestimmte Benutzer:in(nen) dürfen buchen';
$string['bo_cond_booking_time'] = 'Nur in einer bestimmten Zeit buchbar';
$string['bo_cond_fullybooked'] = 'Ausgebucht';
$string['bo_cond_bookingpolicy'] = 'Buchungsbedingungen';
$string['bo_cond_notifymelist'] = 'Benachrichtigungsliste';
$string['bo_cond_max_number_of_bookings'] = 'max_number_of_bookings: Maximum an Nutzer:innen erreicht, die dieser User buchen darf';
$string['bo_cond_onwaitinglist'] = 'onwaitinglist: Auf Warteliste';
$string['bo_cond_previouslybooked'] = 'Benutzer:in hat früher eine bestimmte Option gebucht';
$string['bo_cond_enrolledincourse'] = 'Benutzer:in ist in bestimmte(n) Kurs(e) eingeschrieben';
$string['bo_cond_priceisset'] = 'priceisset: Preis ist vorhanden';
$string['bo_cond_userprofilefield_1_default'] = 'User-Profilfeld hat einen bestimmten Wert';
$string['bo_cond_userprofilefield_2_custom'] = 'Benutzerdefiniertes User-Profilfeld hat einen bestimmten Wert';
$string['bo_cond_isbookable'] = 'isbookable: Buchen ist erlaubt';
$string['bo_cond_isloggedin'] = 'isloggedin: User ist eingeloggt';
$string['bo_cond_fullybookedoverride'] = 'fullybookedoverride: Kann überbucht werden.';
$string['bo_cond_iscancelled'] = 'iscancelled: Buchungsoption storniert';
$string['bo_cond_subbooking'] = 'Zusatzbuchungen sind vorhanden';
$string['bo_cond_subbooking_blocks'] = 'Zusatzbuchung blockiert Verfügbarkeit';
$string['bo_cond_bookitbutton'] = 'bookitbutton: Zeige den normalen Buchen-Button.';
$string['bo_cond_isloggedinprice'] = 'isloggedinprice: Zeige alle Preise wenn nicht eingelogged.';
$string['bo_cond_optionhasstarted'] = 'Hat bereits begonnen';
$string['bo_cond_customform'] = 'Formular ausfüllen';

$string['bo_cond_booking_time_available'] = 'Innerhalb der normalen Buchungszeiten.';
$string['bo_cond_booking_time_not_available'] = 'Nicht innerhalb der normalen Buchungszeiten.';
$string['bo_cond_booking_opening_time_not_available'] = 'Kann noch nicht gebucht werden.';
$string['bo_cond_booking_opening_time_full_not_available'] = 'Kann ab<br>{$a}<br>gebucht werden.';
$string['bo_cond_booking_closing_time_not_available'] = 'Kann nicht mehr gebucht werden.';
$string['bo_cond_booking_closing_time_full_not_available'] = 'Konnte bis<br>{$a}<br>gebucht werden.';

$string['bo_cond_alreadybooked_available'] = 'Noch nicht gebucht';
$string['bo_cond_alreadybooked_full_available'] = 'Nutzer:in hat noch nicht gebucht';
$string['bo_cond_alreadybooked_not_available'] = 'Gebucht';
$string['bo_cond_alreadybooked_full_not_available'] = 'Gebucht';

$string['bo_cond_alreadyreserved_available'] = 'Noch nicht in den Warenkorb gelegt';
$string['bo_cond_alreadyreserved_full_available'] = 'Noch nicht in den Warenkorb gelegt';
$string['bo_cond_alreadyreserved_not_available'] = 'In den Warenkorb gelegt';
$string['bo_cond_alreadyreserved_full_not_available'] = 'In den Warenkorb gelegt';

$string['bo_cond_fullybooked_available'] = 'Buchen';
$string['bo_cond_fullybooked_full_available'] = 'Buchen möglich';
$string['bo_cond_fullybooked_not_available'] = 'Ausgebucht';
$string['bo_cond_fullybooked_full_not_available'] = 'Ausgebucht. Buchen nicht mehr möglich.';

$string['bo_cond_fullybookedoverride_available'] = 'Buchen';
$string['bo_cond_fullybookedoverride_full_available'] = 'Buchen möglich';
$string['bo_cond_fullybookedoverride_not_available'] = 'Ausgebucht';
$string['bo_cond_fullybookedoverride_full_not_available'] = 'Ausgebucht. Buchen nicht mehr möglich.';

$string['bo_cond_userprofilefield_available'] = 'Buchen';
$string['bo_cond_userprofilefield_full_available'] = 'Buchen möglich';
$string['bo_cond_userprofilefield_not_available'] = 'Buchen nicht möglich';
$string['bo_cond_userprofilefield_full_not_available'] = 'Nur Benutzer:innen, bei denen das Profilfeld
 {$a->profilefield} auf den Wert {$a->value} gesetzt ist, dürfen buchen.<br>Sie haben aber das Recht dennoch zu buchen.';

$string['bo_cond_customuserprofilefield_available'] = 'Buchen';
$string['bo_cond_customuserprofilefield_full_available'] = 'Buchen möglich';
$string['bo_cond_customuserprofilefield_not_available'] = 'Buchen nicht möglich';
$string['bo_cond_customuserprofilefield_full_not_available'] = 'Nur Benutzer:innen, bei denen das benutzerdefinierte Profilfeld
 {$a->profilefield} auf den Wert {$a->value} gesetzt ist, dürfen buchen.<br>Sie haben aber das Recht dennoch zu buchen.';

$string['bo_cond_previouslybooked_available'] = 'Buchen';
$string['bo_cond_previouslybooked_full_available'] = 'Buchen möglich';
$string['bo_cond_previouslybooked_not_available'] = 'Buchen nicht möglich';
$string['bo_cond_previouslybooked_full_not_available'] = 'Nur Benutzer:innen, die früher bereits <a href="{$a}">option</a> gebucht haben, dürfen buchen.
 <br>Sie haben aber das Recht dennoch zu buchen.';

$string['bo_cond_enrolledincourse_available'] = 'Buchen';
$string['bo_cond_enrolledincourse_full_available'] = 'Buchen möglich';
$string['bo_cond_enrolledincourse_not_available'] = 'Buchen nicht möglich, da Sie in mindestens einem der folgenden Kurse nicht eingeschrieben sind: {$a}';
$string['bo_cond_enrolledincourse_not_available_and'] = 'Buchen nicht möglich, da Sie nicht in alle der folgenden Kurse eingeschrieben sind: {$a}';
$string['bo_cond_enrolledincourse_full_not_available'] = 'Nur Benutzer:innen, die in den/die folgenden Kurs(e) eingeschrieben sind, dürfen buchen: {$a}
    <br>Sie haben aber das Recht dennoch zu buchen.';

$string['bo_cond_isbookable_available'] = 'Buchen';
$string['bo_cond_isbookable_full_available'] = 'Buchen möglich';
$string['bo_cond_isbookable_not_available'] = 'Buchen nicht möglich';
$string['bo_cond_isbookable_full_not_available'] = 'Buchen ist nicht erlaubt.
 <br>Sie haben aber das Recht dennoch zu buchen.';

$string['bo_cond_subisbookable_available'] = 'Buchen';
$string['bo_cond_subisbookable_full_available'] = 'Buchen möglich';
$string['bo_cond_subisbookable_not_available'] = 'Zuerst buchen';
$string['bo_cond_subisbookable_full_not_available'] = 'Sie müssen zuerst buchen bevor sie Zusatzbuchungen vornehmen können.';

$string['bo_cond_iscancelled_available'] = 'Buchen';
$string['bo_cond_iscancelled_full_available'] = 'Buchen möglich';
$string['bo_cond_iscancelled_not_available'] = 'Storniert';
$string['bo_cond_iscancelled_full_not_available'] = 'Storniert - Buchen nicht möglich';

$string['bo_cond_max_number_of_bookings_available'] = 'Buchen';
$string['bo_cond_max_number_of_bookings_full_available'] = 'Booking is possible';
$string['bo_cond_max_number_of_bookings_not_available'] = 'Max. Buchungsanzahl erreicht';
$string['bo_cond_max_number_of_bookings_full_not_available'] = 'Nutzer:in hat die max. Buchungsanzahl erreicht';

$string['bo_cond_onnotifylist_available'] = 'Buchen';
$string['bo_cond_onnotifylist_full_available'] = 'Buchen möglich';
$string['bo_cond_onnotifylist_not_available'] = 'Max. Buchungsanzahl erreicht';
$string['bo_cond_onnotifylist_full_not_available'] = 'Nutzer:in hat die max. Buchungsanzahl erreicht';

$string['bo_cond_onwaitinglist_available'] = 'Buchen';
$string['bo_cond_onwaitinglist_full_available'] = 'Buchen möglich';
$string['bo_cond_onwaitinglist_not_available'] = 'Ausgebucht - Sie sind auf der Warteliste';
$string['bo_cond_onwaitinglist_full_not_available'] = 'Nutzer:in ist auf der Warteliste';

$string['bo_cond_priceisset_available'] = 'Buchen';
$string['bo_cond_priceisset_full_available'] = 'Buchen möglich';
$string['bo_cond_priceisset_not_available'] = 'Muss bezahlt werden';
$string['bo_cond_priceisset_full_not_available'] = 'Preis gesetzt, Bezahlung nötig';

$string['bo_cond_optionhasstarted_available'] = 'Buchen';
$string['bo_cond_optionhasstarted_full_available'] = 'Buchen möglich';
$string['bo_cond_optionhasstarted_not_available'] = 'Bereits begonnen - Buchen nicht mehr möglich';
$string['bo_cond_optionhasstarted_full_not_available'] = 'Bereits begonnen - User können nicht mehr buchen';

$string['bo_cond_selectusers_available'] = 'Buchen';
$string['bo_cond_selectusers_full_available'] = 'Buchen möglich';
$string['bo_cond_selectusers_not_available'] = 'Buchen nicht möglich';
$string['bo_cond_selectusers_full_not_available'] = 'Nur die folgenden Nutzer:innen können buchen:<br>{$a}';

$string['bo_cond_subbookingblocks_available'] = 'Buchen';
$string['bo_cond_subbookingblocks_full_available'] = 'Buchen möglich';
$string['bo_cond_subbookingblocks_not_available'] = 'Buchen';
$string['bo_cond_subbookingblocks_full_not_available'] = 'Buchen möglich';

$string['bo_cond_customform_restrict'] = 'Formular muss vor der Buchung ausgefüllt werden';
$string['bo_cond_customform_available'] = 'Buchen';
$string['bo_cond_customform_full available'] = 'Booking is possible';
$string['bo_cond_customform_not_available'] = 'Buchen';
$string['bo_cond_customform_full_not_available'] = 'Booking is possible';

// This does not really block, it just handels available subbookings.
$string['bo_cond_subbooking_available'] = 'Buchen';
$string['bo_cond_subbooking_full_available'] = 'Buchen möglich';
$string['bo_cond_subbooking_not_available'] = 'Buchen';
$string['bo_cond_subbooking_full_not_available'] = 'Buchen möglich';

// BO conditions in mform.
$string['bo_cond_selectusers_restrict'] = 'Nur bestimmte Benutzer:in(nen) dürfen buchen';
$string['bo_cond_selectusers_userids'] = 'Benutzer:in(nen), die buchen dürfen';
$string['bo_cond_selectusers_userids_help'] = '<p>Wenn Sie diese Einschränkung verwenden, können nur ausgewählten Personen diese Veranstaltung buchen.</p>
<p>Sie können diese Einschränkung aber auch verwenden, um es bestimmten Personen zu ermöglichen, andere Einschränkungen zu umgehen:</p>
<p>(1) Klicken Sie hierzu auf das Häkchen "Steht in Bezug zu einer anderen Einschränkung"<br>
(2) Stellen Sie sicher, dass der Operator "ODER" ausgewählt ist<br>
(3) Wählen Sie alle Einschränkungen aus, die umgangen werden sollen.</p>
<p>Beispiele:<br>
"Ausgebucht" => Die ausgewählte Person darf auch dann buchen, wenn die Veranstaltung bereits ausgebucht ist.<br>
"Nur in einer bestimmten Zeit buchbar" => Die ausgewählte Person darf auch außerhalb der normalen Buchungszeiten buchen</p>';

$string['userinfofieldoff'] = 'Kein User-Profilfeld ausgewählt';
$string['bo_cond_userprofilefield_1_default_restrict'] = 'Ein ausgewähltes Userprofilfeld soll einen bestimmten Wert haben';
$string['bo_cond_previouslybooked_restrict'] = 'User hat früher bereits eine bestimmte Option gebucht';
$string['bo_cond_userprofilefield_field'] = 'Profilfeld';
$string['bo_cond_userprofilefield_value'] = 'Wert';
$string['bo_cond_userprofilefield_operator'] = 'Operator';

$string['bo_cond_userprofilefield_2_custom_restrict'] = 'Ein ausgewähltes benutzerdefiniertes Userprofilfeld soll einen bestimmten Wert haben';
$string['bo_cond_customuserprofilefield_field'] = 'Profilfeld';
$string['bo_cond_customuserprofilefield_value'] = 'Wert';
$string['bo_cond_customuserprofilefield_operator'] = 'Operator';

$string['equals'] = 'hat genau diesen Wert (Text oder Zahl)';
$string['contains'] = 'beinhaltet (Text)';
$string['lowerthan'] = 'ist kleiner als (Zahl)';
$string['biggerthan'] = 'ist größer als (Zahl)';
$string['equalsnot'] = 'hat nicht genau diesen Wert (Text oder Zahl)';
$string['containsnot'] = 'beinhaltet nicht (Text)';
$string['inarray'] = 'TeilnehmerIn hat einen dieser Werte (Komma getrennt)';
$string['notinarray'] = 'TeilnehmerIn hat keinen dieser Werte (Komma getrennt)';
$string['isempty'] = 'TeilnehmerIn hat keinen Wert gesetzt';
$string['isnotempty'] = 'TeilnehmerIn hat einen Wert gesetzt';
$string['overrideconditioncheckbox'] = 'Steht in Bezug zu einer anderen Einschränkung';
$string['overridecondition'] = 'Einschränkung';
$string['overrideoperator'] = 'Operator';
$string['overrideoperator:and'] = 'UND';
$string['overrideoperator:or'] = 'ODER';
$string['bo_cond_previouslybooked_optionid'] = 'Buchungsoption';
$string['allcoursesmustbefound'] = 'Alle Kurse müssen gebucht sein';
$string['onecoursemustbefound'] = 'Zumindest einer dieser Kurse muss gebucht sein';

$string['noelement'] = "Kein Element";
$string['checkbox'] = "Checkbox";
$string['displaytext'] = "Text anzeigen";
$string['textarea'] = "Textbereich";
$string['shorttext'] = "Kurztext";
$string['formtype'] = "Formulartyp";
$string['bo_cond_customform_label'] = "Bezeichnung";

// Teacher_performed_units_report.php.
$string['error:wrongteacherid'] = 'Fehler: Für die angegebene "teacherid" wurde kein:e Nutzer:in gefunden.';
$string['duration:minutes'] = 'Dauer (Minuten)';
$string['duration:units'] = 'Einheiten ({$a} min)';
$string['teachingreportfortrainer:subtitle'] = '<strong>Hinweis:</strong> Sie können die Dauer einer Unterrichtseinheit
in den Einstellungen anpassen. (Z.B. 45 statt 60 Minuten).<br/>
<a href="' . $CFG->wwwroot . '/admin/settings.php?section=modsettingbooking" target="_blank">
&gt;&gt; Zu den Einstellungen...
</a>';
$string['error:missingteacherid'] = 'Fehler: Report kann nicht geladen werden, da die teacherid fehlt.';

// Teacher_performed_units_report_form.php.
$string['filterstartdate'] = 'Von';
$string['filterenddate'] = 'Bis';
$string['filterbtn'] = 'Filtern';

// Booking campaigns.
$string['bookingcampaignswithbadge'] = 'Buchung: Kampagnen ' . $string['badge:pro'];
$string['bookingcampaigns'] = 'Buchung: Kampagnen (PRO)';
$string['bookingcampaign'] = 'Kampagne';
$string['bookingcampaignssubtitle'] = 'Mit Kampagnen können Sie für einen festgelegten Zeitraum die Preise von ausgewählten
 Buchungsoptionen vergünstigen und das Buchungslimit für diesen Zeitraum erhöhen. Damit die Kampagnen funktionieren, muss der
 Moodle Cron-Job regelmäßig laufen.';
$string['campaigntype'] = 'Kampagnentyp';
$string['editcampaign'] = 'Kampagne bearbeiten';
$string['addbookingcampaign'] = 'Kampagne hinzufügen';
$string['deletebookingcampaign'] = 'Kampagne löschen';
$string['deletebookingcampaign_confirmtext'] = 'Wollen Sie die folgende Kampagne wirklich löschen?';
$string['campaign_name'] = 'Eigener Name der Kampagne';
$string['campaign_customfield'] = 'Preis oder Buchungslimit anpassen';
$string['campaign_customfield_descriptiontext'] = 'Betrifft: Benutzerdefiniertes Buchungsoptionsfeld "{$a->fieldname}"
 mit dem Wert "{$a->fieldvalue}".';
$string['campaignfieldname'] = 'Feld';
$string['campaignfieldvalue'] = 'Wert';
$string['campaignstart'] = 'Beginn der Kampagne';
$string['campaignend'] = 'Ende der Kampagne';

$string['campaign_blockbooking'] = 'Bestimmte Buchungen blockieren';
$string['campaign_blockbooking_descriptiontext'] = 'Betrifft: Benutzerdefiniertes Buchungsoptionsfeld "{$a->fieldname}"
mit dem Wert "{$a->fieldvalue}".';

$string['blockoperator'] = 'Operator';
$string['blockoperator_help'] = '<b>Blockiere über</b> ... Sobald der angegebene Prozentsatz an Buchungen erreicht ist, wird das Online-Buchen geblockt,
es kann dann nur noch an der Kassa oder durch einen Admin gebucht werden.<br>
<b>Blockiere unter</b> ... Das Buchen wird geblockt bis der angegebene Prozentsatz an Buchungen erreicht ist,
bis dahin kann nur an der Kassa oder durch einen Admin gebucht werden.';
$string['blockabove'] = 'Blockiere über';
$string['blockbelow'] = 'Blockiere unter';
$string['percentageavailableplaces'] = 'Prozent der verfügbaren Plätze';
$string['percentageavailableplaces_help'] = 'Geben Sie einen gültigen Prozentsatz zwischen 0 und 100 an (ohne %-Zeichen!).';
$string['hascapability'] = 'Außer mit dieser Fähikgeit';
$string['blockinglabel'] = 'Nachricht beim Blockieren';
$string['blockinglabel_help'] = 'Geben Sie die Nachricht ein, die angezeigt werden soll, wenn Buchungen blockiert werden.
Wenn Sie die Nachricht lokalisieren wollen, verwenden Sie die
<a href="https://docs.moodle.org/403/de/Mehrsprachiger_Inhalt" target="_blank">Moodle-Sprachfilter</a>.';

// Booking campaign help buttons.
$string['campaign_name_help'] = 'Geben Sie einen beliebigen Namen für die Kampagne an - z.B. "Weihnachtsaktion 2023" oder "Oster-Rabatt 2023".';
$string['campaignfieldname_help'] = 'Wählen Sie das benutzerdefinierte Buchungsoptionsfeld aus, dessen Wert verglichen werden soll.';
$string['campaignfieldvalue_help'] = 'Wählen Sie den Wert des Feldes aus. Die Kampagne trifft auf alle Buchungsoptionen zu, die beim ausgewählten Feld diesen Wert eingetragen haben.';
$string['campaignstart_help'] = 'Wann soll die Kampagne starten?';
$string['campaignend_help'] = 'Wann soll die Kampagne enden?';
$string['pricefactor_help'] = 'Geben Sie einen Wert an, mit dem der Preis multipliziert werden soll. Um die Preise beispielsweise um 20% zu vergünstigen, geben Sie den Wert 0,8 ein.';
$string['limitfactor_help'] = 'Geben Sie einen Wert an, mit dem das Buchungslimit multipliziert werden soll. Um das Buchungslimit beispielsweise um 20% zu erhöhen, geben Sie den Wert 1.2 ein.';

// Booking campaign errors.
$string['error:pricefactornotbetween0and1'] = 'Sie müssen einen Wert zwischen 0 und 1 eingeben. Um die Preise z.B. um 10% zu reduzieren,
 geben Sie den Wert 0,9 ein.';
$string['error:limitfactornotbetween1and2'] = 'Sie müssen einen Wert zwischen 0 und 2 eingeben. Um das Buchungslimit z.B. um 20% zu erhöhen,
 geben Sie den Wert 1,2 ein.';
 $string['error:missingblockinglabel'] = 'Geben Sie die Nachricht ein, die angezeigt werden soll, wenn Buchungen blockiert werden.';
 $string['error:percentageavailableplaces'] = 'Geben Sie einen gültigen Prozentsatz zwischen 0 und 100 an (ohne %-Zeichen!).';
$string['error:campaignstart'] = 'Kampagnenbeginn muss vor dem Kampagnenende liegen.';
$string['error:campaignend'] = 'Kampagnenende muss nach dem Kampagnenbeginn sein.';

// Booking rules.
$string['bookingruleswithbadge'] = 'Buchung: Globale Regeln ' . $string['badge:pro'];
$string['bookingrules'] = 'Buchung: Globale Regeln (PRO)';
$string['bookingrule'] = 'Regel';
$string['addbookingrule'] = 'Regel hinzufügen';
$string['deletebookingrule'] = 'Regel löschen';
$string['deletebookingrule_confirmtext'] = 'Wollen Sie die folgende Regel wirklich löschen?';

$string['rule_event'] = 'Event';
$string['rule_mailtemplate'] = 'E-Mail-Vorlage';
$string['rule_datefield'] = 'Datumsfeld';
$string['rule_customprofilefield'] = 'Benutzerdefiniertes User-Profilfeld';
$string['rule_operator'] = 'Operator';
$string['rule_value'] = 'Wert';
$string['rule_days'] = 'Anzahl Tage vorher';

$string['rule_optionfield'] = 'Buchungsoptionsfeld, das verglichen werden soll';
$string['rule_optionfield_coursestarttime'] = 'Beginn (coursestarttime)';
$string['rule_optionfield_courseendtime'] = 'Ende (coursestarttime)';
$string['rule_optionfield_bookingopeningtime'] = 'Beginn der erlaubten Buchungsperiode (bookingopeningtime)';
$string['rule_optionfield_bookingclosingtime'] = 'Ende der erlaubten Buchungsperiode (bookingclosingtime)';
$string['rule_optionfield_text'] = 'Name der Buchungsoption (text)';
$string['rule_optionfield_location'] = 'Ort (location)';
$string['rule_optionfield_address'] = 'Adresse (address)';

$string['rule_sendmail_cpf'] = '[Vorschau] E-Mail versenden an User:in mit benutzerdefiniertem Feld';
$string['rule_sendmail_cpf_desc'] = 'Wählen Sie ein Event aus, auf das reagiert werden soll. Legen Sie eine E-Mail-Vorlage an
 (Sie können auch Platzhalter wie {bookingdetails} verwenden) und legen Sie fest, an welche Nutzer:innen die E-Mail versendet werden soll.
  Beispiel: Alle Nutzer:innen, die im benutzerdefinierten Feld "Studienzentrumsleitung" den Wert "SZL Wien" stehen haben.';

$string['rule_daysbefore'] = 'Reagiere n Tage vor einem bestimmtem Datum';
$string['rule_daysbefore_desc'] = 'Wählen Sie die Anzahl der Tage VOR einem gewissen Datum einer Buchungsoption aus.';
 $string['rule_react_on_event'] = 'Reagiere auf Ereignis';
 $string['rule_react_on_event_desc'] = 'Wählen Sie ein Ereignis aus, durch das die Regel ausgelöst werden soll.';

$string['error:nofieldchosen'] = 'Sie müssen ein Feld auswählen.';
$string['error:mustnotbeempty'] = 'Darf nicht leer sein.';

// Booking rules conditions.
$string['rule_name'] = "Eigener Name der Regel";
$string['bookingrulecondition'] = "Kondition der Regel";
$string['bookingruleaction'] = "Aktion der Regel";
$string['enter_userprofilefield'] = "Wähle Nutzer:innen nach eingegebenem Wert für Profilfeld.";
$string['condition_textfield'] = 'Wert';
$string['match_userprofilefield'] = "Wähle Nutzer:innen nach gleichem Wert in Buchungsoption und Profil.";
$string['select_users'] = "Wähle Nutzer:innen ohne direkte Verbindung zur Buchungsoption";
$string['select_student_in_bo'] = "Wähle Nutzer:innen einer Buchungsoption";
$string['select_teacher_in_bo'] = "Wähle Trainer:innen einer Buchungsoption";
$string['select_user_from_event'] = "Wähle Nutzer:in vom Ereignis";
$string['send_mail'] = "Sende E-Mail";
$string['bookingcondition'] = "Bedingung";
$string['condition_select_teacher_in_bo_desc'] = 'Trainer:innen der von der Regel betroffenen Buchungsoption wählen.';
$string['condition_select_student_in_bo_desc'] = 'Nutzer:innen der von der Regel betroffenen Buchungsoption wählen.';
$string['condition_select_student_in_bo_roles'] = 'Rolle wählen';
$string['condition_select_users_userids'] = "Wähle die gewünschten Nutzer:innen";
$string['condition_select_user_from_event_desc'] = 'Nutzer:in, die mit dem Ereignis in Verbindung steht wählen';
$string['studentbooked'] = 'Nutzer:innen, die gebucht haben';
$string['studentwaitinglist'] = 'Nutzer:innen auf der Warteliste';
$string['studentnotificationlist'] = 'Nutzer:innen auf der Benachrichtigungsliste';
$string['studentdeleted'] = 'Nutzer:innen, die bereits entfernt wurden';
$string['useraffectedbyevent'] = 'Vom Ereignis betroffene:r Nutzer:in';
$string['userwhotriggeredevent'] = 'Nutzer:in, die das Ereignis ausgelöst hat';
$string['condition_select_user_from_event_type'] = 'Rolle wählen';

// Booking rules actions.
$string['bookingaction'] = "Aktion";

// Cancel booking option.
$string['canceloption'] = "Storniere Buchungsoption";
$string['canceloption_desc'] = "Stornieren einer Buchungsoption bedeutet, dass die Option nicht mehr buchbar ist, aber weiterhin als storniert in der Liste angezeigt wird.";
$string['confirmcanceloption'] = "Bestätige die Stornierung der Buchungsoption";
$string['confirmcanceloptiontitle'] = "Ändere den Status der Buchungsoption";
$string['cancelthisbookingoption'] = "Storniere diese Buchungsoption";
$string['undocancelthisbookingoption'] = "Stornierung rückgängig machen";
$string['cancelreason'] = "Grund für die Stornierung dieser Buchungsoption";
$string['usergavereason'] = '{$a} gab folgenden Grund für die Stornierung an:';
$string['undocancelreason'] = "Möchten Sie wirklich die Stornierung dieser Buchungsoption rückgängig machen?";
$string['nocancelreason'] = "Sie müssen eine Grund für die Stornierung angeben";

// Access.php.
$string['booking:bookforothers'] = "Für andere buchen";
$string['booking:canoverbook'] = "Darf überbuchen";
$string['booking:canreviewsubstitutions'] = "Kann Vertretungen als kontrolliert markieren";
$string['booking:conditionforms'] = "Formulare von Buchungsbedingungen abschicken (z.B. Buchungsbedingungen oder Zusatzbuchungen)";
$string['booking:view'] = 'Darf Buchungsinstanzen sehen';
$string['booking:viewreports'] = 'Zugang um gewisse Buchungsberichte zu sehen';
$string['booking:manageoptiondates'] = 'Bearbeite Termine';
$string['booking:limitededitownoption'] = 'Weniger als addeditownoption, nur sehr beschränktes Editieren eigener Optionen erlaubt.';

// Booking_handler.php.
$string['error:newcoursecategorycfieldmissing'] = 'Sie müssen zuerst ein <a href="{$a->bookingcustomfieldsurl}"
 target="_blank">benutzerdefiniertes Buchungsoptionsfeld</a> erstellen, das für die Kurskategorien für automatisch
 erstellte Kurse verwendet wird. Stellen Sie sicher, dass Sie dieses Feld
 auch in den <a href="{$a->settingsurl}" target="_blank">Plugin-Einstellungen des Buchungsmoduls</a> ausgewählt haben.';
$string['error:coursecategoryvaluemissing'] = 'Sie müssen hier einen Wert auswählen, da dieser als Kurskategorie für den
 automatisch erstellten Moodle-Kurs benötigt wird.';

 // Subbookings.
$string['bookingsubbookingsheader'] = "Zusatzbuchungen";
$string['bookingsubbooking'] = "Zusatzbuchungen";
$string['subbooking_name'] = "Name der Zusatzbuchung";
$string['bookingsubbookingadd'] = 'Füge eine Zusatzbuchung hinzu';
$string['bookingsubbookingedit'] = 'Bearbeite';
$string['editsubbooking'] = 'Bearbeite Zusatzbuchung';
$string['bookingsubbookingdelete'] = 'Lösche Zusatzbuchung';

$string['onlyaddsubbookingsonsavedoption'] = "Sie müssen diese neue Buchungsoption speichern, bevor sie Unterbuchungen hinzufügen können.";
$string['onlyaddentitiesonsavedsubbooking'] = "Sie müssen diese neue zusätzliche Buchungsoption speichern, bevor sie Entities hinzufügen können.";

$string['subbooking_timeslot'] = "Zeitfenster Buchung";
$string['subbooking_timeslot_desc'] = "Mit dieser Funktion kann die Dauer von buchbaren Zeitfenstern für jedes Datum der Buchungsoption festgelegt werden.";
$string['subbooking_duration'] = "Dauer in Minuten";

$string['subbooking_additionalitem'] = "Buche zusätzlichen Artikel";
$string['subbooking_additionalitem_desc'] = "Diese zusätzliche Buchung erlaubt einen weiten Artiekl zu buchen, etwa einen besseren Platz oder zusätzliches Material.";
$string['subbooking_additionalitem_description'] = "Beschreiben Sie hier den zusätzlich buchbaren Artikel:";

$string['subbooking_additionalperson'] = "Buche zusätzliche Person";
$string['subbooking_additionalperson_desc'] = "Buchen Sie Plätze für zusätzliche Personen, z.B. für Familienmitglieder.";
$string['subbooking_additionalperson_description'] = "Beschreiben Sie die Buchungsmöglichkeit.";

$string['subbooking_addpersons'] = "Füge Person(en) hinzu";
$string['subbooking_bookedpersons'] = "Die folgenden Personen werden hinzugefügt:";
$string['personnr'] = 'Person Nr. {$a}';

// Shortcodes.
$string['recommendedin'] = "Shortcode um Buchungsoptionen in bestimmten Kursen zu empfehlen.
 Legen Sie ein neues benutzerdefiniertes Feld für Buchungsoptionen mit dem Kurznamen 'recommendedin' an.
 In einer Buchungsoption setzen Sie nun den Wert dieses Feldes auf 'course1', wenn Sie die Buchungsoption
 im Course 1 (course1) empfehlen wollen.";
$string['fieldofstudyoptions'] = "Shortcode um alle Buchungsoptionen eines Studiengangs anzuzeigen.
 Ein Studientgang wird über die gemeinsame Einschreibung über eine globale Gruppe definiert.
 Außerdem muss in der angezeigten Buchungsoption in der Buchungsvoraussetzung einer der betroffenen
 Kurse ausgewählt sein.";
$string['fieldofstudycohortoptions'] = "Shortcode um alle Buchungsoptionen eines Studiengangs anzuzeigen.
 Wird dadurch definiert, dass die NutzerInnen in allen Kursen in die Gruppe mit dem gleichen Namen
 eingeschrieben sind. Buchungsoptionen werden über das 'recommendedin' customfield zugeordnet.";
$string['nofieldofstudyfound'] = "Es konnte keine Studienrichtung über die Globalen Gruppen herausgefunden werden.";
$string['shortcodenotsupportedonyourdb'] = "Dieser Shortcode funktioniert nur auf Postgres & Mariadb Datenbanken.";
$string['definefieldofstudy'] = 'Sie können hier alle Buchungsoptionen aus dem gesamten Studienbereich anzeigen lassen. Damit dies funktioniert,
 verwenden Sie Gruppen mit dem Namen Ihres Studiengangs. Bei einem Kurs, der in "Psychologie" und "Philosophie" verwendet wird,
 haben Sie zwei Gruppen, die nach diesen Studiengängen benannt sind. Folgen Sie diesem Schema für alle Ihre Kurse.
 Fügen Sie nun das benutzerdefinierte Buchungsoptionsfeld mit dem Shortname "recommendedin" hinzu, in das Sie die kommagetrennten
 Shortcodes derjenigen Kurse, in denen eine Buchungsoption empfohlen werden soll, eintragen. Wenn ein:e Benutzer:in Teil der
 Gruppe "Philosophie" ist, werden ihm:ihr alle Buchungsoptionen aus Kursen angezeigt, in denen mindestens einer der "Philosophie"-Kurse empfohlen wird.';

// Elective.
$string['elective'] = "Wahlfach";
$string['selected'] = 'Ausgewählt';
$string['bookelectivesbtn'] = 'Ausgewählte Wahlfächer buchen';
$string['electivesbookedsuccess'] = 'Ihre ausgewählten Wahlfächer wurden erfolgreich gebucht.';
$string['errormultibooking'] = 'Beim Buchen der Wahlfächer ist ein Fehler aufgetreten.';
$string['showdescription'] = 'Info anzeigen';
$string['hidedescription'] = 'Info verstecken';
$string['editteacherslink'] = 'Lehrer:innen bearbeiten';
$string['selectelective'] = 'Wahlfach für {$a} Credits auswählen';
$string['electivedeselectbtn'] = 'Wahlfach abwählen';
$string['confirmbookingtitle'] = "Buchung bestätigen";
$string['sortbookingoptions'] = "Bitte die Buchungsoptionen in die richtige Reihenfolge bringen. Die Kurse können nur in der hier festgelegten Reihenfolge absolviert werden. Der oberste Kurs muss zuerst absolviert werden.";
$string['selectoptionsfirst'] = "Bitte zuerst die Buchungsoptionen auswählen.";
$string['electivesettings'] = 'Wahlfach Einstellungen';
$string['iselective'] = 'Verwende Instanz als Wahlfach';
$string['iselective_help'] = 'Damit können Nutzer:innen gezwungen werden, mehrere Buchungen auf einmal in einer
 bestimmten Reihenfolge und in gewissen Beziehungen zueinander vorzunehmen, außerdem kann der Verbrauch von Credits erzwungen werden.';
$string['maxcredits'] = 'Anzahl verfügbare Credits';
$string['maxcredits_help'] = 'Sie können die maximal in dieser Buchung verfügbaren Credits angeben, die verbraucht werden können oder müssen. Für jede Buchungsoption können die entsprechenden Credits angegeben werden.';
$string['unlimitedcredits'] = 'Verwende keine Credits';
$string['enforceorder'] = 'Erzwinge Reihenfolge';
$string['enforceorder_help'] = 'Nutzer:innen werden erst nach Abschluss des vorangegangene Kurses in den nächsten Kurs eingeschrieben.';
$string['consumeatonce'] = 'Alle Credits müssen in einer Buchung verbraucht werden';
$string['consumeatonce_help'] = 'Die Nutzer:innen haben nur einen einzigen Buchungsschritt, bei dem alle Wahlfächer gebucht werden müssen.';
$string['credits'] = 'Credits';
$string['bookwithcredits'] = '{$a} Credits';
$string['bookwithcredit'] = '{$a} Credit';
$string['notenoughcreditstobook'] = 'Nicht genug Credit um zu buchen';
$string['electivenotbookable'] = 'Nicht buchbar';
$string['credits_help'] = 'Wie viele credits werden bei der Buchung dieser Option verbraucht';
$string['mustcombine'] = 'Notwendige Buchungsoptionen';
$string['mustcombine_help'] = 'Buchungsoptionen mit denen diese Buchungsoption kombiniert werden muss';
$string['mustnotcombine'] = 'Ausgeschlossene Buchungsoptionen';
$string['mustnotcombine_help'] = 'Buchungsoptionen mit denen diese Buchungsoption nicht kombiniert werden kann';
$string['nooptionselected'] = 'Keine Buchungsoption ausgewählt';
$string['creditsmessage'] = 'Noch {$a->creditsleft} von insgesamt {$a->maxcredits} Credits verfügbar.';
$string['notemplateyet'] = 'Es gibt noch kein Template';
$string['notbookablecombiantion'] = 'Diese Kombination von Wahlfächern ist nicht erlaubt';

// Booking Actions.
$string['boactionsheader'] = 'Aktionen nach der Buchung [EXPERIMENTELL]';
$string['selectboactiontype'] = 'Wähle Aktion nach der Buchung';
$string['bookingactionadd'] = "Füge Aktion hinzu";
$string['boactions_desc'] = "Aktionen nach der Buchung sind derzeit ein experimentelles Feature.
Sie können es ausprobieren, aber bitte verwenden Sie es noch auf keiner Produktivplattform!";
$string['boactions'] = 'Aktionen nach der Buchung
' . $string['badge:pro'] . ' ' . $string['badge:experimental'];
$string['onlyaddactionsonsavedoption'] = "Aktionen nach der Buchung könnnen nur zu schon gespeicherte Optionen hinzugefügt werden.";
$string['boactionname'] = "Name der Aktion";
$string['showboactions'] = "Aktiviere Aktionen nach der Buchung";
$string['boactionselectuserprofilefield'] = "Wähle Profilfeld";
$string['boactioncancelbookingvalue'] = "Activate immediate cancelation";
$string['boactioncancelbooking_desc'] = "Used for options which can be bought multiple times";
$string['boactionuserprofilefieldvalue'] = 'Wert';
$string['actionoperator:set'] = 'Ersetzen';
$string['actionoperator:subtract'] = 'Minus';
$string['actionoperator'] = 'Aktion';
$string['actionoperator:adddate'] = 'Füge Zeitraum hinzu';

// Dates class.
$string['adddatebutton'] = "Füge Datum hinzu";
$string['nodatesstring'] = "Aktuell gibt es keine Daten zu dieser Buchungsoption";
$string['nodatesstring_desc'] = "no dates";
