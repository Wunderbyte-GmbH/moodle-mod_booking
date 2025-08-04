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

$string['aboutmodaloptiondateform'] = 'Hier können Sie benutzerdefinierte Termine anlegen
(z.B. bei Block-Veranstaltungen oder wenn einzelne Termine von der Terminserie abweichen).';
$string['accept'] = 'Akzeptieren';
$string['accessdenied'] = 'Zugriff verweigert';
$string['actionoperator'] = 'Aktion';
$string['actionoperator:adddate'] = 'Füge Zeitraum hinzu';
$string['actionoperator:set'] = 'Ersetzen';
$string['actionoperator:subtract'] = 'Minus';
$string['actions'] = 'Aktionen';
$string['activatemails'] = 'E-Mails aktivieren (Bestätigungen, Erinnerungen etc.)';
$string['activebookingoptions'] = 'Aktuelle Buchungsoptionen';
$string['activitycompletionsuccess'] = 'Alle Nutzer:innen wurden für den Aktivitätsabschluss ausgewählt';
$string['activitycompletiontext'] = 'Nachricht an Nutzer/in, wenn Buchungsoption abgeschlossen ist';
$string['activitycompletiontextmessage'] = 'Sie haben die folgende Buchungsoption abgeschlossen:
{$a->bookingdetails}
Zum Kurs: {$a->courselink}
Alle Buchungsoptionen ansehen: {$a->bookinglink}';
$string['activitycompletiontextsubject'] = 'Buchungsoption abgeschlossen';
$string['addastemplate'] = 'Als Vorlage hinzufügen';
$string['addbookingcampaign'] = 'Kampagne hinzufügen';
$string['addbookingrule'] = 'Regel hinzufügen';
$string['addcategory'] = 'Kategorien bearbeiten';
$string['addcomment'] = 'Kommentar hinzufügen...';
$string['addcustomfieldorcomment'] = 'Kommentar oder benutzerdefiniertes Feld hinzufügen';
$string['adddatebutton'] = "Füge Datum hinzu";
$string['addedrecords'] = '{$a} Eintrag/Einträge hinzugefügt.';
$string['addholiday'] = 'Ferien(tag) hinzufügen';
$string['additionalfields'] = 'Zusätzliche Felder';
$string['additionalpricecategories'] = 'Preiskategorien hinzufügen oder bearbeiten';
$string['addmorebookings'] = 'Buchungen hinzufügen';
$string['addnewcategory'] = 'Neue Kategorie hinzufügen';
$string['addnewreporttemplate'] = 'Vorlage für Bericht hinzufügen';
$string['addnewtagtemplate'] = 'Hinzufügen';
$string['addoptiondate'] = 'Termin hinzufügen';
$string['addoptiondateseries'] = 'Terminserie erstellen';
$string['addpricecategory'] = 'Neue Preiskategorie hinzufügen';
$string['addpricecategoryinfo'] = 'Sie können eine weitere Preiskategorie definieren.';
$string['address'] = 'Adresse';
$string['addsemester'] = 'Semester hinzufügen';
$string['addtocalendar'] = 'Zum Kurs-Kalender hinzufügen';
$string['addtocalendardesc'] = 'Kurs-Kalenderevents können von ALLEN Kursteilnehmer:innen des Kurses gesehen werden. Falls Sie nicht möchten, dass Kurs-Kalenderevents
erstellt werden, können Sie diese Einstellung standardmäßig ausschalten und sperren. Keine Sorge: Normale Kalenderevents für gebuchte Optionen (User-Events) werden weiterhin erstellt.';
$string['addtogroup'] = 'Nutzer:innen automatisch in Gruppe des verknüpften Kurses einschreiben';
$string['addtogroup_help'] = 'Nutzer:innen automatisch in Gruppe des in der Buchungsoption verknüpften Kurses eintragen. Die Gruppe wird nach folgendem Schema automatisch erstellt: Aktivitätsname - Name der Buchungsoption';
$string['addtogroupofcurrentcourse'] = 'Benutzer automatisch in Gruppen des aktuellen Kurses einschreiben';
$string['addtogroupofcurrentcourse_help'] = "Wählen Sie die Gruppe(n) des aktuellen Kurses aus, in die die Benutzer eingeschrieben werden sollen, sobald sie mindestens eine der Buchungsoptionen in dieser Instanz gebucht haben. Gruppen müssen zuvor innerhalb dieses Kurses erstellt werden.</br>
Es ist auch möglich, Benutzer für jede gebuchte Option in eine bestimmte Gruppe einzuschreiben. Diese Gruppen werden nach der jeweiligen Buchungsoption benannt.";
$string['addtogroupofcurrentcoursebookingoption'] = "In spezifische Gruppe für jede gebuchte Option einschreiben";
$string['adminparameter_desc'] = "Benutze die Parameter aus den Admin Einstellungen.";
$string['adminparametervalue'] = "Admin Parameter";
$string['advancedoptions'] = 'Erweiterte Einstellungen';
$string['aftercompletedtext'] = 'Nach Aktivitätsabschluss';
$string['aftercompletedtext_help'] = 'Text, der nach dem Abschluss angezeigt wird';
$string['aftersubmitaction'] = 'Nach dem Speichern...';
$string['age'] = 'Alter';
$string['alertrecalculate'] = '<b>Vorsicht!</b> Alle Preise der Instanz werden mit der eingetragenen Formel neu berechnet und alle alten Preise werden überschrieben.';
$string['allbookingoptions'] = 'Nutzer:innen für alle Buchungsoptionen herunterladen';
$string['allchangessaved'] = 'Alle Änderungen wurden gespeichert.';
$string['allcohortsmustbefound'] = 'Zugehörigkeit zu allen globalen Gruppen';
$string['allcomments'] = 'Jede/r kann kommentieren';
$string['allcoursesmustbefound'] = 'Alle Kurse müssen gebucht sein';
$string['allmailssend'] = 'Alle Benachrichtigungen wurden erfolgreich versandt!';
$string['allmoodleusers'] = 'Alle Nutzer:innen dieser Website';
$string['alloptionsinreport'] = 'Report über alle Buchungen einer Instanz <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['alloptionsinreportdesc'] = 'Der Report einer Buchungsoption beinhaltet alle Buchungen der ganzen Instanz';
$string['allowbookingafterstart'] = 'Buchen nach Kursbeginn erlauben';
$string['allowoverbooking'] = 'Überbuchen erlauben';
$string['allowoverbookingheader'] = 'Buchungsoptionen überbuchen <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['allowoverbookingheader_desc'] = 'Berechtigten Nutzer:innen erlauben, Kurse zu überbuchen.
 (Achtung: Dies kann zu unerwünschtem Verhalten führen. Nur aktivieren, wenn wirklich benötigt.)';
$string['allowupdate'] = 'Buchungen dürfen gelöscht/aktualisiert werden';
$string['allowupdatedays'] = 'Tage vor Referenzdatum';
$string['allratings'] = 'Jede/r kann bewerten';
$string['allteachers'] = 'Alle Trainer:innen';
$string['allteacherspagebookinginstances'] = 'Auf der "Alle Trainer:innen"-Seite nur Trainer:innen aus den folgenden Buchungsintanzen anzeigen. (Wählen Sie "Keine Auswahl", um ALLE Trainer:innen anzuzeigen.)';
$string['allusercertificates'] = 'Zertifikate des Users';
$string['allusersbooked'] = 'Alle {$a} Nutzer:innen wurden erfolgreich für diese Buchungsoption gebucht.';
$string['alreadybooked'] = 'Bereits gebucht';
$string['alreadyonlist'] = 'Sie werden benachrichtigt';
$string['alreadypassed'] = 'Bereits vergangen';
$string['always'] = 'Immer';
$string['alwaysshowlinkondetailspage'] = 'Immer den Link zur Buchungsoption auf der Kursseite anzeigen';
$string['alwaysshowlinkondetailspage_desc'] = 'Die Detailseite kann über den Link im Titel oder das Header-Bild erreicht werden. Aber dies wird einen zusätzlichen Button hinzufügen.';
$string['andotherfield'] = "UND weiteres Feld";
$string['annotation'] = 'Interne Anmerkung';
$string['answer'] = "Antwort";
$string['answered'] = 'Beantwortet';
$string['answerscount'] = "Anzahl der Buchungen";
$string['appearancesettings'] = 'Darstellung <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['appearancesettings_desc'] = 'Passen Sie die Darstellung des Buchungsplugins an.';
$string['apply'] = 'Anwenden';
$string['applybookingrules'] = 'Buchungsregeln anwenden';
$string['applyunitfactor'] = 'Einheitenfaktor anwenden';
$string['applyunitfactor_desc'] = 'Wenn diese Einstellung aktiviert ist, wird die Länge der oben gesetzten Unterrichtseinheiten (z.B. 45 min) zur Berechnung der Anzahl der Einheiten
 herangezogen und als Faktor für die Preisformel verwendet. Beispiel: Eine Buchungsoption hat die Terminserie "Mo, 15:00 - 16:30". Sie dauert also 2 UE von
 jeweils 45 min. Auf die Preisformel wird also der Einheitenfaktor von 2 angewendet. (Einheitenfaktor wird nur bei vorhandener Preisformel angewendet.)';
$string['applyuserwhobookedcheckbox'] = 'Ja, ich buche das Training auch für mich selbst.';
$string['areyousure:book'] = 'Nochmal klicken, um die Buchung zu bestätigen';
$string['areyousure:cancel'] = 'Nochmal klicken, um die Buchung zu stornieren';
$string['asglobaltemplate'] = 'Als globale Vorlage hinzufügen';
$string['assesstimefinish'] = 'Ende der Bewertungsperiode';
$string['assesstimestart'] = 'Start der Bewertungsperiode';
$string['assignteachers'] = 'Lehrer:innen zuweisen:';
$string['associatedcourse'] = 'Dazu gehörender Kurs';
$string['astemplate'] = 'Als Vorlage in diesem Kurs hinzufügen';
$string['attachedfiles'] = 'Dateianhänge';
$string['attachicalfile'] = 'iCal-Datei anhängen';
$string['attachicalfile_desc'] = 'iCal-Datei mit den Terminen einer Buchungsoption zu E-Mails hinzufügen.';
$string['attachment'] = 'Angehängte Dateien';
$string['autcrheader'] = '[VERALTET] Automatisches Erstellen von Buchungsoptionen';
$string['autcrwhatitis'] = 'If this option is enabled it automatically creates a new booking option and assigns
 a user as booking manager / teacher to it. Users are selected based on a custom user profile field value.';
$string['autoenrol'] = 'Nutzer:innen automatisch in verknüpften Kurs einschreiben';
$string['autoenrol_help'] = 'Falls ausgewählt werden Nutzer:innen automatisch in den Kurs eingeschrieben sobald sie die Buchung durchgeführt haben und wieder ausgetragen, wenn die Buchung storniert wird.';
$string['automaticbookingoptioncompletion'] = 'Buchungsoption abgeschlossen, wenn Kurs abgeschlossen ist';
$string['automaticbookingoptioncompletion_desc'] = 'Wenn aktiviert, wird die Buchungsoption als abgeschlossen gesetzt, sobald der zugehörige Kurs abgeschlossen ist.';
$string['automaticcoursecreation'] = 'Automatische Erstellung von Moodle-Kursen <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['availability'] = 'Verfügbarkeit';
$string['availabilityconditions'] = 'Verfügbarkeit einschränken';
$string['availabilityconditionsheader'] = '<i class="fa fa-fw fa-key" aria-hidden="true"></i>&nbsp;Verfügbarkeit einschränken';
$string['availabilityinfotextsheading'] = 'Beschreibungstexte für verfügbare Buchungs- und Wartelistenplätze <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['available'] = 'Plätze verfügbar';
$string['availableplaces'] = 'Verfügbare Plätze: {$a->available} von {$a->maxanswers}';
$string['availplacesfull'] = 'Voll';
$string['back'] = 'Zurück';
$string['backtoresponses'] = '&lt;&lt; Zurück zu den Buchungen';
$string['badge:exp'] = '<span class="badge bg-danger text-light"><i class="fa fa-flask" aria-hidden="true"></i> Experimentell</span>';
$string['badge:pro'] = '<span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['banusernames'] = 'Nutzer:innennamen ausschließen';
$string['banusernames_help'] = 'Komma getrennte Liste von Usernamen, die nicht teilnehmen können. Um Usernamen mit bestimmten Endungen auszuschließen, kann man folgendes eingeben: gmail.com, yahoo.com';
$string['beforebookedtext'] = 'Vor der Buchung';
$string['beforecompletedtext'] = 'Nach der Buchung';
$string['beforecompletedtext_help'] = 'Text der vor dem Abschluss angezeigt wird';
$string['bigbluebuttonmeeting'] = 'BigBlueButton-Meeting';
$string['biggerthan'] = 'ist größer als (Zahl)';
$string['billboardtext'] = 'Text der statt der ursprünglichen Beschreibung angezeigt wird';
$string['blockabove'] = 'Blockiere über';
$string['blockalways'] = 'Blockiere unabhängig von Plätzen';
$string['blockbelow'] = 'Blockiere unter';
$string['blockinglabel'] = 'Nachricht beim Blockieren';
$string['blockinglabel_help'] = 'Geben Sie die Nachricht ein, die angezeigt werden soll, wenn Buchungen blockiert werden.
Wenn Sie die Nachricht lokalisieren wollen, verwenden Sie die
<a href="https://docs.moodle.org/403/de/Mehrsprachiger_Inhalt" target="_blank">Moodle-Sprachfilter</a>.';
$string['blockoperator'] = 'Operator';
$string['blockoperator_help'] = '<b>Blockiere über</b> ... Sobald der angegebene Prozentsatz an Buchungen erreicht ist, wird das Online-Buchen geblockt,
es kann dann nur noch an der Kassa oder durch einen Admin gebucht werden.<br>
<b>Blockiere unter</b> ... Das Buchen wird geblockt bis der angegebene Prozentsatz an Buchungen erreicht ist,
bis dahin kann nur an der Kassa oder durch einen Admin gebucht werden.';
$string['boactioncancelbookingdesc'] = "Wird verwendet, wenn eine Option mehrmals gekauft werden können soll.";
$string['boactioncancelbookingvalue'] = "Aktiviere sofortige Ausbuchung";
$string['boactionname'] = "Name der Aktion";
$string['boactions'] = 'Aktionen nach der Buchung <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span> <span class="badge bg-danger text-light"><i class="fa fa-flask" aria-hidden="true"></i> Experimentell</span>';
$string['boactions_desc'] = "Aktionen nach der Buchung sind derzeit ein experimentelles Feature.
Sie können es ausprobieren, aber bitte verwenden Sie es noch auf keiner Produktivplattform!";
$string['boactionselectuserprofilefield'] = "Wähle Profilfeld";
$string['boactionuserprofilefieldvalue'] = 'Wert';
$string['bocondallowedtobookininstance'] = 'Buchen soll auch ohne spezielle Berechtigung möglich sein';
$string['bocondallowedtobookininstanceanyways'] = "Benutzer:innen dürfen auch ohne die Berechtigung '<b>mod/booking:choose</b>' buchen.<br>
<div class='text-danger'>Hinweis: Sowohl dieses als auch das darüberliegende Kästchen müssen angehakt sein, um dies zu aktivieren.</div>";
$string['bocondallowedtobookininstanceavailable'] = 'Buchen';
$string['bocondallowedtobookininstancefullavailable'] = 'Buchen möglich';
$string['bocondallowedtobookininstancefullnotavailable'] = 'Kein Recht auf dieser Instanz zu buchen';
$string['bocondallowedtobookininstancenotavailable'] = 'Buchen nicht möglich';
$string['bocondalreadybooked'] = 'alreadybooked: Von diesem User bereits gebucht';
$string['bocondalreadybookedavailable'] = 'Noch nicht gebucht';
$string['bocondalreadybookedfullavailable'] = 'Nutzer:in hat noch nicht gebucht';
$string['bocondalreadybookedfullnotavailable'] = 'Gebucht';
$string['bocondalreadybookednotavailable'] = 'Gebucht';
$string['bocondalreadyreserved'] = 'alreadyreserved: Von diesem User bereits in den Warenkorb gelegt';
$string['bocondalreadyreservedavailable'] = 'Noch nicht in den Warenkorb gelegt';
$string['bocondalreadyreservedfullavailable'] = 'Noch nicht in den Warenkorb gelegt';
$string['bocondalreadyreservedfullnotavailable'] = 'In den Warenkorb gelegt';
$string['bocondalreadyreservednotavailable'] = 'In den Warenkorb gelegt';
$string['bocondaskforconfirmation'] = 'askforconfirmation: Manuelle Bestätigung der Buchung';
$string['bocondaskforconfirmationavailable'] = 'Buchen';
$string['bocondaskforconfirmationfullavailable'] = 'Buchen möglich';
$string['bocondaskforconfirmationfullnotavailable'] = 'Buchen - auf Warteliste';
$string['bocondaskforconfirmationnotavailable'] = 'Buchen - auf Warteliste';
$string['bocondbookingclosingtimefullnotavailable'] = 'Konnte bis {$a} gebucht werden.';
$string['bocondbookingclosingtimenotavailable'] = 'Konnte bis {$a} gebucht werden.';
$string['bocondbookingopeningtimefullnotavailable'] = 'Kann ab {$a} gebucht werden.';
$string['bocondbookingopeningtimenotavailable'] = 'Kann ab {$a} gebucht werden.';
$string['bocondbookingpolicy'] = 'Buchungsbedingungen';
$string['bocondbookingtime'] = 'Nur in einer bestimmten Zeit buchbar';
$string['bocondbookingtimeavailable'] = 'Innerhalb der normalen Buchungszeiten.';
$string['bocondbookingtimenotavailable'] = 'Nicht innerhalb der normalen Buchungszeiten.';
$string['bocondbookitbutton'] = 'bookitbutton: Zeige den normalen Buchen-Button.';
$string['bocondcapbookingchooseavailable'] = 'Buchen möglich';
$string['bocondcapbookingchoosefullavailable'] = 'Berechtigung auf dieser Instanz zu buchen';
$string['bocondcapbookingchoosefullnotavailable'] = 'Kein Recht auf dieser Instanz zu buchen';
$string['bocondcapbookingchoosenotavailable'] = 'Buchen nicht möglich';
$string['bocondcustomform'] = 'Formular ausfüllen';
$string['bocondcustomformavailable'] = 'Buchen';
$string['bocondcustomformdeleteinfoscheckboxuser'] = 'Checkbox um Angaben zu löschen';
$string['bocondcustomformdeleteinfoscheckboxusertext'] = 'Möchten Sie, dass Ihre hier gemachten Angaben nach Abschluss der Veranstaltung gelöscht werden?';
$string['bocondcustomformfullavailable'] = 'Buchen ist möglich';
$string['bocondcustomformfullnotavailable'] = 'Buchen ist nicht möglich';
$string['bocondcustomformfullybooked'] = 'Die Option "{$a}" ist bereits voll gebucht.';
$string['bocondcustomformlabel'] = "Bezeichnung";
$string['bocondcustomformmail'] = "E-Mail";
$string['bocondcustomformmailerror'] = "Die E-Mail ist nicht richtig.";
$string['bocondcustomformnotavailable'] = 'Buchen';
$string['bocondcustomformnotempty'] = 'Darf nicht leer sein';
$string['bocondcustomformnumberserror'] = "Bitte trage eine gültige Zahl an Tagen ein.";
$string['bocondcustomformrestrict'] = 'Formular muss vor der Buchung ausgefüllt werden';
$string['bocondcustomformstillavailable'] = "noch verfügbar";
$string['bocondcustomformurl'] = "Url";
$string['bocondcustomformurlerror'] = "Die URL ist nicht valide oder beginnt nicht mit http oder https.";
$string['bocondcustomformvalue'] = 'Wert';
$string['bocondcustomformvalue_help'] = 'Wenn ein DropDown Menü ausgewählt ist bitte einen Wert pro Zeile eingeben. Die Werte und angezeigte Werte können getrennt eingegeben werden, also z.b. "1 => Mein erster Wert => anzahl_der_möglichkeiten" usw.';
$string['bocondcustomuserprofilefieldavailable'] = 'Buchen';
$string['bocondcustomuserprofilefieldconnectsecondfield'] = 'Mit weiterem Profilfeld verbinden';
$string['bocondcustomuserprofilefieldfield'] = 'Profilfeld';
$string['bocondcustomuserprofilefieldfield2'] = 'Zweites Profilfeld';
$string['bocondcustomuserprofilefieldfullavailable'] = 'Buchen möglich';
$string['bocondcustomuserprofilefieldfullnotavailable'] = 'Nur Benutzer:innen, bei denen das benutzerdefinierte Profilfeld
 {$a->profilefield} auf den Wert {$a->value} gesetzt ist, dürfen buchen.<br>Sie haben aber das Recht dennoch zu buchen.';
$string['bocondcustomuserprofilefieldnotavailable'] = 'Buchen nicht möglich';
$string['bocondcustomuserprofilefieldoperator'] = 'Operator';
$string['bocondcustomuserprofilefieldoperator2'] = 'Operator (2. Feld)';
$string['bocondcustomuserprofilefieldvalue'] = 'Wert';
$string['bocondcustomuserprofilefieldvalue2'] = 'Wert (2. Feld)';
$string['bocondenrolledincohorts'] = 'Benutzer:in ist in bestimmte(n) globale(n) Gruppe(n) eingeschrieben';
$string['bocondenrolledincohortsavailable'] = 'Buchen';
$string['bocondenrolledincohortsfullavailable'] = 'Buchen möglich';
$string['bocondenrolledincohortsfullnotavailable'] = 'Nur Benutzer:innen, die in mindestens eine der folgenden globalen Grupppen eingeschrieben sind, dürfen buchen: {$a}
    <br>Sie haben aber das Recht dennoch zu buchen.';
$string['bocondenrolledincohortsfullnotavailableand'] = 'Nur Benutzer:innen, die in alle folgenden globalen Grupppen eingeschrieben sind, dürfen buchen: {$a}
<br>Sie haben aber das Recht dennoch zu buchen.';
$string['bocondenrolledincohortsnotavailable'] = 'Buchen nicht möglich, da Sie in mindestens eine der folgenden globalen Grupppen nicht eingeschrieben sind: {$a}';
$string['bocondenrolledincohortsnotavailableand'] = 'Buchen nicht möglich, da Sie nicht in alle der folgenden globalen Grupppen eingeschrieben sind: {$a}';
$string['bocondenrolledincohortswarning'] = 'Sie haben eine sehr hohe Anzahl an Globalen Gruppen auf Ihrem System. Nicht alle werden als Auswahl angezeigt. Wenn das ein Problem für Sie ist, kontaktieren Sie <a mailto="info@wunderyte.at">Wunderbyte</a>';
$string['bocondenrolledincourse'] = 'Benutzer:in ist in bestimmte(n) Kurs(e) eingeschrieben';
$string['bocondenrolledincourseavailable'] = 'Buchen';
$string['bocondenrolledincoursefullavailable'] = 'Buchen möglich';
$string['bocondenrolledincoursefullnotavailable'] = 'Nur Benutzer:innen, die in mindestens einen der folgenden Kurs(e) eingeschrieben sind, dürfen buchen: {$a}
    <br>Sie haben aber das Recht dennoch zu buchen.';
$string['bocondenrolledincoursefullnotavailableand'] = 'Nur Benutzer:innen, die in alle folgenden Kurs(e) eingeschrieben sind, dürfen buchen: {$a}
<br>Sie haben aber das Recht dennoch zu buchen.';
$string['bocondenrolledincoursenotavailable'] = 'Buchen nicht möglich, da Sie in mindestens einen der folgenden Kurse nicht eingeschrieben sind: {$a}';
$string['bocondenrolledincoursenotavailableand'] = 'Buchen nicht möglich, da Sie nicht in alle der folgenden Kurse eingeschrieben sind: {$a}';
$string['bocondfullybooked'] = 'Ausgebucht';
$string['bocondfullybookedavailable'] = 'Buchen';
$string['bocondfullybookedfullavailable'] = 'Buchen möglich';
$string['bocondfullybookedfullnotavailable'] = 'Ausgebucht';
$string['bocondfullybookednotavailable'] = 'Ausgebucht';
$string['bocondfullybookedoverride'] = 'fullybookedoverride: Kann überbucht werden.';
$string['bocondfullybookedoverrideavailable'] = 'Buchen';
$string['bocondfullybookedoverridefullavailable'] = 'Buchen möglich';
$string['bocondfullybookedoverridefullnotavailable'] = 'Ausgebucht';
$string['bocondfullybookedoverridenotavailable'] = 'Ausgebucht';
$string['bocondinstanceavailability'] = 'instanceavailability: Voraussetzungen der Instanz';
$string['bocondinstanceavailabilityavailable'] = 'Buchen';
$string['bocondinstanceavailabilityfullavailable'] = 'Buchen möglich';
$string['bocondinstanceavailabilityfullnotavailable'] = '<a href="{$a}" target="_blank">Voraussetzungen der Buchungsinstanz</a> nicht erfüllt.<br>
Sie haben aber das Recht dennoch zu buchen.';
$string['bocondinstanceavailabilitynotavailable'] = 'Buchen nicht möglich';
$string['bocondisbookable'] = 'isbookable: Buchen ist erlaubt';
$string['bocondisbookableavailable'] = 'Buchen';
$string['bocondisbookablefullavailable'] = 'Buchen möglich';
$string['bocondisbookablefullnotavailable'] = 'Buchen ist nicht erlaubt.
 <br>Sie haben aber das Recht dennoch zu buchen.';
$string['bocondisbookablenotavailable'] = 'Buchen nicht möglich';
$string['bocondiscancelled'] = 'iscancelled: Buchungsoption storniert';
$string['bocondiscancelledavailable'] = 'Buchen';
$string['bocondiscancelledfullavailable'] = 'Buchen möglich';
$string['bocondiscancelledfullnotavailable'] = 'Storniert';
$string['bocondiscancellednotavailable'] = 'Storniert';
$string['bocondisloggedin'] = 'isloggedin: User ist eingeloggt';
$string['bocondisloggedinnotavailable'] = 'Log-In um zu buchen';
$string['bocondisloggedinprice'] = 'isloggedinprice: Zeige alle Preise wenn nicht eingelogged.';
$string['bocondmaxnumberofbookings'] = 'max_number_of_bookings: Maximum an Nutzer:innen erreicht, die dieser User buchen darf';
$string['bocondmaxnumberofbookingsavailable'] = 'Buchen';
$string['bocondmaxnumberofbookingsfullavailable'] = 'Buchen möglich';
$string['bocondmaxnumberofbookingsfullnotavailable'] = 'Nutzer:in hat die max. Buchungsanzahl erreicht';
$string['bocondmaxnumberofbookingsnotavailable'] = 'Max. Buchungsanzahl erreicht';
$string['bocondnotifymelist'] = 'Benachrichtigungsliste';
$string['bocondonnotifylistavailable'] = 'Buchen';
$string['bocondonnotifylistfullavailable'] = 'Buchen möglich';
$string['bocondonnotifylistfullnotavailable'] = 'Ausgebucht - Nutzer:in ist auf der Benachrichtigungliste';
$string['bocondonnotifylistnotavailable'] = 'Ausgebucht - Sie sind auf der Benachrichtigungsliste';
$string['bocondonwaitinglist'] = 'onwaitinglist: Auf Warteliste';
$string['bocondonwaitinglistavailable'] = 'Buchen';
$string['bocondonwaitinglistfullavailable'] = 'Buchen möglich';
$string['bocondonwaitinglistfullnotavailable'] = 'Nutzer:in ist auf der Warteliste';
$string['bocondonwaitinglistnotavailable'] = 'Sie sind auf der Warteliste';
$string['bocondoptionhasstarted'] = 'Hat bereits begonnen';
$string['bocondoptionhasstartedavailable'] = 'Buchen';
$string['bocondoptionhasstartedfullavailable'] = 'Buchen möglich';
$string['bocondoptionhasstartedfullnotavailable'] = 'Bereits begonnen - User können nicht mehr buchen';
$string['bocondoptionhasstartednotavailable'] = 'Bereits begonnen - Buchen nicht mehr möglich';
$string['bocondotheroptionsavailable'] = 'Verknüpfte Buchungsoptionen nicht verfügbar';
$string['bocondpreviouslybooked'] = 'Benutzer:in hat früher eine bestimmte Option gebucht';
$string['bocondpreviouslybookedavailable'] = 'Buchen';
$string['bocondpreviouslybookedfullavailable'] = 'Buchen möglich';
$string['bocondpreviouslybookedfullnotavailable'] = 'Nur Benutzer:innen, die früher bereits <a href="{$a}">option</a> gebucht haben, dürfen buchen.
 <br>Sie haben aber das Recht dennoch zu buchen.';
$string['bocondpreviouslybookednotavailable'] = 'Nur Benutzer:innen, die früher bereits <a href="{$a}">option</a> gebucht haben, dürfen buchen.';
$string['bocondpreviouslybookedoptionid'] = 'Buchungsoption';
$string['bocondpreviouslybookedrestrict'] = 'User hat früher bereits eine bestimmte Option gebucht';
$string['bocondpriceisset'] = 'priceisset: Preis ist vorhanden';
$string['bocondpriceissetavailable'] = 'Buchen';
$string['bocondpriceissetfullavailable'] = 'Buchen möglich';
$string['bocondpriceissetfullnotavailable'] = 'Preis gesetzt, Bezahlung nötig';
$string['bocondpriceissetnotavailable'] = 'Muss bezahlt werden';
$string['bocondselectusers'] = 'Nur bestimmte Benutzer:in(nen) dürfen buchen';
$string['bocondselectusersavailable'] = 'Buchen';
$string['bocondselectusersfullavailable'] = 'Buchen möglich';
$string['bocondselectusersfullnotavailable'] = 'Nur die folgenden Nutzer:innen können buchen:<br>{$a}';
$string['bocondselectusersnotavailable'] = 'Buchen nicht möglich';
$string['bocondselectusersrestrict'] = 'Nur bestimmte Benutzer:in(nen) dürfen buchen';
$string['bocondselectusersuserids'] = 'Benutzer:in(nen), die buchen dürfen';
$string['bocondselectusersuserids_help'] = '<p>Wenn Sie diese Einschränkung verwenden, können nur ausgewählten Personen diese Veranstaltung buchen.</p>
<p>Sie können diese Einschränkung aber auch verwenden, um es bestimmten Personen zu ermöglichen, andere Einschränkungen zu umgehen:</p>
<p>(1) Klicken Sie hierzu auf das Häkchen "Steht in Bezug zu einer anderen Einschränkung"<br>
(2) Stellen Sie sicher, dass der Operator "ODER" ausgewählt ist<br>
(3) Wählen Sie alle Einschränkungen aus, die umgangen werden sollen.</p>
<p>Beispiele:<br>
"Ausgebucht" => Die ausgewählte Person darf auch dann buchen, wenn die Veranstaltung bereits ausgebucht ist.<br>
"Nur in einer bestimmten Zeit buchbar" => Die ausgewählte Person darf auch außerhalb der normalen Buchungszeiten buchen</p>';
$string['bocondsubbooking'] = 'Zusatzbuchungen sind vorhanden';
$string['bocondsubbookingavailable'] = 'Buchen';
$string['bocondsubbookingblocks'] = 'Zusatzbuchung blockiert Verfügbarkeit';
$string['bocondsubbookingblocksavailable'] = 'Buchen';
$string['bocondsubbookingblocksfullavailable'] = 'Buchen möglich';
$string['bocondsubbookingblocksfullnotavailable'] = 'Buchen möglich';
$string['bocondsubbookingblocksnotavailable'] = 'Buchen';
$string['bocondsubbookingfullavailable'] = 'Buchen möglich';
$string['bocondsubbookingfullnotavailable'] = 'Buchen möglich';
$string['bocondsubbookingnotavailable'] = 'Buchen';
$string['bocondsubisbookableavailable'] = 'Buchen';
$string['bocondsubisbookablefullavailable'] = 'Buchen möglich';
$string['bocondsubisbookablefullnotavailable'] = 'Sie müssen zuerst buchen bevor sie Zusatzbuchungen vornehmen können.';
$string['bocondsubisbookablenotavailable'] = 'Sie müssen zuerst buchen bevor sie Zusatzbuchungen vornehmen können.';
$string['boconduserprofilefield1default'] = 'User-Profilfeld hat einen bestimmten Wert';
$string['boconduserprofilefield1defaultrestrict'] = 'Ein ausgewähltes Userprofilfeld soll einen bestimmten Wert haben';
$string['boconduserprofilefield2custom'] = 'Benutzerdefiniertes User-Profilfeld hat einen bestimmten Wert';
$string['boconduserprofilefield2customrestrict'] = 'Ein ausgewähltes benutzerdefiniertes Userprofilfeld soll einen bestimmten Wert haben';
$string['boconduserprofilefieldavailable'] = 'Buchen';
$string['boconduserprofilefieldfield'] = 'Profilfeld';
$string['boconduserprofilefieldfullavailable'] = 'Buchen möglich';
$string['boconduserprofilefieldfullnotavailable'] = 'Nur Benutzer:innen, bei denen das Profilfeld
 {$a->profilefield} auf den Wert {$a->value} gesetzt ist, dürfen buchen.<br>Sie haben aber das Recht dennoch zu buchen.';
$string['boconduserprofilefieldnotavailable'] = 'Buchen nicht möglich';
$string['boconduserprofilefieldoperator'] = 'Operator';
$string['boconduserprofilefieldvalue'] = 'Wert';
$string['bonumberofdays'] = "Anzahl der tage";
$string['bookanyoneswitchoff'] = '<i class="fa fa-user-times" aria-hidden="true"></i> Buchen von Nutzer:innen, die nicht eingeschrieben sind, nicht erlauben (empfohlen)';
$string['bookanyoneswitchon'] = '<i class="fa fa-user-plus" aria-hidden="true"></i> Buchen von Nutzer:innen, die nicht eingeschrieben sind, erlauben';
$string['bookanyonewarning'] = 'Achtung: Sie können nun beliebige Nutzer:innen buchen. Verwenden Sie diese Einstellung nur, wenn Sie genau wissen, was Sie tun.
 Das Buchen von Nutzer:innen, die nicht in den Kurs eingeschrieben sind, kann möglicherweise zu Problemen führen.';
$string['booked'] = 'Gebucht';
$string['bookeddeleted'] = 'Buchung gelöscht';
$string['bookedpast'] = 'Gebucht (Kurs wurde bereits beendet)';
$string['bookedteachersshowemails'] = 'E-Mail-Adressen von Trainer:innen, bei denen gebucht wurde, anzeigen';
$string['bookedteachersshowemails_desc'] = 'Wenn Sie diese Einstellung aktivieren, dann werden bereits gebuchten Benutzer:innen
die E-Mail-Adressen ihrer Trainer:innen angezeigt.';
$string['bookedtext'] = 'Buchungsbestätigung';
$string['bookedtextmessage'] = 'Ihre Buchung wurde registriert:
{$a->bookingdetails}
<p>##########################################</p>
Buchungsstatus: {$a->status}
Teilnehmer:   {$a->participant}
Zur Buchungsübersicht: {$a->bookinglink}
Hier geht\'s zum dazugehörigen Kurs: {$a->courselink}
';
$string['bookedtextsubject'] = 'Buchungsbestätigung für {$a->title}';
$string['bookedtextsubjectbookingmanager'] = 'Neue Buchung für {$a->title} von {$a->participant}';
$string['bookedusers'] = 'Gebuchte Nutzer:innen';
$string['bookelectivesbtn'] = 'Ausgewählte Wahlfächer buchen';
$string['booking'] = 'Buchung';
$string['booking:addeditownoption'] = 'Neue Buchungsoptionen anlegen und eigene bearbeiten.';
$string['booking:addinstance'] = 'Neue Buchungsinstanz anlegen';
$string['booking:bookanyone'] = 'Darf alle Nutzer:innen buchen';
$string['booking:bookforothers'] = "Für andere buchen";
$string['booking:canoverbook'] = "Darf überbuchen";
$string['booking:canreviewsubstitutions'] = "Kann Vertretungen als kontrolliert markieren";
$string['booking:canseeinvisibleoptions'] = 'Unsichtbare Buchungsoptionen sehen.';
$string['booking:cansendmessages'] = 'Kann Nachrichten schicken.';
$string['booking:changelockedcustomfields'] = 'Kann gesperrte benutzerdefinierte Buchungsoptionsfelder verändern.';
$string['booking:choose'] = 'Buchen';
$string['booking:communicate'] = 'Can communicate';
$string['booking:conditionforms'] = "Formulare von Buchungsbedingungen abschicken (z.B. Buchungsbedingungen oder Zusatzbuchungen)";
$string['booking:deleteresponses'] = 'Buchungen löschen';
$string['booking:downloadresponses'] = 'Buchungen herunterladen';
$string['booking:editbookingrules'] = "Regeln bearbeiten (Pro)";
$string['booking:editoptionformconfig'] = 'Buchungsoptionsfelder bearbeiten';
$string['booking:editteacherdescription'] = 'Beschreibung der Lehrenden bearbeiten';
$string['booking:expertoptionform'] = "Expert Buchungsoptions Formular";
$string['booking:limitededitownoption'] = 'Weniger als addeditownoption, nur sehr beschränktes Editieren eigener Optionen erlaubt.';
$string['booking:managebookedusers'] = 'Buchungen von Nutzer:innen verwalten';
$string['booking:manageoptiondates'] = 'Bearbeite Termine';
$string['booking:manageoptiontemplates'] = "Buchungsoptionsvorlagen verwalten";
$string['booking:overrideboconditions'] = 'Nutzer:in darf buchen auch wenn Verfügbarkeit false zurückliefert.';
$string['booking:rate'] = 'Rate chosen booking options';
$string['booking:readresponses'] = 'Buchungen ansehen';
$string['booking:reducedoptionform1'] = "1. Reduziertes Buchungsoptionsformular für Kursbereich.";
$string['booking:reducedoptionform2'] = "2. Reduziertes Buchungsoptionsformular für Kursbereich.";
$string['booking:reducedoptionform3'] = "3. Reduziertes Buchungsoptionsformular für Kursbereich.";
$string['booking:reducedoptionform4'] = "4. Reduziertes Buchungsoptionsformular für Kursbereich.";
$string['booking:reducedoptionform5'] = "5. Reduziertes Buchungsoptionsformular für Kursbereich.";
$string['booking:seepersonalteacherinformation'] = 'Detailinfos über Lehrende anzeigen';
$string['booking:semesters'] = 'Buchung: Semester';
$string['booking:sendpollurl'] = 'Umfragelink senden';
$string['booking:sendpollurltoteachers'] = 'Umfragelink and Trainer:innen senden';
$string['booking:subscribeusers'] = 'Für andere Teilnehmer:innen Buchungen durchführen';
$string['booking:updatebooking'] = 'Buchungen verwalten';
$string['booking:view'] = 'Darf Buchungsinstanzen sehen';
$string['booking:viewallratings'] = 'Alle Bewertungen sehen';
$string['booking:viewanyrating'] = 'Alle Bewertungen sehen';
$string['booking:viewrating'] = 'Gesamtbewertung sehen';
$string['booking:viewreports'] = 'Zugang um gewisse Buchungsberichte zu sehen';
$string['bookingaction'] = "Aktion";
$string['bookingactionadd'] = "Füge Aktion hinzu";
$string['bookingafteractionsfailed'] = 'Actions nach der Buchung gescheitert';
$string['bookingandcancelling'] = 'Buchen und Stornieren';
$string['bookinganswercancelled'] = 'Buchungsoption von/für Nutzer:in storniert';
$string['bookinganswerwaitingforconfirmation'] = 'Voranmeldung für Buchungsoption eingetroffen';
$string['bookinganswerwaitingforconfirmationdesc'] = 'Nutzer:in mit id {$a->relateduserid} hat sich für die Buchungsoption mit ID {$a->objectid} vorangemeldet.';
$string['bookingattachment'] = 'Anhang';
$string['bookingcampaign'] = 'Kampagne';
$string['bookingcampaigns'] = 'Buchung: Kampagnen (PRO)';
$string['bookingcampaignssubtitle'] = 'Mit Kampagnen können Sie für einen festgelegten Zeitraum die Preise von ausgewählten
 Buchungsoptionen vergünstigen und das Buchungslimit für diesen Zeitraum erhöhen. Damit die Kampagnen funktionieren, muss der
 Moodle Cron-Job regelmäßig laufen.<br>
 Überschneidende Kampagnen werden addiert. Zwei 50% Kampagnen führen zu einem 25% Preis.';
$string['bookingcampaignswithbadge'] = 'Buchung: Kampagnen <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['bookingcategory'] = 'Kategorie';
$string['bookingchangedtext'] = 'Benachrichtigung bei Änderungen an der Buchung (geht nur an User, die bereits gebucht haben). Verwenden Sie den Platzhalter {changes} um die Änderungen anzuzeigen. 0 eingeben um Änderungsbenachrichtigungen auszuschalten.';
$string['bookingchangedtext_help'] = '0 eingeben um Änderungsbenachrichtigungen auszuschalten.';
$string['bookingchangedtextmessage'] = 'Ihre Buchung "{$a->title}" hat sich geändert.
Das ist neu:
{changes}
Klicken Sie auf den folgenden Link um die Änderung(en) und eine Übersicht über alle Buchungen zu sehen: {$a->bookinglink}
';
$string['bookingchangedtextsubject'] = 'Änderungsbenachrichtigung für {$a->title}';
$string['bookingclosingtime'] = 'Buchbar bis';
$string['bookingcondition'] = "Bedingung";
$string['bookingconfirmationlink'] = 'Link zur Buchungsbestätigung';
$string['bookingcustomfield'] = 'Benutzerdefinierte Felder für Buchungsoptionen';
$string['bookingdate'] = 'Buchungsdatum';
$string['bookingdebugmode'] = 'Booking-Debug-Modus';
$string['bookingdebugmode_desc'] = 'Der Booking-Debug-Modus sollte nur von Entwickler:innen aktiviert werden.';
$string['bookingdefaulttemplate'] = 'Wähle Template...';
$string['bookingdeleted'] = 'Ihre Buchung wurde erfolgreich storniert';
$string['bookingdetails'] = "Buchungsdetails";
$string['bookingduration'] = 'Dauer';
$string['bookingfailed'] = 'Buchung gescheitert';
$string['bookingfull'] = 'Ausgebucht';
$string['bookingfulldidntregister'] = 'Es wurden nicht alle Nutzer:innen übertragen, da die Option bereits ausgebucht ist!';
$string['bookinghistory'] = 'Buchungshistorie';
$string['bookingidfilter'] = 'Buchungsinstanz';
$string['bookingimages'] = 'Header-Bilder für Buchungsoptionen hochladen - diese müssen exakt den selben Namen haben, wie der jeweilige Wert, den das ausgewählte benutzerdefinierte Feld in der jeweiligen Buchungsoption hat.';
$string['bookingimagescustomfield'] = 'Benutzerdefiniertes Feld von Buchungsoptionen, mit dem die Header-Bilder gematcht werden';
$string['bookinginstance'] = 'Buchungsinstanz';
$string['bookinginstancetemplatename'] = 'Name der Buchungsinstanz-Vorlage';
$string['bookinginstancetemplatessettings'] = 'Buchung: Vorlagen für Buchungsinstanzen';
$string['bookinginstanceupdated'] = 'Buchungsinstanz upgedated';
$string['bookinglink'] = "Buchungsinstanzlink";
$string['bookingmanagererror'] = 'Der angegebene Nutzername ist ungültig. Entweder existiert der/die Nutzer/in nicht oder es gibt mehrere Nutzer:innen mit dem selben Nutzernamen (Dies ist zum Beispiel der Fall, wenn Sie MNET und lokale Authentifizierung gleichzeitig aktiviert haben)';
$string['bookingmeanwhilefull'] = 'Leider hat inzwischen jemand anderer den letzten Platz gebucht';
$string['bookingname'] = 'Buchungsinstanzname';
$string['bookingnotopenyet'] = 'Ihr Event startet erst in {$a} Minuten. Dieser Link wird Sie ab 15 Minuten vor dem Event weiterleiten.';
$string['bookingopen'] = 'Offen';
$string['bookingopeningtime'] = 'Buchbar ab';
$string['bookingoption'] = 'Buchungsoption';
$string['bookingoptionbooked'] = 'Buchungsoption gebucht';
$string['bookingoptionbookedotheruserdesc'] = 'NutzerIn mit ID {$a->userid} hat NutzerIn mit ID {$a->relateduserid} für die Buchung der Option Nr. {$a->objectid} gebucht.';
$string['bookingoptionbookedotheruserwaitinglistdesc'] = 'NutzerIn mit ID {$a->userid} hat NutzerIn mit ID {$a->relateduserid} für die Buchung der Option Nr. {$a->objectid} auf die Warteliste gebucht.';
$string['bookingoptionbookedsameuserdesc'] = 'NutzerIn mit ID {$a->userid} hat die Buchung der Option Nr. {$a->objectid} gebucht.';
$string['bookingoptionbookedsameuserwaitinglistdesc'] = 'NutzerIn mit ID {$a->userid} hat die Buchung der Option Nr. {$a->objectid} auf die Warteliste gebucht.';
$string['bookingoptionbookedviaautoenrol'] = 'Buchungsoption automatisch gebucht';
$string['bookingoptionbookedviaautoenroldesc'] = 'NutzerIn mit ID {$a->userid} wurde in die Buchungsoption Nr. {$a->objectid} via Einschreibelink angemeldet';
$string['bookingoptioncalendarentry'] = '<a href="{$a}" class="btn btn-primary">Jetzt buchen...</a>';
$string['bookingoptioncanbecancelleduntil'] = 'Sie können bis zum {$a} stornieren.';
$string['bookingoptioncancelled'] = "Buchungsoption für alle storniert";
$string['bookingoptioncantbecancelledanymore'] = 'Stornierung war bis zum {$a} möglich.';
$string['bookingoptioncompleted'] = 'Buchungsoption abgeschlossen';
$string['bookingoptionconfirmed'] = 'Buchungsoption bestätigt';
$string['bookingoptionconfirmed:description'] = 'NutzerIn mit ID {$a->userid} hat NutzerIn mit ID {$a->relateduserid} für die Buchung der Option Nr. {$a->objectid} freigeschaltet.';
$string['bookingoptioncreated'] = 'Buchungsoption angelegt';
$string['bookingoptiondatecreated'] = 'Termin erstellt';
$string['bookingoptiondatedeleted'] = 'Termin gelöscht';
$string['bookingoptiondateupdated'] = 'Termin geändert';
$string['bookingoptiondefaults'] = 'Standard-Einstellungen für Buchungsoptionen';
$string['bookingoptiondefaultsdesc'] = 'Hier können Sie Standardwerte für die Erstellung von Buchungsoptionen setzen und diese gegebenenfalls sperren.';
$string['bookingoptiondeleted'] = 'Buchungsoption gelöscht';
$string['bookingoptiondescription'] = 'Beschreibung der Buchungsoption';
$string['bookingoptiondetaillink'] = 'Buchungsoptiondetaillink';
$string['bookingoptionfreetobookagain'] = 'Wieder freie Plätze';
$string['bookingoptionimage'] = 'Header-Bild';
$string['bookingoptionname'] = 'Bezeichnung der Buchungsoption';
$string['bookingoptionnamewithoutprefix'] = 'Name (ohne Präfix)';
$string['bookingoptionprice'] = 'Preis';
$string['bookingoptionsall'] = 'Shortcode um alle Buchungsoptionen anzuzeigen.';
$string['bookingoptionsfromtemplatemenu'] = 'Neue Buchungsoption aus Vorlage erstellen';
$string['bookingoptionsmenu'] = 'Buchungsoptionen';
$string['bookingoptiontitle'] = 'Bezeichnung der Buchungsoption';
$string['bookingoptionupdated'] = 'Buchungsoption aktualisiert';
$string['bookingoptionupdateddesc'] = 'NutzerIn mit ID "{$a->userid}" hat Buchungsoption "{$a->objectid}" aktualisiert.';
$string['bookingoptionwaitinglistbooked'] = 'Auf Warteliste gebucht';
$string['bookingorganizatorname'] = 'Name des Veranstalters';
$string['bookingpassed'] = 'Dieses Event ist nicht mehr aktiv.';
$string['bookingplacesenoughmessage'] = 'Noch Plätze verfügbar.';
$string['bookingplacesfullmessage'] = 'Ausgebucht.';
$string['bookingplacesinfotexts'] = 'Anzeige der Platzverfügbarkeit';
$string['bookingplacesinfotextsinfo'] = 'Wählen Sie aus, wie die Platzverfügbarkeit für NutzerInnen angezeigt werden soll.';
$string['bookingplaceslowmessage'] = 'Nur noch wenige Plätze verfügbar!';
$string['bookingplaceslowpercentage'] = 'Buchungsplätze: Prozentsatz für "Nur noch wenige Plätze verfügbar"-Nachricht';
$string['bookingplaceslowpercentagedesc'] = 'Wenn die Anzahl verbookingstrackerpresencecounterfügbarer Buchungsplätze diesen Prozentsatz erreicht oder unter diesen Prozentsatz sinkt, wird eine Nachricht angezeigt, dass nur noch wenige Plätze verfügbar sind.';
$string['bookingplacesplacesleft'] = '{$a} freie Plätze';
$string['bookingplacesunlimitedmessage'] = 'Sofort verfügbar';
$string['bookingpoints'] = 'Kurspunkte';
$string['bookingpolicy'] = 'Buchungsbedingungen - Booking Policy';
$string['bookingpolicyagree'] = 'Ich habe die Buchungsbedingungen gelesen und erkläre mich damit einverstanden.';
$string['bookingpolicynotchecked'] = 'Sie haben die Buchungsbedingungen nicht akzeptiert.';
$string['bookingpollurl'] = 'Link zur Umfrage';
$string['bookingpollurlteachers'] = 'Link zur Trainer:innen-Umfrage';
$string['bookingpricecategory'] = 'Preiskategorie"';
$string['bookingpricecategoryinfo'] = 'Definieren Sie den Namen der Preiskategorie, zum Beispiel "Studierende"';
$string['bookingpricesettings'] = 'Preis-Einstellungen';
$string['bookingpricesettings_desc'] = 'Individuelle Einstellungen für die Preise von Buchungen.';
$string['bookingreportlink'] = 'Link zum Buchungsberichts';
$string['bookingrule'] = 'Regel';
$string['bookingruleaction'] = "Aktion der Regel";
$string['bookingruleapply'] = "Regel anwenden";
$string['bookingruleapplydesc'] = "Entfernen Sie den Haken, wenn Sie die Regel deaktivieren möchten.";
$string['bookingrulecondition'] = "Kondition der Regel";
$string['bookingruledeactivate'] = "Regel für diese Buchungsoption <b>deaktivieren</b>";
$string['bookingruleisactive'] = "Regel ist aktiv und wird angewandt";
$string['bookingruleisnotactive'] = "Regel ist nicht aktiv und wird nicht angewandt";
$string['bookingrules'] = 'Buchung: Regeln';
$string['bookingrulesnootherfound'] = 'Keine anderen Regeln gefunden';
$string['bookingrulesothercontextheading'] = 'Link zu Regeln in anderen Kontexten';
$string['bookingruleswithbadge'] = 'Buchung: Regeln <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['bookingruletemplate'] = 'Vorgefertigte Templates für Regeln deaktivieren';
$string['bookingruletemplates'] = 'Lade eine Template-Regel';
$string['bookingruletemplatesactive'] = 'Vorgefertigte Templates für Regeln aktivieren';
$string['bookings'] = 'Buchungen';
$string['bookingsaved'] = '<b>Vielen Dank für Ihre Buchung!</b> <br /> Ihre Buchung wurde erfolgreich gespeichert und ist somit abgeschlossen. Sie können nun weitere Online-Seminare buchen oder bereits getätigte Buchungen verwalten';
$string['bookingsettings'] = 'Buchung: Einstellungen';
$string['bookingstracker'] = "Buchungstracker";
$string['bookingstracker_desc'] = "Hier können Sie den Buchungstracker aktivieren.
Er erlaubt es berechtigten Benutzer/innen, die Buchungen der gesamten Seite auf verschiedenen hierarchischen Buchungsebenen
(Termin, Buchungsoption, Buchungsinstanz, Moodle-Kurs, gesamte Plattform) zu verwalten und für gebuchte Benutzer/innen
die Anwesenheiten zu hinterlegen.";
$string['bookingstrackerdelete'] = 'Abmelden';
$string['bookingstrackerpresencecounter'] = 'Anwesenheiten zählen';
$string['bookingstrackerpresencecounter_desc'] = 'Zähler anzeigen, der die Gesamtzahl der Anwesenheiten anzeigt.
Definieren Sie in der nächsten Einstellung, welcher Anwesenheitsstatus gezählt werden soll.';
$string['bookingstrackerpresencecountervaluetocount'] = 'Anwesenheitsstatus, der gezählt werden soll';
$string['bookingstrackerpresencecountervaluetocount_desc'] = 'Die Anzahl der Anwesenheiten wird für den ausgewählten Status gezählt und im Buchungstracker angezeigt.';
$string['bookingsubbooking'] = "Zusatzbuchungen";
$string['bookingsubbookingadd'] = 'Füge eine Zusatzbuchung hinzu';
$string['bookingsubbookingdelete'] = 'Lösche Zusatzbuchung';
$string['bookingsubbookingedit'] = 'Bearbeite';
$string['bookingsubbookingsheader'] = "Zusatzbuchungen";
$string['bookingtags'] = 'Schlagwörter';
$string['bookingtext'] = 'Buchungsbeschreibung';
$string['bookinguseastemplate'] = 'Setze diese Regel als Template';
$string['booknow'] = 'Jetzt buchen';
$string['bookondetail'] = 'Mehr Info';
$string['bookonlyondetailspage'] = 'Buchen nur auf der Detailseite der Buchungsoption';
$string['bookonlyondetailspage_desc'] = 'Das bedeutet, dass das Buchen nicht aus der Liste heraus möglich ist, sondern nur von der Detailseite der Buchungsoption.';
$string['bookotheroptions'] = 'Optionen buchen';
$string['bookotheroptionsconditionsblock'] = 'Nur buchen, wenn alle Bedingungen eingehalten sind';
$string['bookotheroptionsforce'] = "Umgang mit bestehenden Einschränkungen dieser Optionen";
$string['bookotheroptionsforcebooking'] = 'Immer buchen';
$string['bookotheroptionsnooverbooking'] = 'Nur buchen, wenn Plätze frei sind';
$string['bookotheroptionsselect'] = 'In weitere Buchungsoptionen einschreiben';
$string['bookotherusers'] = 'Buchung für andere Nutzer:innen durchführen';
$string['bookotheruserslimit'] = 'Max. Anzahl an Buchungen, die ein:e der Buchungsoption zugewiesene:r Trainer:in vornehmen kann';
$string['booktootherbooking'] = 'Nutzer:innen umbuchen / zu anderer Buchungsoption hinzufügen';
$string['bookusers'] = 'Feld für den Import, um NutzerInnen zu buchen';
$string['bookwithcredit'] = '{$a} Credit';
$string['bookwithcredits'] = '{$a} Credits';
$string['bookwithcreditsactive'] = "Buchen mit Guthaben/Credits";
$string['bookwithcreditsactive_desc'] = "Nutzer:innen mit Guthaben/Credits sehen keinen Preis, sondern können mit ihren Credits buchen.";
$string['bookwithcreditsprofilefield'] = "Benutzerdefiniertes Profilfeld für Guthaben/Credits";
$string['bookwithcreditsprofilefield_desc'] = "Um die Funktion nutzen zu können, muss es ein Profilfeld geben, in dem die Credits der Nutzer:innen hiinterlegt werden können.
<span class='text-danger'><b>Achtung:</b> Dieses Feld sollte von den Nutzer:innen nicht bearbeitet werden können.</span>";
$string['bookwithcreditsprofilefieldoff'] = 'Nicht anzeigen';
$string['bopathtoscript'] = "Pfad zur REST-Skript";
$string['bosecrettoken'] = "Sicherheits-Token";
$string['bstcourse'] = 'Kurs';
$string['bstcoursestarttime'] = 'Datum / Uhrzeit';
$string['bstinstitution'] = 'Institution';
$string['bstlink'] = 'Anzeigen';
$string['bstlocation'] = 'Ort';
$string['bstmanageresponses'] = 'Buchungen verwalten';
$string['bstparticipants'] = 'Teilnehmer:innen';
$string['bstteacher'] = 'Trainer:in(nen)';
$string['bsttext'] = 'Buchungsoption';
$string['bstwaitinglist'] = 'Auf Warteliste';
$string['btnbooknowname'] = 'Bezeichnung des Buttons "Jetzt buchen"';
$string['btncacname'] = 'Bezeichnung des Buttons "Aktivitätsabschluss bestätigen"';
$string['btncancelname'] = 'Bezeichnung des Buttons "Buchung stornieren"';
$string['btnviewavailable'] = "Verfügbare Optionen anzeigen";
$string['bulkoperations'] = 'Show list of bookingoptions to enable bulk operations';
$string['bulkoperationsheader'] = 'Daten der ausgewählten Buchungsoptionen überschreiben';
$string['cachedef_bookedusertable'] = 'Gebuchte Nutzer:innen-Tabelle (Cache)';
$string['cachedef_bookforuser'] = 'Für Nutzer:innen buchen (Cache)';
$string['cachedef_bookinganswers'] = 'Boooking Antworten (Cache)';
$string['cachedef_bookinghistorytable'] = 'Buchungshistorie (Cache)';
$string['cachedef_bookingoptions'] = 'Buchungsoptionen (Cache)';
$string['cachedef_bookingoptionsanswers'] = 'Buchungen von Buchungsoptionen (Cache)';
$string['cachedef_bookingoptionsettings'] = 'Settings für Buchungsoptionen (Cache)';
$string['cachedef_bookingoptionstable'] = 'Tabelle mit gesamten SQL-Abfragen (Cache)';
$string['cachedef_cachedbookinginstances'] = 'Buchungsinstanzen (Cache)';
$string['cachedef_cachedpricecategories'] = 'Preiskategorien in Booking (Cache)';
$string['cachedef_cachedprices'] = 'Standardpreise in Booking (Cache)';
$string['cachedef_cachedsemesters'] = 'Semester (Cache)';
$string['cachedef_cachedteachersjournal'] = 'Vertretungen & Absagen (Cache)';
$string['cachedef_conditionforms'] = 'Condition Forms (Cache)';
$string['cachedef_confirmbooking'] = 'Buchung bestätigt (Cache)';
$string['cachedef_customformuserdata'] = 'Benutzerdefiniertes Formular - Nutzerdaten (Cache)';
$string['cachedef_electivebookingorder'] = 'Elective booking order (Cache)';
$string['cachedef_eventlogtable'] = 'Eventlog-Tabelle (Cache)';
$string['cachedef_mybookingoptionstable'] = 'Meine Buchungsoptionen (Cache)';
$string['cachedef_subbookingforms'] = 'Subbooking Forms (Cache)';
$string['cachesettings'] = 'Cache Einstellungen';
$string['cachesettings_desc'] = 'Diese Änderungen haben massive Auswirkungen auf die Performance. Bitte ändern Sie hier nur etwas, wenn Sie genau wissen, was Sie tun.';
$string['cacheturnoffforbookinganswers'] = 'Caching der Antworten (der Buchungen durch Nutzer:innen) abschalten';
$string['cacheturnoffforbookinganswers_desc'] = 'Die Last auf die Datenbank wird durch diese Einstellung massiv erhöht. Bei schweren Problemen mit der Cache Kofiguration kann diese Einstellung dennoch vorteilhaft sein.';
$string['cacheturnoffforbookingsettings'] = 'Caching der Einstellungen der Buchungsoptionen abschalten';
$string['cacheturnoffforbookingsettings_desc'] = 'Die Last auf die Datenbank wird durch diese Einstellung massiv erhöht. Bei schweren Problemen mit der Cache Kofiguration kann diese Einstellung dennoch vorteilhaft sein.';
$string['caladdascourseevent'] = 'Zum Kalender hinzufügen (nur für Kursteilnehmer:innen sichtbar)';
$string['caladdassiteevent'] = 'Zum Kalender hinzufügen (für alle Nutzer:innen sichtbar)';
$string['caldonotadd'] = 'Nicht zum Kurs-Kalender hinzufügen';
$string['caleventtype'] = 'Kalenderereignis ist sichtbar für';
$string['callbackfunctionnotapplied'] = 'Callback Funktion konnte nicht angewandt werden.';
$string['callbackfunctionnotdefined'] = 'Callback Funktion nicht definiert.';
$string['campaignblockbooking'] = 'Bestimmte Buchungen blockieren';
$string['campaigncustomfield'] = 'Preis oder Buchungslimit anpassen';
$string['campaigndescriptioncpvalue'] = 'Benutzerdefiniertes User Profilfeld "{$a->cpfield}" {$a->cpoperator} "{$a->cpvalue}"';
$string['campaigndescriptionfieldvalue'] = 'Benutzerdefiniertes Buchungsoptionsfeld "{$a->bofieldname}" {$a->campaignfieldnameoperator} "{$a->fieldvalue}"';
$string['campaignend'] = 'Ende der Kampagne';
$string['campaignend_help'] = 'Wann soll die Kampagne enden?';
$string['campaignfieldname'] = 'Buchungsoptionsfeld';
$string['campaignfieldname_help'] = 'Wählen Sie das benutzerdefinierte Buchungsoptionsfeld aus, dessen Wert verglichen werden soll.';
$string['campaignfieldvalue'] = 'Wert';
$string['campaignfieldvalue_help'] = 'Wählen Sie den Wert des Feldes aus. Die Kampagne trifft auf alle Buchungsoptionen zu, die beim ausgewählten Feld diesen Wert eingetragen haben.';
$string['campaignname'] = 'Eigener Name der Kampagne';
$string['campaignname_help'] = 'Geben Sie einen beliebigen Namen für die Kampagne an - z.B. "Weihnachtsaktion 2023" oder "Oster-Rabatt 2023".';
$string['campaignstart'] = 'Beginn der Kampagne';
$string['campaignstart_help'] = 'Wann soll die Kampagne starten?';
$string['campaigntype'] = 'Kampagnentyp';
$string['cancancelbookabsolute'] = 'Stornodatum mit fixem Datum setzen';
$string['cancancelbookallow'] = 'Teilnehmer:innen dürfen Buchungen selbst stornieren';
$string['cancancelbookdays'] = 'Nutzer:innen können nur bis n Tage vor Kursstart stornieren. Negative Werte meinen n Tage NACH Kursstart.';
$string['cancancelbookdays:bookingclosingtime'] = 'Nutzer:innen können nur bis n Tage vor <b>Anmeldeschluss (Buchungsende)</b> stornieren. Negative Werte meinen n Tage NACH Anmeldeschluss.';
$string['cancancelbookdays:bookingopeningtime'] = 'Nutzer:innen können nur bis n Tage vor <b>Anmeldebeginn (Buchungsbeginn)</b> stornieren. Negative Werte meinen n Tage NACH Anmeldebeginn.';
$string['cancancelbookdays:coursestarttime'] = 'Nutzer:innen können nur bis n Tage vor <b>Kursbeginn (Start der Buchungoption)</b> stornieren. Negative Werte meinen n Tage NACH Anmeldebeginn.';
$string['cancancelbookdays:semesterstart'] = 'Nutzer:innen können nur bis n Tage vor <b>Semesterbeginn</b> stornieren. Negative Werte meinen n Tage NACH Semesterbeginn.';
$string['cancancelbookdaysno'] = 'Kein Limit';
$string['cancancelbookrelative'] = 'Stornodatum <b>relativ zu {$a}</b> setzen';
$string['cancancelbooksetting'] = 'Stornobedingen definieren';
$string['cancancelbooksetting_help'] = 'Diese Einstellungen können durch die Einstellugnen in den einzelnen Buchungsoptionen überschrieben werden.';
$string['cancancelbookunlimited'] = 'Stornieren ohne limit möglich.';
$string['cancel'] = 'Abbrechen';
$string['cancelallusers'] = 'Alle gebuchten Teilnehmer:innen stornieren';
$string['cancelbooking'] = 'Buchung stornieren';
$string['canceldateabsolute'] = 'Datum, bis zu dem storniert werden kann';
$string['canceldependenton'] = 'Stornierungsfristen abhängig von';
$string['canceldependenton_desc'] = 'Wählen Sie aus, auf welches Datumsfeld sich die Einstellung
"Nutzer:innen können nur bis n Tage vor Kursstart stornieren. Negative Werte meinen n Tage NACH Kursstart."
beziehen soll.<br>Dadurch wird auch die <i>Serviceperiode</i> von Kursen im Warenkorb entsprechend festgelegt
(wenn Shopping Cart installiert ist). Dies betrifft auch die Ratenzahlung. Entfernen Sie das ausgewählte Semester, wenn Sie Kursstart anstelle von Semesterstart nutzen möchten.';
$string['cancelical'] = 'Termin(e) absagen';
$string['cancellation'] = 'Stornierung';
$string['cancellationsettings'] = 'Stornierungseinstellungen <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['cancelmyself'] = 'Wieder abmelden';
$string['canceloption'] = "Storniere Buchungsoption";
$string['canceloption_desc'] = "Stornieren einer Buchungsoption bedeutet, dass die Option nicht mehr buchbar ist, aber weiterhin als storniert in der Liste angezeigt wird.";
$string['cancelreason'] = "Grund für die Stornierung dieser Buchungsoption";
$string['cancelsign'] = '<i class="fa fa-ban" aria-hidden="true"></i>';
$string['cancelthisbookingoption'] = "Storniere diese Buchungsoption";
$string['canceluntil'] = 'Stornieren nur bis zu bestimmtem Zeitpunkt erlauben';
$string['cannotremovesubscriber'] = 'Um die Buchung zu stornieren, muss zuvor der Aktivitätsabschluss entfernt werden. Die Buchung wurde nicht storniert';
$string['categories'] = 'Kategorien';
$string['category'] = 'Kategorie';
$string['categoryheader'] = '[VERALTET] Kategorie';
$string['categoryname'] = 'Kategoriename';
$string['cdo:bookingclosingtime'] = 'Anmeldeschluss (bookingclosingtime)';
$string['cdo:bookingopeningtime'] = 'Buchungsbeginn (bookingopeningtime)';
$string['cdo:buttoncolor:danger'] = 'Danger (Rot)';
$string['cdo:buttoncolor:primary'] = 'Primary (Blau)';
$string['cdo:buttoncolor:secondary'] = 'Secondary (Grau)';
$string['cdo:buttoncolor:success'] = 'Success (Grün)';
$string['cdo:buttoncolor:warning'] = 'Warning (Gelb)';
$string['cdo:coursestarttime'] = 'Beginn der Buchungsoption (coursestarttime)';
$string['cdo:semesterstart'] = 'Semesterstart';
$string['certificate'] = 'Zertifikat';
$string['certificatecode'] = 'Zertifikatscode';
$string['certificatecolheader'] = 'Aktuellstes Zertifikat';
$string['certificateexpirationdate'] = 'Ablaufdatum';
$string['certificateheader'] = 'Moodle Zertifikat';
$string['certificateissuedate'] = 'Ausstelldatum';
$string['certificatemodalheader'] = 'Zertifikate von {$a}';
$string['certificateon'] = 'Zertifikatserstellung aktivieren <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['certificateon_desc'] = 'Aktivieren Sie diese Einstellung, wenn sie Zertifikate für den Abschluss von Buchungsoptionen ausstellen möchten.';
$string['certificateurl'] = 'Zeritifikat URL';
$string['certificatewithexpiration'] = 'Ablaufdatum: {$a}';
$string['certificatewithoutexpiration'] = 'Kein Ablaufdatum.';
$string['cfcostcenter'] = "Benutzerdefiniertes Buchungsoptionsfeld für die Kostenstelle";
$string['cfcostcenter_desc'] = "Wenn Sie Kostenstellen verwenden, müssen Sie hier angeben,
in welchem benutzerdefinierten Buchungsoptionsfeld diese gespeichert werden.";
$string['cfgsignin'] = 'Einstellungen für die Unterschriftenliste';
$string['cfgsignin_desc'] = 'Konfiguration der Unterschriftenliste';
$string['changeinfoadded'] = ' wurde hinzugefügt:';
$string['changeinfocfadded'] = 'Ein Feld wurde hinzugefügt:';
$string['changeinfocfchanged'] = 'Ein Feld hat sich geändert:';
$string['changeinfocfdeleted'] = 'Ein Feld wurde gelöscht:';
$string['changeinfochanged'] = '{$a} hat/haben sich geändert';
$string['changeinfodeleted'] = ' wurde gelöscht:';
$string['changeinfosessionadded'] = 'Ein Termin wurde hinzugefügt:';
$string['changeinfosessiondeleted'] = 'Ein Termin wurde gelöscht:';
$string['changenew'] = '[NEU] ';
$string['changeold'] = '[GELÖSCHT] ';
$string['changepresencestatus'] = 'Anwesenheitsstatus ändern';
$string['changes'] = "Änderungen";
$string['changesemester'] = 'Termine für Semester neu erstellen';
$string['changesemester:warning'] = '<strong>Achtung:</strong> Durch Klicken auf "Änderungen speichern" werden alle bisherigen Termine gelöscht und durch die Termine
im ausgewählten Semester ersetzt.';
$string['changesemesteradhoctaskstarted'] = 'Erfolg. Sobald CRON das nächste Mal läuft, werden die Termine neu erstellt. Dies kann einige Minuten dauern.';
$string['changesinentity'] = '{$a->name} (ID: {$a->id})';
$string['checkbox'] = "Checkbox";
$string['checkdelimiter'] = 'Überprüfen Sie die Spaltennamen durch das angegebene Zeichen getrennt sind.';
$string['checkdelimiteroremptycontent'] = 'Überprüfen Sie ob Daten vorhanden und durch das angegebene Zeichen getrennt sind.';
$string['checkedanswersdeleted'] = 'Die ausgewählten Buchungen wurden gelöscht.';
$string['checkoutidentifier'] = "Bestellnummer";
$string['choose...'] = 'Auswählen...';
$string['choosedifferentvalue'] = 'Wählen Sie einen anderen Wert als im oberen Feld';
$string['choosepdftitle'] = 'Wählen Sie einen Titel für die Unterschriftenliste';
$string['chooseperiod'] = 'Zeitraum auswählen';
$string['chooseperiod_help'] = 'Wählen Sie den Zeitraum innerhalb dessen die Terminserie erstellt werden soll.';
$string['choosesemester'] = "Semester auswählen";
$string['choosesemester_help'] = "Wählen Sie das Semester aus, für das der oder die Feiertag(e) erstellt werden sollen.";
$string['choosesession'] = 'Termin (Session) auswählen...';
$string['choosetags'] = 'Wähle Tags';
$string['choosetags_desc'] = 'Kurse, die mit diesen Tags markiert sind, können als Vorlagen verwendet werden. Wird eine Buchungsoption mit so einer Vorlage verknüpft, wird beim ersten Speichern automatisch eine Kopie des Vorlagen-Kurses erstellt.';
$string['circumventavailabilityconditions'] = 'Einschränkungen umgehen';
$string['circumventavailabilityconditions_desc'] = 'Wenn diese Einstellung gesetzt ist, können Einschränkungen von Buchungsoptionen, die das Benutzerprofilfeld betreffen, umgangen werden.
    Wenn Nutzer:innen die "optionview.php" Seite einmalig mit den richtigen Parametern aufrufen, kann die Buchungsoption trotz dieser Einschränkungen für sie buchbar werden.
    Parameter sind <b>cvfield=userfeldkurzname_Gewuenschterwert</b> und optional <b>cvpwd=passwort</b>.
    Die Umgehung der Einschränkung ist buchungsinstanzspezifisch und gilt nur für jene Instanz, bei der als letztes die optionview mit dem "cvfield" aufgerufen wurde.';
$string['circumventpassword'] = 'Passwort um die Einschränkung zu umgehen. Leer bedeutet, kein Passwort nötig.';
$string['close'] = 'Schließen';
$string['closed'] = 'Buchung beendet';
$string['cohort'] = 'Globale Gruppe';
$string['cohorts'] = 'Globale Gruppe(n)';
$string['collapsedescriptionmaxlength'] = 'Beschreibungen einklappen (Zeichenanzahl)';
$string['collapsedescriptionmaxlength_desc'] = 'Geben Sie die maximale Anzahl an Zeichen, die eine Beschreibung haben darf, ein.
Beschreibungen, die länger sind werden eingeklappt.';
$string['collapsedescriptionoff'] = 'Beschreibungen nicht einklappen';
$string['collapseshowsettings'] = "Klappe Terminanzeige bei mehr als x Terminen zu.";
$string['collapseshowsettings_desc'] = "Um auf der Überblicksseite nicht zu viele Termine auf einmal anzuzeigen, kann hier ein Limit definiert werden, ab dem die Anzeige standardmäßig eingeklappt ist.";
$string['comments'] = 'Kommentare';
$string['competencies'] = 'Kompetenzen';
$string['competenciesheader'] = ' <i class="fa fa-line-chart" aria-hidden="true"></i>&nbsp;Kompetenzen';
$string['competencychoose'] = 'Wählen Sie Kompetenzen dieser Buchungsoption';
$string['competencynonefound'] = 'Bisher keine Kompetenzen angelegt';
$string['completed'] = 'Abgeschlossen';
$string['completedcomments'] = 'Nur diejenigen, die Aktivität abgeschlossen haben';
$string['completedratings'] = 'Nur diejenigen, die Aktivität abgeschlossen haben';
$string['completionchanged'] = 'Abschlussänderung';
$string['completionchangedhistory'] = 'Der Abschluss wurde von "{$a->completionold}" zu "{$a->completionnew}" geändert';
$string['completionmodule'] = 'Aktiviere Massenlöschung von getätigten Buchungen basierend auf den Aktivitätsabschluss einer Kursaktivität';
$string['completionmodule_help'] = 'Button zum Löschen aller Buchungen anzeigen, wenn eine andere Kursaktivität abgeschlossen wurde. Die Buchungen von Nutzer:innen werden mit einem Klick auf einen Button auf der Berichtsseite gelöscht! Nur Aktivitäten mit aktiviertem Abschluss können aus der Liste ausgewählt werden.';
$string['completionoptioncompletedcminfo'] = 'Vervollständigen Sie eine Option';
$string['completionoptioncompletedform'] = 'Mindestens eine der gebuchten Optionen muss als abgeschlossen markiert sein. Das kann von Trainer:in, Kursersteller:in oder Manager:in durchgeführt werden.';
$string['conditionselectstudentinbo_desc'] = 'Nutzer:innen der von der Regel betroffenen Buchungsoption wählen.';
$string['conditionselectstudentinboroles'] = 'Rolle wählen';
$string['conditionselectteacherinbo_desc'] = 'Trainer:innen der von der Regel betroffenen Buchungsoption wählen.';
$string['conditionselectuserfromevent_desc'] = 'Nutzer:in, die mit dem Ereignis in Verbindung steht wählen';
$string['conditionselectuserfromeventtype'] = 'Rolle wählen';
$string['conditionselectusershoppingcart_desc'] = "Nutzer:in mit Zahlungsverpflichtung ist ausgewählt";
$string['conditionselectusersuserids'] = "Wähle die gewünschten Nutzer:innen";
$string['conditionsoverwritingbillboard'] = 'Überschreiben von Nachrichten zur Buchbarkeit bzw. deren Blockierung ermöglichen';
$string['conditionsoverwritingbillboard_desc'] = 'In den Einstellungen der Buchungsinstanz kann ein Text eingegeben werden, der anstelle von anderen Nachrichten zur (Nicht-)Buchbarkeit angezeigt wird.';
$string['conditiontextfield'] = 'Wert';
$string['configurefields'] = 'Spalten und Felder anpassen';
$string['confirmationmessagesettings'] = 'Buchungsbestätigungseinstellungen';
$string['confirmationonnotification'] = 'Buchungen für benachrichtigte Personen erlauben';
$string['confirmationonnotificationnoopen'] = 'Nein, für alle BenutzerInnen offen lassen';
$string['confirmationonnotificationoneatatime'] = 'Nur die <b>eine</b>, zuletzt benachrichtigte Person soll buchen können';
$string['confirmationonnotificationwarning'] = '<div class="alert alert-warning" role="alert">Achtung, damit diese Funktion funktioniert, müssen Sie eine entsprechende Regel konfigurieren.</div>';
$string['confirmationonnotificationyesforall'] = 'Ja, für alle benachrichtigten BenutzerInnen';
$string['confirmationonnotificationyesoneatatime'] = 'Ja, Bestätigung jeweils nur für eine/n Benutzer/in';
$string['confirmbooking'] = 'Bestätigen der Buchung';
$string['confirmbookinganswer'] = 'Buchungsantwort bestätigen, wenn die Benachrichtigung für BenutzerInnen aktiviert ist.';
$string['confirmbookinglong'] = 'Wollen Sie diese Buchung wirklich bestätigen?';
$string['confirmbookingoffollowing'] = 'Bitte bestätigen Sie folgende Buchung';
$string['confirmbookingtitle'] = "Buchung bestätigen";
$string['confirmcanceloption'] = "Bestätige die Stornierung der Buchungsoption";
$string['confirmcanceloptiontitle'] = "Ändere den Status der Buchungsoption";
$string['confirmchangesemester'] = 'JA, ich möchte wirklich alle Termine der Buchungsinstanz löschen und neue erstellen.';
$string['confirmdeletebookingoption'] = 'Möchten Sie diese Buchungsmöglichkeit <b>{$a}</b> wirklich löschen?';
$string['confirmed'] = 'Bestätigt';
$string['confirmoptioncompletion'] = 'Abschluss bestätigen / aufheben';
$string['confirmoptioncreation'] = 'Wollen Sie diese Buchungsoption splitten sodass aus jedem Einzeltermin eine eigene
 Buchungsoption erstellt wird?';
$string['confirmrecurringoption'] = 'Diese Änderungen auch für alle abgeleiteten Buchungsoptionen anwenden?';
$string['confirmrecurringoptionapplychanges'] = 'Aktuelle Änderungen übernehmen';
$string['confirmrecurringoptionerror'] = 'Sie können mit jeder dieser Optionen fortfahren.';
$string['confirmrecurringoptionoverwrite'] = 'Alle Felder angleichen';
$string['connectedbooking'] = '[VERALTET] Vorgeschaltete Buchung';
$string['connectedbooking_help'] = 'Buchung von der Teilnehmer:innen übernommen werden. Es kann bestimmt werden wie viele Teilnehmer:innen übernommen werden.';
$string['connectedmoodlecourse'] = 'Verbundener Moodle-Kurs';
$string['connectedmoodlecourse_help'] = 'Wählen Sie "Neuen Kurs erstellen...", wenn Sie wollen, dass ein neuer Moodle-Kurs für diese Buchungsoption angelegt werden soll.';
$string['consumeatonce'] = 'Alle Credits müssen in einer Buchung verbraucht werden';
$string['consumeatonce_help'] = 'Die Nutzer:innen haben nur einen einzigen Buchungsschritt, bei dem alle Wahlfächer gebucht werden müssen.';
$string['contains'] = 'beinhaltet (Text)';
$string['containsinarray'] = 'TeilnehmerIn hat einen dieser Werte zumindest teilweise (Komma getrennt)';
$string['containsnot'] = 'beinhaltet nicht (Text)';
$string['containsnotinarray'] = 'TeilnehmerIn keinen dieser Werte auch nur teilweise (Komma getrennt)';
$string['containsnotplain'] = 'beinhaltet nicht';
$string['containsplain'] = 'beinhaltet';
$string['coolingoffperiod'] = 'Stornierung möglich nach x Sekunden';
$string['coolingoffperiod_desc'] = 'Um zu vermeiden, dass NutzerInnen z.B. irrtümlich durch zu schnelles Klicken auf den Buchen-Button wieder stornieren, kann eine Cooling Off Period in Sekunden eingestellt werden. In dieser Zeit ist Stornieren nicht möglich. Nicht mehr als wenige Sekunden einstellen, die Wartezeit wird den UserInne nicht extra angezeigt.';
$string['copy'] = 'Kopie';
$string['copycircumventlink'] = 'Zugangslink für Außenstehende kopieren';
$string['copymail'] = 'Eine Kopie der Bestätigungsmail an den Buchungsverwalter senden';
$string['copytotemplate'] = 'Buchungsoption als Vorlage speichern';
$string['copytotemplatesucesfull'] = 'Buchungsoption erfolgreich als Vorlage gespeichert';
$string['course'] = 'Moodle-Kurs';
$string['coursecalendarurl'] = "Kurskalenderlink";
$string['coursedate'] = 'Kurstermin';
$string['coursedoesnotexist'] = 'Die Kursnummer {$a} existiert nicht';
$string['courseduplicating'] = 'Diesen Eintrag NICHT ENTFERNEN. Moodle-Kurs wird mit der nächsten Ausführung des CRON-Tasks kopiert.';
$string['courseendtime'] = 'Kursende';
$string['courseid'] = 'Kurs, in den eingeschrieben wird';
$string['courselink'] = "Link zum Kurs in Beziehung mit Buchungsoption";
$string['courselist'] = 'Zeige alle Buchungsoptionen einer Buchungsinstanz';
$string['coursepageshortinfo'] = 'Wenn Sie diesen Kurs buchen wollen, klicken Sie auf "Verfügbare Optionen anzeigen", treffen Sie eine Auswahl und klicken Sie auf "Jetzt buchen".';
$string['coursepageshortinfolbl'] = 'Kurzinfo';
$string['coursepageshortinfolbl_help'] = 'Geben Sie den Kurzinfo-Text ein, der auf der Kursseite angezeigt werden soll.';
$string['courses'] = 'Kurse';
$string['coursesheader'] = 'Moodle-Kurs';
$string['courseshortname'] = 'Kurzname (shortname) des Kurses';
$string['coursestart'] = 'Starten';
$string['coursestarttime'] = 'Kursbeginn';
$string['createcompetencylink'] = '<a href="{$a}" class="btn btn-outline-secondary" target="_blank" rel="noopener noreferrer">
Neue Kompetenz erstellen (in Kompetenzrahmen) </a>';
$string['createdbywunderbyte'] = 'Dieses Buchungsmodul wurde von der Wunderbyte GmbH entwickelt';
$string['createical'] = 'Termin(e) erstellen';
$string['createnewbookingoption'] = 'Neue Buchungsoption';
$string['createnewbookingoptionfromtemplate'] = 'Neue Buchungsoption von Vorlage erstellen';
$string['createnewmoodlecourse'] = 'Erstelle neuen, leeren Moodle-Kurs';
$string['createnewmoodlecoursefromtemplate'] = 'Erstelle neuen Kurs von Template';
$string['createnewmoodlecoursefromtemplate_help'] = 'Vorlagen können nur verwendet werden, wenn sie das in den Einstellugnen definierte Tag haben und wenn die Nutzer:in folgende Rechte auf den Vorlagen-Kurs besitzt:
<br>
Am einfachsten ist es, in den Vorlagen-Kurs als Lehrende eingeschrieben zu sein.
<br>
moodle/course:view
moodle/backup:backupcourse
moodle/restore:restorecourse
moodle/question:add
';
$string['createnewmoodlecoursefromtemplatewithusers'] = 'Übernehme die Nutzer:innen des Vorlagenkurses in den neuen Kurs';
$string['createoptionsfromoptiondate'] = 'Für jeden Einzeltermin eine neue Buchungsoption erstellen';
$string['credits'] = 'Credits';
$string['credits_help'] = 'Wie viele credits werden bei der Buchung dieser Option verbraucht';
$string['creditsmessage'] = 'Noch {$a->creditsleft} von insgesamt {$a->maxcredits} Credits verfügbar.';
$string['csvfile'] = 'CSV Datei';
$string['custombulkmessagesent'] = 'Persönl. Nachricht als Rundmail gesendet (> 75% der TN, mind. 3 TN)';
$string['customdatesbtn'] = '<i class="fa fa-plus-square"></i> Benutzerdefinierte Termine...';
$string['customdownloadreport'] = 'Bericht herunterladen';
$string['customfield'] = 'Benutzerdefiniertes Feld, dessen Wert in den Buchungsoptionseinstellungen angegeben wird und in der Buchungsoptionsübersicht angezeigt wird';
$string['customfieldchanged'] = 'Benutzerdefiniertes Feld geändert';
$string['customfieldconfigure'] = 'Buchung: Benutzerdefinierte Buchungsoptionsfelder';
$string['customfielddef'] = 'Benutzerdefiniertes Buchungsoptionsfeld';
$string['customfielddesc'] = 'Definieren Sie den Wert dieses Feldes in den Buchungsoptionseinstellungen.';
$string['customfieldname'] = 'Feldname';
$string['customfieldname_help'] = 'Sie können einen beliebigen Feldnamen angeben. <br>
                                    Die Spezial-Feldnamen
                                    <ul>
                                        <li>TeamsMeeting</li>
                                        <li>ZoomMeeting</li>
                                        <li>BigBlueButtonMeeting</li>
                                    </ul> zeigen in Kombination mit einem Link im Feld "Wert" einen Button mit dem Link an,
                                    der nur während des Meetings (und kurz davor) sichtbar ist.';
$string['customfields'] = 'Benutzerdefinierte Felder';
$string['customfieldsforfilter'] = 'Benutzerdefinierte Felder, die als Filtermöglichkeit angezeigt werden sollen';
$string['customfieldsplaceholdertext'] = 'Custom user profile fields & custom booking option fields can be referenced using their shortname';
$string['customfieldtype'] = 'Feldtyp';
$string['customfieldvalue'] = 'Wert';
$string['customfieldvalue_help'] = 'Sie können einen beliebigen Wert für das Feld angeben (Text, Zahl oder HTML).<br>
                                    Sollten Sie einen der Spezial-Feldnamen
                                    <ul>
                                        <li>TeamsMeeting</li>
                                        <li>ZoomMeeting</li>
                                        <li>BigBlueButtonMeeting</li>
                                    </ul> verwendet haben, geben Sie den vollständigen Link zum Meeting beginnend mit https:// oder http:// an.';
$string['customform'] = "Customform";
$string['customformnotchecked'] = 'Noch nicht akzeptiert.';
$string['customformparams_desc'] = "Benutze die Parameter aus der customform.";
$string['customformparamsvalue'] = "Customform Parameter";
$string['customformselectoptions'] = '<div class="alert alert-info" role="alert">
    <i class="fa fa-info-circle"></i>
    <span><b>Werte für Auswahl können folgendermaßen angeben werden:</b> <br>
    key => Anzeigename <br>
    Details und weitere optionale Werte: <br>
    key (<i>Sollte keine Abstände oder Sonderzeichen enthalten</i>) => <br>
    Anzeigename (<i>Wird den NutzerInnen angezeigt</i>) => <br>
    Maximalanzahl der Buchungen (<i>Gesamtverfügbarkeit für alle NutzerInnen gemeinsam, wird NutzerInnen angezeigt</i>) => <br>
    Preis (<i>Kann mit dem definierten Preiskategoriefeld modifiziert werden, wird NutzerInnen angezeigt</i>) => <br>
    Erlaubte NutzerInnen (<i>Userids von jeden Personen, denen diese Option zur Verfügung steht</i>) <br>
    <b>Beispiel:</b> <br>
    choose => Auswählen... <br>
    singleroom => Einzelzimmer => 10 => 100 => 1,2,3,4,5 <br>
    doubleroom => Doppelzimmer => 5 => student:100,expert:200,default:150 => 1,2,3,4,5
    </span>
    </div>';
$string['customlabelsdeprecated'] = '[VERALTET] Benutzerdefinierte Bezeichnungen';
$string['custommessagesent'] = 'Persönliche Nachricht gesendet';
$string['customprofilefield'] = 'Custom profile field to check';
$string['customprofilefieldvalue'] = 'Custom profile field value to check';
$string['customreporttemplate'] = 'Vorlage für benutzerdefinierten Bericht';
$string['customreporttemplates'] = 'Vorlagen für benutzerdefinierte Berichte';
$string['customuserprofilefield'] = "Benutzerdefiniertes User Profilfeld";
$string['customuserprofilefield_help'] = "Wenn Sie ein Benutzerdefiniertes User Profilfeld auswählen, ist der Preis-Teil der Kampagne nur für NutzerInnen wirksam, die auch einen bestimmten Wert in einem bestimmten Profilfeld haben.";
$string['dashboard_auswertung'] = 'Auswertung';
$string['dashboard_bookingfields'] = 'Buchungsoptionsfelder';
$string['dashboard_managelocation'] = 'Standorte verwalten';
$string['dashboard_mydashboard'] = 'Mein Dashboard';
$string['dashboard_neue_stornos'] = 'Neue Stornos';
$string['dashboard_new_bookings'] = 'Buchungen';
$string['dashboard_noshows'] = 'Nicht erschienen';
$string['dashboard_organisation'] = 'Organisation';
$string['dashboard_overview'] = 'Übersicht';
$string['dashboard_ppl_wl'] = 'Personen in Wartelisten';
$string['dashboard_stats'] = 'Auswertung';
$string['dashboard_zeitraum'] = 'Zeitraum';
$string['dashboardsummary'] = 'Allgemein';
$string['dashboardsummary_desc'] = 'Enthält Konfiguration und Einstellungen für die gesamte Moodle Seite.';
$string['dataincomplete'] = 'Der Datensatz mit "componentid" {$a->id} ist unvollständig und konnte nicht gänzlich eingefügt werden. Überprüfen Sie das Feld "{$a->field}".';
$string['dateandtime'] = 'Datum und Uhrzeit';
$string['dateerror'] = 'Falsche Datumsangabe in Zeile {$a}: ';
$string['datenotset'] = 'Datum nicht angegeben';
$string['dateparseformat'] = 'Datumsformat';
$string['dateparseformat_help'] = 'Bitte Datum so wie es im CSV definiert wurde verwenden. Hilfe unter <a href="http://php.net/manual/en/function.date.php">Datumsdokumentation</a> für diese Einstellung.';
$string['dates'] = 'Termine';
$string['datesandentities'] = 'Termine mit Orten';
$string['datesheader'] = 'Termine';
$string['dayofweek'] = 'Wochentag';
$string['dayofweektime'] = 'Tag & Uhrzeit';
$string['days'] = '{$a} Tage';
$string['daysafter'] = '{$a} Tag(e) danach';
$string['daysbefore'] = '{$a} Tag(e) davor';
$string['daystonotify'] = 'Wie viele Tage vor Kursbeginn soll an die Teilnehmenden eine Benachrichtigung gesendet werden?';
$string['daystonotify2'] = 'Zweite Teilnehmerbenachrichtigung vor Veranstaltungsbeginn';
$string['daystonotify_help'] = "Funktioniert nur, wenn ein Beginn- und Enddatum für die Buchungsoption gesetzt sind. Wenn Sie 0 eingeben, wird die Benachrichtigung deaktiviert.";
$string['daystonotifysession'] = 'Benachrichtigung n Tage vor Beginn';
$string['daystonotifysession_help'] = "Wie viele Tage vor Beginn dieser Session soll an die Teilnehmenden eine Benachrichtigung gesendet werden?
Geben Sie 0 ein, um die E-Mail-Benachrichtigung für diese Session zu deaktivieren.";
$string['daystonotifysessionrulenooverride'] = 'Anzahl Tage nicht überschreiben (Regel normal anwenden)';
$string['daystonotifysessionruleoverride'] = 'Anzahl Tage vor Beginn';
$string['daystonotifysessionruleoverride_help'] = 'Hier können Sie die Anzahl der Tage aus der (oder den) Buchungsregel(n) für diesen einen Termin überschreiben.';
$string['daystonotifyteachers'] = 'Wie viele Tage vor Kursbeginn soll an die Trainer:innen eine Benachrichtigung gesendet werden?';
$string['deduction'] = 'Abzug';
$string['deductionnotpossible'] = 'Da alle Trainer:innen bei diesem Termin anwesend waren kann kein Abzug eingetragen werden.';
$string['deductionreason'] = 'Grund für den Abzug';
$string['defaultbookingoption'] = 'Standardeinstellungen für Buchungsoptionen';
$string['defaultdateformat'] = 'j.n.Y H:i:s';
$string['defaultoptionsort'] = 'Standardsortierung nach Spalte';
$string['defaultpricecategoryinfoalert'] = 'Die erste Preiskategorie hat immer den Identifier "default" und kann nicht deaktiviert werden.';
$string['defaultpricecategoryname'] = 'Standardpreiskategorie (Name)';
$string['defaultpriceformula'] = "Preisformel";
$string['defaultpriceformuladesc'] = "Das JSON Objekt erlaubt die Konfiguation der automatischen Preisberechnung.";
$string['defaulttemplate'] = 'Standard-Vorlage';
$string['defaulttemplatedesc'] = 'Standard-Vorlage für neue Buchungsoptionen';
$string['defaultvalue'] = 'Standardpreis';
$string['defaultvalue_help'] = 'Geben Sie einen Standardpreis für jeden Preis in dieser Kategorie ein. Natürlich kann dieser Wert später überschrieben werden.';
$string['definecmidforshortcode'] = "Um diesen Shortcode verwenden zu können, muss die cmid einer Booking instanz folgendermaßen zum shortcode hinzugefügt werden: [courselist cmid=23]";
$string['definedresponsiblecontactrole'] = 'Rolle für verantwortliche Kontaktperson einer Buchungsoption festlegen';
$string['definedresponsiblecontactrole_desc'] = 'Wird eine verantwortliche Kontaktperson zu einer Buchungsoption hinzugefügt, erhält sie im zugehörigen verbundenen Moodle-Kurs die ausgewählte Rolle.';
$string['definedteacherrole'] = 'Rolle für Trainer:innen einer Buchungsoption festlegen';
$string['definedteacherrole_desc'] = 'Wird ein:e Trainer:in einer Buchungsoption hinzugefügt, erhält sie im zugehörigen Kurs die ausgewählte Rolle.';
$string['definefieldofstudy'] = 'Sie können hier alle Buchungsoptionen aus dem gesamten Studienbereich anzeigen lassen. Damit dies funktioniert,
 verwenden Sie Gruppen mit dem Namen Ihres Studiengangs. Bei einem Kurs, der in "Psychologie" und "Philosophie" verwendet wird,
 haben Sie zwei Gruppen, die nach diesen Studiengängen benannt sind. Folgen Sie diesem Schema für alle Ihre Kurse.
 Fügen Sie nun das benutzerdefinierte Buchungsoptionsfeld mit dem Shortname "recommendedin" hinzu, in das Sie die kommagetrennten
 Shortcodes derjenigen Kurse, in denen eine Buchungsoption empfohlen werden soll, eintragen. Wenn ein:e Benutzer:in Teil der
 Gruppe "Philosophie" ist, werden ihm:ihr alle Buchungsoptionen aus Kursen angezeigt, in denen mindestens einer der "Philosophie"-Kurse empfohlen wird.';
$string['delcustfield'] = 'Dieses Feld und alle dazugehörenden Einstellungen in den Buchungsoptionen löschen';
$string['delete'] = 'Löschen';
$string['deleteallchildren'] = 'Alle folgenden Buchungsoptionen löschen';
$string['deletebooking'] = 'Buchung löschen';
$string['deletebookingaction'] = 'Diese Aktion nach der Buchung löschen';
$string['deletebookingcampaign'] = 'Kampagne löschen';
$string['deletebookingcampaignconfirmtext'] = 'Wollen Sie die folgende Kampagne wirklich löschen?';
$string['deletebookinglong'] = 'Wollen Sie diese Buchung wirklich löschen?';
$string['deletebookingrule'] = 'Regel löschen';
$string['deletebookingruleconfirmtext'] = 'Wollen Sie die folgende Regel wirklich löschen?';
$string['deletecategory'] = 'Löschen';
$string['deletecheckedanswersbody'] = 'Wollen Sie die ausgewählten Buchungen wirklich löschen?';
$string['deleteconditionsfrombookinganswer'] = 'Userdaten aus Buchungsformular löschen';
$string['deletecustomfield'] = 'Feld löschen?';
$string['deletecustomfield_help'] = 'Achtung: Wenn Sie diese Checkbox aktivieren, wird das zugehörige Feld beim Speichern gelöscht!';
$string['deleted'] = 'Gelöscht';
$string['deletedatafrombookinganswer'] = 'Userdaten aus Buchungsformular löschen';
$string['deletedatafrombookingansweradhoc'] = 'Booking: Userdaten von Buchungsoption löschen (adhoc task)';
$string['deletedbookings'] = 'Gelöschte Buchungen';
$string['deletedbookingusermessage'] = 'Guten Tag {$a->participant},
Die Buchung für {$a->title} wurde erfolgreich storniert
';
$string['deletedbookingusersubject'] = 'Stornobestätigung für {$a->title}';
$string['deletedrule'] = 'Buchungsoption erfolgreich gelöscht';
$string['deletedtext'] = 'Stornierungsbenachrichtigung (0 eingeben zum Ausschalten)';
$string['deletedtextmessage'] = 'Folgende Buchung wurde storniert: {$a->title}
Nutzer/in: {$a->participant}
Titel: {$a->title}
Datum: {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
Kurs: {$a->courselink}
Link: {$a->bookinglink}
';
$string['deletedtextsubject'] = 'Storno von {$a->title}, User: {$a->participant}';
$string['deletedusers'] = 'Gelöschte Nutzer:innen';
$string['deleteholiday'] = 'Eintrag löschen';
$string['deleteinfoscheckboxadmin'] = 'Die vom User angegebenen Daten löschen, nachdem die Option beendet wurde.';
$string['deleteinfoscheckboxadminwarning'] = '<div class="alert alert-warning style="margin-left: 200px;">
<i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
<span>Für die Ausführung muss eine entsprechende <a target="_blank" href="{$a}">Buchungsregel (Booking Rule)</a> aktiviert werden</span></div>';
$string['deleteoptiondate'] = 'Termin entfernen';
$string['deleterule'] = 'Löschen';
$string['deletesemester'] = 'Semester löschen';
$string['deletesubcategory'] = 'Löschen Sie zuerst alle Unterkategorien dieser Kategorie!';
$string['deletethisbookingoption'] = 'Diese Buchungsoption löschen';
$string['deleteuserfrombooking'] = 'Buchung für Nutzer:innen wirklich stornieren?';
$string['department'] = 'Abteilung';
$string['description'] = 'Beschreibung';
$string['descriptionmaxlength'] = 'Maximale Länge der Beschreibung';
$string['descriptionmaxlength_desc'] = 'Die Beschreibung einer Buchungsoption kann nicht länger sein';
$string['details'] = 'Details';
$string['disablebookingforinstance'] = 'Keine Option dieser Buchungsinstanz soll buchbar sein';
$string['disablebookingusers'] = 'Buchung von Teilnehmer:innen deaktivieren - "Jetzt buchen" Button unsichtbar schalten';
$string['disablecancel'] = "Stornieren dieser Buchungsoption nicht möglich";
$string['disablecancelforinstance'] = "Stornieren für die gesamte Instanz deaktivieren.
(Wenn Sie diese Einstellung aktivieren können Buchungsoptionen, die sich in dieser Instanz befinden, nicht storniert werden.)";
$string['disablepricecategory'] = 'Deaktiviere Preiskategorie';
$string['disablepricecategory_help'] = 'Wenn Sie eine Preiskategorie deaktivieren, kann diese nicht mehr benützt werden.';
$string['displayemptyprice'] = 'Preis anzeigen wenn dieser 0 ist';
$string['displayemptyprice_desc'] = 'Wenn eine Buchungsoption Preise für einige Preiskategorien hat und für andere nicht, können Sie entscheiden, ob NutzerInnen, für die die Option kostenlos ist, den Preis 0 angezeigt bekommen oder ob der Preis komplett ausgeblendet wird.';
$string['displayinfoaboutrules'] = 'Warnung anzeigen, dass Regeln aktiviert werden müssen?';
$string['displayloginbuttonforbookingoptions'] = 'Zeige in Buchungsoption Button an, der zur Loginseite führt';
$string['displayloginbuttonforbookingoptions_desc'] = 'Wird nur für nicht eingeloggte Benutzer angezeigt';
$string['displayshoppingcarthistory'] = 'Warenkorb Transaktionen bei "Meine Buchungen" anzeigen';
$string['displayshoppingcarthistory_desc'] = 'Sollen die vergangenen Transaktionen, Buchungsbesätigungen etc. wie im Warenkorb-Shortcode [shoppingcarthistory] auf der Seite "Meine Buchungen" (mybookings.php) angezeigt werden?';
$string['displaytext'] = "Text anzeigen";
$string['dontaddpersonalevents'] = 'Keine Einträge im persönlichen Kalender erstellen.';
$string['dontaddpersonaleventsdesc'] = 'Für jede Buchung und alle Termine werden eigene Einträge im persönlichen Kalender der TeilnehmerInnen erstellt. Für eine bessere Performance auf sehr intensiv genutzten Seiten kann diese Funktion deaktiviert werden.';
$string['dontapply'] = 'Nicht anwenden';
$string['dontmove'] = 'Nicht bewegen';
$string['dontusetemplate'] = 'Vorlage nicht verwenden';
$string['download'] = 'Download';
$string['downloadallresponses'] = 'Alle Buchungen herunterladen';
$string['downloaddemofile'] = 'Demofile herunterladen';
$string['downloadusersforthisoptionods'] = 'Nutzer:innen im .ods-Format herunterladen';
$string['downloadusersforthisoptionxls'] = 'Nutzer:innen im  .xls-Format herunterladen';
$string['doyouwanttobook'] = 'Wollen Sie <b>{$a}</b> buchen?';
$string['duedate'] = 'Fälligkeitsdatum';
$string['duplicatebookingoption'] = 'Diese Buchungsoption duplizieren';
$string['duplicatemoodlecourses'] = 'Moodle-Kurs duplizieren';
$string['duplicatemoodlecourses_desc'] = 'Wenn diese Einstellung aktiviert ist, dann wird beim Duplizieren einer Buchungsoption
auch der verbundene Moodle-Kurs dupliziert (Achtung: Nutzer:innen-Daten des Moodle-Kurses werden nicht mit-dupliziert!).
Da das Duplizieren asynchron über einen Adhoc-Task gemacht wird, stellen Sie bitte sicher, dass der CRON-Task regelmäßig läuft.';
$string['duplicatename'] = 'Diese Bezeichnung für eine Buchungsoption existiert bereits. Bitte wählen Sie eine andere.';
$string['duplication'] = 'Duplizierung';
$string['duplicationrestore'] = 'Buchungsinstanzen: Duplizieren, Backup und Wiederherstellen';
$string['duplicationrestorebookings'] = 'Buchungsoptionen inkludieren <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['duplicationrestoredesc'] = 'Hier können Sie einstellen, welche Informationen beim Duplizieren bzw. beim Backup / Wiederherstellen von Buchungsinstanzen inkludiert werden sollen.';
$string['duplicationrestoreentities'] = 'Entities inkludieren';
$string['duplicationrestoreoption'] = 'Buchungsoptionen: Duplizieren <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['duplicationrestoreoption_desc'] = 'Spezielle Einstellungen für das Duplizieren von Buchungsoptionen.';
$string['duplicationrestoreprices'] = 'Preise inkludieren';
$string['duplicationrestoresubbookings'] = 'Zusatzbuchungen inkludieren <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['duplicationrestoreteachers'] = 'Trainer:innen inkludieren';
$string['duration'] = "Dauer";
$string['duration:minutes'] = 'Dauer (Minuten)';
$string['duration:units'] = 'Einheiten ({$a} min)';
$string['durationforcertificate'] = '{$a->hours} Stunde(n) {$a->minutes} Minuten';
$string['easyavailabilitypreviouslybooked'] = 'Einfache bereits gebuchte Voraussetzung';
$string['easyavailabilityselectusers'] = 'Einfache NutzerInnen Voraussetzung';
$string['easybookingclosingtime'] = 'Einfache Buchungsendzeit';
$string['easybookingopeningtime'] = 'Einfache Buchungsstartzeit';
$string['easytext'] = 'Einfacher, nicht veränderbarer Text';
$string['editaction'] = "Editiere Action";
$string['editbookingoption'] = 'Buchungsoption bearbeiten';
$string['editbookingoptions'] = 'Buchungsoptionen bearbeiten';
$string['editcampaign'] = 'Kampagne bearbeiten';
$string['editcategory'] = 'Bearbeiten';
$string['editingoptiondate'] = 'Sie bearbeiten gerade diesen Termin';
$string['editinstitutions'] = 'Institutionen bearbeiten';
$string['editotherbooking'] = 'Andere Buchungsoptionen';
$string['editrule'] = "Bearbeiten";
$string['editsubbooking'] = 'Bearbeite Zusatzbuchung';
$string['edittag'] = 'Bearbeiten';
$string['editteacherslink'] = 'Lehrer:innen bearbeiten';
$string['educationalunitinminutes'] = 'Länge einer Unterrichtseinheit (Minuten)';
$string['educationalunitinminutes_desc'] = 'Hier können Sie die Länge einer Unterrichtseinheit in Minuten angeben. Diese wird zur Berechnung der geleisteten UEs herangezogen.';
$string['elective'] = "Wahlfach";
$string['electivedeselectbtn'] = 'Wahlfach abwählen';
$string['electivenotbookable'] = 'Nicht buchbar';
$string['electivesbookedsuccess'] = 'Ihre ausgewählten Wahlfächer wurden erfolgreich gebucht.';
$string['electivesettings'] = 'Wahlfach Einstellungen';
$string['email'] = "Email";
$string['emailbody'] = 'E-Mail Text';
$string['emailsettings'] = 'E-Mail-Einstellungen <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['enable'] = 'Enable';
$string['enablecompletion'] = 'Es muss mindestens eine der Buchungen als abgeschlossen markiert werden.';
$string['enablecompletion_help'] = 'Um die Optionen als abgeschlossen zu markieren, fügen Sie unter dem Punkt "Spalten und Felder anpassen" bei "Buchungen verwalten" das Feld "Abgeschlossen" hinzu. Danach können die Optionen auf der Berichtsseite als abgeschlossen markiert werden.';
$string['enablecompletiongroup'] = 'Aktivitätsabschluss';
$string['enddate'] = "Enddatum";
$string['endtime'] = "Endzeit";
$string['endtimenotset'] = 'Kursende nicht festgelegt';
$string['enforceorder'] = 'Erzwinge Reihenfolge';
$string['enforceorder_help'] = 'Nutzer:innen werden erst nach Abschluss des vorangegangene Kurses in den nächsten Kurs eingeschrieben.';
$string['enrolementstatus'] = 'Modus der Kurseinschreibung';
$string['enrolledcomments'] = 'Nur Eingeschriebene können kommentieren';
$string['enrolledratings'] = 'Nur Eingeschriebene können bewerten';
$string['enrolledusers'] = 'In den Kurs eingeschriebene Nutzer:innen';
$string['enrollink'] = 'Link zur Einschreibung';
$string['enrollink:0'] = 'Beim Einschreiben ist ein Fehler passiert';
$string['enrollink:1'] = 'Sie sind bereits in diesen Kurs eingeschrieben und können darauf zugreifen';
$string['enrollink:2'] = 'Sie sind erfolgreich eingeschrieben';
$string['enrollink:3'] = 'Ihr Einschreibelink ist leider fehlerhaft';
$string['enrollink:4'] = 'Es sind keine freien Plätze mehr in Ihrem Kontingent verfügbar';
$string['enrollink:5'] = 'Keine Gastnutzer erlaubt';
$string['enrollink:6'] = 'Ihre Anmeldung ist erfolgt und muss noch von einer berechtigten Person bestätigt werden.';
$string['enrollinktriggered'] = 'Einschreibe-Link Generierung ausgelöst';
$string['enrollinktriggered:description'] = 'Das Event als Grundlage für die automatische Generierung eines Einschreibe-Links wurde ausgelöst.';
$string['enrolmentstatus'] = 'Nutzer:innen erst zu Kursbeginn in den Kurs einschreiben (Standard: Nicht angehakt &rarr; sofort einschreiben.)';
$string['enrolmentstatus_help'] = 'Achtung: Damit die automatische Einschreibung funktioniert,
 müssen Sie in den Einstellungen der Buchungsinstanz "Nutzer:innen automatisch einschreiben" auf "Ja" setzen.';
$string['enrolmultipleusers'] = 'Mehrere Nutzer:innen einschreiben';
$string['enrolusersaction:alert'] = '<div class="alert alert-info" role="alert">
<i class="fa fa-info-circle"></i>
<span><b>Diese Funktion bezieht sich auf den ausgewählten Kurs im Bereich Moodle Kurse.</b> <br>
</span>
</div>';
$string['enroluserstowaitinglist'] = "Buchende NutzerInnen auf die Warteliste setzen und erst nach Bestätigung einschreiben?";
$string['enroluserwhobookedtocourse'] = "Möchten Sie diese Option selbst auch absolvieren?";
$string['enroluserwhobookedtocoursewarning'] = "Wenn Sie nur einen Platz kaufen und selbst eingeschrieben werden, wird kein Einschreibelink generiert.";
$string['enteruserprofilefield'] = "Wähle Nutzer:innen nach eingegebenem Wert für Profilfeld. Achtung! Das betrifft ALLE Nutzer:inne nauf der Plattform.";
$string['entervalidurl'] = 'Bitte geben Sie eine gültige URL an!';
$string['entities'] = 'Orte mit Entities Plugin auswählen';
$string['entitiesfieldname'] = 'Ort(e)';
$string['entitydeleted'] = 'Ort wurde gelöscht';
$string['equals'] = 'hat genau diesen Wert (Text oder Zahl)';
$string['equalsnot'] = 'hat nicht genau diesen Wert (Text oder Zahl)';
$string['equalsnotplain'] = 'hat nicht genau diesen Wert';
$string['equalsplain'] = 'hat genau diesen Wert';
$string['error:bookingstrackernotactivated'] = 'Sie dürfen diese Seite nicht öffnen.
Entweder ist die Einstellung für den Buchungstracker (bookingstracker) nicht aktiviert
oder Sie haben keine Booking PRO-Lizenz (oder Ihre Booking PRO-Lizenz ist abgelaufen).';
$string['error:campaignend'] = 'Kampagnenende muss nach dem Kampagnenbeginn sein.';
$string['error:campaignstart'] = 'Kampagnenbeginn muss vor dem Kampagnenende liegen.';
$string['error:chooseint'] = 'Sie müssen hier eine ganze Zahl eingeben';
$string['error:choosevalue'] = 'Sie müssen hier einen Wert auswählen.';
$string['error:confirmthatyouaresure'] = 'Bitte bestätigen Sie, dass Sie wissen, was Sie tun.';
$string['error:coursecategoryvaluemissing'] = 'Sie müssen hier einen Wert auswählen, da dieser als Kurskategorie für den
automatisch erstellten Moodle-Kurs benötigt wird.';
$string['error:deactivatelegacymailtemplates'] = 'Um diese Funktion zu verwenden, müssen Sie die <a href="{$a}" target="_blank">alten E-Mail-Vorlagen deaktivieren</a>.';
$string['error:entervalue'] = 'Sie müssen hier einen Wert eingeben.';
$string['error:failedtosendconfirmation'] = 'Folgender User hat kein Bestätigungsmail erhalten
Die Buchung wurde erfolgreich durchgeführt, das Senden des Bestätigungsmails ist aber fehlgeschlagen.
Buchungsstatus: {$a->status}
User:   {$a->participant}
Gebuchte Buchungsoption: {$a->title}
Kurstermin: {$a->date}
Link: {$a->bookinglink}
';
$string['error:formcapabilitymissing'] = 'Ihnen fehlt die Berechtigung, um dieses Formular zu bearbeiten. Bitte wenden Sie sich an einen Administrator.';
$string['error:identifierexists'] = 'Wählen Sie einen anderen Identifikator. Dieser existiert bereits.';
$string['error:invalidcmid'] = 'Der Bericht kann nicht geöffnet werden, weil keine gültige Kursmodul-ID (cmid) übergeben wurde. Die cmid muss auf eine Buchungsinstanz verweisen!';
$string['error:limitfactornotbetween1and2'] = 'Sie müssen einen Wert zwischen 0 und 2 eingeben. Um das Buchungslimit z.B. um 20% zu erhöhen,
 geben Sie den Wert 1,2 ein.';
$string['error:missingblockinglabel'] = 'Geben Sie die Nachricht ein, die angezeigt werden soll, wenn Buchungen blockiert werden.';
$string['error:missingcapability'] = 'Erforderliche Berechtigung fehlt. Bitte wenden Sie sich an einen Administrator.';
$string['error:missingteacherid'] = 'Fehler: Report kann nicht geladen werden, da die teacherid fehlt.';
$string['error:mustnotbeempty'] = 'Darf nicht leer sein.';
$string['error:negativevaluenotallowed'] = 'Bitte einen positiven Wert eingeben.';
$string['error:newcoursecategorycfieldmissing'] = 'Sie müssen zuerst ein <a href="{$a->bookingcustomfieldsurl}"
 target="_blank">benutzerdefiniertes Buchungsoptionsfeld</a> erstellen, das für die Kurskategorien für automatisch
 erstellte Kurse verwendet wird. Stellen Sie sicher, dass Sie dieses Feld
 auch in den <a href="{$a->settingsurl}" target="_blank">Plugin-Einstellungen des Buchungsmoduls</a> ausgewählt haben.';
$string['error:noendtagfound'] = 'Beenden Sie den begonnenen Placeholder-Abschnitt "{$a}" durch einen Backslash ("/").';
$string['error:nofieldchosen'] = 'Sie müssen ein Feld auswählen.';
$string['error:percentageavailableplaces'] = 'Geben Sie einen gültigen Prozentsatz zwischen 0 und 100 an (ohne %-Zeichen!).';
$string['error:pricefactornotbetween0and1'] = 'Sie müssen einen Wert zwischen 0 und 1 eingeben. Um die Preise z.B. um 10% zu reduzieren,
 geben Sie den Wert 0,9 ein.';
$string['error:pricemissing'] = 'Bitte geben Sie einen Preis ein.';
$string['error:reasonfordeduction'] = 'Geben Sie einen Grund für den Abzug an.';
$string['error:reasonfornoteacher'] = 'Geben Sie einen Grund an, warum an diesem Termin kein/e Trainer:in anwesend war.';
$string['error:reasonforsubstituteteacher'] = 'Geben Sie einen Grund für die Vertretung an.';
$string['error:reasontoolong'] = 'Grund ist zu lange, geben Sie einen kürzeren Text ein.';
$string['error:ruleactionsendcopynotpossible'] = 'Für das gewählte Ereignis kann leider keine E-Mail-Kopie versendet werden.';
$string['error:selflearningcourseallowsnodates'] = 'Buchungsoptionen vom Typ "{$a}" dürfen keine Termine haben. Bitte löschen Sie alle Termine bevor Sie speichern.';
$string['error:semestermissingbutcanceldependentonsemester'] = 'Die Einstellung zur Berechnung der
Stornierungsfrist ab Semesterbeginn ist aktiv, aber das Semester fehlt!';
$string['error:taskalreadystarted'] = 'Sie haben bereits einen Task gestartet!';
$string['error:wrongteacherid'] = 'Fehler: Für die angegebene "teacherid" wurde kein:e Nutzer:in gefunden.';
$string['errorduplicatepricecategoryidentifier'] = 'Identifikatoren von Preiskategorien müssen eindeutig sein.';
$string['errorduplicatepricecategoryname'] = 'Namen von Preiskategorien müssen eindeutig sein.';
$string['errorduplicatepricecatsortorder'] = 'Sortierreihenfolge muss eindeutig sein.';
$string['errorduplicatesemesteridentifier'] = 'Der Semesteridentifikator muss eindeutig sein.';
$string['errorduplicatesemestername'] = 'Der Name des Semesters muss eindeutig sein.';
$string['erroremptycustomfieldname'] = 'Name des Felds darf nicht leer sein.';
$string['erroremptycustomfieldvalue'] = 'Wert des Felds darf nicht leer sein.';
$string['erroremptypricecategoryidentifier'] = 'Identifikator der Preiskategorie darf nicht leer sein.';
$string['erroremptypricecategoryname'] = 'Name der Preiskategorie darf nicht leer sein.';
$string['erroremptysemesteridentifier'] = 'Identifikator des Semesters fehlt.';
$string['erroremptysemestername'] = 'Name des Semesters wurde nicht angegeben.';
$string['errorholidayend'] = 'Ferienende darf nicht vor dem Ferienbeginn liegen.';
$string['errorholidaystart'] = 'Ferienbeginn darf nicht nach dem Ferienende liegen.';
$string['errormultibooking'] = 'Beim Buchen der Wahlfächer ist ein Fehler aufgetreten.';
$string['erroroptiondateend'] = 'Terminende muss nach dem Terminbeginn liegen.';
$string['erroroptiondatestart'] = 'Terminbeginn muss vor dem Terminende liegen.';
$string['errorpagination'] = 'Geben Sie ein Zahl ein, die größer als 0 ist';
$string['errorpricecategoryidentifierdefaultnotallowed'] = 'Der Identifikator "default" ist für die erste Preiskategorie reserviert.';
$string['errorpricecategoryidentifiermustbedefault'] = 'Der Identifikator "default" muss für die erste Preiskategorie verwendet werden.';
$string['errorsemesterend'] = 'Semesterende muss nach dem Semesterstart sein.';
$string['errorsemesterstart'] = 'Semesterstart muss vor dem Semesterende sein.';
$string['errortoomanydecimals'] = 'Sie können maximal 2 Nachkommastellen angeben.';
$string['errorusernotfound'] = 'Fehler: Der Veranstalter mit der ID "{$a}" wurde nicht gefunden.';
$string['eventalreadyover'] = 'Diese Veranstaltung ist bereits vorüber.';
$string['eventdesc:bookinganswercancelled'] = 'Nutzer:in "{$a->user}" hat Nutzer:in "{$a->relateduser}" aus "{$a->title}" storniert.';
$string['eventdesc:bookinganswercancelledself'] = 'Nutzer:in "{$a->user}" hat "{$a->title}" storniert.';
$string['eventdesc:bookinganswercustomformconditionsdeleted'] = 'Nutzer:in "{$a->user}" hat die Daten zu Customform Bedingungen von {$a->relateduser} der Buchungsantwort mit ID "{$a->bookinganswerid}" gelöscht.';
$string['eventdesc:bookinganswerupdated'] = 'Nutzer:in "{$a->user}" hat bei "{$a->title}" Werte der Spalte "{$a->column}" geändert.';
$string['eventdescription'] = "Beschreibung des Events";
$string['eventduration'] = 'Dauer';
$string['eventpoints'] = 'Punkte';
$string['eventreportviewed'] = 'Report angesehen';
$string['eventslist'] = 'Letzte Bearbeitungen';
$string['eventteacheradded'] = 'Trainer:in hinzugefügt';
$string['eventteacherremoved'] = 'Trainer:in entfernt';
$string['eventtype'] = 'Art des Ereignisses';
$string['eventtype_help'] = 'Sie können den Namen der Ereignisart manuell eingeben oder aus einer Liste von
                            früheren Ereignisarten auswählen. Sie können nur eine Ereignisart angeben. Sobald
                            Sie speichern, wird die Ereignisart zur Liste hinzugefügt.';
$string['eventuserprofilefieldsupdated'] = 'Nutzerprofil aktualisiert';
$string['excelfile'] = 'CSV Datei mit Aktivitätsabschluss';
$string['executerestscript'] = 'REST script ausführen';
$string['existingsubscribers'] = 'Vorhandene Nutzer:innen';
$string['expired'] = 'Diese Aktivität wurde leider am {$a} beendet und steht nicht mehr zur Verfügung';
$string['extendlimitforoverbooked'] = 'Überbuchte Personen zusätzlich zu Faktor addieren';
$string['extendlimitforoverbooked_help'] = 'Wählen Sie diese Option, passiert folgendes:
    Ein Kurs hat ein Limit von 40. Er ist aber bereits mit 2 TN auf 42 TN überbucht.
    Wird auf diesen Kurs eine Limiterhöhung um beispielsweise 10% angewandt, wird das Limit auf 46 erhöht (40 + 4 (10%) + 2 (bereits überbuchte)), statt auf 44 (40+4).';
$string['fallbackonlywhenempty'] = 'Fallback nur, wenn entsprechendes Nutzerprofilfeld leer ist';
$string['fallbackonlywhennotmatching'] = 'Fallback nur, wenn nicht übereinstimmend (auch wenn Feld leer ist)';
$string['fallbackturnedoff'] = 'Fallback deaktiviert';
$string['feedbackurl'] = 'Link zur Umfrage';
$string['feedbackurl_help'] = 'Link zu einem Feedback-Formular, das an Teilnehmer:innen gesendet werden soll.
 Verwenden Sie in E-Mails den Platzhalter <b>{pollurl}</b>.';
$string['feedbackurlteachers'] = 'Trainer:innen Umfragelink';
$string['feedbackurlteachers_help'] = 'Link zu einem Feedback-Formular, das an Trainer:innen gesendet werden soll.
Verwenden Sie in E-Mails den Platzhalter <b>{pollurlteachers}</b>.';
$string['fieldnamesdontmatch'] = 'Die importierten Spaltennamen entsprechen nicht der Vorgabe.';
$string['fieldofstudycohortoptions'] = "Shortcode um alle Buchungsoptionen eines Studiengangs anzuzeigen.
 Wird dadurch definiert, dass die NutzerInnen in allen Kursen in die Gruppe mit dem gleichen Namen
 eingeschrieben sind. Buchungsoptionen werden über das 'recommendedin' customfield zugeordnet.";
$string['fieldofstudyoptions'] = "Shortcode um alle Buchungsoptionen eines Studiengangs anzuzeigen.
 Ein Studientgang wird über die gemeinsame Einschreibung über eine globale Gruppe definiert.
 Außerdem muss in der angezeigten Buchungsoption in der Buchungsvoraussetzung einer der betroffenen
 Kurse ausgewählt sein.";
$string['fillinatleastoneoption'] = 'Geben Sie mindestens 2 mögliche Buchungen an.';
$string['filterbtn'] = 'Filtern';
$string['filterenddate'] = 'Bis';
$string['filterstartdate'] = 'Von';
$string['firstname'] = "Vorname";
$string['firstnamerelated'] = "Vorname betroffene Person";
$string['forcourse'] = 'für Kurs';
$string['format'] = 'Format';
$string['formconfig'] = 'Anzeige, welches Formular verwendet wird';
$string['formtype'] = "Formulartyp";
$string['friday'] = 'Freitag';
$string['from'] = 'Ab';
$string['full'] = 'Ausgebucht';
$string['fullname'] = 'Voller Name';
$string['fullwaitinglist'] = 'Volle Warteliste';
$string['fullybooked'] = 'Ausgebucht';
$string['general'] = 'Allgemein';
$string['generalsettings'] = 'Allgemeine Einstellungen';
$string['generaterecnum'] = "Eintragsnummern erstellen";
$string['generaterecnumareyousure'] = "Neue Nummern erstellen und die alten verwerfen!";
$string['generaterecnumnotification'] = "Neue Nummern erfolgreich erstellt.";
$string['global'] = 'Global';
$string['globalactivitycompletiontext'] = 'Nachricht an Nutzer/in, wenn Buchungsoption abgeschlossen ist (globale Vorlage) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['globalbookedtext'] = 'Buchungsbestätigung (globale Vorlage) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['globalbookingchangedtext'] = 'Benachrichtigung bei Änderungen an der Buchung (geht nur an User, die bereits gebucht haben). Verwenden Sie den Platzhalter {changes} um die Änderungen anzuzeigen. 0 eingeben um Änderungsbenachrichtigungen auszuschalten. (Globale Vorlage) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['globalcurrency'] = 'Währung';
$string['globalcurrencydesc'] = 'Wählen Sie die Währung für Preise von Buchungsoptionen aus';
$string['globaldeletedtext'] = 'Stornierungsbenachrichtigung (globale Vorlage) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['globalmailtemplates'] = 'Veraltete Mailvorlagen <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['globalmailtemplates_desc'] = 'Nach der Aktivierung können Sie in den Einstellungen jeder beliebigen Buchungsinstanz die Quelle der Mailvorlagen auf global setzen.';
$string['globalnotifyemail'] = 'Teilnehmer:innen-Benachrichtigung vor dem Beginn (globale Vorlage) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['globalnotifyemailteachers'] = 'Trainer:innen-Benachrichtigung vor dem Beginn (globale Vorlage) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['globalpollurlteacherstext'] = 'Link zum Absender der Umfrage für Trainer:innen (globale Vorlage) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['globalpollurltext'] = 'Umfragelink versenden (globale Vorlage) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['globalstatuschangetext'] = 'Benachrichtigung über Statusänderung (globale Vorlage) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['globaluserleave'] = 'Nutzer/in hat Buchung storniert (globale Vorlage) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['globalwaitingtext'] = 'Wartelistenbestätigung (globale Vorlage) <span class="badge bg-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Veraltet</span>';
$string['gotobooking'] = '&lt;&lt; Zu den Buchungen';
$string['gotobookingoption'] = "Buchungslink";
$string['gotobookingoptionlink'] = '{$a}';
$string['gotomanageresponses'] = '&lt;&lt; Buchungen verwalten';
$string['gotomoodlecourse'] = 'Zum Moodle-Kurs';
$string['groupdeleted'] = 'Diese Buchung erstellt automatisch Gruppen im Zielkurs. Aber die Gruppe wurde im Zielkurs manuell gelöscht. Aktivieren Sie folgende Checkbox, um die Gruppe erneut zu erstellen';
$string['groupexists'] = 'Die Gruppe existiert bereits im Zielkurs. Bitte verwenden Sie einen anderen Namen für die Buchungsoption';
$string['groupname'] = 'Gruppenname';
$string['h'] = ' Uhr';
$string['hascapability'] = 'Außer mit dieser Fähikgeit';
$string['helptext:emailsettings'] = '<div class="alert alert-warning style="margin-left: 200px;">
<i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
<span>&nbsp;Veraltete Funktion, bitte migrieren Sie ihre Vorlagen und Einstellungen zu <a href="{$a}">Buchungs Regeln</a></span>!
</div>';
$string['helptext:placeholders'] = '<div class="alert alert-info" style="margin-left: 200px;">
<a data-toggle="collapse" href="#collapsePlaceholdersHelptext" role="button" aria-expanded="false" aria-controls="collapsePlaceholdersHelptext">
  <i class="fa fa-question-circle" aria-hidden="true"></i><span>&nbsp;Platzhalter, die Sie in Ihren E-Mails verwenden können.</span>
</a>
</div>
<div class="collapse" id="collapsePlaceholdersHelptext">
  <div class="card card-body">
    {$a}
  </div>
</div>';
$string['hidedescription'] = 'Beschreibung verstecken';
$string['hidelistoncoursepage'] = 'Nein, Extra-Info nicht auf Kursseite anzeigen (Standard)';
$string['holiday'] = "Ferien / Feiertag(e)";
$string['holidayend'] = 'Ende';
$string['holidayendactive'] = 'Ende nicht am selben Tag';
$string['holidayname'] = "Name (optional)";
$string['holidays'] = "Ferien und Feiertage";
$string['holidaystart'] = 'Feiertag / Beginn';
$string['hours'] = '{$a} Stunden';
$string['howmanytimestorepeat'] = 'Anzahl an Wiederholungen';
$string['howmanyusers'] = 'Beschränkungen';
$string['howoftentorepeat'] = 'Intervall der Wiederholungen';
$string['icalcancel'] = 'Einen iCal Anhang in die Benachrichtigungsmail einfügen, wenn eine Buchung storniert wurde.';
$string['icalcanceldesc'] = 'Wenn ein User eine Buchung storniert oder von der Buchungsliste entfernt wurde, ein iCal-Event mit dem stornierten Event anhängen. (Das fügt den Termin als abgesagten Termin in den Kalender ein bzw. berichtigt den Termin)';
$string['icalcfg'] = 'Kalender-Einstellungen und iCal-Attachments';
$string['icalcfgdesc'] = 'Einstellungen für die Einträge im Moodle-Kalender und iCal-Dateien, die an E-Mails angehängt werden können. Mit iCal-Dateien können Termine zum persönlichen Kalender hinzugefügt werden.';
$string['icalfieldlocation'] = 'Text, der im iCal-Feld angezeigt werden soll';
$string['icalfieldlocationdesc'] = 'Wählen Sie aus der Dropdown-Liste, welcher Text für das Kalender-Feld verwendet werden soll.';
$string['id'] = "Id";
$string['identifier'] = 'Identifikator';
$string['ifdefinedusedtomatch'] = 'Wenn angegeben findet der Abgleich über diesen Wert statt.';
$string['importaddtocalendar'] = 'Zum Moodle Kalender hinzufügen';
$string['importcolumnsinfos'] = 'Informationen zu Importfeldern:';
$string['importcoursenumber'] = 'Moodle ID Nummer eines Moodle-Kurses, in den die Buchenden eingeschrieben werden';
$string['importcourseshortname'] = 'Kurzname eines Moodle-Kurses, in den die Buchenden eingeschrieben werden';
$string['importcsv'] = 'CSV Importer';
$string['importcsvbookingoption'] = 'Buchungsoptionen via CSV-Datei importieren';
$string['importcsvtitle'] = 'CSV-Datei importieren';
$string['importdayofweek'] = 'Wochentag einer Buchungsoption, z.B. Montag';
$string['importdayofweekendtime'] = 'Endzeit eines Kurses, z.B. 12:00';
$string['importdayofweekstarttime'] = 'Anfangszeit eines Kurses, z.B. 10:00';
$string['importdayofweektime'] = 'Wochentag und Zeit einer Buchungsoption, z.B. Montag, 10:00 - 12:00';
$string['importdefault'] = 'Standardpreis einer Buchungsoption. Nur wenn der Standardpreis gesetzt ist, können weitere Preise angegeben werden. Die Spalten müssen dafür den Kurznamen der Buchungskategorien entsprechen.';
$string['importdescription'] = 'Beschreibung der Buchungsoption';
$string['importexcelbutton'] = 'Aktivitätsabschluss importieren';
$string['importexceltitle'] = 'Aktivitätsabschluss importieren';
$string['importfailed'] = 'Import fehlgeschlagen.';
$string['importfinished'] = 'Importieren beendet!';
$string['importidentifier'] = 'Einzigartiger Identifikator einer Buchungsoption';
$string['importinfo'] = 'Import info: Folgende Spalten können importiert werden (Erklärung des Spaltennamens in Klammern)';
$string['importlocation'] = 'Ort einer Buchungsoption. Wird automatisch bei 100% Übereinstimmung mit dem Klarnamen einer "Entity" (local_entities) verknüpft. Auch die ID Nummer einer Entity kann hier eingegeben werden.';
$string['importmaxanswers'] = 'Maximale Anzahl von Buchungen pro Buchungsoption';
$string['importmaxoverbooking'] = 'Maximale Anzahl an Wartelistenplätzen pro Buchungsoption';
$string['importpartial'] = 'Der CSV-Import wurde nur teilweise durchgeführt. Bei folgenden Zeilen traten Fehler auf und sie wurden nicht importiert: ';
$string['importsuccess'] = 'Import war erfolgreich. Es wurden {$a} Datensatz/Datensätze bearbeitet.';
$string['importteacheremail'] = 'E-Mail Adressen von Nutzerinnen auf der Plattform, die als LehrerInnen in den Buchungsoptionen hinterlegt werden können. Bei mehreren e-mail Adressen Komma als Trennzeichen verwenden (aufpassen auf "Escape" bei Komma getrennten CSV!)';
$string['importtext'] = 'Titel einer Buchungsoption (Synonym zu text)';
$string['importtileprefix'] = 'Prefix (z.b. Kursnummer)';
$string['importtitle'] = 'Titel einer Buchungsoption';
$string['importuseremail'] = 'E-Mail Adressen von Nutzerinnen auf der Plattform, die diese Buchungsoption gebucht haben. Bei mehreren e-mail Adressen Komma als Trennzeichen verwenden (aufpassen auf "Escape" bei Komma getrennten CSV!)';
$string['inarray'] = 'TeilnehmerIn hat einen dieser Werte (Komma getrennt)';
$string['includeteachers'] = 'Trainer:innen in Unterschriftenliste anführen';
$string['indexnumber'] = 'Nummerierung';
$string['info:teachersforoptiondates'] = 'Wechseln Sie zum <a href="{$a}" target="_self">Trainingsjournal</a>, um die Trainer:innen für spezifische Termine zu protokollieren.';
$string['infoalreadybooked'] = '<div class="infoalreadybooked"><i>Sie haben diese Option bereits gebucht.</i></div>';
$string['infonobookingoption'] = 'Um eine Buchungsoption zu erstellen, nutzen Sie den Block Einstellungen oder das Einstellungs-Icon';
$string['infotext:installmoodlebugfix'] = 'Wunderbyte hat einen Bugfix zum Core von Moodle beigefügt. Dieser Bugfix ist in Ihrer Moodle Version noch nicht eingefügt. Sie erhalten daher an manchen Stellen Javascript Fehlermeldungen. Ab Moodle 4.1 genügt es, die laufenden Sicherheitsupdates einzuspielen.';
$string['infotext:prolicensenecessary'] = '<a href="https://showroom.wunderbyte.at/course/view.php?id=62" target="_blank">Jetzt auf Booking PRO upgraden...</a>';
$string['infotext:prolicensenecessarytextandlink'] = 'Sie benötigen Booking PRO, um dieses Feature nutzen zu können. <a href="https://showroom.wunderbyte.at/course/view.php?id=62" target="_blank">Get your PRO license here...</a>';
$string['infowaitinglist'] = '<div class="infowaitinglist"><i>Sie sind auf der Warteliste für diese Option.</i></div>';
$string['installmentprice'] = 'Ratenzahlungspreis';
$string['installmoodlebugfix'] = 'Moodle update notwendig <span class="badge bg-danger text-light"><i class="fa fa-cogs" aria-hidden="true"></i> Wichtig</span>';
$string['instancename'] = "Instanzname";
$string['instancenotsavednovalidlicense'] = 'Buchung konnte nicht als Vorlage gespeichert werden.
                                                  Holen Sie sich die PRO-Version, um beliebig viele Vorlagen erstellen
                                                  zu können.';
$string['instancesuccessfullysaved'] = 'Diese Buchung wurde erfolgreich als Vorlage gespeichert.';
$string['instancetemplate'] = 'Buchungsinstanz-Vorlage';
$string['institution'] = 'Institution';
$string['institution_help'] = 'Sie können den Namen der Institution manuell eingeben oder aus einer Liste von
                            früheren Institutionen auswählen. Sie können nur eine Institution angeben. Sobald
                            Sie speichern, wird die Institution zur Liste hinzugefügt.';
$string['institutions'] = 'Institutionen';
$string['interval'] = "Interval";
$string['interval_help'] = "In Minuten. 1440 für 24h.";
$string['invisible'] = 'Unsichtbar';
$string['invisibleoption'] = '<i class="fa fa-eye-slash" aria-hidden="true"></i> Unsichtbar';
$string['invisibleoption:notallowed'] = 'Sie sind nicht berechtigt, diese Buchungsoption zu sehen.';
$string['invisibleoptions'] = 'Unsichtbare Buchungsoptionen';
$string['iselective'] = 'Verwende Instanz als Wahlfach';
$string['iselective_help'] = 'Damit können Nutzer:innen gezwungen werden, mehrere Buchungen auf einmal in einer
 bestimmten Reihenfolge und in gewissen Beziehungen zueinander vorzunehmen, außerdem kann der Verbrauch von Credits erzwungen werden.';
$string['isempty'] = 'TeilnehmerIn hat keinen Wert gesetzt';
$string['isnotempty'] = 'TeilnehmerIn hat einen Wert gesetzt';
$string['journal'] = "Buchungsjournal";
$string['json'] = "Sammelfeld für zum Speichern von Informationen";
$string['keepusersbookedonreducingmaxanswers'] = 'Benutzer:innen bei Limit-Reduktion gebucht lassen';
$string['keepusersbookedonreducingmaxanswers_desc'] = 'Benutzer:innen weiterhin im Status "gebucht" lassen,
auch wenn das Limit der verfügbaren Plätze reduziert wird. Beispiel: Ein Kurs hat 5 Plätze.
Das Limit wird auf 3 reduziert. Die 5 Nutzer:innen, die schon gebucht haben, bleiben trotzdem im Status "gebucht".';
$string['lastname'] = "Nachname";
$string['lastnamerelated'] = "Nachname betroffene Person";
$string['lblacceptingfrom'] = 'Bezeichnung für: Annehmen von';
$string['lblbooking'] = 'Bezeichnung für: Buchung';
$string['lblbooktootherbooking'] = 'Bezeichnung für den Button "Zu anderer Buchungsoption hinzufügen"';
$string['lblinstitution'] = 'Bezeichnung für: Institution';
$string['lbllocation'] = 'Bezeichnung für: Ort';
$string['lblname'] = 'Bezeichnung für: Name';
$string['lblnumofusers'] = 'Bezeichnung für: Nutzer:innenanzahl';
$string['lblsputtname'] = 'Alternative Bezeichnung für "Umfragelink an Trainer:innen senden" verwenden';
$string['lblsurname'] = 'Bezeichnung für: Nachname';
$string['lblteachname'] = 'Alternative Bezeichnung für "Trainer:in" verwenden';
$string['leftandrightdate'] = '{$a->leftdate} bis {$a->righttdate}';
$string['licenseactivated'] = 'PRO-Version wurde erfolgreich aktiviert.<br>(Läuft ab am: ';
$string['licenseinvalid'] = 'Ungültiger Lizenz-Schlüssel.';
$string['licensekey'] = 'PRO-Lizenz-Schlüssel';
$string['licensekeycfg'] = 'PRO-Version aktivieren';
$string['licensekeycfgdesc'] = '<div class="alert alert-warning"><i class="fa fa-lightbulb-o" aria-hidden="true"></i>&nbsp;
<a href="https://showroom.wunderbyte.at/course/view.php?id=62" target="_blank">
Sie können die PRO-Version 30 Tage lang KOSTENLOS testen. Hier klicken für mehr Info.
</a>
</div>';
$string['licensekeycfgdesc:active'] = '<div class="alert alert-secondary"><i class="fa fa-lightbulb-o" aria-hidden="true"></i>&nbsp;
<a href="https://showroom.wunderbyte.at/course/view.php?id=62" target="_blank">
Hier klicken um Ihre Lizenz zu erneuern, wenn sie abgelaufen ist.
</a>
</div>';
$string['licensekeydesc'] = 'Laden Sie hier einen gültigen Schlüssel hoch, um die PRO-Version zu aktivieren.';
$string['limit'] = 'Maximale Anzahl';
$string['limitanswers'] = 'Teilnehmeranzahl beschränken';
$string['limitanswers_help'] = 'Bei Änderung dieser Einstellung und vorhandenen Buchungen, werden die Buchungen für die betroffenen Nutzer:innen ohne Benachrichtigung entfernt.';
$string['limitchangestrackinginrules'] = "Reaktionen auf Änderungen in Buchungs Regeln begrenzen";
$string['limitchangestrackinginrulesdesc'] = "Wenn Sie diese Einstellung aktivieren, gilt die Reaktion auf Änderungen in Buchungs Regeln nur für die ausgewählten Felder.";
$string['limitfactor'] = 'Buchungslimit-Faktor';
$string['limitfactor_help'] = 'Geben Sie einen Wert an, mit dem das Buchungslimit multipliziert werden soll. Um das Buchungslimit beispielsweise um 20% zu erhöhen, geben Sie den Wert 1.2 ein. Es wird auf ganze Plätze aufgerundet. 0 bedeutet unbegrenzt.';
$string['linkbacktocourse'] = 'Link zu Buchungsoptionen';
$string['linkgotobookingoption'] = 'Buchung anzeigen: {$a}</a>';
$string['linknotavailableyet'] = 'Der Link zum Online-Meeting-Raum ist erst 15 Minuten vor dem Meeting sichtbar
und verschwindet nach Ende des Meetings wieder.';
$string['linknotvalid'] = 'Dieser Link / dieses Event ist derzeit nicht verfügbar.
Bitte probieren Sie es kurz vor Beginn noch einmal, wenn Sie dieses Event gebucht haben.';
$string['linktocalendarurltext'] = "Hier geht's zum Kalender";
$string['linktocourse'] = "Hier geht's zum Kurs";
$string['linktomoodlecourseonbookedbutton'] = 'Zeige Link auf Moodle-Kurs direkt am Buchen-Button';
$string['linktomoodlecourseonbookedbutton_desc'] = 'Statt eines extra Links auf den Moodle-Kurs wird diese Option den Buchungsbutton in einen Link auf den gebuchten Moodle-Kurs umwandeln';
$string['linktoshowroom:bookingrules'] = '<div class="alert alert-secondary"><i class="fa fa-lightbulb-o" aria-hidden="true"></i>&nbsp;
<a href="https://showroom.wunderbyte.at/course/view.php?id=70" target="_blank">
Sie möchten Buchungsregeln besser verstehen? Hier geht\'s zum Tutorial.
</a>
</div>';
$string['linktoteachersinstancereport'] = '<p><a href="{$a}" target="_self">&gt;&gt; Zum Trainer:innen-Gesamtbericht für die Buchungsinstanz</a></p>';
$string['listentoaddresschange'] = "Reagieren auf Änderungen des Ortes der Buchungsoption";
$string['listentoresponsiblepersonchange'] = "Reagieren auf Änderungen der verantwortlichen Person der Buchungsoption";
$string['listentoteacherschange'] = "Reagieren auf Änderungen des Lehrerenden der Buchungsoption";
$string['listentotextchange'] = "Reagieren auf Änderungen des Textes der Buchungsoption";
$string['listentotimestampchange'] = "Reagieren auf Änderungen der Zeitpunktes (und Tages) der Buchungsoption";
$string['location'] = 'Ort';
$string['location_help'] = 'Sie können den Namen des Orts manuell eingeben oder aus einer Liste von
                            früheren Orten auswählen. Sie können nur einen Ort angeben. Sobald
                            Sie speichern, wird der Ort zur Liste hinzugefügt.';
$string['loginbuttonforbookingoptionscoloroptions'] = 'Stil (Farbe) des angezeigten Buttons';
$string['loginbuttonforbookingoptionscoloroptions_desc'] = 'Nutzt Bootstrap 4 Styles. Die Farben sind für die Standardanwendung.';
$string['loopprevention'] = 'Den Platzhalter {$a} hier zu verwenden führt zu einem Loop. Bitte entfernen Sie ihn.';
$string['lowerthan'] = 'ist kleiner als (Zahl)';
$string['mail'] = 'Mail';
$string['mailconfirmationsent'] = 'Sie erhalten in Kürze ein Bestätigungsmail an die in Ihrem Profil angegebene E-Mail Adresse';
$string['mailtemplatesadvanced'] = 'Erweiterte Einstelllungen für E-Mail-Vorlagen aktivieren';
$string['mailtemplatesglobal'] = 'Globale E-Mail-Vorlagen aus den Plugin-Einstellungen verwenden';
$string['mailtemplatesinstance'] = 'E-Mail-Vorlagen aus dieser Buchungsinstanz verwenden (Standard)';
$string['mailtemplatessource'] = 'Quelle von E-Mail-Vorlagen festlegen';
$string['mailtemplatessource_help'] = '<b>Achtung:</b> Wenn Sie globale E-Mail-Vorlagen wählen, werden die Instanz-spezifischen
E-Mail-Vorlagen nicht verwendet, sondern die E-Mail-Vorlagen, die in den Einstellungen des Buchungs-Plugins angelegt
wurden. <br><br>Bitte stellen Sie sicher, dass zu allen E-Mail-Typen eine Vorlage vorhanden ist.';
$string['managebookedusers_heading'] = 'Buchungen verwalten für <b>{$a->scopestring}</b>: "{$a->title}"';
$string['managebooking'] = 'Verwalten';
$string['managebookinginstancetemplates'] = 'Buchungsinstanz-Vorlagen verwalten';
$string['managecustomreporttemplates'] = 'Vorlagen für benutzerdefinierte Berichte verwalten';
$string['manageoptiontemplates'] = 'Buchungsoptionsvorlagen verwalten';
$string['manageresponses'] = 'Buchungen verwalten';
$string['manageresponsesdownloadfields'] = 'Buchungen verwalten - Download (CSV, XLSX...)';
$string['manageresponsespagefields'] = 'Buchungen verwalten - Seite';
$string['mandatory'] = 'verpflichtend';
$string['matchuserprofilefield'] = "Wähle Nutzer:innen nach gleichem Wert in Buchungsoption und Profil.";
$string['maxanswers'] = 'Limit für Antworten';
$string['maxcredits'] = 'Anzahl verfügbare Credits';
$string['maxcredits_help'] = 'Sie können die maximal in dieser Buchung verfügbaren Credits angeben, die verbraucht werden können oder müssen. Für jede Buchungsoption können die entsprechenden Credits angegeben werden.';
$string['maxoptionsfromcategory'] = 'Anzahl der Buchungen pro Kategorie einschränken';
$string['maxoptionsfromcategorycount'] = 'Wieviele Buchungen sollen in der Kategorie "{$a}" pro Person maximal möglich sein? Wird auf jedes der unten angegebenen Felder angewandt. 0 bedeutet unbegrenzt.';
$string['maxoptionsfromcategorydesc'] = 'Soll es die Möglichkeit geben, dass die Anzahl der Buchungen pro in einer Kategorie eingeschränkt wird? Die genauen Einstellungen erfolgen in der Buchungs-Instanz. Falls gewünscht, speichern und im nächsten Schritt einstellen, welches Feld dafür ausgewählt werden soll.';
$string['maxoptionsfromcategoryfield'] = 'Welches Feld soll für die Einschränkungen verwendet werden?';
$string['maxoptionsfromcategoryfielddesc'] = 'Wählen Sie ein Feld, auf dessen Werte hin das Buchen multipler Optionen für die Nutzenden eingeschränkt werden kann.';
$string['maxoptionsfromcategoryvalue'] = 'Welcher Wert soll im Feld "{$a}" stehen, damit diese Beschränkung angewendet wird?';
$string['maxoptionsfrominstance'] = 'Einschränkung gilt nur für Buchungen dieser Instanz';
$string['maxoptionsstring'] = 'Sie haben bereits die maximale Anzahl an Buchungen dieses Types erreicht.';
$string['maxoptionsstringdetailed'] = 'Sie haben bereits die maximale Anzahl von {$a->max} Buchungen des Types "{$a->type}" (in Kategorie "{$a->category}") erreicht: <br> {$a->maxoptions}';
$string['maxoverbooking'] = 'Maximale Anzahl der Wartelistenplätze';
$string['maxoverbooking_help'] = 'Geben Sie "-1" ein für unbegrenzte Warteliste und "0" wenn Sie keine Warteliste erlauben möchten.';
$string['maxparticipantsnumber'] = 'Maximale Teilnehmeranzahl';
$string['maxparticipantsnumber_help'] = '"0" bedeutet unbegrenzt';
$string['maxperuser'] = 'Maximale Anzahl an Buchungen pro User';
$string['maxperuser_help'] = 'Die maximale Anzahl an Buchungen, die ein/e Nutzer/in auf einmal buchen kann.
<b>Achtung:</b> In den Booking-Plugin-Einstellungen können Sie auswählen, ob Nutzer:innen, die teilgenommen
oder abgeschlossen haben und ob Buchungsoptionen, die bereits vorbei sind, mitgezählt werden sollen oder nicht.';
$string['maxperuserdontcountcompleted'] = 'Max. Anz. Buchungen: Abgeschlossene ignorieren';
$string['maxperuserdontcountcompleted_desc'] = 'Abgeschlossene Buchungen und Teilnehmer:innen mit Anwesenheitsstatus "Teilgenommen" oder "Abgeschlossen"
bei der Berechnung der maximalen Anzahl an Buchungen nicht mitzählen';
$string['maxperuserdontcountnoshow'] = 'Max. Anz. Buchungen: Abwesende ignorieren';
$string['maxperuserdontcountnoshow_desc'] = 'Abwesende Teilnehmer:innen mit Anwesenheitsstatus "Nicht aufgetaucht"
bei der Berechnung der maximalen Anzahl an Buchungen nicht mitzählen';
$string['maxperuserdontcountpassed'] = 'Max. Anz. Buchungen: Vergangene ignorieren';
$string['maxperuserdontcountpassed_desc'] = 'Buchungen von Buchungsoptionen, die bereits vergangen sind, bei der Berechnung
der maximalen Anzahl an Buchungen nicht mitzählen';
$string['maxperuserwarning'] = 'Sie haben zur Zeit ein Limit von {$a->count}/{$a->limit} Buchungen';
$string['messagebutton'] = 'Nachricht';
$string['messageprovider:bookingconfirmation'] = "Buchungsbestätigungen";
$string['messageprovider:sendmessages'] = 'Kann Nachrichten schicken';
$string['messagesend'] = 'Die Nachricht wurde erfolgreich versandt.';
$string['messagesent'] = 'Nachricht gesendet';
$string['messagesubject'] = 'Betreff';
$string['messagetext'] = 'Nachricht';
$string['messagingteacherimpossible'] = 'Sie können dieser Trainerin / diesem Trainer keine Nachrichten senden,
 weil Sie in keinen Kurs von ihr/ihm eingeschrieben sind.';
$string['minanswers'] = 'Mindestteilnehmer/innenzahl';
$string['minanswers_help'] = '"0" bedeutet keine Mindestteilnehmer/innenzahl';
$string['minutes'] = '{$a} Minuten';
$string['missinghours'] = 'Fehlstunden';
$string['missinglabel'] = 'Im importierten File fehlt die verpflichtede Spalte {$a}. Daten können nicht importiert werden.';
$string['mobileappheading'] = "Mobile App";
$string['mobileappheading_desc'] = "Wählen Sie Ihre Buchungsinstanz aus, die in den verbundenen Moodle Mobile Apps angezeigt werden soll.";
$string['mobileappnobookinginstance'] = "Keine Buchungsinstanz auf Ihrer Plattform";
$string['mobileappnobookinginstance_desc'] = "Sie müssen mindestens eine Buchungsinstanz erstellen.";
$string['mobileappprice'] = 'Preis';
$string['mobileappsetinstance'] = "Buchungsinstanz";
$string['mobileappsetinstancedesc'] = "Wählen Sie die Buchungsinstanz aus, die in der mobilen App angezeigt werden soll.";
$string['mobilefieldrequired'] = 'Dieses Feld ist erforderlich';
$string['mobilenotification'] = 'Formular wurde eingereicht';
$string['mobileresetsubmission'] = 'Einreichungsformular zurücksetzen';
$string['mobilesetsubmission'] = 'Einreichen';
$string['mobilesettings'] = 'Einstellungen für die Moodle App';
$string['mobilesettings_desc'] = 'Hier können Sie besondere Einstellungen für die Moodle Mobile App treffen.';
$string['mobilesubmission'] = 'Mobile Einreichung';
$string['mobilesubmissiondisabled'] = 'Mobile Einreichung ist deaktiviert';
$string['mobilesubmissionenabled'] = 'Mobile Einreichung ist aktiviert';
$string['mobilesubmissionform'] = 'Mobile Einreichungsformular';
$string['mobilesubmissionform_desc'] = 'Einschalten oder Ausschalten der mobilen Einreichungsformulare';
$string['mobilesubmittedsuccess'] = 'Sie können fortfahren und den Kurs buchen';
$string['mobileviewoptionsdesc'] = 'Auswahl der möglichen Ansichten in der Mobilen-Ansicht';
$string['mobileviewoptionstext'] = 'Mobile Ansichten';
$string['mod/booking:bookanyone'] = 'JedeN buchen';
$string['mod/booking:expertoptionform'] = 'Buchungsoption für ExpertInnen';
$string['mod/booking:reducedoptionform1'] = 'Buchungsoption reduziert 1';
$string['mod/booking:reducedoptionform2'] = 'Buchungsoption reduziert 2';
$string['mod/booking:reducedoptionform3'] = 'Buchungsoption reduziert 3';
$string['mod/booking:reducedoptionform4'] = 'Buchungsoption reduziert 4';
$string['mod/booking:reducedoptionform5'] = 'Buchungsoption reduziert 5';
$string['mod/booking:seepersonalteacherinformation'] = 'Detailinfos über Lehrende anzeigen';
$string['modaloptiondateformtitle'] = 'Benutzerdefinierte Termine';
$string['modelinformation'] = 'Dieses Feld ist notwendig, um Fragen vollständig zu erfassen. Ist das Feld leer, kann die Frage lediglich einer Skala zugeordnet werden.';
$string['modulename'] = 'Buchung';
$string['modulenameplural'] = 'Buchungen';
$string['monday'] = 'Montag';
$string['movedbookinghistory'] = 'Die Buchungsoption wurde von der Buchung mit der ID: {$a->oldbooking} nach {$a->newbooking} verschoben. ';
$string['moveoption'] = 'Option verschieben';
$string['moveoption_help'] = 'Option in eine andere Buchungsaktivität verschieben';
$string['moveoptionto'] = 'Buchungsoption in andere Buchungsinstanz verschieben';
$string['multipledayofweektimestringshint'] = '<b>Pro Zeile</b> können Sie eine Kombination aus Wochentag und Uhrzeit angeben.<br>Beispiel: "Montag, 10:00 - 12:00" und "Dienstag, 15:00 - 16:30"';
$string['mustchooseone'] = 'Sie müssen eine Option auswählen';
$string['mustcombine'] = 'Notwendige Buchungsoptionen';
$string['mustcombine_help'] = 'Buchungsoptionen mit denen diese Buchungsoption kombiniert werden muss';
$string['mustfilloutuserinfobeforebooking'] = 'Bevor Sie buchen, füllen Sie bitte noch Ihre persönlichen Buchungsdaten aus';
$string['mustnotcombine'] = 'Ausgeschlossene Buchungsoptionen';
$string['mustnotcombine_help'] = 'Buchungsoptionen mit denen diese Buchungsoption nicht kombiniert werden kann';
$string['mybookingoptions'] = 'Meine Buchungen';
$string['mycourselist'] = 'Zeige meine Buchungsoptionen';
$string['myinstitution'] = 'Meine Institution';
$string['name'] = 'Name';
$string['newcourse'] = 'Neuen Kurs erstellen...';
$string['newcoursecategorycfield'] = 'Benutzerdefiniertes Buchungsoptionsfeld für Kurskategorie';
$string['newcoursecategorycfielddesc'] = 'Wählen Sie ein benutzerdefiniertes Buchungsoptionsfeld, das verwendet werden soll,
 um die Kurskategorie von automatisch erstellten Kursen festzulegen. Kurse können mit dem Eintrag "Neuen Kurs erstellen..." im Menü "Einen Kurs auswählen"
 des Formulars zum Anlegen von Buchungsoptionen automatisch erstellt werden.';
$string['newoptiondate'] = 'Neuen Termin anlegen...';
$string['newtemplatesaved'] = 'Neue Buchungsoptionsvorlage wurde gespeichert.';
$string['next'] = 'Nächste';
$string['no'] = 'Nein';
$string['nobookinginstancesexist'] = 'Keine Buchungsinstanz vorhanden';
$string['nobookingpossible'] = 'Keine Buchung möglich.';
$string['nobookingselected'] = 'Keine Buchungsoption ausgewählt';
$string['nocancelreason'] = "Sie müssen eine Grund für die Stornierung angeben";
$string['nocfnameselected'] = "Nichts ausgewählt. Tippen Sie einen neuen Namen oder wählen Sie einen aus der Liste.";
$string['nocomments'] = 'Kommentare deaktiviert';
$string['nocourse'] = 'Kein Kurs für Buchungsoption ausgewählt';
$string['nocourseselected'] = 'Kein Kurs ausgewählt';
$string['nodatesstring'] = "Aktuell gibt es keine Daten zu dieser Buchungsoption";
$string['nodatesstring_desc'] = "no dates";
$string['nodescriptionmaxlength'] = 'Keine maximale Länge der Beschreibung';
$string['nodirectbookingbecauseofprice'] = 'Das Buchen von anderen ist bei dieser Buchungsoption nur eingeschränkt möglich. Die Gründe dafür sind folgende:
<ul>
<li>ein Preis ist hinterlegt</li>
<li>das Shopping Cart Modul ist installiert</li>
<li>die Warteliste ist global nicht deaktiivert</li>
</ul>
Der Zweck dieses Verhaltens ist es, "gemischte" Buchungen mit und ohne Warenkorb zu verhindern. Bitte verwenden Sie die Kassierfunktion des Warenkorbs, um Benutzer:innen zu buchen.';
$string['noelement'] = "Kein Element";
$string['noeventtypeselected'] = 'Keine Ereignisart ausgewählt';
$string['nofieldchosen'] = 'Kein Feld ausgewählt';
$string['nofieldofstudyfound'] = "Es konnte keine Studienrichtung über die Globalen Gruppen herausgefunden werden.";
$string['noformlink'] = "Keine Verbindung zum Formular dieser Buchungsoption";
$string['nogrouporcohortselected'] = 'Sie müssen mindestens eine Gruppe oder globale Gruppe auswählen.';
$string['noguestchoose'] = 'Gäste dürfen keine Buchungen vornehmen';
$string['noinstitutionselected'] = 'Keine Institution ausgewählt';
$string['nolabels'] = 'Keine Spaltennamen definiert.';
$string['nolocationselected'] = 'Kein Ort ausgewählt';
$string['nomoodlecourseconnection'] = 'Keine Verbindung zu Moodle-Kurs';
$string['nooptionselected'] = 'Keine Buchungsoption ausgewählt';
$string['nooverlapblocking'] = 'Diese Option kann nicht gebucht werden, sie überlappt der/den von Ihnen gebuchten Option(en): {$a}';
$string['nooverlappingselectblocking'] = 'Buchen blockieren';
$string['nooverlappingselectinfo'] = 'Wenn diese Buchungsoption ausgewählt wird, obwohl die Zeiträume mit einer anderen überlappt, was soll passieren?';
$string['nooverlappingselectwarning'] = 'Warnung anzeigen';
$string['nooverlappingsettingcheckbox'] = 'Reagiere auf den Versuch überlappende Buchungsoptionen zu buchen';
$string['nooverlapwarning'] = 'Achtung, diese Option überlappt mit der/den von Ihnen gebuchten Option(en): {$a}';
$string['nopermissiontoaccesscontent'] = '<div class="alert alert-danger" role="alert">Sie sind nicht berechtigt, auf diese Inhalte zuzugreifen.</div>';
$string['nopermissiontoaccesspage'] = '<div class="alert alert-danger" role="alert">Sie sind nicht berechtigt, auf diese Seite zuzugreifen.</div>';
$string['nopricecategoriesyet'] = 'Es wurden noch keine Preiskategorien angelegt.';
$string['nopricecategoryselected'] = 'Geben Sie den Namen einer neuen Preiskategorie ein';
$string['nopriceformulaset'] = 'Sie müssen zuerst eine Formel in den Buchungseinstellungen eintragen. <a href="{$a->url}" target="_blank">Formel hier bearbeiten.</a>';
$string['nopriceisset'] = 'Kein Preis für Preiskategorie {$a} vorhanden';
$string['noratings'] = 'Bewertungen deaktiviert';
$string['norestriction'] = 'Keine Eischränkung';
$string['noresultsviewable'] = 'Die Ergebnisse sind momentan nicht einsehbar';
$string['norighttobook'] = 'Sie haben zur Zeit keine Berechtigung Buchungen vorzunehmen. Loggen Sie sich ein, schreiben Sie sich in diesen Kurs ein oder kontaktieren Sie den/die Administrator/in.';
$string['norowsselected'] = 'Sie haben noch nichts ausgewählt. Bitte schließen Sie dieses Fenster und wählen Sie zunächst die Zeilen aus, die Sie bearbeiten möchten.';
$string['noruleselected'] = 'Keine Regeln ausgewählt';
$string['noselection'] = 'Keine Auswahl';
$string['nosemester'] = 'Kein Semester gewählt';
$string['nosubscribers'] = 'Keine Trainer:innen zugewiesen!';
$string['notallbooked'] = 'Folgende Nutzer:innen konnten aufgrund nicht mehr verfügbarer Plätze oder durch das Überschreiten des vorgegebenen Buchungslimits pro Nutzer:in nicht gebucht werden: {$a}';
$string['notanswered'] = 'Nicht beantwortet';
$string['notateacher'] = 'Die ausgewählte Person unterrichtet keine buchbaren Kurse und kann daher nicht angezeigt werden.';
$string['notbookable'] = "Nicht buchbar";
$string['notbookablecombiantion'] = 'Diese Kombination von Wahlfächern ist nicht erlaubt';
$string['notbooked'] = 'Noch nicht gebucht';
$string['notconectedbooking'] = 'Nicht vorgeschaltete Buchung';
$string['noteacherfound'] = 'Die Nutzer/in die in Zeile {$a} in der Spalte für teacher angeführt wurde, existiert nicht auf der Plattform';
$string['noteacherset'] = 'Kein/e Trainer:in';
$string['notemplate'] = 'Nicht als Vorlage benutzen';
$string['notemplateyet'] = 'Es gibt noch kein Template';
$string['notenoughcreditstobook'] = 'Nicht genug Credit um zu buchen';
$string['notes'] = 'Anmerkungen';
$string['notesedited'] = 'Anmerkungen bearbeitet';
$string['noteseditedhistory'] = 'Die Anmerkungen wurden von "{$a->notesold}" zu "{$a->notesnew}" geändert.';
$string['noteseditedinfo'] = 'Die Anmerkungen von {$a->relateduser} wurden von "{$a->notesold}" zu "{$a->notesnew}" geändert.';
$string['notfullwaitinglist'] = 'Nicht volle Warteliste';
$string['notfullybooked'] = 'Nicht ausgebucht';
$string['notificationlist'] = 'Benachrichtigungsliste';
$string['notificationlistdesc'] = 'Wenn es bei einer Buchungsoption keine verfügbaren Plätze mehr gibt,
 können sich Teilnehmer:innnen registrieren lassen, um eine Benachrichtung zu erhalten, sobald wieder
 Plätze verfügbar sind.';
$string['notificationtext'] = 'Benachrichtigungstext';
$string['notifyemail'] = 'Teilnehmer:innen-Benachrichtigung vor dem Beginn';
$string['notifyemailmessage'] = 'Ihre Buchung startet demnächst:
{$a->bookingdetails}
Name:   {$a->participant}
Um eine Übersicht über alle Buchungen zu erhalten, klicken Sie auf den folgenden Link:
{$a->bookinglink}
Hier geht\'s zum Kurs:  {$a->courselink}
';
$string['notifyemailsubject'] = 'Ihre Buchung startet demnächst';
$string['notifyemailteachers'] = 'Trainer:innen-Benachrichtigung vor dem Beginn';
$string['notifyemailteachersmessage'] = 'Ihre Buchung startet demnächst:
{$a->bookingdetails}
Sie haben <b>{$a->numberparticipants} gebuchte Teilnehmer:innen</b> und <b>{$a->numberwaitinglist} Personen auf der Warteliste</b>.
Um eine Übersicht über alle Buchungen zu erhalten, klicken Sie auf den folgenden Link:
{$a->bookinglink}
Hier geht\'s zum Kurs:  {$a->courselink}
';
$string['notifyemailteacherssubject'] = 'Ihre Buchung startet demnächst';
$string['notifyme'] = 'Benachrichtigen wenn frei';
$string['notifymelistdeleted'] = 'Nutzer:in von der Benachrichtigungsliste gelöscht';
$string['notinarray'] = 'TeilnehmerIn hat keinen dieser Werte (Komma getrennt)';
$string['notopenyet'] = 'Diese Aktivität ist bis {$a} nicht verfügbar';
$string['nouserfound'] = 'Kein/e User/in gefunden: ';
$string['numberofinstallment'] = 'Anzahl Ratenzahlung';
$string['numberofinstallmentstring'] = '{$a}. Ratenzahlung';
$string['numberparticipants'] = "Max buchbar";
$string['numberrows'] = 'Zeilen nummerieren';
$string['numberrowsdesc'] = 'Nummerierung der Zeilen in der Unterschriftenliste aktivieren. Die Nummer wird links des Namens dargestellt';
$string['numberwaitinglist'] = "Max auf Warteliste";
$string['numgenerator'] = 'Automatische Seitennummerierung aktivieren?';
$string['numrec'] = "Eintragsnummer.";
$string['off'] = "Aus";
$string['on'] = "An";
$string['onecohortmustbefound'] = 'Zumindest eine dieser globalen Gruppen muss zutreffen';
$string['onecoursemustbefound'] = 'Zumindest einer dieser Kurse muss gebucht sein';
$string['onlineoptiondate'] = 'Findet online statt';
$string['onlyaddactionsonsavedoption'] = "Aktionen nach der Buchung könnnen nur zu schon gespeicherte Optionen hinzugefügt werden.";
$string['onlyaddentitiesonsavedsubbooking'] = "Sie müssen diese neue zusätzliche Buchungsoption speichern, bevor sie Entities hinzufügen können.";
$string['onlyaddsubbookingsonsavedoption'] = "Sie müssen diese neue Buchungsoption speichern, bevor sie Unterbuchungen hinzufügen können.";
$string['onlythisbookingoption'] = 'Nur diese Buchungsoption';
$string['onlyusersfrominstitution'] = 'Sie können nur Nutzerinnen von dieser Instition hinzufügen: {$a}';
$string['onwaitinglist'] = 'Sie sind auf der Warteliste';
$string['openbookingdetailinsametab'] = 'Beim Klick auf Titel der Buchungsoption direkt öffnen';
$string['openbookingdetailinsametab_desc'] = 'In der Kursliste wird beim Klick auf den Titel der Buchungsoption die Detailansicht geöffnet. Wenn Sie diese Option anwählen, wir der/die NutzerIn dabei direkt zu dieser Seite geleitet. Wird der Haken nicht gesetzt, öffnet sich die Detailansicht in einem neuen Tab.';
$string['openformat'] = 'offenes Format';
$string['optional'] = 'optional';
$string['optionannotation'] = 'Interne Anmerkung';
$string['optionannotation_help'] = 'Fügen Sie interne Notizen bzw. Anmerkungen hinzu. Diese werden NUR in DIESEM Formular und sonst nirgendwo angezeigt.';
$string['optionbookablebody'] = 'Sie können {$a->title} ab sofort wieder buchen. Klicken Sie <a href="{$a->url}">hier</a>, um direkt zur Buchungsoption zu gelangen.<br><br>
(Sie erhalten diese Nachricht, da Sie bei der Buchungsoption auf den Benachrichtigungs-Button geklickt haben.)<br><br>
<a href="{$a->unsubscribelink}">Von Erinnerungs-E-Mails für "{$a->title}" abmelden.</a>';
$string['optionbookabletitle'] = '{$a->title} wieder buchbar';
$string['optiondate'] = 'Termin';
$string['optiondateend'] = 'Ende';
$string['optiondates'] = 'Termine';
$string['optiondatesmanager'] = 'Termine verwalten';
$string['optiondatesmessage'] = 'Termin {$a->number}: {$a->date} <br> Von: {$a->starttime} <br> Bis: {$a->endtime}';
$string['optiondatessuccessfullydelete'] = "Termin wurde gelöscht";
$string['optiondatessuccessfullysaved'] = "Termin wurde bearbeitet";
$string['optiondatestart'] = 'Beginn';
$string['optiondatesteacheradded'] = 'Vertretung wurde eingetragen';
$string['optiondatesteacherdeleted'] = 'Trainer:in wurde aus Trainingsjournal entfernt';
$string['optiondatesteachersreport'] = 'Vertretungen & Absagen';
$string['optiondatesteachersreport_desc'] = 'In diesem Report erhalten Sie eine Übersicht, welche:r Trainer:in an welchem Termin geleitet hat.<br>
Standardmäßig werden alle Termine mit dem/den eingestellten Trainer:innen der Buchungsoption befüllt. Sie können einzelne Termine mit Vertretungen überschreiben.';
$string['optiondatestime'] = 'Termine';
$string['optionformconfig'] = 'Formulare für Buchungsoptionen anpassen (PRO)';
$string['optionformconfig:nobooking'] = 'Sie müssen zumindest eine Buchungsinstanz anlegen, bevor Sie dieses Formular nutzen können!';
$string['optionformconfiggetpro'] = 'Mit Booking <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span> haben Sie die Möglichkeit, mit Drag & Drop individuelle Formulare für bestimmte Nutzer:innen-Gruppen und Kontexte
(z.B. nur für eine bestimmte Buchungsinstanz) anzulegen.';
$string['optionformconfiginfotext'] = 'Mit diesem PRO-Feature können Sie sich mit Drag & Drop und den Checkboxen beliebige Buchungsoptionsformulare zusammenstellen.
Die einzelnen Formulare werden auf bestimmten Kontext-Ebenen (z.B. pro Buchungsinstanz, Systemweit...) definiert. Den jeweiligen Nutzer:innen sind die Formulare nur zugänglich,
wenn Sie die jeweils entsprechende Berechtigung haben.';
$string['optionformconfignotsaved'] = 'Es wurde keine besondere Formular-Definition gespeichert';
$string['optionformconfigsaved'] = 'Konfiguration für das Buchungsoptionsformular gespeichert.';
$string['optionformconfigsavedcourse'] = 'Ihre Formular-Definition wurde auf dem Kontextlevel Kurs gespeichert';
$string['optionformconfigsavedcoursecat'] = 'Ihre Formular-Definition wurde auf dem Kontextlevel Kurskategorie gespeichert';
$string['optionformconfigsavedmodule'] = 'Ihre Formular-Definition wurde auf dem Kontextlevel Modul gespeichert';
$string['optionformconfigsavedother'] = 'Ihre Formular-Definition wurde auf Kontextlevel {$a} gespeichert';
$string['optionformconfigsavedsystem'] = 'Ihre Formular-Definition wurde auf dem Kontextlevel System gespeichert';
$string['optionformconfigsubtitle'] = '<p>Hier können Sie nicht benötigte Funktionalitäten entfernen, um das Formular für die Erstellung von Buchungsoptionen übersichtlicher zu gestalten.</p>
<p><strong>ACHTUNG:</strong> Deaktivieren Sie nur Felder, von denen Sie sicher sind, dass Sie sie nicht benötigen!</p>';
$string['optionid'] = 'Option ID';
$string['optionidentifier'] = 'Identifikator';
$string['optionidentifier_help'] = 'Geben Sie einen eindeutigen Identifikator für diese Buchungsoption an.';
$string['optioninvisible'] = 'Nur für berechtigte Personen sichtbar (z.B. Teacher, Manager)';
$string['optionmenu'] = 'Diese Buchungsoption';
$string['optionmoved'] = 'Buchungsoption verschoben';
$string['optionnoimage'] = 'Kein Bild';
$string['optionsdownloadfields'] = 'Buchungsübersicht - Download (CSV, XLSX...)';
$string['optionsfield'] = 'Buchungsoptionsfeld';
$string['optionsfields'] = 'Buchungsoptionsfelder';
$string['optionsiamresponsiblefor'] = 'Ich bin Kontaktperson';
$string['optionsiteach'] = 'Von mir geleitet';
$string['optionspagefields'] = 'Buchungsübersicht - Seite';
$string['optionspecificcampaignwarning'] = '
Wenn Sie ein Buchungsoptionsfeld auswählen, wird die Kampagne nur für jede Buchungsoptionen angewandt, die diese Anforderungen erfüllen.
<div class="alert alert-warning style="margin-left: 200px;">
<i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
<span> Achtung: Entsprechend Ihrer Einstellungen kann diese Kampagne die Verfügbarkeit von sehr vielen Buchungsoptionen blockieren.</span>
</div>
Wenn Sie auch ein Benutzerdefiniertes User Profilfeld wählen, wird der Preis nur dann geändert, wenn BEIDE Anforderungen erfüllt sind.';
$string['optiontemplate'] = 'Option template';
$string['optiontemplatename'] = 'Vorlagenname der Buchungsoption';
$string['optiontemplatenotsavednovalidlicense'] = 'Buchungsoption konnte nicht als Vorlage gespeichert werden.
                                                  Holen Sie sich die PRO-Version, um beliebig viele Vorlagen erstellen
                                                  zu können.';
$string['optiontemplatessettings'] = 'Buchungsoptionsvorlagen';
$string['optionviewcustomfields'] = 'Benutzerdefinierte Felder auf Detailseite anzeigen';
$string['optionviewcustomfieldsdesc'] = 'Wählen Sie die benutzerdefinierten Buchungsoptionsfelder aus, die auf der Detailseite von Buchungsoptionen angezeigt werden sollen. Um die Reihenfolge der benutzerdefinierten Felder auf der Detailseite zu ändern, können Sie einfach die Reihenfolge der benutzerdefinierten Felder <a href="/mod/booking/customfield.php" target="_blank">hier</a> ändern.';
$string['optionvisibility'] = 'Sichtbarkeit';
$string['optionvisibility_help'] = 'Stellen Sie ein, ob die Buchungsoption für jede_n sichtbar sein soll oder nur für berechtigte Nutzer:innen.';
$string['optionvisible'] = 'Für alle sichtbar (Standard)';
$string['optionvisibledirectlink'] = 'Für nicht berechtigte Personen nur mit direktem Link sichtbar';
$string['organizatorname'] = 'Name des Organisators';
$string['organizatorname_help'] = 'Sie können den Namen des Organisators/der Organisatorin manuell eingeben oder aus einer Liste von
                                    früheren Organisator:innen auswählen. Sie können nur eine/n Organisator/in angeben. Sobald
                                    Sie speichern, wird der/die Organisator/in zur Liste hinzugefügt.';
$string['orotherfield'] = 'ODER weiteres Feld';
$string['otherbookingaddrule'] = 'Neue Buchungsoption hinzufügen';
$string['otherbookinglimit'] = "Limit";
$string['otherbookinglimit_help'] = "Anzahl der Nutzer:innen die von dieser Buchungsoption akzeptiert werden. 0 bedeutet unlimitiert.";
$string['otherbookingnumber'] = 'Nutzer:innen-Anzahl';
$string['otherbookingoptions'] = 'Nutzer:innen dieser Buchungsoption zulassen';
$string['otherbookingsuccessfullysaved'] = 'Buchungsoption gespeichert!';
$string['otheroptionsavailable'] = 'Gegebene verknüpfte Optionen verfügbar';
$string['otheroptionsnotavailable'] = 'Verknüpfte Buchungsoption(en) nicht verfügbar';
$string['overridecondition'] = 'Einschränkung';
$string['overrideconditioncheckbox'] = 'Steht in Bezug zu einer anderen Einschränkung';
$string['overrideoperator'] = 'Operator';
$string['overrideoperator:and'] = 'UND';
$string['overrideoperator:or'] = 'ODER';
$string['overwriteblockingwarnings'] = 'Warnungen mit unten stehendem Text überschreiben';
$string['page:bookingpolicy'] = 'Buchungsbedingungen';
$string['page:bookitbutton'] = 'Buchen';
$string['page:checkout'] = 'Zur Bezahlung';
$string['page:confirmation'] = 'Buchung abgeschlossen';
$string['page:customform'] = 'Formular ausfüllen';
$string['page:subbooking'] = 'Zusätzliche Buchungen';
$string['paginationnum'] = 'Anzahl der Einträge pro Seite';
$string['participant'] = "Nutzer:in Name";
$string['pdflandscape'] = 'Querformat';
$string['pdfportrait'] = 'Hochformat';
$string['percentageavailableplaces'] = 'Prozent der verfügbaren Plätze';
$string['percentageavailableplaces_help'] = 'Geben Sie einen gültigen Prozentsatz zwischen 0 und 100 an (ohne %-Zeichen!).';
$string['personnr'] = 'Person Nr. {$a}';
$string['placeholders'] = 'Platzhalter';
$string['placeholders_help'] = 'Lassen Sie dieses Feld leer, um den Standardtext der Website zu verwenden.';
$string['places'] = 'Plätze';
$string['placesinfoshowbooked'] = 'Gebuchte Plätze anzeigen';
$string['placesinfoshowfreeonly'] = 'Text für freie Plätze anzeigen';
$string['placesinfoshowinfotexts'] = 'Verfügbarkeitstexte anzeigen';
$string['pluginadministration'] = 'Booking administration';
$string['pluginname'] = 'Booking';
$string['pollstartdate'] = "Start Datum der Umfrage";
$string['pollstrftimedate'] = '%Y-%m-%d';
$string['pollurl'] = 'Link zur Umfrage';
$string['pollurlteachers'] = 'Trainer:innen Umfragelink';
$string['pollurlteacherstext'] = 'Umfragetext für Trainer:innen';
$string['pollurlteacherstextmessage'] = 'Bitte nehmen Sie an der Umfrage teil:
Link zur Umfrage: <a href="{pollurlteachers}" target="_blank">{pollurlteachers}</a>
';
$string['pollurlteacherstextsubject'] = 'Bitte nehmen Sie an der Umfrage teil';
$string['pollurltext'] = 'Umfragelink senden';
$string['pollurltextmessage'] = 'Bitte nehmen Sie an der Umfrage teil:
Link zur Umfrage: <a href="{pollurl}" target="_blank">{pollurl}</a>
';
$string['pollurltextsubject'] = 'Bitte nehmen Sie an der Umfrage teil';
$string['populatefromtemplate'] = 'Mit Vorlage ausfüllen';
$string['potentialsubscribers'] = 'Mögliche Nutzer:innen';
$string['prepareimport'] = "Bereite den Import vor";
$string['presence'] = "Anwesenheitsstatus";
$string['presencechanged'] = 'Anwesenheitsstatus geändert';
$string['presencechangedhistory'] = 'Die Anwesenheit wurde von "{$a->presenceold}" zu "{$a->presencenew}" geändert.';
$string['presencechangedinfo'] = 'Die Anwesenheit von {$a->relateduser} wurde von "{$a->presenceold}" zu "{$a->presencenew}" geändert.';
$string['presencecount'] = 'Anzahl der Anwesenheiten';
$string['presenceoptions'] = "Möglicher Anwesenheitsstatus";
$string['presenceoptions_desc'] = "Welcher Status soll zur Verfügung stehen?";
$string['presencestatustoissuecertificate'] = 'Zertifikatsausstellung mit Anwesenheitsstatus <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['presencestatustoissuecertificate_desc'] = "Wenn aktiviert, kann ein Zertifikat NUR mit dem ausgewählten Anwesenheitsstatus ausgestellt werden. Der Abschluss der Buchungsoption hat dann keine Auswirkung mehr.";
$string['previous'] = 'Vorherige';
$string['price'] = 'Preis';
$string['pricecategories'] = 'Buchung: Preiskategorien';
$string['pricecategoriessaved'] = 'Preiskategorien wurden gespeichert';
$string['pricecategoriessubtitle'] = '<p>Hier können Sie unterschiedliche Kategorien von Preisen definieren,
    z.B. eigene Preiskategorien für Studierende, Mitarbeitende oder Externe.
    <b>Achtung:</b> Sobald Sie eine Kategorie erstellt haben, können Sie diese nicht mehr löschen.
    Sie können Kategorien aber umbenennen oder deaktivieren.</p>';
$string['pricecategory'] = 'Preiskategorie';
$string['pricecategorychanged'] = 'Preiskategorie geändert';
$string['pricecategorychoosehighest'] = 'Höchst sortierte Preiskategorie wird zuerst gewählt';
$string['pricecategorychoosehighest_desc'] = 'Hat ein/e Nutzer:in mehrere Preiskategorie-Identifier in seinem Userprofil hinterlegt, wird die am höchsten gereihte Preiskategorie zuerst gewählt. Standard ist die niedrigste.';
$string['pricecategoryfallback'] = 'Nutze standard Preiskategorie als Fallback';
$string['pricecategoryfallback_desc'] = 'Nutze default Preiskategorie wenn keine passende Kategorie gefunden wurde';
$string['pricecategoryfield'] = 'Nutzerprofilfeld für die Preiskategorie';
$string['pricecategoryfielddesc'] = 'Wählen Sie ein Nutzerprofilfeld aus, in dem für jede/n Nutzer/in der Identifikator der Preiskategorie gesichert wird.';
$string['pricecategoryidentifier'] = 'Identifikator der Preiskategorie';
$string['pricecategoryidentifier_help'] = 'Geben Sie einen Kurztext ein mit dem die Preiskategorie identifiziert werden soll, z.B. "stud" oder "akad".';
$string['pricecategoryname'] = 'Bezeichnung der Preiskategorie';
$string['pricecategoryname_help'] = 'Geben Sie den Namen der Preiskategorie ein, der in Buchungsoptionen angezeigt wird, z.B. "Akademikerpreis".';
$string['pricecatsortorder'] = 'Sortierung (Zahl)';
$string['pricecatsortorder_help'] = 'Geben Sie eine ganze Zahl ein. "1" bedeutet, dass die Kategorie auf Platz 1 angezeigt wird, "2" an zweiter Stelle usw.';
$string['pricecurrency'] = 'Währung';
$string['pricefactor'] = 'Preisfaktor';
$string['pricefactor_help'] = 'Geben Sie einen Wert an, mit dem der Preis multipliziert werden soll. Um die Preise beispielsweise um 20% zu vergünstigen, geben Sie den Wert 0,8 ein.';
$string['priceformulaadd'] = 'Absolutwert';
$string['priceformulaadd_help'] = 'Zusätzlicher Wert, der zum Ergebnis <strong>addiert</strong> werden soll.';
$string['priceformulaheader'] = 'Preisformel <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['priceformulaheader_desc'] = "Eine Preisformel verwenden, um Preise automatisch berechnen zu können.";
$string['priceformulainfo'] = '<a data-toggle="collapse" href="#priceformula" role="button" aria-expanded="false" aria-controls="priceformula">
<i class="fa fa-code"></i> Preisformel-JSON anzeigen...
</a>
<div class="collapse" id="priceformula">
<samp>{$a->formula}</samp>
</div><br>
<a href="{$a->url}" target="_blank"><i class="fa fa-edit"></i> Formel bearbeiten...</a><br><br>
Unterhalb können Sie zusätzlich einen manuellen Faktor (Multiplikation) und einen Absolutwert (Addition) hinzufügen.';
$string['priceformulaisactive'] = 'Beim Speichern Preise mit Preisformel neu berechnen (aktuelle Preise werden überschrieben).';
$string['priceformulamultiply'] = 'Manueller Faktor';
$string['priceformulamultiply_help'] = 'Zusätzlicher Wert mit dem das Ergebnis <strong>multipliziert</strong> werden soll.';
$string['priceformulaoff'] = 'Neuberechnung der Preise verhindern';
$string['priceformulaoff_help'] = 'Aktivieren Sie diese Option, um zu verhindern, dass die Funktion "Alle Preise der Instanz mit Formel neu berechnen"
 die Preise für diese Buchungsoption neu berechnet.';
$string['priceisalwayson'] = 'Preise immer aktiviert';
$string['priceisalwayson_desc'] = 'Wenn Sie dieses Häkchen aktivieren, können Preise für einzelne Buchungsoptionen NICHT abgeschalten werden.
 Es ist aber dennoch möglich, 0 EUR als Preis einzustellen.';
$string['problemsofcohortorgroupbooking'] = '<br><p>Es konnten nicht alle Buchungen durchgeführt werden:</p>
<ul>
<li>{$a->notenrolledusers} Nutzer:innen sind nicht in den Kurs eingeschrieben</li>
<li>{$a->notsubscribedusers} Nutzer:innen konnten aus anderen Gründen nicht gebucht werden</li>
</ul>';
$string['problemwithdate'] = 'Bitte die Daten überprüfen';
$string['profeatures:appearance'] = '<ul>
<li><b>Wunderbyte Logo und Link ausblenden</b></li>
<li><b>Beschreibungen einklappen</b></li>
<li><b>Terminanzeige einklappen</b></li>
<li><b>Modale (Fenster) ausschalten</b></li>
<li><b>Optionen für Anwesenheitsstatus</b></li>
</ul>';
$string['profeatures:automaticcoursecreation'] = '<ul>
<li><b>Benutzerdefiniertes Buchungsoptionfeld, das als Kurskategorie von automatisch erstellten Kursen verwendet werden soll</b></li>
<li><b>Markieren Sie den Kurs mit Tags, um ihn als Vorlage zu verwenden</b></li>
</ul>';
$string['profeatures:availabilityinfotexts'] = '<ul>
<li><b>Beschreibungstexte für verfügbare Buchungsplätze anzeigen</b></li>
<li><b>Aktivierung der Meldung „Nur wenige Plätze verfügbar“</b></li>
<li><b>Beschreibungstexte für verfügbare Wartelistenplätze anzeigen</b></li>
<li><b>Aktivierung der Meldung „Wenige Plätze auf der Warteliste“</b></li>
<li><b>Platz auf der Warteliste anzeigen</b></li>
</ul>';
$string['profeatures:boactions'] = '<ul>
<li><b>Aktionen nach der Buchung aktivieren</b></li>
</ul>';
$string['profeatures:bookingstracker'] = '<ul>
<li><b>Benutzer:innen erlauben, die Buchungen der gesamten Seite auf verschiedenen hierarchischen Buchungsebenen
(Termin, Buchungsoption, Buchungsinstanz, Moodle-Kurs, gesamte Plattform) zu verwalten
und für gebuchte Benutzer:innen die Anwesenheiten zu hinterlegen.</b></li>
<li><b>Anwesenheiten zählen - Sie können bei jedem Termin einzeln angeben, wer anwesend war.</b></li>
<li><b>Wählen Sie selbst den Anwesenheitsstatus, der gezählt werden soll.</b></li>
</ul>';
$string['profeatures:cachesettings'] = '<ul>
<li><b>Kein Caching der Buchungsoptions-Einstellungen</b></li>
<li><b>Kein Caching der Buchungsantworten (Buchungen)</b></li>
</ul>';
$string['profeatures:cancellationsettings'] = '<ul>
<li><b>Veränderbare Stornierungsfrist</b></li>
<li><b>Stornierungs Cool Off Period (Sekunden)</b></li>
</ul>';
$string['profeatures:duplicationrestoreoption'] = '<ul>
<li><b>Moodle-Kurs duplizieren, wenn eine Buchungsoption dupliziert wird</b></li>
</ul>';
$string['profeatures:overbooking'] = '<ul>
<li><b>Überbuchen erlauben</b></li>
</ul>';
$string['profeatures:priceformula'] = '<ul>
<li><b>Eine Preisformel verwenden, um Preise automatisch berechnen zu können</b></li>
<li><b>Einheitenfaktor anwenden</b></li>
<li><b>Preise runden (Preisformel)</b></li>
</ul>';
$string['profeatures:progressbars'] = '<ul>
<li><b>Fortschrittsbalken für bereits vergangene Zeit anzeigen</b></li>
<li><b>Fortschrittsbalken können ausgeklappt werden</b></li>
</ul>';
$string['profeatures:selflearningcourse'] = '<ul>
<li><b>Buchungsoptionen mit fixer Dauer aktivieren (z.B. für Selbstlernkurse)</b></li>
<li><b>Benutzerdefinierten Namen vergeben (z.B. "Selbstlernkurs")</b></li>
</ul>';
$string['profeatures:shortcodes'] = '<ul>
<li><b>Shortcodes verwenden, um Buchungsoptionen auf beliebigen Seiten anzuzeigen</b></li>
</ul>';
$string['profeatures:subbookings'] = '<ul>
<li><b>Zusatzbuchungen aktivieren</b></li>
</ul>';
$string['profeatures:tabwhatsnew'] = '<ul>
<li><b>Eigener Tab für kürzlich sichtbar geschaltene (oder neu veröffentlichte) Buchungsoptionen</b></li>
<li><b>Anzahl der Tage, wie lange eine Buchungsoption als "neu" gilt, kann eingestellt werden</b></li>
<li><b>Tab kann individuell benannt werden.</b></li>
</ul>';
$string['profeatures:teachers'] = '<ul>
<li><b>Fügen Sie Links zu Trainer:innen-Seiten hinzu</b></li>
<li><b>Einloggen für Trainer:innen-Seiten nicht notwendig</b></li>
<li><b>Allen Nutzer:innen werden immer die E-Mail-Adressen der Trainer:innen angezeigt</b></li>
<li><b>E-Mail-Adressen von Trainer:innen, bei denen gebucht wurde, anzeigen</b></li>
<li><b>Trainer:innen können mit ihrem eigenen E-Mail-Client E-Mails an gebuchte Nutzer:innen senden</b></li>
<li><b>Rolle für Trainer:innen einer Buchungsoption festlegen</b></li>
</ul>';
$string['profeatures:unenroluserswithoutaccess'] = '<ul>
<li><b>Buchungen von Nutzer:innen löschen, die keinen Zugang zum Kurs mehr haben, in dem sich die Buchung befindet.</b></li>
</ul>';
$string['profilepicture'] = 'Profilbild';
$string['progressbars'] = 'Fortschrittsbalken für bereits vergangene Zeit <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['progressbars_desc'] = 'Mit diesem Feature erhalten Sie eine visuelle Darstellung der bereits vergangenen Zeit von Buchungsoptionen.';
$string['progressbarscollapsible'] = 'Fortschrittsbalken können ausgeklappt werden';
$string['prolicensefeatures'] = 'Sie benötigen Booking PRO, um dieses Feature nutzen zu können. Durch den Kauf einer Pro-Lizenz können Sie die folgenden Funktionen nutzen:';
$string['proversion:extraviews'] = 'Mit Booking PRO können Sie weitere Ansichten aktivieren (z.B. Kartenansicht oder Listanansicht mit Bildern).';
$string['proversiononly'] = 'Nur in der PRO-Version verfügbar.';
$string['qrenrollink'] = "QR Code von Einschreibelink";
$string['qrid'] = "QR Code von Id";
$string['qrusername'] = "QR Code von Nutzer/innenname";
$string['question'] = "Frage";
$string['ratings'] = 'Bewertung der Buchungsoption';
$string['ratingsuccessful'] = 'Die Bewertungen wurden erfolgreich aktualisiert';
$string['reallydeleteaction'] = 'Wirklich löschen?';
$string['reason'] = 'Grund';
$string['recalculateall'] = 'Alle Preise der Instanz mit Formel neu berechnen';
$string['recalculateprices'] = 'Preise mit Formel neu berechnen';
$string['recommendedin'] = "Shortcode um Buchungsoptionen in bestimmten Kursen zu empfehlen.
 Legen Sie ein neues benutzerdefiniertes Feld für Buchungsoptionen mit dem Kurznamen 'recommendedin' an.
 In einer Buchungsoption setzen Sie nun den Wert dieses Feldes auf 'course1', wenn Sie die Buchungsoption
 im Course 1 (course1) empfehlen wollen.";
$string['recordsimported'] = 'Buchungsoptionen importiert via CSV';
$string['recordsimporteddescription'] = '{$a} Buchungsoptionen importiert via CSV';
$string['recreategroup'] = 'Gruppe erneut anlegen und Nutzer:innen der Gruppe zuordnen';
$string['recurringactioninfo'] = 'Diese Aktion wird ausgeführt, wenn Sie das Formular absenden (indem Sie auf "Speichern" klicken). <b>Achtung!</b> Diese Aktion kann nicht rückgängig gemacht werden.';
$string['recurringchildoptions'] = 'Abgeleitete Buchungsoptionen dieser Buchungsoption:';
$string['recurringheader'] = '<i class="fa fa-fw fa-repeat" aria-hidden="true"></i>&nbsp;Wiederkehrende Optionen';
$string['recurringmultiparenting'] = 'Wiederholende Optionen von selber Vorlage erzeugen';
$string['recurringmultiparenting_desc'] = 'Wenn eine Buchungsoptions bereits Vorlage für folgende Optionen ist, soll es möglich sein, aus ihrer Grundlage noch weitere zu generieren?';
$string['recurringnotpossibleinfo'] = '<div class="alert alert-info" role="alert">
    Für diese Buchungsoption können keine Wiederkehrenden Optionen erstellt werden, weil sie selbst von einer anderen Buchungsoption abgeleitet ist.
    </div>';
$string['recurringoptions'] = 'Wiederkehrende Buchungs Optionen';
$string['recurringparentoption'] = 'Vorlage dieser Buchungsoption:';
$string['recurringsameparentoptions'] = 'Buchungsoption(en) mit gleicher Vorlage:';
$string['recurringsaveinfo'] = '<div class="alert alert-info" role="alert">
                                <strong>Achtung:</strong> Bitte speichern Sie allfällige Änderungen bevor Sie wiederkehrende Buchungsoption anlegen. Ihre Änderungen werden sonst in den neuen Buchungen nicht übernommen.
                                </div>';
$string['recurringselectapplysiblings'] = 'Sollen diese Änderungen auch für alle folgenden Buchungsoptionen mit der gleichen Vorlage übernommen werden?';
$string['recurringsettingsheader'] = 'Wiederkehrende Buchungsoptionen <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['recurringsettingsheader_desc'] = 'Einstellungen für Wiederkehrende Optionen und ihre Vorlagen';
$string['redirectonlogintocourse'] = 'Weiterleitung von nicht eingeloggten Nutzern zum Kurs';
$string['redirectonlogintocourse_desc'] = 'Falls aktiviert, werden Nutzer nach dem Login zum gebuchten Kurs weitergeleitet, anstatt zur Buchungsdetailseite.';
$string['relatedcourseidneeded'] = 'Aufgrund Ihrer Verfügbarkeits-Einstellungen muss ein verknüpfter Moodle-Kurs angegeben werden.';
$string['reminder1sent'] = 'Erste Benachrichtigung versendet';
$string['reminder2sent'] = 'Zweite Benachrichtigung versendet';
$string['reminderteachersent'] = 'Benachrichtigung an Trainer:in versendet';
$string['removeafterminutes'] = 'Aktivitätsabschluss nach N Minuten entfernen';
$string['removeresponses'] = 'Alle Buchungen löschen';
$string['removeuseronunenrol'] = 'Nutzer/in von Buchungsoption autom. entfernen wenn diese/r aus dem dazugehörenden Moodle-Kurs ausgetragen wurde?';
$string['reoccurringdatestring'] = 'Wochentag, Start- und Endzeit (Tag, HH:MM - HH:MM)';
$string['reoccurringdatestring_help'] = 'Geben Sie einen Text in folgendem Format ein:
    "Tag, HH:MM - HH:MM", z.B. "Montag, 10:00 - 11:00" oder "So 09:00-10:00" oder "Block" bzw. "Blockveranstaltung.';
$string['reoccurringdatestringerror'] = 'Geben Sie einen Text in folgendem Format ein:
    Tag, HH:MM - HH:MM oder "Block" bzw. "Blockveranstaltung."';
$string['report2labelcourse'] = 'Moodle-Kurs';
$string['report2labelinstance'] = 'Buchungsinstanz';
$string['report2labeloption'] = 'Buchungsoption';
$string['report2labeloptiondate'] = 'Termin';
$string['report2labelsystem'] = 'Gesamte Seite';
$string['reportfields'] = 'Felder reportieren';
$string['reportremindermessage'] = '{bookingdetails}';
$string['reportremindersubject'] = 'Erinnerung: Ihr gebuchter Kurs';
$string['requirepreviousoptionstobebooked'] = 'Einschränkung aktivieren: Vorangegangene Buchungsoption muss gebucht sein, damit die folgende buchbar wird.';
$string['reserveddeleted'] = 'Reservierte Nutzer:in gelöscht';
$string['reservedusers'] = 'Kurzfristige Reservierungen';
$string['reset'] = 'Zurücksetzen';
$string['responses'] = 'Buchungen';
$string['responsesfields'] = 'Felder in der Teilnehmer:innen-Liste';
$string['responsesto'] = 'Buchungen zu {$a} ';
$string['responsible'] = 'Zuständig';
$string['responsiblecontact'] = 'Zuständige Kontaktperson(en)';
$string['responsiblecontact_help'] = 'Geben Sie eine zuständige Kontaktperson(en) an. Dies sollte jemand anderer als der/die Lehrer/in sein.';
$string['responsiblecontactcanedit'] = 'Kontaktpersonen das Editieren erlauben';
$string['responsiblecontactcanedit_desc'] = 'Aktivieren Sie diese Einstellung, um es Kontaktpersonen zu erlauben,
die Buchungsoptionen, bei denen Sie eingetragen sind, zu editieren und Teilnehmer:innen-Listen einzusehen.<br>
<b>Wichtig:</b> Die Kontaktperson braucht zusätzlich das Recht <b>mod/booking:addeditownoption</b>.';
$string['responsiblecontactenroltocourse'] = 'Kontaktperson in verbundenen Moodle-Kurs einschreiben';
$string['responsiblecontactenroltocourse_desc'] = 'Bitte definieren Sie auch die Rolle, die die Kontaktperson im verbundenen Moodle-Kurs haben soll.';
$string['restresponse'] = "rest_response";
$string['restrictanswerperiodclosing'] = 'Buchen nur bis zu einem bestimmten Zeitpunkt ermöglichen';
$string['restrictanswerperiodopening'] = 'Buchen erst ab einem bestimmten Zeitpunkt ermöglichen';
$string['restrictavailabilityforinstance'] = 'Verfügbarkeit von Buchungsinstanzen auf Buchungsoptionen anwenden';
$string['restrictavailabilityforinstance_desc'] = 'Wenn Sie dieses Feature aktivieren und ihre Buchungsinstanz nur unter bestimmten Voraussetzungen verfügbar ist,
werden diese Voraussetzungen auch auf die in der Buchungsinstanz enthaltenen Buchungsoptionen angewendet (dies kann z.B. hilfreich sein, wenn Sie Shortcodes wie [courselist] verwenden).';
$string['restscriptexecuted'] = 'Nach dem Rest-Skript Aufruf';
$string['restscriptfailed'] = 'Skript konnte nicht ausgeführt werden';
$string['restscriptsuccess'] = 'Rest Skript Ausführung';
$string['resultofcohortorgroupbooking'] = '<p>Die Buchung der globalen Gruppen hat folgendes Ergebnis gebracht:</p>
<ul>
<li>{$a->sumcohortmembers} Nutzer:innen in den ausgewählten globalen Gruppen gefunden</li>
<li>{$a->sumgroupmembers} Nutzer:innen in den ausgewählten Kursgruppen gefunden</li>
<li>{$a->subscribedusers} Nutzer:innen wurden erfolgreich für die Option gebucht</li>
</ul>';
$string['returnurl'] = "Adresse für Rückkehr";
$string['reviewed'] = 'Kontrolliert';
$string['rootcategory'] = 'Übergeordnete Kategorie';
$string['roundpricesafterformula'] = 'Preise runden (Preisformel)';
$string['roundpricesafterformula_desc'] = 'Preise auf ganze Zahlen runden (mathematisch), nachdem die <strong>Preisformel</strong> angewandt wurde.';
$string['rowupdated'] = 'Zeile wurde aktualisiert.';
$string['rulecustomprofilefield'] = 'Benutzerdefiniertes User-Profilfeld';
$string['ruledatefield'] = 'Datumsfeld';
$string['ruledays'] = 'Anzahl Tage';
$string['ruledaysbefore'] = 'Reagiere n Tage vor/nach einem bestimmtem Datum';
$string['ruledaysbefore_desc'] = 'Wählen Sie die Anzahl der Tage in Bezug zu einem gewissen Datum einer Buchungsoption aus.';
$string['ruleevent'] = 'Event';
$string['ruleeventcondition'] = 'Führe aus wenn...';
$string['rulemailtemplate'] = 'E-Mail-Vorlage';
$string['rulename'] = "Eigener Name der Regel";
$string['ruleoperator'] = 'Operator';
$string['ruleoptionfield'] = 'Buchungsoptionsfeld, das verglichen werden soll';
$string['ruleoptionfieldaddress'] = 'Adresse (address)';
$string['ruleoptionfieldbookingclosingtime'] = 'Ende der erlaubten Buchungsperiode (bookingclosingtime)';
$string['ruleoptionfieldbookingopeningtime'] = 'Beginn der erlaubten Buchungsperiode (bookingopeningtime)';
$string['ruleoptionfieldcourseendtime'] = 'Ende (courseendtime)';
$string['ruleoptionfieldcoursestarttime'] = 'Beginn (coursestarttime)';
$string['ruleoptionfieldlocation'] = 'Ort (location)';
$string['ruleoptionfieldoptiondatestarttime'] = 'Beginn eines jeden Termins';
$string['ruleoptionfieldselflearningcourseenddate'] = 'Enddatum eines Selbstlernkurses';
$string['ruleoptionfieldtext'] = 'Name der Buchungsoption (text)';
$string['rulereactonchangeevent_desc'] = 'Für das "Buchungsoption aktualisiert" Event können Sie Ihre Einstellungen hier ändern: <a href="{$a}">Einstellungen</a>';
$string['rulereactonevent'] = 'Reagiere auf Ereignis';
$string['rulereactonevent_desc'] = 'Wählen Sie ein Ereignis aus, durch das die Regel ausgelöst werden soll.<br>
<b>Tipp:</b> Verwenden Sie den Platzhalter <code>{eventdescription}</code> um eine Beschreibung des Ereignisses anzuzeigen.';
$string['rulereactoneventaftercompletion'] = "Anzahl der Tage nach dem Ende der Buchungsoption, in denen die Regel weiterhin gilt";
$string['rulereactoneventaftercompletion_help'] = "Feld leer lassen oder auf 0 setzen, wenn die Aktion unbegrenzt gelten soll. Sie können negative Zahlen eingeben, damit die Regel bereits vor dem Kursende ausgesetzt wird.";
$string['rulereactoneventcancelrules'] = 'Diese Regel aussetzen';
$string['rulesendmailcpf'] = '[Vorschau] E-Mail versenden an User:in mit benutzerdefiniertem Feld';
$string['rulesendmailcpf_desc'] = 'Wählen Sie ein Event aus, auf das reagiert werden soll. Legen Sie eine E-Mail-Vorlage an
(Sie können auch Platzhalter wie {bookingdetails} verwenden) und legen Sie fest, an welche Nutzer:innen die E-Mail versendet werden soll.
Beispiel: Alle Nutzer:innen, die im benutzerdefinierten Feld "Studienzentrumsleitung" den Wert "SZL Wien" stehen haben.';
$string['rulesheader'] = '<i class="fa fa-fw fa-pencil-square" aria-hidden="true"></i>&nbsp; Regeln';
$string['rulesincontextglobalheader'] = '<a href="{$a}" target="_blank">Globale Regeln</a>';
$string['rulesincontextheader'] = '<a href="{$a->rulesincontexturl}" target="_blank">Regeln in Buchungsinstanz "{$a->bookingname}"</a>';
$string['rulesnotfound'] = 'Keine Regeln für diese Buchungsoption gefunden';
$string['rulessettings'] = "Einstellungen für Regeln";
$string['rulessettingsdesc'] = 'Einstellungen, die für die <a href="{$a}">Funktion Buchungs Regeln</a> gelten.';
$string['ruletemplatebookingoptioncompleted'] = "Template - Buchungsoption abgeschlossen mit Umfrage";
$string['ruletemplatebookingoptioncompletedbody'] = "Sie haben die folgende Buchungsoption abgeschlossen:<br>{bookingdetails}<br> Bitte nehmen Sie an der Umfrage teil:<br><br>Link zur Umfrage: {pollurl} <br> Zum Kurs: {courselink}<br>Alle Buchungsoptionen ansehen: {bookinglink}";
$string['ruletemplatebookingoptioncompletedsubject'] = "Buchungsoption abgeschlossen";
$string['ruletemplateconfirmbooking'] = "Template - Bestätige Buchung";
$string['ruletemplateconfirmbookingbody'] = "Sehr geehrte/r {firstname} {lastname},<br>Vielen Dank für Ihre Buchung<br>{bookingdetails}<br>Alles Gute!";
$string['ruletemplateconfirmbookingsubject'] = "Sie haben erfolgreich gebucht";
$string['ruletemplateconfirmwaitinglist'] = "Template - Bestätigung Wartelistenplatz";
$string['ruletemplateconfirmwaitinglistbody'] = "Sehr geehrte/r {firstname} {lastname},<br>Sie befinden sich auf der Warteliste<br>{bookingdetails}<br>Alles Gute!";
$string['ruletemplateconfirmwaitinglistsubject'] = "Sie befinden sich auf der Warteliste";
$string['ruletemplatecourseupdate'] = "Template - Update";
$string['ruletemplatecourseupdatebody'] = "Das ist neu:<br>{changes}<br>Klicken Sie auf den folgenden Link um die Änderung(en) und eine Übersicht über alle Buchungen zu sehen: {bookinglink}";
$string['ruletemplatecourseupdatesubject'] = "Ihre Buchung \"{title}\" hat sich geändert.";
$string['ruletemplatedaysbefore'] = "Template - Benachrichtigung n Tage vor Beginn";
$string['ruletemplatedaysbeforebody'] = "Ihre Buchung startet in einigen Tagen:<br>{bookingdetails} <br> Um eine Übersicht über alle Buchungen zu erhalten, klicken Sie auf den folgenden Link: {bookinglink}<br> Hier geht's zum Kurs: {courselink}";
$string['ruletemplatedaysbeforesubject'] = "Ihr Kurs fängt in einigen Tagen an";
$string['ruletemplatepaymentconfirmation'] = "Template - Zahlung der Buchung bestätigt";
$string['ruletemplatepaymentconfirmationbody'] = "Vielen Dank für Ihre Buchung!<br>Ihre Buchung {Title} mit dem Preis: {price} wurde erfolgreich gebucht.<br>Hier ist der der Bestätigungslink:<br>{bookingconfirmationlink}<br>Hier geht's zum Kurs:<br>{courselink}<br>Mit freundlichen Grüßen";
$string['ruletemplatepaymentconfirmationsubject'] = "Zahlung von {Title} bestätigt";
$string['ruletemplatesessionreminders'] = 'Template - E-Mail vor jedem Termin';
$string['ruletemplatesessionremindersbody'] = 'Guten Tag {firstname} {lastname},<br>der nächste Termin von "{title}" startet bald:<br><br>{bookingdetails}';
$string['ruletemplatesessionreminderssubject'] = 'Ein neuer Termin von "{Title}" startet bald';
$string['ruletemplatetrainercancellation'] = "Template - Absage Buchungsoption - Mail an Trainer/innen";
$string['ruletemplatetrainercancellationbody'] = "Guten Tag {firstname} {lastname},<br>leider musste folgende Veranstaltung abgesagt werden:<br>Veranstaltung: {Title}<br>Mit freundlichen Grüßen";
$string['ruletemplatetrainercancellationsubject'] = "Absage von {Title}";
$string['ruletemplatetrainerpoll'] = "Template - Trainer/innen Umfrage n Tage nach Ende";
$string['ruletemplatetrainerpollbody'] = "Bitte nehmen Sie an der Umfrage teil. <br><br>Link zur Umfrage: {pollurlteachers}";
$string['ruletemplatetrainerpollsubject'] = "Umfrage";
$string['ruletemplatetrainerreminder'] = "Template - Trainer/innen Benachrichtigung n Tage vor Beginn";
$string['ruletemplatetrainerreminderbody'] = "Ihre Kurs startet in einigen Tagen:<br>{bookingdetails}<br>Sie haben {numberparticipants} gebuchte Teilnehmer:innen und {numberwaitinglist} Personen auf der Warteliste.<br>Um eine Übersicht über alle Buchungen zu erhalten, klicken Sie auf den folgenden Link:<br>{bookinglink}<br>Hier geht's zum Kurs: {courselink}";
$string['ruletemplatetrainerremindersubject'] = "Ihr Kurs startet in einigen Tagen";
$string['ruletemplateusercancellation'] = "Template - Absage Buchungsoption - Mail an Teilnehmer/innen";
$string['ruletemplateusercancellationbody'] = "Guten Tag {firstname} {lastname},<br>leider musste folgende Veranstaltung abgesagt werden:<br>Veranstaltung: {Title}<br>Mit freundlichen Grüßen";
$string['ruletemplateusercancellationsubject'] = "Absage von {Title}";
$string['ruletemplateuserpoll'] = "Template - Teilnehmer/innen Umfrage n Tage nach Ende";
$string['ruletemplateuserpollbody'] = "Bitte nehmen Sie an der Umfrage teil:<br>Link zur Umfrage: {pollurl}";
$string['ruletemplateuserpollsubject'] = "Umfrage";
$string['ruletemplateuserstorno'] = "Template - Teilnehmer/innen Storno";
$string['ruletemplateuserstornobody'] = "Hallo {participant},<br>Sie wurden erfolgreich von {title} abgemeldet.";
$string['ruletemplateuserstornosubject'] = "Stornierung";
$string['rulevalue'] = 'Wert';
$string['sameday'] = 'Selber Tag';
$string['saturday'] = 'Samstag';
$string['save'] = 'Speichern';
$string['saveinstanceastemplate'] = 'Buchung als Vorlage hinzufügen';
$string['savenewtagtemplate'] = 'Speichern';
$string['sccartdescription'] = "Beschreibung im Shopping Cart";
$string['sccartdescription_desc'] = "Beschreibung, die im Shopping Cart angezeigt wird. Felder der Buchungsoption können mit Platzhaltern eingefügt werden, z.B. {location}";
$string['scgfbookgroupscohorts'] = 'Globale Gruppe(n) oder Gruppe(n) buchen';
$string['scgfcohortheader'] = 'Globale Gruppe (Kohorte) buchen';
$string['scgfgroupheader'] = 'Gruppe aus dem Kurs buchen';
$string['scgfselectcohorts'] = 'Globale Gruppe(n) wählen';
$string['scgfselectgroups'] = 'Gruppe(n) auswählen';
$string['sch_allowinstallment'] = 'Ratenzahlung erlauben';
$string['sch_allowrebooking'] = 'Umbuchen erlauben';
$string['screstoreitemfromreserved'] = 'Reservierte Items automatisch in den Warenkorb legen';
$string['screstoreitemfromreserved_desc'] = 'Dadurch werden Artikel nach dem Löschen des Caches wieder automatisch in den Warenkorb der Nutzer:innen gelegt';
$string['search'] = 'Suche...';
$string['searchdate'] = 'Datum';
$string['searchname'] = 'Vorname';
$string['searchsurname'] = 'Nachname';
$string['searchtag'] = 'Schlagwortsuche';
$string['select'] = "DropDown Menü";
$string['selectanoption'] = 'Wählen Sie eine Buchungsoption aus!';
$string['selectatleastoneuser'] = 'Mindestens 1 Nutzer/in auswählen!';
$string['selectboactiontype'] = 'Wähle Aktion nach der Buchung';
$string['selectcategory'] = 'Übergeordnete Kategorie auswählen';
$string['selected'] = 'Ausgewählt';
$string['selectelective'] = 'Wahlfach für {$a} Credits auswählen';
$string['selectfieldofbookingoption'] = 'Bereich der Buchungsoption auswählen';
$string['selectoptionid'] = 'Eine Auswahl treffen';
$string['selectoptioninotherbooking'] = "Auswahl";
$string['selectoptionsfirst'] = "Bitte zuerst die Buchungsoptionen auswählen.";
$string['selectstudentinbo'] = "Wähle Nutzer:innen einer Buchungsoption";
$string['selectteacherinbo'] = "Wähle Trainer:innen einer Buchungsoption";
$string['selectteacherswithprofilefieldonly'] = 'Trainer:innen-Auswahl einschränken';
$string['selectteacherswithprofilefieldonlydesc'] = 'Nur Benutzer:innen, mit einem bestimmten Wert in einem definierten Nutzerprofilfeld können als Trainer:innen ausgewählt werden.<br>
<span class="text-danger">Hinweis: <b>Speichern und Seite neu laden</b>, um das Profilfeld zu wählen und den Wert anzugeben.</span>';
$string['selectteacherswithprofilefieldonlyfield'] = '⤷ Nutzerprofilfeld für Trainer:innen wählen';
$string['selectteacherswithprofilefieldonlyvalue'] = '⤷ Wert';
$string['selectteacherswithprofilefieldonlyvaluedesc'] = 'Geben Sie entweder den exakten Wert oder eine Bestich-getrennte Liste an Werten ein';
$string['selectuserfromevent'] = "Wähle Nutzer:in vom Ereignis";
$string['selectusers'] = "Wähle Nutzer:innen ohne direkte Verbindung zur Buchungsoption";
$string['selectusersfromuserfieldofeventuser'] = "Wähle Nutzer:in aus Profilfeld von Person des Events";
$string['selectusershoppingcart'] = "Wähle Nutzer:in die Ratenzahlung zu leisten hat";
$string['selflearncoursesall'] = "Alle anzeigen";
$string['selflearncoursesnotdisplayed'] = "Keine anzeigen";
$string['selflearncoursessortingdateinfuture'] = "Sortierdatum in der Zukunft";
$string['selflearningcourse'] = 'Selbstlernkurs';
$string['selflearningcourse_help'] = 'Buchungsoptionen vom Typ "{$a}" haben eine fixe Dauer, aber keine fixen Termine. Der Kurs beginnt sobald er gebucht wurde.';
$string['selflearningcourseactive'] = 'Buchungsoptionen mit fixer Dauer aktivieren';
$string['selflearningcoursealert'] = 'Wenn ein Moodle-Kurs verbunden ist, dann werden bei Buchungsoptionen vom Typ "{$a}" die Benutzer:innen immer <b>direkt nach der Buchung</b> eingeschrieben. Die angegebene Dauer legt fest, wie lange der:die Benutzer:in im Kurs eingeschrieben bleibt.<br><br> <b>Achtung:</b> Sie können keine Termine angeben, jedoch ein <b>Sortierdatum</b> (im Abschnitt "Termine"), das für die Sortierung verwendet wird.';
$string['selflearningcoursecoursestarttime'] = 'Sortierdatum';
$string['selflearningcoursecoursestarttime_help'] = 'Dieses Datum wird ausschließlich für die Sortierung verwendet, da Buchungsoptionen vom Typ "{$a}" kein fixes Startdatum haben.';
$string['selflearningcoursecoursestarttimealert'] = 'Da Sie unter "Moodle-Kurs" die Option "{$a}" gewählt haben, können Sie hier keine Termine angeben, sondern nur ein Sortierdatum.';
$string['selflearningcoursedisplayinshortcode'] = 'Welche Selbstlernkurse sollen in Shortcodes mit zeitlicher Beschränkung angezeigt werden';
$string['selflearningcoursedisplayinshortcodedesc'] = 'Einige der pluginspezifischen Shortcodes beinhalten die Möglichkeit auf Buchungsoptionen zu filtern, die in der Zukunft liegen. Sollen in diesem Fall alle, keine oder nur Selbstlernkurse zukünftigem Sortierdatum angezeigt werden?';
$string['selflearningcoursedurationinfo'] = 'Dieser Kurs ist {$a} lang verfügbar.';
$string['selflearningcoursehideduration'] = 'Dauer für Selbstlernkurse ausblenden';
$string['selflearningcourselabel'] = 'Bezeichnung für Buchungsoptionen mit fixer Dauer';
$string['selflearningcourselabeldesc'] = 'Buchungsoptionen mit fixer Dauer, aber ohne Termine, haben die Standardbezeichnung "Selbstlernkurs". Sie können hier einen beliebigen anderen Namen für diesen Typ von Buchungsoptionen vergeben.';
$string['selflearningcourseplaceholder'] = 'Der Kurs/Das Angebot steht Ihnen ab sofort zur Verfügung.';
$string['selflearningcourseplaceholderduration'] = 'Sie haben noch {$a} Zugang.';
$string['selflearningcourseplaceholderdurationexpired'] = 'Sie haben keinen Zugang mehr.';
$string['selflearningcoursesettingsheader'] = 'Buchungsoptionen mit fixer Dauer <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['selflearningcoursesettingsheaderdesc'] = 'Dieses Feature erlaubt es Ihnen Buchungsoptionen ohne Termine, jedoch mit einer fixen Dauer anzulegen. Die Benutzer:innen werden bei der Buchung für die festgelegte Dauer in den verknüpften Moodle-Kurs eingeschrieben.';
$string['selflearningcoursetimeremaininginfo'] = 'Sie haben noch {$a} Zugriff auf diesen Kurs.';
$string['selflearningcoursetimeremaininginfoexpired'] = 'Sie haben keinen Zugang mehr.';
$string['semester'] = 'Semester';
$string['semesterend'] = 'Semesterende';
$string['semesterend_help'] = 'An welchem Tag endet das Semester?';
$string['semesterid'] = 'SemesterID';
$string['semesteridentifier'] = 'Identifikator';
$string['semesteridentifier_help'] = 'Kurztext zur Identifikation des Semesters, z.B. "ws22".';
$string['semestername'] = 'Bezeichnung';
$string['semestername_help'] = 'Geben Sie den vollen Namen des Semesters ein, z.B. "Wintersemester 2021/22"';
$string['semesters'] = 'Semester';
$string['semesterssaved'] = 'Semester wurden gespeichert';
$string['semesterssubtitle'] = 'Hier können Sie <strong>Semester, Ferien und Feiertage</strong> anlegen, ändern und löschen.
    Die Einträge werden nach dem Speichern nach ihrem <strong>Start-Datum abwärts</strong> sortiert.';
$string['semesterstart'] = 'Semesterbeginn';
$string['semesterstart_help'] = 'An welchem Tag beginnt das Semester?';
$string['send'] = 'Senden';
$string['sendcopyofmail'] = 'Eine Kopie der E-Mail senden';
$string['sendcopyofmailmessageprefix'] = 'Vorangestellter Text für die Nachricht';
$string['sendcopyofmailsubjectprefix'] = 'Vorangestellter Text für den Betreff';
$string['sendcustommsg'] = 'Persönliche Nachricht senden';
$string['sendical'] = 'ical-Datei (.ics) anhängen';
$string['sendicalcreateorcancel'] = 'Soll die ical-Datei neue Termine erstellen oder vorhandene absagen?';
$string['sendmail'] = "Sende E-Mail";
$string['sendmailheading'] = 'E-Mail an alle TrainerInnen der ausgewählten Buchungsoptionen senden';
$string['sendmailinterval'] = 'Eine Nachricht zeitversetzt an mehrere Nutzer:innen schicken';
$string['sendmailtoallbookedusers'] = 'E-Mail an alle gebuchten Nutzer:innen senden';
$string['sendmailtobooker'] = 'Buchung für andere User durchführen: Mail an User, der Buchung durchführt, anstatt an gebuchte User senden';
$string['sendmailtobooker_help'] = 'Diese Option aktivieren, um Buchungsbestätigungsmails anstatt an die gebuchten Nutzer:innen zu senden an den/die Nutzer/in senden, die die Buchung durchgeführt hat. Dies betrifft nur Buchungen, die auf der Seite "Buchung für andere Nutzer:innen durchführen" getätigt wurden';
$string['sendmailtoteachers'] = 'E-Mail an TrainerInnen senden';
$string['sendmessage'] = 'Nachricht senden';
$string['sendreminderemailsuccess'] = 'Benachrichtung wurde per E-Mail versandt';
$string['session'] = 'Termin';
$string['sessionnotifications'] = 'E-Mail-Benachrichtigungen für Einzeltermine';
$string['sessionremindermailmessage'] = '<p>Erinnerung: Sie haben den folgenden Termin gebucht:</p>
<p>{$a->optiontimes}</p>
<p>##########################################</p>
<p>{$a->sessiondescription}</p>
<p>##########################################</p>
<p>Buchungsstatus: {$a->status}</p>
<p>Teilnehmer: {$a->participant}</p>
';
$string['sessionremindermailsubject'] = 'Erinnerung: Sie haben demnächst einen Kurstermin';
$string['sessionremindershint'] = 'Mit <a href="{$a}" target="_blank">Buchungsregeln</a> können Sie Benachrichtigungen für Termine einrichten';
$string['sessionremindersruleexists'] = 'Es gibt mindestens eine Buchungsregel, die für diesen Termin angewendet wird.';
$string['sessions'] = 'Termin(e)';
$string['sharedplacenoselect'] = 'Geteilt mit <a href="{$a->url}">{$a->text}</a>';
$string['sharedplaces'] = 'Geteilte Plätze';
$string['sharedplaces_help'] = 'Gebuchte Plätze einer anderen Buchungsoption werden addiert. Haben beide Optionen 10 verfügbare Plätze und nur eine wird gebucht, bleiben nur noch 9 Plätze in beiden.';
$string['sharedplacespriority'] = 'Hat Vorrang';
$string['sharedplacespriority_help'] = 'Wenn zwei verbundene Buchungsoptionen gleichzeitig freie Plätze haben, soll diese zuerst gebucht werden.';
$string['sharedplacespriorityerror'] = 'Folgende Buchungsoption hat bereits Vorrang, weshalb diese keinen Vorrang haben kann: <br> {$a}';
$string['shoppingcart'] = 'Zahlungsoptionen mit Shopping Cart Plugin definieren';
$string['shoppingcartplaceholder'] = 'Warenkorb';
$string['shortcode:cmidnotexisting'] = 'Der Kursmodul ID {$a} existiert nicht für die Aktivität Booking';
$string['shortcode:courseidnotexisting'] = 'Die Moodle Kurs Id {$a} existiert';
$string['shortcode:error'] = "Dieser Shortcode führt zu einer fehlerhaften Ausgabe. Überprüfen Sie die Parameter";
$string['shortcodenotsupportedonyourdb'] = "Dieser Shortcode funktioniert nur auf Postgres & Mariadb Datenbanken.";
$string['shortcodesettings'] = "Shortcodes Einstellungen";
$string['shortcodesettings_desc'] = "Booking unterstützt einige Shortcodes, die es Ihnen ermöglichen, Buchungsoptionen an verschiedenen Stellen auf Ihrer Website anzuzeigen.";
$string['shortcodesispasswordprotected'] = "Shortcodes sind durch Passwörter geschützt";
$string['shortcodesoff'] = 'Shortcodes deaktivieren';
$string['shortcodesoff_desc'] = 'Aktivieren Sie diese Einstellung, wenn Sie Shortcodes (z.B. [courselist]) für die gesamte Website deaktivieren möchten.';
$string['shortcodesoffwarning'] = 'Shortcode [{$a}] kann nicht verwendet werden, da Shortcodes ausgeschalten sind.';
$string['shortcodespassword'] = "Passwort";
$string['shortcodespassword_desc'] = "Wenn Sie hier einen Wert eingeben, können Shortcodes nur mit dem Parameter 'password' verwendet werden, ansonsten kommt eine Warnung.
Beispiel: [courselist cmid=1 <b>password=top_secret123</b>] oder [courselist cmid=2 <b>password=\"Passwort mit Leerzeichen\"</b>]";
$string['shorttext'] = "Kurztext";
$string['showallbookingoptions'] = 'Alle Buchungsoptionen';
$string['showallteachers'] = '&gt;&gt; Alle Trainer:innen anzeigen';
$string['showboactions'] = "Aktiviere Aktionen nach der Buchung";
$string['showbookingdetailstoall'] = 'Buchungsdetails für alle anzeigen';
$string['showbookingdetailstoall_desc'] = 'Auch Gäste und ausgeloggte Nutzer:innen können Buchungsdetails sehen.';
$string['showcertificates'] = 'Zertifikate anzeigen';
$string['showcoursenameandbutton'] = 'Kursnamen, Kurzinfo und einen Button, der die verfügbaren Buchungsoptionen öffnet, anzeigen';
$string['showcoursesofteacher'] = 'Kurse';
$string['showcustomfields'] = 'Anzuzeigende benutzerdefnierte Buchungsoptionsfelder';
$string['showcustomfields_desc'] = 'Wählen Sie die benutzerdefinierte Buchungsoptionfelder, die auf der Unterschriftenliste abgedruckt werden sollen';
$string['showdates'] = 'Zeige Termine';
$string['showdescription'] = 'Beschreibung anzeigen';
$string['showdetaildotsnextbookedalert'] = 'Bei gebuchten Optionen Link zu Details anzeigen';
$string['showdetaildotsnextbookedalert_desc'] = 'Wenn diese Option aktiviert ist, wird für Nutzende neben der Info dass eine Buchungsoption gebucht ist noch ein kleiner Button mit drei Punkten angezeigt,
der mit Detailansicht jener Option verlinkt ist.';
$string['showinapi'] = 'In API anzeigen?';
$string['showlistoncoursepage'] = 'Extra-Info auf Kursseite anzeigen';
$string['showlistoncoursepage_help'] = 'Wenn Sie diese Einstellung aktivieren, werden der Kursname, eine Kurzinfo
 und ein Button, der auf die verfügbaren Buchungsoptionen verlinkt, angezeigt.';
$string['showmessages'] = 'Zeige Nachrichten';
$string['showmybookingsonly'] = 'Meine Buchungen';
$string['showmyfieldofstudyonly'] = "Mein Studiengang";
$string['showoptiondatesextrainfo'] = 'Extra-Infos zu Terminen anzeigen';
$string['showoptiondatesextrainfo_desc'] = 'Kommentare und Extra-Infos zu Terminen in der Liste der Buchungsoptionen anzeigen
(auf der Buchungsoptionsdetailseite werden die zusätzlichen Informationen immer angezeigt, unabhängig von dieser Einstellung).
<i>Hinweis: Links zu Online-Räumen (Zoom, Teams...) werden nur auf der Detailseite angezeigt, nicht in der Liste.</i>';
$string['showpriceifnotloggedin'] = 'Preis(e) anzeigen, wenn Nutzer:innen nicht eingeloggt sind';
$string['showprogressbars'] = 'Fortschrittsbalken für bereits vergangene Zeit anzeigen';
$string['showrecentupdates'] = 'Zeige die letzten Bearbeitungen';
$string['showsimilaroptions'] = 'Ähnliche Optionen anzeigen';
$string['showsubbookings'] = 'Zusatzbuchungen aktivieren';
$string['showteachersmailinglist'] = 'E-Mail-Liste für alle Trainer:innen anzeigen...';
$string['showviews'] = 'Ansichten der Buchungsoptionsübersicht';
$string['signature'] = 'Unterschrift';
$string['signinadddatemanually'] = 'Datum händisch eintragen';
$string['signinaddemptyrows'] = 'Leeren Zeilen hinzufügen';
$string['signincustfields'] = 'Anzuzeigende Profilfelder';
$string['signincustfields_desc'] = 'Wählen Sie die Profilfelder, die auf der Unterschriftenliste abgedruckt werden sollen';
$string['signinextracols'] = 'Extra Spalte auf der Unterschriftenliste';
$string['signinextracols_desc'] = 'Sie können bis zu 3 extra Spalten auf der Unterschriftenliste abbilden. Geben Sie den Titel der Spalte ein, oder lassen Sie das Feld leer, um keine extra Spalte anzuzeigen';
$string['signinextracolsheading'] = 'Zusätzliche Spalten auf der Unterschriftenliste';
$string['signinextrasessioncols'] = 'Extra-Spalten für Termine hinzufügen';
$string['signinformat'] = 'Speicherfromat wählen';
$string['signinformatbutton'] = 'Aus HTML-Vorlage erstellen';
$string['signinhidedate'] = 'Termine ausblenden';
$string['signinlogo'] = 'Logo für die Unterschriftenliste';
$string['signinlogofooter'] = 'Logo in der Fußzeile auf der Unterschriftenliste';
$string['signinlogoheader'] = 'Logo in der Kopfzeile auf der Unterschriftenliste';
$string['signinonesession'] = 'Termin(e) im Header anzeigen';
$string['signinsheet_htmltemplate'] = 'HTML-Vorlage';
$string['signinsheet_legacy'] = 'Klassische Unterschriftenliste';
$string['signinsheetaddress'] = 'Adresse: ';
$string['signinsheetconfigure'] = 'Unterschriftenliste konfigurieren';
$string['signinsheetdate'] = 'Termin(e): ';
$string['signinsheetdatetofillin'] = 'Datum: ';
$string['signinsheetdownload'] = 'Unterschriftenliste herunterladen';
$string['signinsheetfields'] = 'Auf der Unterschriftenliste (PDF-Download)';
$string['signinsheethtml'] = 'HTML-Vorlage zur Erstellung von Unterschriftenlisten';
$string['signinsheethtmldescription'] = 'Sie können die folgenden Platzhalter verwenden:

<b>Innerhalb von [[users]] ... [[/users]]:</b><br>
[[fullname]], [[firstname]], [[lastname]], [[email]], [[signature]], [[institution]], [[description]], [[city]], [[country]], [[idnumber]], [[phone1]], [[department]], [[address]], [[places]]<br>
(ggf. auch benutzerdefinierte Profilfelder, falls konfiguriert)

<b>Außerhalb von [[users]]:</b><br>
[[location]], [[dayofweektime]], [[teachers]], [[dates]], [[logourl]], [[tablename]]

Verwenden Sie nur einfaches HTML, das von TCPDF / PhpWord unterstützt wird. Um Unterschriften in eine Tabelle einzufügen, verwenden Sie die CSS-Klasse <code>"signaturetable"</code>.';
$string['signinsheetlocation'] = 'Ort: ';
$string['signinsheetmode'] = 'Anwesenheitsliste Modus';
$string['signinsheetmode_desc'] = 'Wählen Sie den Modus für das Herunterladen der Anwesenheitsliste: HTML-Vorlage oder Legacy-Modus.';
$string['skipbookingrulesmode'] = 'Anwendung der Buchungsregeln';
$string['skipbookingrulesoptin'] = 'Opt in: Nur folgende Regeln anwenden';
$string['skipbookingrulesoptout'] = 'Opt out: Folgende Regeln nicht anwenden';
$string['skipbookingrulesrules'] = 'Auswahl der Buchungsregeln';
$string['sortbookingoptions'] = "Bitte die Buchungsoptionen in die richtige Reihenfolge bringen. Die Kurse können nur in der hier festgelegten Reihenfolge absolviert werden. Der oberste Kurs muss zuerst absolviert werden.";
$string['sortorder'] = 'Sortierreihenfolge';
$string['sortorder:asc'] = 'A&rarr;Z';
$string['sortorder:desc'] = 'Z&rarr;A';
$string['spaceleft'] = 'Platz verfügbar';
$string['spacesleft'] = 'Plätze verfügbar';
$string['sqlfiltercheckstring'] = 'Bookingoption ausblenden wenn diese Bedingung nicht erfüllt ist';
$string['startdate'] = "Startdatum";
$string['starttime'] = "Startzeit";
$string['starttimenotset'] = 'Kursbeginn nicht festgelegt';
$string['status'] = 'Status';
$string['statusattending'] = "Teilgenommen";
$string['statuschangetext'] = 'Statusänderungsbenachrichtigung';
$string['statuschangetextmessage'] = 'Guten Tag, {$a->participant}!
Ihr Buchungsstatus hat sich geändert.
Ihr Buchungsstatus: {$a->status}
Teilnehmer/in:   {$a->participant}
Buchungsoption: {$a->title}
Termin:  {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
Link zur Buchungsoption: {$a->gotobookingoption}
';
$string['statuschangetextsubject'] = 'Buchungstatus für {$a->title} geändert';
$string['statuscomplete'] = "Abgeschlossen";
$string['statusexcused'] = "Entschuldigt";
$string['statusfailed'] = "Nicht erfolgreich";
$string['statusincomplete'] = "Nicht abgeschlossen";
$string['statusnoshow'] = "Nicht teilgenommen";
$string['statusnotset'] = "Kein Status";
$string['statusunknown'] = "Unbekannt";
$string['sthwentwrongwithplaceholder'] = '';
$string['studentbooked'] = 'Nutzer:innen, die gebucht haben';
$string['studentbookedandwaitinglist'] = 'Nutzer:innen, die gebucht haben oder auf der Warteliste sind';
$string['studentdeleted'] = 'Nutzer:innen, die bereits entfernt wurden';
$string['studentnotificationlist'] = 'Nutzer:innen auf der Benachrichtigungsliste';
$string['studentwaitinglist'] = 'Nutzer:innen auf der Warteliste';
$string['subbookingadditemformlink'] = "Verbindung zum Formular dieser Buchungsoption";
$string['subbookingadditemformlink_help'] = "Wählen Sie das Formularelement, das Sie mit dieser Zusatzbuchung verbinden wollen. Die Zusatzbuchung wird nur angezeigt, wenn die Nutzer:in davor den entsprechenden Wert im Formular gewählt hat.";
$string['subbookingadditemformlinkvalue'] = "Wert, der im Formular ausgewählt sein soll";
$string['subbookingadditionalitem'] = "Buche zusätzlichen Artikel";
$string['subbookingadditionalitem_desc'] = "Diese zusätzliche Buchung erlaubt einen weiten Artiekl zu buchen, etwa einen besseren Platz oder zusätzliches Material.";
$string['subbookingadditionalitemdescription'] = "Beschreiben Sie hier den zusätzlich buchbaren Artikel:";
$string['subbookingadditionalperson'] = "Buche zusätzliche Person";
$string['subbookingadditionalperson_desc'] = "Buchen Sie Plätze für zusätzliche Personen, z.B. für Familienmitglieder.";
$string['subbookingadditionalpersondescription'] = "Beschreiben Sie die Buchungsmöglichkeit.";
$string['subbookingaddpersons'] = "Füge Person(en) hinzu";
$string['subbookingbookedpersons'] = "Die folgenden Personen werden hinzugefügt:";
$string['subbookingduration'] = "Dauer in Minuten";
$string['subbookingname'] = "Name der Zusatzbuchung";
$string['subbookings'] = "Zusatzbuchungen";
$string['subbookings_desc'] = 'Schalten Sie Zusatzbuchungen wie z.B. zusätzlich buchbare Items oder Slot-Buchungen für bestimmte Zeiten (z.B. für Tennisplätze) frei.';
$string['subbookingsheader'] = 'Zusatzbuchungen <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['subbookingtimeslot'] = "Zeitfenster Buchung";
$string['subbookingtimeslot_desc'] = "Mit dieser Funktion kann die Dauer von buchbaren Zeitfenstern für jedes Datum der Buchungsoption festgelegt werden.";
$string['subject'] = 'Betreff';
$string['submitandadd'] = 'Neue Buchungsoption anlegen';
$string['submitandgoback'] = 'Formular schließen';
$string['submitandstay'] = 'Formular weiterbearbeiten';
$string['subplugintype_bookingextension_plural'] = 'Booking-Erweiterungen';
$string['subscribersto'] = 'Trainer:innen für \'{$a}\'';
$string['subscribetocourse'] = 'Nutzer:innen in den Kurs einschreiben';
$string['subscribeuser'] = 'Wollen Sie diese User wirklich in diesen Kurs einschreiben';
$string['substitutions'] = 'Vertretung(en)';
$string['successfulcalculation'] = 'Preise erfolgreich neu berechnet!';
$string['successfulldeleted'] = 'Kategorie wurde erfolgreich gelöscht!';
$string['successfullybooked'] = 'Erfolgreich gebucht';
$string['successfullysorted'] = 'Erfolgreich sortiert';
$string['sucessfullybooked'] = 'Erfolgreich gebucht';
$string['sumunits'] = 'Summe UE';
$string['sunday'] = 'Sonntag';
$string['switchtemplates'] = 'Nutzer:innen können die Ansicht wechseln';
$string['switchtemplates_help'] = 'Aktivieren Sie diese Einstellung, um es Nutzer:innen zu ermöglichen zwischen verschiedenen Ansichten zu wechseln.
Definieren Sie im nächsten Schritt die Ansichten zwischen denen gewechselt werden kann.';
$string['switchtemplatesselection'] = 'Ansichten zwischen denen gewechselt werden kann';
$string['switchtemplatesselection_help'] = 'Wählen Sie die Ansichten aus, zwischen denen Nutzer:innen wechseln können.';
$string['system'] = 'System';
$string['tableheadercourseendtime'] = 'Kursende';
$string['tableheadercoursestarttime'] = 'Kursbeginn';
$string['tableheadermaxanswers'] = 'Verfügbare Plätze';
$string['tableheadermaxoverbooking'] = 'Wartelistenplätze';
$string['tableheaderminanswers'] = 'Mindestteilnehmerzahl';
$string['tableheaderteacher'] = 'Trainer:in(nen)';
$string['tableheadertext'] = 'Kursbezeichnung';
$string['tabwhatsnew'] = 'Buchungs-Tab: "Was ist neu?"';
$string['tabwhatsnew_desc'] = 'Sie können diesen Tab verwenden, um Benutzer:innen alle neuen Buchungen anzuzeigen,
die innerhalb der letzten X Tage (die Anzahl können Sie hier angeben) auf sichtbar gesetzt ODER erstellt wurden.
<span class="text-danger">Denken Sie daran, den Tab in den Einstellungen Ihrer Buchungsinstanz hinzuzufügen, nachdem Sie ihn aktiviert haben.</span>';
$string['tabwhatsnewdays'] = 'Anzahl Tage für "Was ist neu?"';
$string['tabwhatsnewdays_desc'] = 'Geben Sie die Anzahl an Tagen in der Vergangenheit an bis wann eine Buchungsoption als neu gilt.
Beispiel: Wenn Sie hier 30 angeben, dann werden Buchungsoptionen, die vor mehr als 30 Tagen auf sichtbar gestellt (oder erstellt) wurden,
im "Was ist neu?"-Tab nicht angezeigt. 0 bedeutet, dass nur Buchungsoptionen angezeigt werden, die heute erstellt oder auf sichtbar gestellt wurden.';
$string['tagsuccessfullysaved'] = 'Schlagwort erfolgreich gespeichert.';
$string['tagtag'] = 'Schlagwort';
$string['tagtemplates'] = 'Schlagwort Vorlagen';
$string['tagtext'] = 'Schlagwort-Text';
$string['taken'] = 'gebucht';
$string['taskadhocresetoptiondatesforsemester'] = 'Adhoc task: Termine zurücksetzen und neu erstellen';
$string['taskcheckanswers'] = 'Booking: Antworten prüfen';
$string['taskcleanbookingdb'] = 'Booking: Datenbank aufräumen';
$string['taskenrolbookeduserstocourse'] = 'Booking: Gebuchte User in Kurs einschreiben';
$string['taskpurgecampaigncaches'] = 'Booking: Caches für Buchungskampagne leeren';
$string['taskrecalculateprices'] = 'Preise einer Buchungsaktivität werden mit der Preisformel neu berechnet';
$string['taskremoveactivitycompletion'] = 'Booking: Activitätsabschluss entfernen';
$string['tasksendcompletionmails'] = 'Booking: Abschluss-Mails versenden';
$string['tasksendconfirmationmails'] = 'Booking: Bestätigungs-Mails versenden';
$string['tasksendmailbyruleadhoc'] = 'Booking: Mail via Regel versenden (Adhoc-Task)';
$string['tasksendnotificationmails'] = 'Booking: Benachrichtigungs-Mails versenden';
$string['tasksendremindermails'] = 'Booking: Erinnerungs-Mails versenden';
$string['teacher'] = 'Trainer:in';
$string['teacherdescription'] = 'Beschreibung';
$string['teachernotfound'] = 'Trainer:in konnte nicht gefunden werden oder existiert nicht.';
$string['teacherpageshiddenbookingids'] = 'Buchungsinstanzen, die auf Trainer:innen-Seiten nicht angezeigt werden sollen';
$string['teacherroleid'] = 'Wähle folgende Rolle, um Lehrkräfte in einen ggf. neu angelegten Kurs einzuschreiben.';
$string['teachers'] = 'Trainer:innen';
$string['teachersallowmailtobookedusers'] = 'Trainer:innen erlauben, eine Direkt-Mail an gebuchte Nutzer:innen zu senden';
$string['teachersallowmailtobookedusers_desc'] = 'Wenn Sie diese Einstellung aktivieren, können Trainer:innen eine Direktnachricht
mit ihrem eigenen Mail-Programm an gebuchte Nutzer:innen senden - die E-Mail-Adressen der gebuchten Nutzer:innen werden dadurch sichtbar.
<span class="text-danger"><b>Achtung:</b> Dies könnte ein Datenschutz-Problem darstellen. Aktivieren Sie dies nur,
wenn es die Datenschutzbestimmungen Ihrer Organisation erlauben.</span>';
$string['teachersalwaysenablemessaging'] = 'Nutzer:innen erlauben, allen Trainer:innen Nachrichten zu senden.';
$string['teachersalwaysenablemessaging_desc'] = 'Wenn Sie diese Einstellung aktivieren, können Nutzer:innen Nachrichten an Trainer:innen senden, auch wenn sie in keinem ihrer Kurse eingeschrieben sind.';
$string['teachersettings'] = 'Trainer:innen <span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['teachersettings_desc'] = 'Trainer:innen-spezifische Einstellungen.';
$string['teachersforoption'] = 'Trainer:innen';
$string['teachersforoption_help'] = '<b>ACHTUNG:</b> Wenn Sie hier Trainer:innen hinzufügen werden diese im Training-Journal <b>zu JEDEM ZUKÜNFTIGEN Termin hinzugefügt</b>.
Wenn Sie hier Trainer:innen löschen, werden diese im Training-Journal <b>von JEDEM ZUKÜNFTIGEN Termin entfernt</b>.';
$string['teachersinstanceconfig'] = 'Bearbeite Buchungsoptionsformular';
$string['teachersinstancereport'] = 'Trainer:innen-Gesamtbericht';
$string['teachersinstancereport:subtitle'] = '<strong>Hinweis:</strong> Die Anzahl der UE berechnet sich anhand des gesetzten Terminserien-Textfeldes (z.B. "Mo, 16:00-17:30")
und der in den <a href="{$a}" target="_blank">Einstellungen festgelegten Dauer</a> einer UE. Für Blockveranstaltungen oder
Buchungsoptionen bei denen das Feld nicht gesetzt ist, können die UE nicht berechnet werden!';
$string['teacherslinkonteacher'] = 'Links zu Trainer:innen-Seiten hinzufügen';
$string['teacherslinkonteacher_desc'] = 'Sind bei einer Buchungsoption Trainer:innen definiert, so werden die Namen automatisch mit einer Überblicksseite für diese Trainer:innen verknüpft.';
$string['teachersnologinrequired'] = 'Einloggen bei Trainer:innen-Seiten nicht notwendig';
$string['teachersnologinrequired_desc'] = 'Wenn Sie diese Einstellung aktivieren, dann können die Trainer:innen-Seiten auch von
nicht-eingeloggten Benutzer:innen gesehen werden.';
$string['teachersshowemails'] = 'E-Mail-Adressen von Trainer:innen immer anzeigen';
$string['teachersshowemails_desc'] = 'Wenn Sie diese Einstellung aktivieren, dann werden allen Benutzer:innen die E-Mail-Adressen der Trainer:innen
angezeigt, selbst wenn diese nicht eingeloggt sind. <span class="text-danger"><b>Achtung:</b> Dies könnte ein Datenschutz-Problem darstellen. Aktivieren Sie dies nur,
wenn es die Datenschutzbestimmungen Ihrer Organisation erlauben.</span>';
$string['teachingconfigforinstance'] = 'Bearbeite Buchungsoptionsformular für ';
$string['teachingreportforinstance'] = 'Trainer:innen-Gesamtbericht für ';
$string['teachingreportfortrainer'] = 'Leistungs-Report für Trainer:in';
$string['teachingreportfortrainer:subtitle'] = '<strong>Hinweis:</strong> Sie können die Dauer einer Unterrichtseinheit
in den Einstellungen anpassen. (Z.B. 45 statt 60 Minuten).<br/>
<a href="{$a}" target="_blank">
&gt;&gt; Zu den Einstellungen...
</a>';
$string['teamsmeeting'] = 'Teams-Meeting';
$string['template'] = 'Vorlagen';
$string['templatecategoryname'] = 'Kurzname der Kurskategorie, in der sich die Vorlagenkurse befinden.';
$string['templatecategoryname_desc'] = 'Buchungsoptionen können mit Moodle-Kursen verknüpft werden. Dieses Feature erlaubt es, die Kurse beim ersten Speichern der Buchungsoption zu erstellen.';
$string['templatedeleted'] = 'Vorlage wurde gelöscht!';
$string['templatefile'] = 'Datei für Vorlage';
$string['templatesuccessfullysaved'] = 'Vorlage wurde gespeichert';
$string['text'] = 'Titel';
$string['textarea'] = "Textbereich";
$string['textdependingonstatus'] = 'Statusabhängiger Buchungsoptionstext ';
$string['textfield'] = 'Eingabezeile';
$string['thankyoubooked'] = '<i class="fa fa-3x fa-calendar-check-o text-success" aria-hidden="true"></i><br><br>
Vielen Dank! Sie haben <b>{$a}</b> erfolgreich gebucht.';
$string['thankyoucheckout'] = '<i class="fa fa-3x fa-shopping-cart text-success" aria-hidden="true"></i><br><br>
Vielen Dank! Sie haben <b>{$a}</b> in den Warenkorb gelegt. Klicken Sie nun auf <b>"Weiter zur Bezahlung"</b>
 um den Buchungsvorgang fortzusetzen.';
$string['thankyouerror'] = '<i class="fa fa-3x fa-frown-o text-danger" aria-hidden="true"></i><br>
Leider ist bei der Buchung von <b>{$a}</b> ein Fehler aufgetreten.';
$string['thankyouwaitinglist'] = '<i class="fa fa-3x fa-clock-o text-primary" aria-hidden="true"></i><br><br>
Sie wurden zur Warteliste von <b>{$a}</b> hinzugefügt. Sollte jemand ausfallen, rücken Sie automatisch nach.';
$string['thisinstance'] = 'Diese Buchung';
$string['thursday'] = 'Donnerstag';
$string['timecreated'] = 'Erstellt';
$string['timefilter:bookingtime'] = 'Anmeldezeiten';
$string['timefilter:coursetime'] = 'Kurszeiten';
$string['timeintervalls'] = "Zeitintervalle";
$string['timeintervalls_desc'] = "Wenn angeschalten, kann bei Zeitauswahlfeldern die Zeit in 5 Minuten, anstelle von 1 Minuten Intervallen ausgewählt werden.";
$string['timemadevisible'] = 'Zeitpunkt der letzten Sichtbar-Schaltung';
$string['timemodified'] = 'Zuletzt bearbeitet';
$string['timerestrict'] = 'Buchungsoption auf diesen Zeitraum beschränken: Diese Option ist veraltet und sollte nicht mehr verwendet werden. Stattdessen verwenden Sie bitte die Optionen unter "Voraussetzungen", um die Buchungen für einen bestimmten Zeitraum zu beschränken.';
$string['title'] = "Titel";
$string['titleprefix'] = 'Präfix';
$string['titleprefix_help'] = 'Fügen Sie ein Präfix hinzu, das vor dem Titel angezeigt wird, z.B. "BB42".';
$string['to'] = 'bis';
$string['toomanytoshow'] = 'Zu viele gefunden...';
$string['toomuchusersbooked'] = 'Maximale Anzahl an Nutzer:innen, die Sie buchen können: {$a}';
$string['topic'] = "Thema";
$string['transfer'] = 'Umbuchen';
$string['transferheading'] = 'Ausgewählte Nutzer:innen in die ausgewählte Buchungsoption umbuchen';
$string['transferoptionsuccess'] = 'Die Buchungsoption und die registrierten Nutzer:innen wurden erfolgreich umgebucht';
$string['transferproblem'] = 'Die folgenden Nutzer:innen konnten aufgrund einer limitierten Anzahl an Plätzen der Buchungsoption oder aufgrund individueller Limitierungen seitens des/der Nutzer/in nicht umgebucht werden: {$a}';
$string['transfersuccess'] = 'Die Nutzer:innen wurden erfolgreich umgebucht';
$string['tuesday'] = 'Dienstag';
$string['turnoffmodals'] = "Keine Modale verwenden.";
$string['turnoffmodals_desc'] = "Für manche Schritte vor dem Buchen werden aktuell Modale verwendet. Diese Einstellung führt dazu, dass der ganze Prozess direkt in der Seite, ohne Modale, abläuft.
<b>Bitte beachten:</b> Wenn Sie die <b>Karten-Ansicht</b> von Booking verwenden, werden weiterhin Modale verwendet, Modale können <b>nur bei der Listen-Ansicht</b> ausgeschaltet werden.";
$string['turnoffwaitinglist'] = 'Warteliste global deaktivieren';
$string['turnoffwaitinglist_desc'] = 'Aktivieren Sie diese Einstellung, um die Warteliste auf der gesamten
 Plattform auszuschalten (z.B. weil Sie nur die Benachrichtigungsliste verwenden möchten).';
$string['turnoffwaitinglistaftercoursestart'] = 'Automatisches Nachrücken von der Warteliste ab Beginn der Buchungsoption deaktivieren.';
$string['turnoffwunderbytelogo'] = 'Wunderbyte Logo und Link nicht anzeigen';
$string['turnoffwunderbytelogo_desc'] = 'Wenn diese Einstellung aktiviert ist, werden das Wunderbyte Logo und der Link zur Wunderbyte-Website nicht angezeigt.';
$string['turnthisoninsettings'] = 'Aktivierung in globalen Einstellungen nötig';
$string['turnthisoninsettings_help'] = 'Noch nicht aktiviert. <a href="{$a}" target="_blank">Hier klicken, um diese Funktionalität in den globalen Einstellungen zu aktivieren</a>.';
$string['unconfirm'] = 'Lösche Bestätigung';
$string['unconfirmbooking'] = 'Lösche Bestätigung dieser Buchung';
$string['unconfirmbookinglong'] = 'Wollen Sie die Bestätigung dieser Buchung wirklich aufheben?';
$string['undocancelreason'] = "Möchten Sie wirklich die Stornierung dieser Buchungsoption rückgängig machen?";
$string['undocancelthisbookingoption'] = "Stornierung rückgängig machen";
$string['unenrolfromgroupofcurrentcourse'] = 'Beim Abmelden von der Buchungsoption auch aus der spezifischen Gruppe abmelden?';
$string['unenroluserswithoutaccess'] = 'Abmelden von Nutzer:innen ohne Zugang';
$string['unenroluserswithoutaccess_desc'] = 'Melde Nutzer:innen automatisch ab, die keinen Zugang mehr zu einem Moodle-Kurs oder einer Buchungsaktivität haben.
<div class="text-danger">Achtung: Damit wird die Nachverfolgung womöglich erschwert. Nach Aktivierung dieses Häkchens wird einmalig systemweit überprüft,
ob es zu löschende Buchungen gibt. Das Löschen der Buchungen geschieht immer asynchron mit ca. 15 Minuten Verzögerung.
Wenn Sie also ein:e/n Nutzer:in irrtümlich ausschreiben, haben Sie noch einige Minuten Zeit, um dieses Häkchen zu entfernen und das automatische Löschen somit zu verhindern.</div>';
$string['unenroluserswithoutaccessareyousure'] = 'Möchten Sie wirklich "Abmelden von Nutzer:innen ohne Zugang" aktivieren?';
$string['unenroluserswithoutaccessareyousure_desc'] = 'Erst nach Aktivierung dieses Kontrollkästchens und Speichern können Sie die eigentliche Einstellung aktivieren.
Das Verhalten wird nur aktiviert, wenn beide Kontrollkästchen aktiviert sind.';
$string['unenroluserswithoutaccessheader_desc'] = 'Melde Nutzer:innen automatisch ab, die keinen Zugang mehr zu einem Moodle-Kurs oder einer Buchungsaktivität haben.
(<b>Achtung</b>: Dies kann zu unerwünschtem Verhalten führen. Nur aktivieren, wenn wirklich benötigt.)';
$string['units'] = 'UE';
$string['unitscourses'] = 'Kurse / UE';
$string['unitsunknown'] = 'Anzahl UE unbekannt';
$string['unlimitedcredits'] = 'Verwende keine Credits';
$string['unlimitedplaces'] = 'Unbegrenzt';
$string['unlinkallchildren'] = 'Verknüpfung von folgenden Buchungsoptionen löschen';
$string['unlinkchild'] = 'Verknüpfung mit Vorlage löschen';
$string['unsubscribe:alreadyunsubscribed'] = 'Sie sind bereits abgemeldet.';
$string['unsubscribe:errorotheruser'] = 'Es ist nicht erlaubt, E-Mail-Abmeldungen für fremde Benutzer:innen durchzuführen!';
$string['unsubscribe:successnotificationlist'] = 'Sie wurden erfolgreich von den E-Mail-Benachrichtigungen für "{$a}" abgemeldet.';
$string['until'] = 'Bis';
$string['updatebooking'] = 'Update Buchung';
$string['updatedrecords'] = '{$a} Eintrag/Einträge aktualisiert.';
$string['uploadheaderimages'] = 'Header-Bilder für Buchungsoptionen';
$string['usecompetencies'] = 'Kompetenzen verwenden';
$string['usecompetencies_desc'] = 'Buchungsoptionen können mit Kompetenzen versehen und entsprechend dieser Zuweisungen gruppiert angezeigt werden';
$string['usecoursecategorytemplates'] = 'Verwende Vorlagen für neu zu erstellende Moodle-Kurse';
$string['usecoursecategorytemplates_desc'] = '';
$string['usedinbooking'] = 'Das Löschen dieser Kategorie/n ist nicht möglich, da sie verwendet werden!';
$string['usedinbookinginstances'] = 'Die Vorlage wird in folgenden Buchungsinstanzen verwendet';
$string['uselegacymailtemplates'] = 'Weiterhin veraltete E-Mail-Vorlagen verwenden';
$string['uselegacymailtemplates_desc'] = 'Diese Funktion ist veraltet und wird in naher Zukunft entfernt. Wir empfehlen Ihnen dringend, Ihre Vorlagen und Einstellungen zu <a href="{$a}">Buchungs Regeln</a> zu migrieren.
<span class="text-danger"><b>Vorsicht:</b> Wenn Sie dieses Kästchen deaktivieren, werden Ihre E-Mail-Vorlagen in Ihren Buchungsinstanzen nicht mehr angezeigt und verwendet.</span>';
$string['usenotificationlist'] = 'Verwende Benachrichtigungsliste';
$string['useonlyonefield'] = 'Kein weiteres Feld';
$string['useprice'] = 'Nur mit Preis buchbar';
$string['useprotoenablemorerules'] = 'Sie benötigen Booking PRO, um weitere Regeln hinzu zu fügen.
<a href="https://wunderbyte.at/kontakt" target="_blank">Kontaktieren Sie Wunderbyte</a>, wenn Sie eine Lizenz erwerben möchten.';
$string['useraffectedbyevent'] = 'Vom Ereignis betroffene:r Nutzer:in';
$string['usercalendarentry'] = 'Sie haben <a href="{$a}">diese Option</a> gebucht.';
$string['usercalendarurl'] = "Nutzer:innen Kalender";
$string['userdownload'] = 'Nutzer:innenliste herunterladen';
$string['usergavereason'] = '{$a} gab folgenden Grund für die Stornierung an:';
$string['userinfofieldoff'] = 'Kein User-Profilfeld ausgewählt';
$string['userinfosasstring'] = '{$a->firstname} {$a->lastname} (ID:{$a->id})';
$string['userleave'] = 'Nutzer/in hat Buchung storniert (0 eingeben zum Ausschalten)';
$string['userleavemessage'] = 'Hallo {$a->participant},
Sie wurden erfolgreich von {$a->title} abgemeldet.
';
$string['userleavesubject'] = 'Sie wurden erfolgreich abgemeldet von: {$a->title}';
$string['usermodified'] = 'Bearbeitet von';
$string['username'] = "Usernamen";
$string['usernameofbookingmanager'] = 'Buchungsverwalter/in auswählen';
$string['usernameofbookingmanager_help'] = 'Nutzername des/der Nutzer/in, der als Absender/in der Buchungsbestätigunsmitteilungen angeführt wird. Wenn die Option "Eine Kopie des Bestätigungsmail an Buchungsverwalter senden" aktiviert ist, wird die Kopie der Buchungsbestätigung an diese/n Nutzer/in gesendet.';
$string['userparameter_desc'] = "Benutze User Parameter.";
$string['userparametervalue'] = "User Parameter";
$string['userprofilefield'] = "Profilfeld";
$string['userprofilefieldoff'] = 'Nicht anzeigen';
$string['userrank'] = 'Reihenfolge';
$string['usersmatching'] = 'Gefundene Nutzer:innen';
$string['usersonlist'] = 'Nutzer:innen';
$string['userspecificcampaignwarning'] = "Wenn Sie ein unten ein Benutzerdefiniertes User Profilfeld auswählen, wird die Kampagne nur für jene NutzerInnen wirksam, die in diesem Feld den angegebenen Wert haben (oder nicht haben).";
$string['userssuccessfullenrolled'] = 'Alle Nutzer:innen wurden erfolgreich eingeschrieben!';
$string['userssuccessfullybooked'] = 'Alle Nutzer:innen wurden erfolgreich in die andere Buchungsoption eingeschrieben.';
$string['userssucesfullygetnewpresencestatus'] = 'Anwesenheitsstatus für ausgewählte Nutzer:innen erfolgreich aktualisiert';
$string['userstonotify'] = 'Benachrichtigungsliste';
$string['userwhotriggeredevent'] = 'Nutzer:in, die das Ereignis ausgelöst hat';
$string['viewallresponses'] = '{$a} Buchungen verwalten';
$string['viewconfirmationbooked'] = 'Ihre Buchung wurde registriert:
{bookingdetails}
<p>##########################################</p>
Buchungsstatus: {status} <br>
Teilnehmer:   {firstname} {lastname} <br>
Zurück zur Übersicht der Buchungsoptionen: {bookinglink} <br>
';
$string['viewconfirmationwaiting'] = 'Sie sind nun auf der Warteliste von:
{bookingdetails}
<p>##########################################</p>
Buchungsstatus: {status} <br>
Teilnehmer:   {firstname} {lastname} <br>
Zurück zur Übersicht der Buchungsoptionen: {bookinglink} <br>
';
$string['viewparam'] = 'Ansichtsart';
$string['viewparam:cards'] = 'Karten-Ansicht';
$string['viewparam:list'] = 'Listen-Ansicht';
$string['viewparam:listimgleft'] = 'Listen-Ansicht mit Bild links';
$string['viewparam:listimglefthalf'] = 'Listen-Ansicht mit Bild links über die Hälfte';
$string['viewparam:listimgright'] = 'Listen-Ansicht mit Bild rechts';
$string['visibilitystatus'] = 'Sichtbarkeitsstatus';
$string['visibleoptions'] = 'Sichtbare Buchungsoptionen';
$string['vue_dashboard_booking_instances'] = 'Booking instances';
$string['vuebookingstatsback'] = 'Zurück';
$string['vuebookingstatsbooked'] = 'Gebucht';
$string['vuebookingstatsbookingoptions'] = 'Buchungsoptionen';
$string['vuebookingstatscapability'] = 'Berechtigung';
$string['vuebookingstatsno'] = 'Nein';
$string['vuebookingstatsreserved'] = 'Reserviert';
$string['vuebookingstatsrestore'] = 'Zurücksetzen';
$string['vuebookingstatsrestoreconfirmation'] = 'Möchten Sie diese Konfiguration wirklich zurücksetzen?';
$string['vuebookingstatssave'] = 'Speichern';
$string['vuebookingstatsselectall'] = 'Alle auswählen';
$string['vuebookingstatswaiting'] = 'Warteliste';
$string['vuebookingstatsyes'] = 'Ja';
$string['vuecapabilityoptionscapconfig'] = 'Berechtigungskonfiguration';
$string['vuecapabilityoptionsnecessary'] = 'notwendig';
$string['vuecapabilityunsavedchanges'] = 'Es gibt ungespeicherte Änderungen';
$string['vuecapabilityunsavedcontinue'] = 'Möchten Sie diese Konfiguration wirklich zurücksetzen?';
$string['vueconfirmmodal'] = 'Sind Sie sicher, dass Sie zurückgehen möchten?';
$string['vuedashboardassignrole'] = 'Rollen zuweisen';
$string['vuedashboardchecked'] = 'Default Ausgewählt';
$string['vuedashboardcoursecount'] = 'Anzahl der Kurse';
$string['vuedashboardcreateoe'] = 'Neue OE erstellen';
$string['vuedashboardgotocategory'] = 'Zur Kategorie';
$string['vuedashboardname'] = 'Name';
$string['vuedashboardnewcourse'] = 'Neuen Kurs erstellen';
$string['vuedashboardpath'] = 'Pfad';
$string['vueheadingmodal'] = 'Bestätigung';
$string['vuenotfoundroutenotfound'] = 'Route nicht gefunden';
$string['vuenotfoundtryagain'] = 'Bitte versuchen Sie es später erneut';
$string['vuenotificationtextactionfail'] = 'Beim Speichern ist ein Fehler aufgetreten. Die Änderungen wurden nicht vorgenommen.';
$string['vuenotificationtextactionsuccess'] = 'Die Konfiguration wurde erfolgreich {$a}.';
$string['vuenotificationtextunsave'] = 'Es wurden keine ungespeicherten Änderungen erkannt.';
$string['vuenotificationtitleactionfail'] = 'Die Konfiguration wurde nicht erfolgreich {$a}';
$string['vuenotificationtitleactionsuccess'] = 'Die Konfiguration wurde erfolgreich {$a}';
$string['vuenotificationtitleunsave'] = 'Keine ungespeicherten Änderungen erkannt';
$string['waitforconfirmation'] = 'Buchen immer nur nach Bestätigung';
$string['waitforconfirmationonwaitinglist'] = 'Bestätigung nur bei Wartelistenplatz';
$string['waitinglist'] = 'Warteliste';
$string['waitinglistconfirmed'] = 'Wartelistenplatz bestätigt';
$string['waitinglistdeleted'] = 'Von der Warteliste gelöscht';
$string['waitinglistenoughmessage'] = 'Noch Wartelistenplätze verfügbar.';
$string['waitinglistfullmessage'] = 'Warteliste ist voll.';
$string['waitinglistheader'] = 'Warteliste';
$string['waitinglistheader_desc'] = 'Hier können Sie Einstellungen zum Verhalten der Warteliste vornehmen.';
$string['waitinglistinfotexts'] = 'Anzeige der Platzverfügbarkeit für die Warteliste';
$string['waitinglistinfotextsinfo'] = 'Wählen Sie aus, wie die Platzverfügbarkeit für die Warteliste den NutzerInnen angezeigt werden soll.';
$string['waitinglistlowmessage'] = 'Nur noch wenige Wartelistenplätze!';
$string['waitinglistlowpercentage'] = 'Warteliste: Prozentsatz für "Nur noch wenige Plätze verfügbar"-Nachricht';
$string['waitinglistlowpercentagedesc'] = 'Wenn die Anzahl verfügbarer Wartelistenplätze diesen Prozentsatz erreicht oder unter diesen Prozentsatz sinkt, wird eine Nachricht angezeigt, dass nur noch wenige Plätze verfügbar sind.';
$string['waitinglistplacesplacesleft'] = '{$a} freie Plätze auf der Warteliste';
$string['waitinglistshowplaceonwaitinglist'] = 'Wartelistenplätze aktivieren';
$string['waitinglistshowplaceonwaitinglistinfo'] = 'Warteliste: Zeige den Platz der Nutzer:innen auf der Warteliste an.
Sie können die Reihenfolge der Nutzer:innen auf der Warteliste per Drag & Drop anpassen.';
$string['waitinglisttaken'] = 'Auf der Warteliste';
$string['waitinglistusers'] = 'Nutzer:innen auf der Warteliste';
$string['waitingplacesavailable'] = 'Verfügbare Wartelistenplätze:  {$a->overbookingavailable} von {$a->maxoverbooking}';
$string['waitingtext'] = 'Wartelistenbestätigung';
$string['waitingtextmessage'] = 'Sie sind nun auf der Warteliste von:
{$a->bookingdetails}
<p>##########################################</p>
Buchungsstatus: {$a->status}
Teilnehmer:   {$a->participant}
Zur Buchungsübersicht: {$a->bookinglink}
Hier geht\'s zum dazugehörigen Kurs: {$a->courselink}
';
$string['waitingtextsubject'] = 'Buchung auf Warteliste für {$a->title}';
$string['waitingtextsubjectbookingmanager'] = 'Wartelistenbuchung für {$a->title} von {$a->participant}';
$string['waitspaceavailable'] = 'Wartelistenplätze verfügbar';
$string['warningcustomfieldsforbiddenshortname'] = 'Sie können die folgenden Kurzbezeichnungen für benutzerdefinierte Felder nicht verwenden: <b>{$a}</b>.
Bitte wählen Sie eine andere Kurzbezeichnung.';
$string['warningonlyteachersofselectedinstances'] = 'Hinweis: Hier werden aktuell nur Trainer:innen angezeigt,
die Trainer:innen in einer der in der <a href="{$a}" target="_blank">globalen Einstellung "allteacherspagebookinginstances"</a>
ausgewählten Buchungsinstanzen sind.';
$string['wednesday'] = 'Mittwoch';
$string['week'] = "Woche";
$string['whatsnew'] = 'Was ist neu?';
$string['whichview'] = 'Standardansicht in der Buchungsoptionsübersicht';
$string['whichviewerror'] = 'Die Standardansicht muss auch in den Ansichten der Buchungsoptionsübersicht ausgewählt werden';
$string['withselected'] = 'Ausgewählte Nutzer:innen';
$string['wrongdataallfields'] = 'Bitte alle Felder ausfüllen!';
$string['wronglabels'] = 'Die importierten Spaltennamen entsprechen nicht der Vorgabe. {$a} kann nicht importiert werden.';
$string['xusersarebooked'] = '{$a} Nutzer:innen sind gebucht';
$string['yes'] = 'Ja';
$string['youareediting'] = 'Sie bearbeiten "<b>{$a}</b>".';
$string['youareusingconfig'] = 'Sie verwenden folgende Formular-Konfiguration: {$a}';
$string['yourplaceonwaitinglist'] = 'Sie sind auf Platz {$a} auf der Warteliste';
$string['yourselection'] = 'Ihre Auswahl';
$string['zoommeeting'] = 'Zoom-Meeting';

// phpcs:disable
/*$string['ersaverelationsforoptiondates'] = 'Entity auch für jeden Termin speichern';
$string['confirm:ersaverelationsforoptiondates'] = '<span class="text-danger">
<b>Achtung:</b> Diese Buchungsoption hat Termine mit unterschiedlichen Entities.
Wollen Sie wirklich ALLE Termine mit der ausgewählten Entity überschreiben?</span>';
$string['error:ersaverelationsforoptiondates'] = 'Bitte bestätigen Sie, dass Sie abweichende Entities überschreiben wollen.';*/
// phpcs:enable
