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
 * English lang strings of the booking module
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

global $CFG;

// General strings.
$string['accept'] = 'Accepter';
$string['aftersubmitaction'] = 'Après avoir enregistré...';
$string['age'] = 'Âge';
$string['allowupdatedays'] = 'Jours avant la date de référence';
$string['areyousure:book'] = 'Cliquez à nouveau pour confirmer la réservation';
$string['areyousure:cancel'] = 'Cliquez à nouveau pour confirmer l\'annulation';
$string['assesstimestart'] = 'Début de la période d\'évaluation';
$string['assesstimefinish'] = 'Fin de la période d\'évaluation';
$string['assignteachers'] = 'Attribuer des enseignants :';
$string['alreadypassed'] = 'Déjà passé';
$string['bookingopeningtime'] = 'Réservable à partir de';
$string['bookingclosingtime'] = 'Réservable jusqu\'à';
$string['bookingoption'] = 'Option de réservation';
$string['bookingoptionnamewithoutprefix'] = 'Nom (sans préfixe)';
$string['bookings'] = 'Réservations';
$string['cancelallusers'] = 'Annuler la réservation pour tous les utilisateurs';
$string['cancelmyself'] = 'Annuler ma réservation';
$string['cancelsign'] = '<i class="fa fa-ban" aria-hidden="true"></i>';
$string['canceluntil'] = 'L\'annulation n\'est possible que jusqu\'à une certaine date';
$string['close'] = 'Fermer';
$string['collapsedescriptionoff'] = 'Ne pas réduire les descriptions';
$string['collapsedescriptionmaxlength'] = 'Réduire les descriptions (longueur max.)';
$string['collapsedescriptionmaxlength_desc'] = 'Entrez la longueur maximale de caractères d\'une description. Les descriptions ayant plus de caractères seront réduites.';
$string['confirmoptioncreation'] = 'Voulez-vous diviser cette option de réservation afin qu\'une option de réservation distincte soit créée
 pour chaque date individuelle de cette option de réservation ?';
$string['createoptionsfromoptiondate'] = 'Pour chaque date d\'option, créer une nouvelle option';
$string['customformnotchecked'] = 'Vous n\'avez pas encore accepté.';
$string['customfieldsplaceholdertext'] = 'Champs personnalisés';
$string['updatebooking'] = 'Mettre à jour la réservation';
$string['booking:manageoptiontemplates'] = "Gérer les modèles d'options";
$string['booking:editbookingrules'] = "Modifier les règles (Pro)";
$string['booking:overrideboconditions'] = 'L\'utilisateur peut réserver même lorsque les conditions sont fausses.';
$string['confirmchangesemester'] = 'OUI, je veux vraiment supprimer toutes les dates existantes de l\'instance de réservation et en générer de nouvelles.';
$string['course'] = 'Cours Moodle';
$string['courseduplicating'] = 'NE PAS SUPPRIMER cet élément. Le cours Moodle est en cours de copie avec la prochaine exécution de la tâche CRON.';
$string['courses'] = 'Cours';
$string['course_s'] = 'Cours';
$string['custom_bulk_message_sent'] = 'Message personnalisé envoyé en masse (> 75% des utilisateurs réservés, min. 3)';
$string['custom_message_sent'] = 'Message personnalisé envoyé';
$string['date_s'] = 'Date(s)';
$string['dayofweek'] = 'Jour de la semaine';
$string['deduction'] = 'Déduction';
$string['deductionreason'] = 'Raison de la déduction';
$string['deductionnotpossible'] = 'Tous les enseignants étaient présents à cette date. Aucune déduction ne peut donc être enregistrée.';
$string['defaultoptionsort'] = 'Tri par défaut par colonne';
$string['doyouwanttobook'] = 'Voulez-vous réserver <b>{$a}</b> ?';
$string['from'] = 'De';
$string['generalsettings'] = 'Paramètres généraux';
$string['global'] = 'global';
$string['gotomanageresponses'] = '&lt;&lt; Gérer les réservations';
$string['gotomoodlecourse'] = 'Aller au cours Moodle';
$string['limitfactor'] = 'Facteur de limitation des réservations';
$string['maxperuserdontcountpassed'] = 'Nombre max. de réservations : Ignorer les cours passés';
$string['maxperuserdontcountpassed_desc'] = 'Lors du calcul du nombre maximum de réservations par utilisateur et par instance,
ne pas compter les options de réservation déjà passées';
$string['maxperuserdontcountcompleted'] = 'Nombre max. de réservations : Ignorer les réservations complétées';
$string['maxperuserdontcountcompleted_desc'] = 'Ne pas compter les réservations marquées comme "complétées" ou ayant un statut de présence "Présent" ou "Complet" lors du calcul du nombre maximum de réservations par utilisateur et par instance';
$string['maxperuserdontcountnoshow'] = 'Nombre max. de réservations : Ignorer les utilisateurs absents';
$string['maxperuserdontcountnoshow_desc'] = 'Ne pas compter les réservations marquées comme "Absence"
lors du calcul du nombre maximum de réservations par utilisateur et par instance';
$string['messageprovider:bookingconfirmation'] = "Confirmations de réservation";
$string['name'] = 'Nom';
$string['noselection'] = 'Aucune sélection';
$string['optionsfield'] = 'Champ d\'option de réservation';
$string['optionsfields'] = 'Champs d\'option de réservation';
$string['optionsiteach'] = 'Enseigné par moi';
$string['placeholders'] = 'Espaces réservés';
$string['pricefactor'] = 'Facteur de prix';
$string['profilepicture'] = 'Photo de profil';
$string['responsesfields'] = 'Champs dans la liste des participants';
$string['responsible'] = 'Responsable';
$string['responsiblecontact'] = 'Personne de contact responsable';
$string['responsiblecontactcanedit'] = 'Permettre aux contacts responsables de modifier';
$string['responsiblecontactcanedit_desc'] = 'Activez ce paramètre si vous souhaitez permettre aux personnes de contact responsables
de modifier leurs options de réservation et de voir et de modifier la liste des utilisateurs réservés.<br>
<b>Important :</b> La personne de contact responsable doit également disposer de la capacité
<b>mod/booking:addeditownoption</b>.';
$string['responsiblecontact_help'] = 'Choisissez une personne responsable de cette option de réservation.
Ce n\'est pas censé être l\'enseignant !';
$string['reviewed'] = 'Revu';
$string['rowupdated'] = 'Ligne mise à jour.';
$string['search'] = 'Rechercher...';
$string['semesterid'] = 'Identifiant du semestre';
$string['nosemester'] = 'Aucun semestre choisi';
$string['sendmailtoallbookedusers'] = 'Envoyer un e-mail à tous les utilisateurs réservés';
$string['sortorder'] = 'Ordre de tri';
$string['sortorder:asc'] = 'A&rarr;Z';
$string['sortorder:desc'] = 'Z&rarr;A';
$string['teachers'] = 'Enseignants';
$string['teacher_s'] = 'Enseignant(s)';
$string['assignteachers'] = 'Attribuer des enseignants :';
$string['thankyoubooked'] = '<i class="fa fa-3x fa-calendar-check-o text-success" aria-hidden="true"></i><br><br>
Merci ! Vous avez réservé avec succès <b>{$a}</b>.';
$string['thankyoucheckout'] = '<i class="fa fa-3x fa-shopping-cart text-success" aria-hidden="true"></i><br><br>
Merci ! Vous avez ajouté <b>{$a}</b> au panier. Cliquez maintenant sur <b>"Passer à la caisse"</b>
 pour continuer.';
$string['thankyouwaitinglist'] = '<i class="fa fa-3x fa-clock-o text-primary" aria-hidden="true"></i><br><br>
 Vous avez été ajouté à la liste d\'attente pour <b>{$a}</b>. Vous monterez automatiquement en cas de désistement.';
$string['thankyouerror'] = '<i class="fa fa-3x fa-frown-o text-danger" aria-hidden="true"></i><br>
Malheureusement, une erreur s\'est produite lors de la réservation de <b>{$a}</b>.';
$string['timefilter:coursetime'] = 'Temps du cours';
$string['timefilter:bookingtime'] = 'Temps de réservation';
$string['toomanytoshow'] = 'Trop de résultats trouvés...';
$string['unsubscribe:successnotificationlist'] = 'Vous avez été désabonné avec succès des notifications par e-mail pour "{$a}".';
$string['unsubscribe:errorotheruser'] = 'Vous n\'êtes pas autorisé à désabonner un autre utilisateur que vous-même !';
$string['unsubscribe:alreadyunsubscribed'] = 'Vous êtes déjà désabonné.';
$string['until'] = 'Jusqu\'à';
$string['userprofilefield'] = "Champ de profil";
$string['usersmatching'] = 'Utilisateurs correspondants';
$string['allmoodleusers'] = 'Tous les utilisateurs de ce site';
$string['enrolledusers'] = 'Utilisateurs inscrits au cours';
$string['nopriceisset'] = 'Aucun prix n\'a été fixé pour la catégorie de prix {$a}';
$string['youareediting'] = 'Vous modifiez "<b>{$a}</b>".';

// Badges.
$string['badge:pro'] = '<span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>';
$string['badge:experimental'] = '<span class="badge bg-danger text-light"><i class="fa fa-flask" aria-hidden="true"></i> Expérimental</span>';

// Erreurs.
$string['error:ruleactionsendcopynotpossible'] = 'Il est impossible d\'envoyer une copie d\'e-mail pour l\'événement choisi.';
$string['error:choosevalue'] = 'Vous devez choisir une valeur ici.';
$string['error:confirmthatyouaresure'] = 'Veuillez confirmer que vous êtes sûr.';
$string['error:taskalreadystarted'] = 'Vous avez déjà commencé une tâche !';
$string['error:entervalue'] = 'Vous devez entrer une valeur ici.';
$string['error:negativevaluenotallowed'] = 'Veuillez entrer une valeur positive.';
$string['error:pricemissing'] = 'Veuillez entrer un prix.';
$string['error:missingcapability'] = 'La capacité nécessaire est manquante. Veuillez contacter un administrateur.';

// Index.php.
$string['week'] = "Semaine";
$string['question'] = "Question";
$string['answer'] = "Réponse";
$string['topic'] = "Sujet";

// Enseignants.
$string['teacher'] = 'Enseignant';
$string['allteachers'] = 'Tous les enseignants';
$string['showallteachers'] = '&gt;&gt; Voir tous les enseignants';
$string['showcoursesofteacher'] = 'Cours';
$string['messagebutton'] = 'Message';
$string['messagingteacherimpossible'] = 'Vous ne pouvez pas envoyer de messages à cet enseignant
 car vous n\'êtes inscrit à aucun de ses cours.';
$string['sendmail'] = 'Mail';
$string['teachernotfound'] = 'L\'enseignant n\'a pas pu être trouvé ou n\'existe pas.';
$string['notateacher'] = 'L\'utilisateur sélectionné n\'enseigne aucun cours et n\'est probablement pas un enseignant.';
$string['showteachersmailinglist'] = 'Afficher une liste d\'e-mails pour tous les enseignants...';

// Teacher_added.php.
$string['eventteacher_added'] = 'Enseignant ajouté';
$string['eventteacher_removed'] = 'Enseignant supprimé';

// Renderer.php.
$string['myinstitution'] = 'Mon institution';
$string['visibleoptions'] = 'Options de réservation visibles';
$string['invisibleoptions'] = 'Options de réservation invisibles';
$string['addusertogroup'] = 'Ajouter l\'utilisateur au groupe : ';

// View.php.
$string['addmorebookings'] = 'Ajouter plus de réservations';
$string['allowupdate'] = 'Autoriser la mise à jour de la réservation';
$string['answered'] = 'Répondu';
$string['dontaddpersonalevents'] = 'Ne pas ajouter d\'événements personnels au calendrier';
$string['dontaddpersonaleventsdesc'] = 'Pour chaque option réservée et pour toutes ses sessions, des événements personnels sont créés dans le calendrier Moodle. Les supprimer améliore les performances pour les sites à forte charge.';
$string['attachical'] = 'Joindre un événement iCal unique par réservation';
$string['attachicaldesc'] = 'Les notifications par e-mail incluront un événement iCal joint, si cette option est activée. L\'iCal n\'inclura qu\'une heure de début et une heure de fin définies
dans les paramètres de l\'option de réservation ou l\'heure de début de la première session à l\'heure de fin de la dernière session';
$string['attachicalsess'] = 'Joindre toutes les dates de session en tant qu\'événements iCal';
$string['attachicalsessdesc'] = 'Les notifications par e-mail incluront toutes les dates de session définies pour une option de réservation en tant que pièce jointe iCal.';
$string['icalcancel'] = 'Inclure l\'événement iCal lorsque la réservation est annulée comme événement annulé';
$string['icalcanceldesc'] = 'Lorsqu\'un utilisateur annule une réservation ou est retiré de la liste des utilisateurs réservés, joindre une pièce jointe iCal comme événement annulé.';
$string['booking'] = 'Réservation';
$string['bookinginstance'] = 'Instance de réservation';
$string['booking:addinstance'] = 'Ajouter une nouvelle réservation';
$string['booking:choose'] = 'Réserver';
$string['booking:deleteresponses'] = 'Supprimer les réponses';
$string['booking:downloadresponses'] = 'Télécharger les réponses';
$string['booking:readresponses'] = 'Lire les réponses';
$string['booking:rate'] = 'Évaluer les options de réservation choisies';
$string['booking:sendpollurl'] = 'Envoyer l\'URL du sondage';
$string['booking:sendpollurltoteachers'] = 'Envoyer l\'URL du sondage aux enseignants';
$string['booking:subscribeusers'] = 'Faire des réservations pour d\'autres utilisateurs';
$string['booking:updatebooking'] = 'Gérer les options de réservation';
$string['booking:viewallratings'] = 'Voir toutes les évaluations brutes données par les individus';
$string['booking:viewanyrating'] = 'Voir les évaluations totales reçues par quiconque';
$string['booking:viewrating'] = 'Voir l\'évaluation totale que vous avez reçue';
$string['booking:addeditownoption'] = 'Ajouter une nouvelle option et modifier ses propres options.';
$string['booking:canseeinvisibleoptions'] = 'Voir les options invisibles.';
$string['booking:changelockedcustomfields'] = 'Peut modifier les champs d\'option de réservation personnalisés verrouillés.';

$string['booking:expertoptionform'] = "Formulaire d'option expert";
$string['booking:reducedoptionform1'] = "1. Formulaire d'option réduit pour la catégorie de cours";
$string['booking:reducedoptionform2'] = "2. Formulaire d'option réduit pour la catégorie de cours";
$string['booking:reducedoptionform3'] = "3. Formulaire d'option réduit pour la catégorie de cours";
$string['booking:reducedoptionform4'] = "4. Formulaire d'option réduit pour la catégorie de cours";

$string['booking:comment'] = 'Ajouter des commentaires';
$string['booking:managecomments'] = 'Gérer les commentaires';
$string['bookingfull'] = 'Il n\'y a pas de places disponibles';
$string['bookingname'] = 'Nom de l\'instance de réservation';
$string['bookingoptionsmenu'] = 'Options de réservation';
$string['bookingopen'] = 'Ouvrir';
$string['bookingtext'] = 'Texte de réservation';
$string['choose...'] = 'Choisir...';
$string['datenotset'] = 'Date non fixée';
$string['daystonotify'] = 'Nombre de jours avant le début de l\'événement pour notifier les participants';
$string['daystonotify_help'] = "Fonctionne uniquement si la date de début et de fin de l'option est fixée ! 0 pour désactiver cette fonctionnalité.";
$string['daystonotify2'] = 'Deuxième notification avant le début de l\'événement pour notifier les participants.';
$string['daystonotifyteachers'] = 'Nombre de jours avant le début de l\'événement pour notifier les enseignants' . $string['badge:pro'];
$string['bookinganswer_cancelled'] = 'Option de réservation annulée pour/par l\'utilisateur';

// Événements d'options de réservation.
$string['bookingoption_cancelled'] = "Option de réservation annulée pour tous";
$string['bookingoption_booked'] = 'Option de réservation réservée';
$string['bookingoption_completed'] = 'Option de réservation complétée';
$string['bookingoption_created'] = 'Option de réservation créée';
$string['bookingoption_updated'] = 'Option de réservation mise à jour';
$string['bookingoption_deleted'] = 'Option de réservation supprimée';
$string['bookinginstance_updated'] = 'Instance de réservation mise à jour';
$string['records_imported'] = 'Options de réservation importées via csv';
$string['records_imported_description'] = '{$a} options de réservation importées via csv';

$string['eventreport_viewed'] = 'Rapport consulté';
$string['eventuserprofilefields_updated'] = 'Profil utilisateur mis à jour';
$string['existingsubscribers'] = 'Abonnés existants';
$string['expired'] = 'Désolé, cette activité s\'est terminée le {$a} et n\'est plus disponible';
$string['fillinatleastoneoption'] = 'Vous devez fournir au moins deux réponses possibles.';
$string['full'] = 'Complet';
$string['infonobookingoption'] = 'Pour ajouter une option de réservation, veuillez utiliser le bloc de paramètres ou l\'icône de paramètres en haut de la page';
$string['infotext:prolicensenecessary'] = 'Vous avez besoin d\'une licence Booking PRO pour utiliser cette fonctionnalité.
 <a href="https://wunderbyte.at/en/contact" target="_blank">Contactez Wunderbyte</a> si vous souhaitez acheter une licence PRO.';
$string['limit'] = 'Limite';
$string['modulename'] = 'Réservation';
$string['modulenameplural'] = 'Réservations';
$string['mustchooseone'] = 'Vous devez choisir une option avant d\'enregistrer. Rien n\'a été enregistré.';
$string['nofieldchosen'] = 'Aucun champ choisi';
$string['noguestchoose'] = 'Désolé, les invités ne sont pas autorisés à entrer des données';
$string['noresultsviewable'] = 'Les résultats ne sont pas actuellement consultables.';
$string['nosubscribers'] = 'Il n\'y a pas d\'enseignants assignés !';
$string['notopenyet'] = 'Désolé, cette activité n\'est pas disponible avant le {$a} ';
$string['pluginadministration'] = 'Administration des réservations';
$string['pluginname'] = 'Réservation';
$string['potentialsubscribers'] = 'Abonnés potentiels';
$string['proversiononly'] = 'Passez à Booking PRO pour utiliser cette fonctionnalité.';
$string['proversion:cardsview'] = 'Avec Booking PRO, vous pouvez également utiliser la vue par cartes.';
$string['removeresponses'] = 'Supprimer toutes les réponses';
$string['responses'] = 'Réponses';
$string['responsesto'] = 'Réponses à {$a} ';
$string['spaceleft'] = 'place disponible';
$string['spacesleft'] = 'places disponibles';
$string['subscribersto'] = 'Enseignants pour \'{$a}\'';
$string['taken'] = 'Pris';
$string['timerestrict'] = 'Restreindre les réponses à cette période : Ceci est obsolète et sera supprimé. Veuillez utiliser les paramètres "Restreindre l\'accès" pour rendre l\'activité de réservation disponible pour une certaine période';
$string['restrictanswerperiodopening'] = 'La réservation est possible uniquement après une certaine date';
$string['restrictanswerperiodclosing'] = 'La réservation est possible uniquement jusqu\'à une certaine date';
$string['to'] = 'à';
$string['viewallresponses'] = 'Gérer les {$a} réponses';
$string['yourselection'] = 'Votre sélection';

// Subscribeusers.php.
$string['cannotremovesubscriber'] = 'Vous devez supprimer la complétion de l\'activité avant d\'annuler la réservation. La réservation n\'a pas été annulée !';
$string['allchangessaved'] = 'Toutes les modifications ont été enregistrées.';
$string['backtoresponses'] = '&lt;&lt; Retour aux réponses';
$string['allusersbooked'] = 'Tous les {$a} utilisateurs sélectionnés ont été assignés avec succès à cette option de réservation.';
$string['notallbooked'] = 'Les utilisateurs suivants n\'ont pas pu être réservés en raison de la limite maximale de réservations par utilisateur ou du manque de places disponibles pour l\'option de réservation : {$a}';
$string['enrolledinoptions'] = "déjà réservé dans les options de réservation : ";
$string['onlyusersfrominstitution'] = 'Vous ne pouvez ajouter que des utilisateurs de cette institution : {$a}';
$string['resultofcohortorgroupbooking'] = '<p>Voici le résultat de votre réservation de cohorte :</p>
<ul>
<li>{$a->sumcohortmembers} utilisateurs trouvés dans les cohortes sélectionnées</li>
<li>{$a->sumgroupmembers} utilisateurs trouvés dans les groupes sélectionnés</li>
<li><b>{$a->subscribedusers} utilisateurs ont été réservés pour cette option</b></li>
</ul>';
$string['problemsofcohortorgroupbooking'] = '<br><p>Tous les utilisateurs n\'ont pas pu être réservés avec la réservation de cohorte :</p>
<ul>
<li>{$a->notenrolledusers} utilisateurs ne sont pas inscrits au cours</li>
<li>{$a->notsubscribedusers} utilisateurs non réservés pour d\'autres raisons</li>
</ul>';
$string['nogrouporcohortselected'] = 'Vous devez sélectionner au moins un groupe ou une cohorte.';
$string['bookanyoneswitchon'] = '<i class="fa fa-user-plus" aria-hidden="true"></i> Permettre la réservation d\'utilisateurs non inscrits';
$string['bookanyoneswitchoff'] = '<i class="fa fa-user-times" aria-hidden="true"></i> Ne pas permettre la réservation d\'utilisateurs non inscrits (recommandé)';
$string['bookanyonewarning'] = 'Attention : Vous pouvez maintenant réserver tous les utilisateurs que vous voulez. Utilisez ce paramètre uniquement si vous savez ce que vous faites.
 Réserver des utilisateurs non inscrits au cours peut causer des problèmes.';

// Subscribe_cohort_or_group_form.php.
$string['scgfcohortheader'] = 'Inscription de cohorte';
$string['scgfgroupheader'] = 'Inscription de groupe';
$string['scgfselectcohorts'] = 'Sélectionnez la(es) cohorte(s)';
$string['scgfbookgroupscohorts'] = 'Réserver la(es) cohorte(s) ou le(s) groupe(s)';
$string['scgfselectgroups'] = 'Sélectionnez le(s) groupe(s)';

// Formulaire de réservation.
$string['address'] = 'Adresse';
$string['general'] = 'Général';
$string['advancedoptions'] = 'Options avancées';
$string['btnbooknowname'] = 'Nom du bouton : Réserver maintenant';
$string['btncacname'] = 'Nom du bouton : Confirmer la complétion de l\'activité';
$string['btncancelname'] = 'Nom du bouton : Annuler la réservation';
$string['courseurl'] = 'URL du cours';
$string['description'] = 'Description';
$string['disablebookingusers'] = 'Désactiver la réservation des utilisateurs - masquer le bouton Réserver maintenant.';
$string['disablecancel'] = "Désactiver l'annulation de cette option de réservation";
$string['disablecancelforinstance'] = "Désactiver l'annulation pour l'ensemble de l'instance de réservation.
(Si vous activez ceci, il ne sera pas possible d'annuler une réservation dans cette instance.)";
$string['bookotheruserslimit'] = 'Nombre max. d\'utilisateurs qu\'un enseignant assigné à l\'option peut réserver';
$string['department'] = 'Département';
$string['institution'] = 'Institution';
$string['institution_help'] = 'Vous pouvez soit entrer le nom de l\'institution manuellement, soit choisir dans une liste d\'institutions précédentes.
                                    Vous pouvez choisir une seule institution. Une fois enregistré, l\'institution sera ajoutée à la liste.';
$string['lblsputtname'] = 'Nom du label : Envoyer l\'URL du sondage aux enseignants';
$string['lblteachname'] = 'Nom du label : Enseignants';
$string['limitanswers_help'] = 'Si vous modifiez cette option et que vous avez des personnes réservées, vous pouvez les supprimer sans notification !';
$string['location'] = 'Emplacement';
$string['location_help'] = 'Vous pouvez soit entrer le nom de l\'emplacement manuellement, soit choisir dans une liste d\'emplacements précédents.
                                    Vous pouvez choisir un seul emplacement. Une fois enregistré, l\'emplacement sera ajouté à la liste.';
$string['removeafterminutes'] = 'Supprimer la complétion de l\'activité après N minutes';
$string['banusernames'] = 'Interdire les noms d\'utilisateur';
$string['banusernames_help'] = 'Pour limiter les noms d\'utilisateur qui ne peuvent pas postuler, écrivez simplement dans ce champ, et séparez par une virgule. Pour interdire les noms d\'utilisateur se terminant par gmail.com et yahoo.com, écrivez simplement : gmail.com, yahoo.com';
$string['completionmodule'] = 'Après complétion de l\'activité de cours sélectionnée, activer la suppression en masse des réservations des utilisateurs';
$string['completionmodule_help'] = 'Afficher le bouton de suppression en masse pour les réponses de réservation, si un autre module de cours a été complété. Les réservations des utilisateurs seront supprimées en un clic sur la page de rapport ! Seules les activités avec la complétion activée peuvent être sélectionnées dans la liste.';
$string['teacherroleid'] = 'Inscrire l\'enseignant avec ce rôle au cours';
$string['bookingoptiontitle'] = 'Titre de l\'option de réservation';
$string['addastemplate'] = 'Ajouter comme modèle';
$string['notemplate'] = 'Ne pas utiliser comme modèle';
$string['astemplate'] = 'Utiliser comme modèle dans ce cours';
$string['asglobaltemplate'] = 'Utiliser comme modèle global';
$string['templatedeleted'] = 'Le modèle a été supprimé !';
$string['bookingoptionname'] = 'Nom de l\'option de réservation';
$string['recurringheader'] = 'Options récurrentes';
$string['repeatthisbooking'] = 'Répéter cette option';
$string['howmanytimestorepeat'] = 'Combien de fois répéter ?';
$string['howoftentorepeat'] = 'À quelle fréquence répéter ?';

// Catégories.
$string['categoryheader'] = '[OBSOLESCENT] Catégorie';
$string['category'] = 'Catégorie';
$string['categories'] = 'Catégories';
$string['addcategory'] = 'Modifier les catégories';
$string['forcourse'] = 'pour le cours';
$string['addnewcategory'] = 'Ajouter une nouvelle catégorie';
$string['categoryname'] = 'Nom de la catégorie';
$string['rootcategory'] = 'Racine';
$string['selectcategory'] = 'Sélectionner la catégorie parente';
$string['editcategory'] = 'Modifier';
$string['deletecategory'] = 'Supprimer';
$string['deletesubcategory'] = 'Veuillez d\'abord supprimer toutes les sous-catégories de cette catégorie !';
$string['usedinbooking'] = 'Vous ne pouvez pas supprimer cette catégorie, car elle est utilisée dans la réservation !';
$string['successfulldeleted'] = 'La catégorie a été supprimée !';

// Événements.
$string['bookingoptiondate_created'] = 'Date de l\'option de réservation créée';
$string['bookingoptiondate_updated'] = 'Date de l\'option de réservation mise à jour';
$string['bookingoptiondate_deleted'] = 'Date de l\'option de réservation supprimée';
$string['custom_field_changed'] = 'Champ personnalisé modifié';
$string['pricecategory_changed'] = 'Catégorie de prix modifiée';
$string['reminder1_sent'] = 'Premier rappel envoyé';
$string['reminder2_sent'] = 'Deuxième rappel envoyé';
$string['reminder_teacher_sent'] = 'Rappel envoyé à l\'enseignant';
$string['optiondates_teacher_added'] = 'Enseignant de substitution ajouté';
$string['optiondates_teacher_deleted'] = 'Enseignant supprimé du journal d\'enseignement';
$string['booking_failed'] = 'Réservation échouée';
$string['booking_afteractionsfailed'] = 'Les actions après la réservation ont échoué';

// View.php.
$string['bookingpolicyagree'] = 'J\'ai lu, compris et j\'accepte la politique de réservation.';
$string['bookingpolicynotchecked'] = 'Vous n\'avez pas accepté la politique de réservation.';
$string['allbookingoptions'] = 'Télécharger les utilisateurs pour toutes les options de réservation';
$string['attachedfiles'] = 'Fichiers joints';
$string['availability'] = 'Toujours disponible';
$string['available'] = 'Places disponibles';
$string['booked'] = 'Réservé';
$string['fullybooked'] = 'Complet';
$string['notifyme'] = 'Notifier quand disponible';
$string['alreadyonlist'] = 'Vous serez notifié';
$string['bookedpast'] = 'Réservé (cours terminé)';
$string['bookingdeleted'] = 'Votre réservation a été annulée';
$string['bookingmeanwhilefull'] = 'Entre-temps, quelqu\'un a déjà pris la dernière place';
$string['bookingsaved'] = 'Votre réservation a été enregistrée avec succès. Vous pouvez maintenant procéder à la réservation d\'autres cours.';
$string['booknow'] = 'Réserver maintenant';
$string['bookotherusers'] = 'Réserver pour d\'autres utilisateurs';
$string['cancelbooking'] = 'Annuler la réservation';
$string['closed'] = 'Réservation fermée';
$string['confirmbookingoffollowing'] = 'Veuillez confirmer la réservation du cours suivant';
$string['confirmdeletebookingoption'] = 'Voulez-vous vraiment supprimer cette option de réservation ?';
$string['coursedate'] = 'Date';
$string['createdbywunderbyte'] = 'Module de réservation créé par Wunderbyte GmbH';
$string['deletebooking'] = 'Voulez-vous vraiment vous désinscrire du cours suivant ? <br /><br /> <b>{$a} </b>';
$string['deletethisbookingoption'] = 'Supprimer cette option de réservation';
$string['deleteuserfrombooking'] = 'Voulez-vous vraiment supprimer les utilisateurs de la réservation ?';
$string['download'] = 'Télécharger';
$string['downloadusersforthisoptionods'] = 'Télécharger les utilisateurs en .ods';
$string['downloadusersforthisoptionxls'] = 'Télécharger les utilisateurs en .xls';
$string['endtimenotset'] = 'Date de fin non fixée';
$string['mustfilloutuserinfobeforebooking'] = 'Avant de passer au formulaire de réservation, veuillez remplir des informations personnelles de réservation';
$string['subscribeuser'] = 'Voulez-vous vraiment inscrire les utilisateurs au cours suivant';
$string['deleteuserfrombooking'] = 'Voulez-vous vraiment supprimer les utilisateurs de la réservation ?';
$string['showallbookingoptions'] = 'Toutes les options de réservation';
$string['showmybookingsonly'] = 'Mes options réservées';
$string['showmyfieldofstudyonly'] = "Mon domaine d'étude";
$string['mailconfirmationsent'] = 'Vous recevrez bientôt un e-mail de confirmation';
$string['confirmdeletebookingoption'] = 'Voulez-vous vraiment supprimer cette option de réservation ?';
$string['norighttobook'] = 'La réservation n\'est pas possible pour votre rôle d\'utilisateur. Veuillez contacter l\'administrateur du site pour vous attribuer les droits appropriés ou vous inscrire/connexer.';
$string['maxperuserwarning'] = 'Vous avez actuellement utilisé {$a->count} sur {$a->limit} réservations maximum disponibles ({$a->eventtype}) pour votre compte utilisateur';
$string['bookedpast'] = 'Réservé (cours terminé)';
$string['attachedfiles'] = 'Fichiers joints';
$string['eventduration'] = 'Durée de l\'événement';
$string['eventdesc:bookinganswercancelledself'] = 'L\'utilisateur "{$a->user}" a annulé "{$a->title}".';
$string['eventdesc:bookinganswercancelled'] = 'L\'utilisateur "{$a->user}" a annulé "{$a->relateduser}" de "{$a->title}".';
$string['eventpoints'] = 'Points';
$string['mailconfirmationsent'] = 'Vous recevrez bientôt un e-mail de confirmation';
$string['managebooking'] = 'Gérer';
$string['mustfilloutuserinfobeforebooking'] = 'Avant de passer au formulaire de réservation, veuillez remplir des informations personnelles de réservation';
$string['nobookingselected'] = 'Aucune option de réservation sélectionnée';
$string['notbooked'] = 'Pas encore réservé';
$string['onwaitinglist'] = 'Vous êtes sur la liste d\'attente';
$string['confirmed'] = 'Confirmé';
$string['organizatorname'] = 'Nom de l\'organisateur';
$string['organizatorname_help'] = 'Vous pouvez soit entrer le nom de l\'organisateur manuellement, soit choisir dans une liste d\'organisateurs précédents.
                                    Vous pouvez choisir un seul organisateur. Une fois enregistré, l\'organisateur sera ajouté à la liste.';
$string['availableplaces'] = 'Places disponibles : {$a->available} sur {$a->maxanswers}';
$string['pollurl'] = 'URL du sondage';
$string['pollurlteachers'] = 'URL du sondage pour les enseignants';
$string['feedbackurl'] = 'URL du sondage';
$string['feedbackurlteachers'] = 'URL du sondage pour les enseignants';
$string['select'] = 'Sélection';
$string['activebookingoptions'] = 'Options de réservation actives';
$string['starttimenotset'] = 'Date de début non fixée';
$string['subscribetocourse'] = 'Inscrire les utilisateurs au cours';
$string['subscribeuser'] = 'Voulez-vous vraiment inscrire les utilisateurs au cours suivant';
$string['tagtemplates'] = 'Modèles d\'étiquettes';
$string['unlimitedplaces'] = 'Illimité';
$string['userdownload'] = 'Télécharger les utilisateurs';
$string['waitinglist'] = 'Liste d\'attente';
$string['waitingplacesavailable'] = 'Places disponibles sur la liste d\'attente : {$a->overbookingavailable} sur {$a->maxoverbooking}';
$string['waitspaceavailable'] = 'Places disponibles sur la liste d\'attente';
$string['banusernameswarning'] = "Votre nom d'utilisateur est banni, vous ne pouvez donc pas réserver.";
$string['duplicatebooking'] = 'Dupliquer cette option de réservation';
$string['moveoptionto'] = 'Déplacer l\'option de réservation vers une autre instance de réservation';

// Modèles d'étiquettes.
$string['cancel'] = 'Annuler';
$string['addnewtagtemplate'] = 'Ajouter un nouveau';
$string['addnewtagtemplate'] = 'Ajouter un nouveau modèle d\'étiquette';
$string['savenewtagtemplate'] = 'Enregistrer';
$string['tagtag'] = 'Étiquette';
$string['tagtext'] = 'Texte';
$string['wrongdataallfields'] = 'Veuillez remplir tous les champs !';
$string['tagsuccessfullysaved'] = 'L\'étiquette a été enregistrée.';
$string['edittag'] = 'Modifier';
$string['tagdeleted'] = 'Le modèle d\'étiquette a été supprimé !';

// Mod_booking\all_options.
$string['showdescription'] = 'Afficher la description';
$string['hidedescription'] = 'Masquer la description';
$string['cancelallusers'] = 'Annuler tous les utilisateurs réservés';

// Mod_form.
$string['signinlogoheader'] = 'Logo dans l\'en-tête à afficher sur la feuille de présence';
$string['signinlogofooter'] = 'Logo dans le pied de page à afficher sur la feuille de présence';
$string['textdependingonstatus'] = "Texte en fonction du statut de réservation";
$string['beforebookedtext'] = 'Avant la réservation';
$string['beforebookedtext_help'] = 'Message affiché avant que l\'option ne soit réservée';
$string['beforecompletedtext'] = 'Après réservation';
$string['beforecompletedtext_help'] = 'Message affiché après que l\'option soit réservée';
$string['aftercompletedtext'] = 'Après la complétion de l\'activité';
$string['aftercompletedtext_help'] = 'Message affiché après que l\'activité soit complétée';
$string['connectedbooking'] = '[OBSOLESCENT] Réservation connectée';
$string['errorpagination'] = 'Veuillez entrer un nombre supérieur à 0';
$string['notconectedbooking'] = 'Non connecté';
$string['connectedbooking_help'] = 'Instance de réservation éligible pour le transfert des utilisateurs réservés. Vous pouvez définir à partir de quelle option au sein de l\'instance de réservation sélectionnée et combien d\'utilisateurs vous accepterez.';
$string['allowbookingafterstart'] = 'Autoriser la réservation après le début du cours';
$string['cancancelbook'] = 'Permettre aux utilisateurs d\'annuler leur propre réservation';
$string['cancancelbookdays'] = 'Ne pas permettre aux utilisateurs d\'annuler leur réservation n jours avant le début. Un nombre négatif signifie que les utilisateurs peuvent encore annuler n jours APRÈS le début du cours.';
$string['cancancelbookdays:semesterstart'] = 'Ne pas permettre aux utilisateurs d\'annuler leur réservation n jours avant le début du <b>semestre</b>. Un nombre négatif signifie que les utilisateurs peuvent encore annuler n jours APRÈS le début du semestre.';
$string['cancancelbookdays:bookingopeningtime'] = 'Ne pas permettre aux utilisateurs d\'annuler leur réservation n jours avant le début de l\'<b>inscription</b> (heure d\'ouverture des réservations). Un nombre négatif signifie que les utilisateurs peuvent encore annuler n jours APRÈS le début de l\'inscription.';
$string['cancancelbookdays:bookingclosingtime'] = 'Ne pas permettre aux utilisateurs d\'annuler leur réservation n jours avant la fin de l\'<b>inscription</b> (heure de fermeture des réservations). Un nombre négatif signifie que les utilisateurs peuvent encore annuler n jours APRÈS la fin de l\'inscription.';
$string['cancancelbookdaysno'] = "Ne pas limiter";
$string['addtocalendar'] = 'Ajouter au calendrier du cours';
$string['caleventtype'] = 'Visibilité de l\'événement du calendrier';
$string['caldonotadd'] = 'Ne pas ajouter au calendrier du cours';
$string['caladdascourseevent'] = 'Ajouter au calendrier (visible uniquement pour les participants au cours)';
$string['caladdassiteevent'] = 'Ajouter au calendrier (visible pour tous les utilisateurs)';
$string['limitanswers'] = 'Limiter le nombre de participants';
$string['maxparticipantsnumber'] = 'Nombre max. de participants';
$string['maxoverbooking'] = 'Nombre max. de places sur la liste d\'attente';
$string['minanswers'] = 'Nombre min. de participants';
$string['defaultbookingoption'] = 'Options de réservation par défaut';
$string['activatemails'] = 'Activer les e-mails (confirmations, notifications et plus)';
$string['copymail'] = 'Envoyer un e-mail de confirmation au gestionnaire de réservation';
$string['bookingpolicy'] = 'Politique de réservation';
$string['viewparam'] = 'Type de vue';
$string['viewparam:list'] = 'Vue en liste';
$string['viewparam:cards'] = 'Vue en cartes';

$string['eventslist'] = 'Mises à jour récentes';
$string['showrecentupdates'] = 'Afficher les mises à jour récentes';

$string['error:semestermissingbutcanceldependentonsemester'] = 'Le paramètre pour calculer les périodes d\'annulation à partir du début du semestre est actif mais le semestre est manquant !';

$string['page:bookingpolicy'] = 'Politique de réservation';
$string['page:bookitbutton'] = 'Réserver';
$string['page:subbooking'] = 'Réservations supplémentaires';
$string['page:confirmation'] = 'Réservation terminée';
$string['page:checkout'] = 'Caisse';
$string['page:customform'] = 'Remplir le formulaire';

$string['confirmationmessagesettings'] = 'Paramètres des e-mails de confirmation';
$string['usernameofbookingmanager'] = 'Choisissez un gestionnaire de réservation';
$string['usernameofbookingmanager_help'] = 'Nom d\'utilisateur de l\'utilisateur qui sera affiché dans le champ "De" des notifications de confirmation. Si l\'option "Envoyer un e-mail de confirmation au gestionnaire de réservation" est activée, c\'est cet utilisateur qui reçoit une copie des notifications de confirmation.';
$string['bookingmanagererror'] = 'Le nom d\'utilisateur entré n\'est pas valide. Soit il n\'existe pas, soit il y a plus d\'un utilisateur avec ce nom d\'utilisateur (exemple : si vous avez l\'authentification mnet et locale activée)';
$string['autoenrol'] = 'Inscrire automatiquement les utilisateurs';
$string['autoenrol_help'] = 'Si sélectionné, les utilisateurs seront inscrits au cours correspondant dès qu\'ils feront la réservation et désinscrits de ce cours dès que la réservation sera annulée.';
$string['bookedtext'] = 'Confirmation de réservation';
$string['userleave'] = 'L\'utilisateur a annulé sa propre réservation (entrez 0 pour désactiver)';
$string['waitingtext'] = 'Confirmation de la liste d\'attente';
$string['statuschangetext'] = 'Message de changement de statut';
$string['deletedtext'] = 'Message d\'annulation de réservation (entrez 0 pour désactiver)';
$string['bookingchangedtext'] = 'Message à envoyer lorsqu\'une option de réservation change (ne sera envoyé qu\'aux utilisateurs ayant déjà réservé). Utilisez l\'espace réservé {changes} pour montrer les changements. Entrez 0 pour désactiver les notifications de changement.';
$string['comments'] = 'Commentaires';
$string['nocomments'] = 'Commentaires désactivés';
$string['allcomments'] = 'Tout le monde peut commenter';
$string['enrolledcomments'] = 'Uniquement les inscrits';
$string['completedcomments'] = 'Uniquement avec l\'activité complétée';
$string['ratings'] = 'Évaluations des options de réservation';
$string['noratings'] = 'Évaluations désactivées';
$string['allratings'] = 'Tout le monde peut évaluer';
$string['enrolledratings'] = 'Uniquement les inscrits';
$string['completedratings'] = 'Uniquement avec l\'activité complétée';
$string['notes'] = 'Notes de réservation';
$string['uploadheaderimages'] = 'Images d\'en-tête pour les options de réservation';
$string['bookingimagescustomfield'] = 'Champ personnalisé de l\'option de réservation pour faire correspondre les images d\'en-tête avec';
$string['bookingimages'] = 'Téléchargez les images d\'en-tête pour les options de réservation - elles doivent avoir exactement le même nom que la valeur du champ personnalisé sélectionné dans chaque option de réservation.';
$string['emailsettings'] = 'Paramètres des e-mails';

// Modèles de courrier (spécifiques à l'instance ou globaux).
$string['mailtemplatesadvanced'] = 'Activer les paramètres avancés pour les modèles d\'e-mails';
$string['mailtemplatessource'] = 'Définir la source des modèles de courrier ' . $string['badge:pro'];
$string['mailtemplatessource_help'] = '<b>Attention :</b> Si vous choisissez des modèles d\'e-mails globaux, les modèles d\'e-mails spécifiques à l\'instance ne seront pas utilisés. À la place, les modèles d\'e-mails spécifiés dans les paramètres du plugin de réservation seront utilisés. <br><br>
Veuillez vous assurer qu\'il existe des modèles d\'e-mails dans les paramètres de réservation pour chaque type d\'e-mail.';
$string['mailtemplatesinstance'] = 'Utiliser les modèles de courrier de cette instance de réservation (par défaut)';
$string['mailtemplatesglobal'] = 'Utiliser les modèles de courrier globaux des paramètres du plugin';

$string['feedbackurl_help'] = 'Entrez un lien vers un formulaire de retour d\'information qui doit être envoyé aux participants.
 Il peut être ajouté aux e-mails avec l\'espace réservé <b>{pollurl}</b>.';

$string['feedbackurlteachers_help'] = 'Entrez un lien vers un formulaire de retour d\'information qui doit être envoyé aux enseignants.
 Il peut être ajouté aux e-mails avec l\'espace réservé <b>{pollurlteachers}</b>.';

$string['bookingchangedtext_help'] = 'Entrez 0 pour désactiver les notifications de changement.';
$string['placeholders_help'] = 'Laissez ce champ vide pour utiliser le texte par défaut du site.';
$string['helptext:placeholders'] = '<div class="alert alert-info" style="margin-left: 200px;">
<a data-toggle="collapse" href="#collapsePlaceholdersHelptext" role="button" aria-expanded="false" aria-controls="collapsePlaceholdersHelptext">
  <i class="fa fa-question-circle" aria-hidden="true"></i><span>&nbsp;Espaces réservés que vous pouvez utiliser dans vos e-mails.</span>
</a>
</div>
<div class="collapse" id="collapsePlaceholdersHelptext">
  <div class="card card-body">
    {$a}
  </div>
</div>';

// Espaces réservés.
$string['bookingdetails'] = "détailsderéservation";
$string['gotobookingoption'] = "alleràoptionderéservation";
$string['bookinglink'] = "lienderéservation";
$string['coursecalendarurl'] = "urlcalendriercours";
$string['courselink'] = "lienducours";
$string['duration'] = "durée";
$string['email'] = "email";
$string['enddate'] = "datedefin";
$string['endtime'] = "heuredefin";
$string['firstname'] = "prénom";
$string['duration'] = "durée";
$string['journal'] = "journal";
$string['lastname'] = "nomdefamille";
$string['numberparticipants'] = "nombredesparticipants";
$string['numberwaitinglist'] = "nombrelistattente";
$string['participant'] = "participant";
$string['pollstartdate'] = "datededébutdusondage";
$string['qr_id'] = "qr_id";
$string['qr_username'] = "qr_nomdutilisateur";
$string['startdate'] = "datededébut";
$string['starttime'] = "heurededébut";
$string['title'] = "titre";
$string['usercalendarurl'] = "urlcalendrierutilisateur";
$string['username'] = "nomdutilisateur";
$string['loopprevention'] = 'L\'espace réservé {$a} provoque une boucle. Veuillez le remplacer.';

$string['configurefields'] = 'Configurer les champs et colonnes';
$string['manageresponsespagefields'] = 'Gérer les réponses - Page';
$string['manageresponsesdownloadfields'] = 'Gérer les réponses - Téléchargement (CSV, XLSX...)';
$string['optionspagefields'] = 'Vue d\'ensemble des options de réservation - Page';
$string['optionsdownloadfields'] = 'Vue d\'ensemble des options de réservation - Téléchargement (CSV, XLSX...)';
$string['signinsheetfields'] = 'Champs de la feuille de présence (PDF)';
$string['signinonesession'] = 'Afficher la(les) date(s) dans l\'en-tête';
$string['signinaddemptyrows'] = 'Ajouter des lignes vides';
$string['signinextrasessioncols'] = 'Ajouter des colonnes supplémentaires pour les dates';
$string['signinadddatemanually'] = 'Ajouter une date manuellement';
$string['signinhidedate'] = 'Masquer les dates';
$string['includeteachers'] = 'Inclure les enseignants dans la feuille de présence';
$string['choosepdftitle'] = 'Sélectionner un titre pour la feuille de présence';
$string['addtogroup'] = 'Inscrire automatiquement les utilisateurs dans un groupe';
$string['addtogroup_help'] = 'Inscrire automatiquement les utilisateurs dans un groupe - le groupe sera créé automatiquement avec le nom : Nom de la réservation - Nom de l\'option';
$string['bookingattachment'] = 'Pièce jointe';
$string['bookingcategory'] = 'Catégorie';
$string['bookingduration'] = 'Durée';
$string['bookingorganizatorname'] = 'Nom de l\'organisateur';
$string['bookingpoints'] = 'Points du cours';
$string['bookingpollurl'] = 'URL du sondage';
$string['bookingpollurlteachers'] = 'URL du sondage pour les enseignants';
$string['bookingtags'] = 'Étiquettes';
$string['customlabelsdeprecated'] = '[OBSOLESCENT] Étiquettes personnalisées';
$string['editinstitutions'] = 'Modifier les institutions';
$string['entervalidurl'] = 'Veuillez entrer une URL valide !';
$string['eventtype'] = 'Type d\'événement';
$string['eventtype_help'] = 'Vous pouvez soit entrer le type d\'événement manuellement, soit choisir dans une liste de types d\'événements précédents.
                             Vous ne pouvez choisir qu\'un seul type d\'événement. Une fois enregistré, le type d\'événement sera ajouté à la liste.';
$string['groupname'] = 'Nom du groupe';
$string['lblacceptingfrom'] = 'Nom du label : Acceptant de';
$string['lblbooking'] = 'Nom du label : Réservation';
$string['lblinstitution'] = 'Nom du label : Institution';
$string['lbllocation'] = 'Nom du label : Emplacement';
$string['lblname'] = 'Nom du label : Nom';
$string['lblnumofusers'] = 'Nom du label : Nombre d\'utilisateurs';
$string['lblsurname'] = 'Nom du label : Nom de famille';
$string['maxperuser'] = 'Réservations max. par utilisateur';
$string['maxperuser_help'] = 'Le nombre maximum de réservations qu\'un utilisateur peut faire dans cette activité à la fois.
<b>Attention :</b> Dans les paramètres du plugin de réservation, vous pouvez choisir si les utilisateurs ayant complété ou assisté et les options de réservation
qui sont déjà passées doivent être comptées ou non pour déterminer le nombre maximum de réservations qu\'un utilisateur peut faire dans cette instance.';
$string['notificationtext'] = 'Message de notification';
$string['numgenerator'] = 'Activer la génération de numéros rec. ?';
$string['paginationnum'] = "N. d'enregistrements - pagination";
$string['pollurlteacherstext'] = 'Message pour l\'URL du sondage envoyé aux enseignants';
$string['pollurltext'] = 'Message pour l\'envoi de l\'URL du sondage aux utilisateurs réservés';
$string['reset'] = 'Réinitialiser';
$string['searchtag'] = 'Rechercher des étiquettes';
$string['showinapi'] = 'Afficher dans l\'API ?';
$string['whichview'] = 'Vue par défaut pour les options de réservation';
$string['whichviewerror'] = 'Vous devez inclure la vue par défaut dans : Vues à afficher dans la vue d\'ensemble des options de réservation';
$string['showviews'] = 'Vues à afficher dans la vue d\'ensemble des options de réservation';
$string['enablepresence'] = 'Activer la présence';
$string['removeuseronunenrol'] = 'Supprimer l\'utilisateur de la réservation lors de la désinscription du cours associé ?';

// Editoptions.php.
$string['editbookingoption'] = 'Modifier l\'option de réservation';
$string['createnewbookingoption'] = 'Nouvelle option de réservation';
$string['createnewbookingoptionfromtemplate'] = 'Ajouter une nouvelle option de réservation à partir d\'un modèle';
$string['connectedmoodlecourse'] = 'Cours Moodle connecté';
$string['connectedmoodlecourse_help'] = 'Choisissez "Créer un nouveau cours..." si vous souhaitez qu\'un nouveau cours Moodle soit créé pour cette option de réservation.';
$string['courseendtime'] = 'Heure de fin du cours';
$string['coursestarttime'] = 'Heure de début du cours';
$string['newcourse'] = 'Créer un nouveau cours...';
$string['nocourseselected'] = 'Aucun cours sélectionné';
$string['noinstitutionselected'] = 'Aucune institution sélectionnée';
$string['nolocationselected'] = 'Aucun emplacement sélectionné';
$string['noeventtypeselected'] = 'Aucun type d\'événement sélectionné';
$string['importcsvbookingoption'] = 'Importer un CSV avec des options de réservation';
$string['importexcelbutton'] = 'Importer la complétion de l\'activité';
$string['activitycompletiontext'] = 'Message à envoyer à l\'utilisateur lorsque l\'option de réservation est complétée';
$string['activitycompletiontextsubject'] = 'Option de réservation complétée';
$string['changesemester'] = 'Réinitialiser et créer des dates pour le semestre';
$string['changesemester:warning'] = '<strong>Attention :</strong> En cliquant sur "Enregistrer les modifications", toutes les dates seront supprimées
et remplacées par les dates du semestre choisi.';
$string['changesemesteradhoctaskstarted'] = 'Succès. Les dates seront régénérées lors de la prochaine exécution du CRON. Cela peut prendre plusieurs minutes.';
$string['activitycompletiontextmessage'] = 'Vous avez complété l\'option de réservation suivante :

{$a->bookingdetails}

Aller au cours : {$a->courselink}
Voir toutes les options de réservation : {$a->bookinglink}';
$string['sendmailtobooker'] = 'Page de réservation pour d\'autres utilisateurs : Envoyer un mail à l\'utilisateur qui réserve à la place des utilisateurs qui sont réservés';
$string['sendmailtobooker_help'] = 'Activez cette option pour envoyer des mails de confirmation de réservation à l\'utilisateur qui réserve pour d\'autres utilisateurs au lieu des utilisateurs qui ont été ajoutés à une option de réservation. Cela ne concerne que les réservations faites sur la page "réserver pour d\'autres utilisateurs".';
$string['submitandadd'] = 'Ajouter une nouvelle option de réservation';
$string['submitandstay'] = 'Rester ici';
$string['waitinglisttaken'] = 'Sur la liste d\'attente';
$string['groupexists'] = 'Le groupe existe déjà dans le cours cible, veuillez choisir un autre nom pour l\'option de réservation';
$string['groupdeleted'] = 'Cette instance de réservation crée automatiquement des groupes dans le cours cible. Mais le groupe a été supprimé manuellement dans le cours cible. Activez la case suivante pour recréer le groupe';
$string['recreategroup'] = 'Recréer le groupe dans le cours cible et inscrire les utilisateurs dans le groupe';
$string['copy'] = 'copier';
$string['enrolmentstatus'] = 'Inscrire les utilisateurs à l\'heure de début du cours (Par défaut : Non coché &rarr; les inscrire immédiatement.)';
$string['enrolmentstatus_help'] = 'Remarque : Pour que l\'inscription automatique fonctionne, vous devez changer le paramètre de l\'instance de réservation
 "Inscrire automatiquement les utilisateurs" à "Oui".';
$string['duplicatename'] = 'Ce nom d\'option de réservation existe déjà. Veuillez en choisir un autre.';
$string['newtemplatesaved'] = 'Nouveau modèle d\'option de réservation enregistré.';
$string['manageoptiontemplates'] = 'Gérer les modèles d\'options de réservation';
$string['usedinbookinginstances'] = 'Le modèle est utilisé dans les instances de réservation suivantes';
$string['optiontemplatename'] = 'Nom du modèle d\'option';
$string['option_template_not_saved_no_valid_license'] = 'Le modèle d\'option de réservation n\'a pas pu être enregistré en tant que modèle.
                                                  Passez à la version PRO pour enregistrer un nombre illimité de modèles.';

// Option_form.php.
$string['bookingoptionimage'] = 'Image d\'en-tête';
$string['submitandgoback'] = 'Fermer ce formulaire';
$string['bookingoptionprice'] = 'Prix';

// Nous avons supprimé cela, mais gardons-le pour l'instant car nous pourrions avoir besoin de ces chaînes à nouveau.
// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
/*$string['er_saverelationsforoptiondates'] = 'Enregistrer l\'entité pour chaque date aussi';
$string['confirm:er_saverelationsforoptiondates'] = '<span class="text-danger">
<b>Attention :</b> Cette option de réservation a des dates avec diverses entités.
Voulez-vous vraiment définir cette entité pour TOUTES les dates ?</span>';
$string['error:er_saverelationsforoptiondates'] = 'Veuillez confirmer que vous souhaitez écraser les entités divergentes.'; */

$string['pricecategory'] = 'Catégorie de prix';
$string['pricecurrency'] = 'Devise';
$string['optionvisibility'] = 'Visibilité';
$string['optionvisibility_help'] = 'Ici, vous pouvez choisir si l\'option doit être visible pour tout le monde ou si elle doit être cachée des utilisateurs normaux et visible uniquement pour les utilisateurs habilités.';
$string['optionvisible'] = 'Visible pour tout le monde (par défaut)';
$string['optioninvisible'] = 'Masquer aux utilisateurs normaux (visible uniquement pour les utilisateurs habilités)';
$string['invisibleoption'] = '<i class="fa fa-eye-slash" aria-hidden="true"></i> Invisible';
$string['optionannotation'] = 'Annotation interne';
$string['optionannotation_help'] = 'Ajoutez des remarques internes, des annotations ou tout ce que vous voulez. Cela ne sera affiché que dans ce formulaire et nulle part ailleurs.';
$string['optionidentifier'] = 'Identifiant unique';
$string['optionidentifier_help'] = 'Ajoutez un identifiant unique pour cette option de réservation.';
$string['titleprefix'] = 'Préfixe';
$string['titleprefix_help'] = 'Ajoutez un préfixe qui sera affiché avant le titre de l\'option, par exemple "BB42".';
$string['error:identifierexists'] = 'Choisissez un autre identifiant. Celui-ci existe déjà.';

// Optionview.php.
$string['invisibleoption:notallowed'] = 'Vous n\'êtes pas autorisé à voir cette option de réservation.';

// Importoptions.php.
$string['csvfile'] = 'Fichier CSV';
$string['dateerror'] = 'Date incorrecte à la ligne {$a} : ';
$string['dateparseformat'] = 'Format d\'analyse de la date';
$string['dateparseformat_help'] = 'Veuillez utiliser le format de date spécifié dans le fichier CSV. Aide avec <a href="http://php.net/manual/en/function.date.php">cette</a> ressource pour les options.';
$string['defaultdateformat'] = 'j.n.Y H:i:s';
$string['importcsvtitle'] = 'Importer CSV';
$string['importfinished'] = 'Importation terminée !';
$string['noteacherfound'] = 'L\'utilisateur spécifié comme enseignant à la ligne {$a} n\'existe pas sur la plateforme.';
$string['nouserfound'] = 'Aucun utilisateur trouvé : ';
$string['import_failed'] = 'L\'importation a échoué pour la raison suivante : ';
$string['import_partial'] = 'L\'importation n\'a été que partiellement complétée. Il y a eu des problèmes avec les lignes suivantes et elles n\'ont pas été importées : ';
$string['importinfo'] = 'Info sur l\'importation : Vous pouvez utiliser les colonnes suivantes dans le téléchargement CSV (explication entre parenthèses)';
$string['coursedoesnotexist'] = 'Le numéro de cours {$a} n\'existe pas';

// Nouvel importateur.
$string['importcsv'] = 'Importateur CSV';
$string['import_identifier'] = 'Identifiant unique d\'une option de réservation';
$string['import_tileprefix'] = 'Préfixe (par ex. numéro de cours)';
$string['import_title'] = 'Titre d\'une option de réservation';
$string['import_text'] = 'Titre d\'une option de réservation (synonyme de texte)';
$string['import_location'] = 'Emplacement d\'une option de réservation. Sera automatiquement associé en cas de correspondance à 100 % avec le nom clair d\'une "entité" (local_entities). L\'ID d\'une entité peut également être saisie ici.';
$string['import_identifier'] = 'Identifiant unique d\'une option de réservation';
$string['import_maxanswers'] = 'Nombre maximum de réservations par option de réservation';
$string['import_maxoverbooking'] = 'Nombre maximum de places sur la liste d\'attente par option de réservation';
$string['import_coursenumber'] = 'Numéro ID Moodle d\'un cours Moodle dans lequel les réservants seront inscrits';
$string['import_courseshortname'] = 'Nom abrégé d\'un cours Moodle dans lequel les réservants seront inscrits';
$string['import_addtocalendar'] = 'Ajouter au calendrier Moodle';
$string['import_dayofweek'] = 'Jour de la semaine d\'une option de réservation, par ex. lundi';
$string['import_dayofweektime'] = 'Jour de la semaine et heure d\'une option de réservation, par ex. lundi, 10:00 - 12:00';
$string['import_dayofweekstarttime'] = 'Heure de début d\'un cours, par ex. 10:00';
$string['import_dayofweekendtime'] = 'Heure de fin d\'un cours, par ex. 12:00';
$string['import_description'] = 'Description de l\'option de réservation';
$string['import_default'] = 'Prix standard d\'une option de réservation. Uniquement si le prix standard est défini, d\'autres prix peuvent être spécifiés. Les colonnes doivent correspondre aux noms abrégés des catégories de réservation.';
$string['import_teacheremail'] = 'Adresses e-mail des utilisateurs sur la plateforme qui peuvent être désignés comme enseignants dans les options de réservation. Utilisez des virgules comme séparateurs pour plusieurs adresses e-mail (attention à l\'échappement dans les CSV séparés par des virgules !)';
$string['import_useremail'] = 'Adresses e-mail des utilisateurs sur la plateforme qui ont réservé cette option de réservation. Utilisez des virgules comme séparateurs pour plusieurs adresses e-mail (attention à l\'échappement dans les CSV séparés par des virgules !)';

$string['importsuccess'] = 'L\'importation a réussi. {$a} enregistrement(s) traité(s).';
$string['importfailed'] = 'L\'importation a échoué';
$string['dateparseformat'] = 'Format d\'analyse de la date';
$string['dateparseformat_help'] = 'Veuillez utiliser le format de date spécifié dans le fichier CSV. Aide avec <a href="http://php.net/manual/en/function.date.php">cette</a> ressource pour les options.';
$string['defaultdateformat'] = 'j.n.Y H:i:s';
$string['importcolumnsinfos'] = 'Informations sur les colonnes à importer :';
$string['mandatory'] = 'obligatoire';
$string['optional'] = 'facultatif';
$string['format'] = 'format';
$string['openformat'] = 'format ouvert';
$string['downloaddemofile'] = 'Télécharger le fichier de démonstration';
$string['updatedrecords'] = '{$a} enregistrement(s) mis à jour.';
$string['addedrecords'] = '{$a} enregistrement(s) ajouté(s).';
$string['callbackfunctionnotdefined'] = 'Fonction de rappel non définie.';
$string['callbackfunctionnotapplied'] = 'La fonction de rappel n\'a pas pu être appliquée.';
$string['ifdefinedusedtomatch'] = 'Si défini, sera utilisé pour correspondre.';
$string['fieldnamesdontmatch'] = 'Les noms de champs importés ne correspondent pas aux noms de champs définis.';
$string['checkdelimiteroremptycontent'] = 'Vérifiez si les données sont fournies et séparées par le symbole sélectionné.';
$string['wronglabels'] = 'Le CSV importé ne contient pas les bonnes étiquettes. La colonne {$a} ne peut pas être importée.';
$string['missinglabel'] = 'Le CSV importé ne contient pas la colonne obligatoire {$a}. Les données ne peuvent pas être importées.';
$string['nolabels'] = 'Aucune étiquette de colonne définie dans l\'objet de paramètres.';
$string['checkdelimiter'] = 'Vérifiez si les données sont séparées par le symbole sélectionné.';
$string['dataincomplete'] = 'L\'enregistrement avec componentid {$a->id} est incomplet et n\'a pas pu être entièrement traité. Vérifiez le champ "{$a->field}".';

// Courriel de confirmation.
$string['days'] = '{$a} jours';
$string['hours'] = '{$a} heures';
$string['minutes'] = '{$a} minutes';

$string['deletedtextsubject'] = 'Réservation supprimée : {$a->title} par {$a->participant}';
$string['deletedtextmessage'] = 'L\'option de réservation a été supprimée : {$a->title}

Utilisateur : {$a->participant}
Titre : {$a->title}
Date : {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
Cours : {$a->courselink}
Lien de réservation : {$a->bookinglink}

';

$string['bookedtextsubject'] = 'Confirmation de réservation pour {$a->title}';
$string['bookedtextsubjectbookingmanager'] = 'Nouvelle réservation pour {$a->title} par {$a->participant}';
$string['bookedtextmessage'] = 'Votre réservation a été enregistrée :

{$a->bookingdetails}
<p>##########################################</p>
Statut de la réservation : {$a->status}
Participant :   {$a->participant}

Pour voir tous vos cours réservés, cliquez sur le lien suivant : {$a->bookinglink}
Le cours associé se trouve ici : {$a->courselink}
';
$string['waitingtextsubject'] = 'Le statut de réservation pour {$a->title} a changé';
$string['waitingtextsubjectbookingmanager'] = 'Le statut de réservation pour {$a->title} a changé';

$string['waitingtextmessage'] = 'Vous êtes maintenant sur la liste d\'attente de :

{$a->bookingdetails}
<p>##########################################</p>
Statut de la réservation : {$a->status}
Participant :   {$a->participant}

Pour voir tous vos cours réservés, cliquez sur le lien suivant : {$a->bookinglink}
Le cours associé se trouve ici : {$a->courselink}
';

$string['notifyemailsubject'] = 'Votre réservation commencera bientôt';
$string['notifyemailmessage'] = 'Votre réservation commencera bientôt :

Statut de la réservation : {$a->status}
Participant : {$a->participant}
Option de réservation : {$a->title}
Date : {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
Pour voir tous vos cours réservés, cliquez sur le lien suivant : {$a->bookinglink}
Le cours associé se trouve ici : {$a->courselink}
';
$string['notifyemail'] = 'Notification des participants avant le début';

$string['notifyemailteacherssubject'] = 'Votre réservation commencera bientôt';
$string['notifyemailteachersmessage'] = 'Votre réservation commencera bientôt :

{$a->bookingdetails}

Vous avez <b>{$a->numberparticipants} participants réservés</b> et <b>{$a->numberwaitinglist} personnes sur la liste d\'attente</b>.

Pour voir tous vos cours réservés, cliquez sur le lien suivant : {$a->bookinglink}
Le cours associé se trouve ici : {$a->courselink}
';
$string['notifyemailteachers'] = 'Notification des enseignants avant le début ' . $string['badge:pro'];

$string['userleavesubject'] = 'Vous vous êtes désinscrit avec succès de {$a->title}';
$string['userleavemessage'] = 'Bonjour {$a->participant},

Vous vous êtes désinscrit de {$a->title}.
';

$string['statuschangetextsubject'] = 'Le statut de réservation a changé pour {$a->title}';
$string['statuschangetextmessage'] = 'Bonjour {$a->participant}!

Votre statut de réservation a changé.

Statut de la réservation : {$a->status}

Participant :   {$a->participant}
Option de réservation : {$a->title}
Date : {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}

Aller à l\'option de réservation : {$a->gotobookingoption}
';

$string['deletedbookingusersubject'] = 'Réservation pour {$a->title} annulée';
$string['deletedbookingusermessage'] = 'Bonjour {$a->participant},

Votre réservation pour {$a->title} ({$a->startdate} {$a->starttime}) a été annulée.
';

$string['bookingchangedtextsubject'] = 'Notification de changement pour {$a->title}';
$string['bookingchangedtextmessage'] = 'Votre réservation "{$a->title}" a changé.

Voici ce qui est nouveau :
{changes}

Pour voir le(s) changement(s) et tous vos cours réservés, cliquez sur le lien suivant : {$a->bookinglink}
';

$string['error:failedtosendconfirmation'] = 'L\'utilisateur suivant n\'a pas reçu un mail de confirmation

Statut de la réservation : {$a->status}
Participant : {$a->participant}
Option de réservation : {$a->title}
Date : {$a->startdate} {$a->starttime} - {$a->enddate} {$a->endtime}
Lien : {$a->bookinglink}
Cours associé : {$a->courselink}

';

$string['pollurltextsubject'] = 'Veuillez répondre au sondage';
$string['pollurltextmessage'] = 'Veuillez répondre au sondage :

URL du sondage : <a href="{pollurl}" target="_blank">{pollurl}</a>
';

$string['pollurlteacherstextsubject'] = 'Veuillez répondre au sondage';
$string['pollurlteacherstextmessage'] = 'Veuillez répondre au sondage :

URL du sondage : <a href="{pollurlteachers}" target="_blank">{pollurlteachers}</a>
';

$string['reportremindersubject'] = 'Rappel : Votre cours réservé';
$string['reportremindermessage'] = '{$a->bookingdetails}';

// Report.php.
$string['allmailssend'] = 'Tous les e-mails aux utilisateurs ont été envoyés !';
$string['associatedcourse'] = 'Cours associé';
$string['bookedusers'] = 'Utilisateurs réservés';
$string['deletedusers'] = 'Utilisateurs supprimés';
$string['reservedusers'] = 'Utilisateurs avec réservations à court terme';
$string['bookingfulldidntregister'] = 'L\'option est complète, donc je n\'ai pas transféré tous les utilisateurs !';
$string['booktootherbooking'] = 'Réserver des utilisateurs à une autre option de réservation';
$string['downloadallresponses'] = 'Télécharger toutes les réponses pour toutes les options de réservation';
$string['copyonlythisbookingurl'] = 'Copier cette URL de réservation';
$string['copypollurl'] = 'Copier l\'URL du sondage';
$string['copytoclipboard'] = 'Copier dans le presse-papiers : Ctrl+C, Entrée';
$string['editotherbooking'] = 'Autres règles de réservation';
$string['editteachers'] = 'Modifier';
$string['generaterecnum'] = "Générer des numéros";
$string['generaterecnumareyousure'] = "Cela générera de nouveaux numéros et supprimera définitivement les anciens !";
$string['generaterecnumnotification'] = "De nouveaux numéros ont été générés.";
$string['gotobooking'] = '&lt;&lt; Réservations';
$string['lblbooktootherbooking'] = 'Nom du bouton : Réserver des utilisateurs à une autre réservation';
$string['no'] = 'Non';
$string['nocourse'] = 'Aucun cours sélectionné pour cette option de réservation';
$string['nodateset'] = 'Date du cours non fixée';
$string['nousers'] = 'Aucun utilisateur !';
$string['numrec'] = "Num. rec.";
$string['onlythisbookingoption'] = 'Seulement cette option de réservation';
$string['optiondatesmanager'] = 'Gérer les dates d\'option';
$string['optionid'] = 'ID de l\'option';
$string['optionmenu'] = 'Cette option de réservation';
$string['searchdate'] = 'Date';
$string['searchname'] = 'Prénom';
$string['searchsurname'] = 'Nom de famille';
$string['yes'] = 'Oui';
$string['no'] = 'Non';
$string['copypollurl'] = 'Copier l\'URL du sondage';
$string['gotobooking'] = '&lt;&lt; Réservations';
$string['nousers'] = 'Aucun utilisateur !';
$string['booktootherbooking'] = 'Réserver des utilisateurs à une autre option de réservation';
$string['lblbooktootherbooking'] = 'Nom du bouton : Réserver des utilisateurs à une autre option de réservation';
$string['toomuchusersbooked'] = 'Le nombre maximum d\'utilisateurs que vous pouvez réserver est : {$a}';
$string['transfer'] = 'Transférer';
$string['transferheading'] = 'Transférer les utilisateurs sélectionnés à l\'option de réservation sélectionnée';
$string['transfersuccess'] = 'Les utilisateurs ont été transférés avec succès à la nouvelle option de réservation';
$string['transferoptionsuccess'] = 'L\'option de réservation et les utilisateurs ont été transférés avec succès.';
$string['transferproblem'] = 'Les éléments suivants n\'ont pas pu être transférés en raison de la limitation de l\'option de réservation ou de la limitation de l\'utilisateur : {$a}';
$string['searchwaitinglist'] = 'Sur la liste d\'attente';
$string['selectatleastoneuser'] = 'Veuillez sélectionner au moins 1 utilisateur !';
$string['selectanoption'] = 'Veuillez sélectionner une option de réservation';
$string['delnotification'] = 'Vous avez supprimé {$a->del} sur {$a->all} utilisateurs. Les utilisateurs ayant complété l\'activité ne peuvent pas être supprimés !';
$string['delnotificationactivitycompletion'] = 'Vous avez supprimé {$a->del} sur {$a->all} utilisateurs. Les utilisateurs ayant complété l\'activité ne peuvent pas être supprimés !';

$string['selectoptionid'] = 'Veuillez sélectionner une option !';
$string['sendcustommsg'] = 'Envoyer un message personnalisé';
$string['sendpollurltoteachers'] = 'Envoyer l\'URL du sondage';
$string['toomuchusersbooked'] = 'Le nombre maximum d\'utilisateurs que vous pouvez réserver est : {$a}';
$string['userid'] = 'ID utilisateur';
$string['userssuccessfullenrolled'] = 'Tous les utilisateurs ont été inscrits !';
$string['userssuccessfullybooked'] = 'Tous les utilisateurs ont été réservés à l\'autre option de réservation.';
$string['sucessfullybooked'] = 'Réservé avec succès';
$string['waitinglistusers'] = 'Utilisateurs sur la liste d\'attente';
$string['withselected'] = 'Avec les utilisateurs sélectionnés :';
$string['editotherbooking'] = 'Autres règles de réservation';
$string['bookingfulldidntregister'] = 'L\'option est complète, donc je n\'ai pas transféré tous les utilisateurs !';
$string['numrec'] = "Num. rec.";
$string['generaterecnum'] = "Générer des numéros";
$string['generaterecnumareyousure'] = "Cela générera de nouveaux numéros et supprimera définitivement les anciens !";
$string['generaterecnumnotification'] = "De nouveaux numéros ont été générés.";
$string['searchwaitinglist'] = 'Sur la liste d\'attente';
$string['ratingsuccessful'] = 'Les évaluations ont été mises à jour avec succès';
$string['userid'] = 'ID utilisateur';
$string['nodateset'] = 'Date du cours non fixée';
$string['editteachers'] = 'Modifier';
$string['sendpollurltoteachers'] = 'Envoyer l\'URL du sondage';
$string['copytoclipboard'] = 'Copier dans le presse-papiers : Ctrl+C, Entrée';
$string['yes'] = 'Oui';
$string['sendreminderemailsuccess'] = 'Le mail de notification a été envoyé !';
$string['sign_in_sheet_download'] = 'Télécharger la feuille de présence';
$string['sign_in_sheet_configure'] = 'Configurer la feuille de présence';
$string['status_complete'] = "Terminé";
$string['status_incomplete'] = "Incomplet";
$string['status_noshow'] = "Absence";
$string['status_failed'] = "Échoué";
$string['status_unknown'] = "Inconnu";
$string['status_attending'] = "Présent";
$string['presence'] = "Présence";
$string['confirmpresence'] = "Confirmer la présence";
$string['selectpresencestatus'] = "Choisir le statut de présence";
$string['userssuccessfullygetnewpresencestatus'] = 'Tous les utilisateurs ont un nouveau statut de présence.';
$string['deleteresponsesactivitycompletion'] = 'Supprimer tous les utilisateurs ayant terminé l\'activité : {$a}';
$string['signature'] = 'Signature';
$string['userssucesfullygetnewpresencestatus'] = 'Le statut de présence des utilisateurs sélectionnés a été mis à jour avec succès';
$string['copytotemplate'] = 'Enregistrer l\'option de réservation comme modèle';
$string['copytotemplatesucesfull'] = 'L\'option de réservation a été enregistrée avec succès comme modèle.';

// Send message.
$string['booking:cansendmessages'] = 'Peut envoyer des messages';
$string['messageprovider:sendmessages'] = 'Peut envoyer des messages';
$string['activitycompletionsuccess'] = 'Tous les utilisateurs sélectionnés ont été marqués pour l\'achèvement de l\'activité';
$string['booking:communicate'] = 'Peut communiquer';
$string['confirmoptioncompletion'] = '(Dé)confirmer le statut d\'achèvement';
$string['enablecompletion'] = 'Au moins une des options réservées doit être marquée comme terminée';
$string['enablecompletiongroup'] = 'Requiert des entrées';
$string['messagesend'] = 'Votre message a été envoyé.';
$string['messagesubject'] = 'Sujet';
$string['messagetext'] = 'Message';
$string['sendmessage'] = 'Envoyer le message';

// Teachers_handler.php.
$string['teachersforoption'] = 'Enseignants';
$string['teachersforoption_help'] = '<b>ATTENTION : </b>En ajoutant des enseignants ici, ils seront également <b>ajoutés à chaque date future</b> dans le rapport d\'enseignement.
Lors de la suppression des enseignants ici, ils seront <b>retirés de chaque date future</b> dans le rapport d\'enseignement !';
$string['info:teachersforoptiondates'] = 'Allez au <a href="{$a}" target="_self">journal d\'enseignement</a>, pour gérer les enseignants pour des dates spécifiques.';

// Lib.php.
$string['pollstrftimedate'] = '%Y-%m-%d';
$string['mybookingoptions'] = 'Mes options réservées';
$string['bookuserswithoutcompletedactivity'] = "Réserver des utilisateurs sans activité terminée";
$string['sessionremindermailsubject'] = 'Rappel : Vous avez une session à venir';
$string['sessionremindermailmessage'] = '<p>N\'oubliez pas : Vous êtes réservé pour la session suivante :</p>
<p>{$a->optiontimes}</p>
<p>##########################################</p>
<p>{$a->sessiondescription}</p>
<p>##########################################</p>
<p>Statut de la réservation : {$a->status}</p>
<p>Participant : {$a->participant}</p>
';

// All_users.php.
$string['completed'] = 'Terminé';
$string['usersonlist'] = 'Utilisateur sur la liste';
$string['fullname'] = 'Nom complet';
$string['timecreated'] = 'Heure de création';
$string['sendreminderemail'] = "Envoyer un e-mail de rappel";

// Importexcel.php.
$string['importexceltitle'] = 'Importer l\'achèvement de l\'activité';

// Importexcel_file.php.
$string['excelfile'] = 'Fichier CSV avec l\'achèvement de l\'activité';

// Institutions.php.
$string['institutions'] = 'Institutions';

// Otherbooking.php.
$string['otherbookingoptions'] = 'Accepté à partir de';
$string['otherbookingnumber'] = 'Num. d\'utilisateurs';
$string['otherbookingaddrule'] = 'Ajouter une nouvelle règle';
$string['editrule'] = "Modifier";
$string['deleterule'] = 'Supprimer';
$string['deletedrule'] = 'Règle supprimée.';

// Otherbookingaddrule_form.php.
$string['selectoptioninotherbooking'] = "Option";
$string['otherbookinglimit'] = "Limite";
$string['otherbookinglimit_help'] = "Combien d\'utilisateurs acceptez-vous pour cette option. Si 0, vous pouvez accepter un nombre illimité d\'utilisateurs.";
$string['otherbookingsuccessfullysaved'] = 'Règle enregistrée !';

// Optiondates.php.
$string['optiondatestime'] = 'Heure de la session';
$string['optiondatesmessage'] = 'Session {$a->number} : {$a->date} <br> De : {$a->starttime} <br> À : {$a->endtime}';
$string['optiondatessuccessfullysaved'] = "L'heure de la session a été enregistrée.";
$string['optiondatessuccessfullydelete'] = "L'heure de la session a été supprimée.";
$string['leftandrightdate'] = '{$a->leftdate} à {$a->righttdate}';
$string['editingoptiondate'] = 'Vous êtes en train de modifier cette session';
$string['newoptiondate'] = 'Créer une nouvelle session...';

// Optiondatesadd_form.php.
$string['dateandtime'] = 'Date et heure';
$string['sessionnotifications'] = 'Notifications par e-mail pour chaque session';
$string['customfields'] = 'Champs personnalisés';
$string['addcustomfieldorcomment'] = 'Ajouter un commentaire ou un champ personnalisé';
$string['customfieldname'] = 'Nom du champ';
$string['customfieldname_help'] = 'Vous pouvez entrer n\'importe quel nom de champ que vous voulez. Les noms de champs spéciaux
                                    <ul>
                                        <li>TeamsMeeting</li>
                                        <li>ZoomMeeting</li>
                                        <li>BigBlueButtonMeeting</li>
                                    </ul> en combinaison avec un lien dans le champ de valeur créeront des boutons et des liens
                                    accessibles uniquement pendant (et peu avant) les réunions réelles.';
$string['customfieldvalue'] = 'Valeur';
$string['customfieldvalue_help'] = 'Vous pouvez entrer n\'importe quelle valeur que vous voulez (texte, nombre ou HTML).<br>
                                    Si vous avez utilisé l\'un des noms de champs spéciaux
                                    <ul>
                                        <li>TeamsMeeting</li>
                                        <li>ZoomMeeting</li>
                                        <li>BigBlueButtonMeeting</li>
                                    </ul> alors entrez le lien complet vers la session de la réunion en commençant par https:// ou http://';
$string['deletecustomfield'] = 'Supprimer le champ personnalisé ?';
$string['deletecustomfield_help'] = 'Attention : cocher cette case supprimera le champ personnalisé associé lors de l\'enregistrement.';
$string['erroremptycustomfieldname'] = 'Le nom du champ personnalisé ne doit pas être vide.';
$string['erroremptycustomfieldvalue'] = 'La valeur du champ personnalisé ne doit pas être vide.';
$string['daystonotifysession'] = 'Notification n jours avant le début';
$string['daystonotifysession_help'] = "Nombre de jours avant le début de la session pour notifier les participants.
Entrez 0 pour désactiver la notification par e-mail pour cette session.";
$string['nocfnameselected'] = "Rien n'a été sélectionné. Tapez un nouveau nom ou sélectionnez-en un dans la liste.";
$string['bigbluebuttonmeeting'] = 'Réunion BigBlueButton';
$string['zoommeeting'] = 'Réunion Zoom';
$string['teamsmeeting'] = 'Réunion Teams';
$string['addcomment'] = 'Ajouter un commentaire...';

// File: locallib.php.
$string['signinsheetdate'] = 'Date(s) : ';
$string['signinsheetaddress'] = 'Adresse : ';
$string['signinsheetlocation'] = 'Lieu : ';
$string['signinsheetdatetofillin'] = 'Date : ';
$string['booking:readallinstitutionusers'] = 'Afficher tous les utilisateurs';
$string['manageoptiontemplates'] = 'Gérer les modèles d\'option';
$string['linkgotobookingoption'] = 'Aller à l\'option réservée : {$a}</a>';

// File: settings.php.
$string['bookingsettings'] = 'Réservation : Paramètres principaux';
$string['bookingdebugmode'] = 'Mode débogage de la réservation';
$string['bookingdebugmode_desc'] = 'Le mode débogage de la réservation ne doit être activé que par les développeurs.';
$string['globalcurrency'] = 'Devise';
$string['globalcurrencydesc'] = 'Choisissez la devise pour les prix des options de réservation';
$string['globalmailtemplates'] = 'Modèles de mail globaux ' . $string['badge:pro'];
$string['globalmailtemplates_desc'] = 'Après activation, vous pouvez aller dans les paramètres d\'une instance de réservation et définir la source des modèles de mail sur global.';
$string['globalbookedtext'] = 'Confirmation de réservation (modèle global)';
$string['globalwaitingtext'] = 'Confirmation de la liste d\'attente (modèle global)';
$string['globalnotifyemail'] = 'Notification des participants avant le début (modèle global)';
$string['globalnotifyemailteachers'] = 'Notification des enseignants avant le début (modèle global)';
$string['globalstatuschangetext'] = 'Message de changement de statut (modèle global)';
$string['globaluserleave'] = 'L\'utilisateur a annulé sa propre réservation (modèle global)';
$string['globaldeletedtext'] = 'Message d\'annulation de réservation (modèle global)';
$string['globalbookingchangedtext'] = 'Message à envoyer lorsqu\'une option de réservation change (sera uniquement envoyé aux utilisateurs ayant déjà réservé). Utilisez le placeholder {changes} pour montrer les changements. Entrez 0 pour désactiver les notifications de changement. (Modèle global)';
$string['globalpollurltext'] = 'Message pour envoyer l\'URL du sondage aux utilisateurs réservés (modèle global)';
$string['globalpollurlteacherstext'] = 'Message pour l\'URL du sondage envoyé aux enseignants (modèle global)';
$string['globalactivitycompletiontext'] = 'Message à envoyer à l\'utilisateur lorsque l\'option de réservation est terminée (modèle global)';
$string['licensekeycfg'] = 'Activer la version PRO';
$string['licensekeycfgdesc'] = 'Avec une licence PRO, vous pouvez créer autant de modèles de réservation que vous le souhaitez et utiliser des fonctionnalités PRO telles que des modèles de mail globaux, des textes d\'information sur la liste d\'attente ou des notifications aux enseignants.';
$string['licensekey'] = 'Clé de licence PRO';
$string['licensekeydesc'] = 'Téléchargez une clé de licence valide pour activer la version PRO.';
$string['license_activated'] = 'Version PRO activée avec succès.<br>(Expire : ';
$string['license_invalid'] = 'Clé de licence invalide';
$string['icalcfg'] = 'Configuration des pièces jointes iCal';
$string['icalcfgdesc'] = 'Configurez les fichiers iCal.ics qui sont joints aux messages électroniques. Ces fichiers permettent d\'ajouter les dates de réservation au calendrier personnel.';
$string['icalfieldlocation'] = 'Texte à afficher dans le champ emplacement de l\'iCal';
$string['icalfieldlocationdesc'] = 'Choisissez dans la liste déroulante le texte à utiliser pour le champ emplacement du calendrier';
$string['customfield'] = 'Champ personnalisé à définir dans les paramètres de l\'option de réservation. Il sera ensuite affiché dans l\'aperçu de l\'option de réservation.';
$string['customfielddesc'] = 'Après avoir ajouté un champ personnalisé, vous pouvez définir la valeur pour le champ dans les paramètres de l\'option de réservation. La valeur sera affichée dans la description de l\'option de réservation.';
$string['customfieldconfigure'] = 'Réservation : Champs d\'option de réservation personnalisés';
$string['customfielddef'] = 'Champ personnalisé d\'option de réservation';
$string['customfieldtype'] = 'Type de champ';
$string['textfield'] = 'Entrée de texte sur une ligne';
$string['selectfield'] = 'Liste déroulante';
$string['multiselect'] = 'Sélection multiple';
$string['customfieldoptions'] = 'Liste des valeurs possibles';
$string['delcustfield'] = 'Supprimer ce champ et tous les paramètres de champ associés dans les options de réservation';
$string['signinlogo'] = 'Logo à afficher sur la feuille de présence';
$string['cfgsignin'] = 'Configuration de la feuille de présence';
$string['cfgsignin_desc'] = 'Configurer les paramètres de la feuille de présence';
$string['pdfportrait'] = 'Portrait';
$string['pdflandscape'] = 'Paysage';
$string['signincustfields'] = 'Champs de profil personnalisés';
$string['signincustfields_desc'] = 'Sélectionnez les champs de profil personnalisés à afficher sur la feuille de présence';
$string['showcustomfields'] = 'Champs d\'option de réservation personnalisés';
$string['showcustomfields_desc'] = 'Sélectionnez les champs d\'option de réservation personnalisés à afficher sur la feuille de présence';
$string['alloptionsinreport'] = 'Un rapport pour une activité de réservation ' . $string['badge:pro'];
$string['alloptionsinreportdesc'] = 'Le rapport d\'une option de réservation inclura toutes les réservations de toutes les options de réservation au sein de cette instance.';

$string['showlistoncoursepage'] = 'Afficher des informations supplémentaires sur la page du cours';
$string['showlistoncoursepage_help'] = 'Si vous activez ce paramètre, le nom du cours, une courte info et un bouton
redirigeant vers les options de réservation disponibles seront affichés.';
$string['hidelistoncoursepage'] = 'Masquer les informations supplémentaires sur la page du cours (par défaut)';
$string['showcoursenameandbutton'] = 'Afficher le nom du cours, une courte info et un bouton redirigeant vers les options de réservation disponibles';

$string['coursepageshortinfolbl'] = 'Courte info';
$string['coursepageshortinfolbl_help'] = 'Choisissez un texte d\'information court à afficher sur la page du cours.';
$string['coursepageshortinfo'] = 'Si vous souhaitez vous inscrire à ce cours, cliquez sur "Voir les options disponibles", choisissez une option de réservation puis cliquez sur "Réserver maintenant".';

$string['btnviewavailable'] = "Voir les options disponibles";

$string['signinextracols_heading'] = 'Colonnes supplémentaires sur la feuille de présence';
$string['signinextracols'] = 'Colonne supplémentaire';
$string['signinextracols_desc'] = 'Vous pouvez imprimer jusqu\'à 3 colonnes supplémentaires sur la feuille de présence. Remplissez le titre de la colonne ou laissez-le vide pour ne pas ajouter de colonne supplémentaire';
$string['numberrows'] = 'Numéroter les lignes';
$string['numberrowsdesc'] = 'Numéroter chaque ligne de la feuille de présence. Le numéro sera affiché à gauche du nom dans la même colonne';

$string['availabilityinfotexts_heading'] = 'Textes d\'information sur la disponibilité des places de réservation et de la liste d\'attente ' . $string['badge:pro'];
$string['bookingplacesinfotexts'] = 'Afficher les textes d\'information sur la disponibilité des places de réservation';
$string['bookingplacesinfotexts_info'] = 'Afficher des messages d\'information courts au lieu du nombre de places de réservation disponibles.';
$string['waitinglistinfotexts'] = 'Afficher les textes d\'information sur la disponibilité de la liste d\'attente';
$string['waitinglistinfotexts_info'] = 'Afficher des messages d\'information courts au lieu du nombre de places disponibles sur la liste d\'attente.';
$string['bookingplaceslowpercentage'] = 'Pourcentage pour le message de faible disponibilité des places de réservation';
$string['bookingplaceslowpercentagedesc'] = 'Si les places de réservation disponibles atteignent ou descendent en dessous de ce pourcentage, un message de faible disponibilité des places de réservation sera affiché.';
$string['waitinglistlowpercentage'] = 'Pourcentage pour le message de faible disponibilité de la liste d\'attente';
$string['waitinglistlowpercentagedesc'] = 'Si les places disponibles sur la liste d\'attente atteignent ou descendent en dessous de ce pourcentage, un message de faible disponibilité de la liste d\'attente sera affiché.';

$string['waitinglistshowplaceonwaitinglist'] = 'Afficher la place sur la liste d\'attente.';
$string['waitinglistshowplaceonwaitinglist_info'] = 'Liste d\'attente : Affiche la place exacte de l\'utilisateur sur la liste d\'attente.';

$string['yourplaceonwaitinglist'] = 'Vous êtes en position {$a} sur la liste d\'attente';

$string['waitinglistlowmessage'] = 'Il ne reste que quelques places sur la liste d\'attente !';
$string['waitinglistenoughmessage'] = 'Encore suffisamment de places sur la liste d\'attente.';
$string['waitinglistfullmessage'] = 'Liste d\'attente complète.';
$string['bookingplaceslowmessage'] = 'Il ne reste que quelques places disponibles !';
$string['bookingplacesenoughmessage'] = 'Encore suffisamment de places disponibles.';
$string['bookingplacesfullmessage'] = 'Complet.';
$string['eventalreadyover'] = 'Cet événement est déjà terminé.';
$string['nobookingpossible'] = 'Aucune réservation possible.';

$string['pricecategories'] = 'Réservation : Catégories de prix';

$string['bookingpricesettings'] = 'Paramètres de prix';
$string['bookingpricesettings_desc'] = 'Ici, vous pouvez personnaliser les prix de réservation.';

$string['bookwithcreditsactive'] = "Réserver avec des crédits";
$string['bookwithcreditsactive_desc'] = "Les utilisateurs avec des crédits peuvent réserver directement sans payer de prix.";

$string['bookwithcreditsprofilefieldoff'] = 'Ne pas afficher';
$string['bookwithcreditsprofilefield'] = "Champ de profil utilisateur pour les crédits";
$string['bookwithcreditsprofilefield_desc'] = "Pour utiliser cette fonctionnalité, veuillez définir un champ de profil utilisateur où les crédits sont stockés.
<span class='text-danger'><b>Attention :</b> Vous devez créer ce champ de manière à ce que vos utilisateurs ne puissent pas définir eux-mêmes un crédit.</span>";

$string['cfcostcenter'] = "Champ d\'option de réservation personnalisé pour le centre de coûts";
$string['cfcostcenter_desc'] = "Si vous utilisez des centres de coûts, vous devez spécifier quel champ d\'option de réservation personnalisé est utilisé pour stocker le centre de coûts.";

$string['priceisalwayson'] = 'Les prix toujours actifs';
$string['priceisalwayson_desc'] = 'Si vous activez cette case à cocher, vous ne pouvez pas désactiver les prix pour les options de réservation individuelles.
 Cependant, vous pouvez toujours définir un prix de 0 EUR.';

$string['bookingpricecategory'] = 'Catégorie de prix';
$string['bookingpricecategory_info'] = 'Définissez le nom de la catégorie, par exemple "étudiants"';

$string['addpricecategory'] = 'Ajouter une catégorie de prix';
$string['addpricecategory_info'] = 'Vous pouvez ajouter une autre catégorie de prix';

$string['userprofilefieldoff'] = 'Ne pas afficher';
$string['pricecategoryfield'] = 'Champ de profil utilisateur pour la catégorie de prix';
$string['pricecategoryfielddesc'] = 'Choisissez le champ de profil utilisateur, qui stocke l\'identifiant de la catégorie de prix pour chaque utilisateur.';

$string['useprice'] = 'Réserver uniquement avec un prix';

$string['teachingreportfortrainer'] = 'Rapport des unités d\'enseignement effectuées pour l\'enseignant';
$string['educationalunitinminutes'] = 'Durée d\'une unité pédagogique (minutes)';
$string['educationalunitinminutes_desc'] = 'Entrez la durée d\'une unité pédagogique en minutes. Elle sera utilisée pour calculer les unités d\'enseignement effectuées.';

$string['duplicationrestore'] = 'Instances de réservation : Duplication, sauvegarde et restauration';
$string['duplicationrestoredesc'] = 'Ici, vous pouvez définir quelles informations vous souhaitez inclure lors de la duplication ou de la sauvegarde/restauration des instances de réservation.';
$string['duplicationrestoreteachers'] = 'Inclure les enseignants';
$string['duplicationrestoreprices'] = 'Inclure les prix';
$string['duplicationrestoreentities'] = 'Inclure les entités';
$string['duplicationrestoresubbookings'] = 'Inclure les sous-réservations ' . $string['badge:pro'];

$string['duplicationrestoreoption'] = 'Options de réservation : Paramètres de duplication ' . $string['badge:pro'];
$string['duplicationrestoreoption_desc'] = 'Paramètres spéciaux pour la duplication des options de réservation.';

$string['waitinglistheader'] = 'Liste d\'attente';
$string['waitinglistheader_desc'] = 'Ici, vous pouvez définir comment la liste d\'attente des réservations doit se comporter.';
$string['turnoffwaitinglist'] = 'Désactiver globalement la liste d\'attente';
$string['turnoffwaitinglist_desc'] = 'Activez ce paramètre, si vous ne souhaitez pas utiliser la fonctionnalité de liste d\'attente sur ce site (par exemple, parce que vous souhaitez uniquement utiliser la liste de notification).';
$string['turnoffwaitinglistaftercoursestart'] = 'Désactiver le passage automatique de la liste d\'attente après le début d\'une option de réservation.';
$string['keepusersbookedonreducingmaxanswers'] = 'Conserver les utilisateurs réservés lors de la réduction de la limite';
$string['keepusersbookedonreducingmaxanswers_desc'] = 'Conservez les utilisateurs réservés même lorsque la limite des places réservables (maxanswers) est réduite.
Exemple : Une option de réservation a 5 places. La limite est réduite à 3. Les 5 utilisateurs qui ont déjà réservé resteront réservés.';

$string['notificationlist'] = 'Liste de notification';
$string['notificationlistdesc'] = 'Lorsqu\'aucune place n\'est plus disponible, les utilisateurs peuvent toujours s\'inscrire pour être notifiés en cas d\'ouverture';
$string['usenotificationlist'] = 'Utiliser la liste de notification';

$string['subbookings'] = 'Sous-réservations ' . $string['badge:pro'];
$string['subbookings_desc'] = 'Activez les sous-réservations pour permettre la réservation d\'articles ou de créneaux supplémentaires (par exemple pour les courts de tennis).';
$string['showsubbookings'] = 'Activer les sous-réservations';

$string['progressbars'] = 'Barres de progression du temps écoulé ' . $string['badge:pro'];
$string['progressbars_desc'] = 'Obtenez une représentation visuelle du temps qui s\'est déjà écoulé pour une option de réservation.';
$string['showprogressbars'] = 'Afficher les barres de progression du temps écoulé';
$string['progressbarscollapsible'] = 'Rendre les barres de progression repliables';

$string['bookingoptiondefaults'] = 'Paramètres par défaut pour les options de réservation';
$string['bookingoptiondefaultsdesc'] = 'Ici, vous pouvez définir les paramètres par défaut pour la création d\'options de réservation et les verrouiller si nécessaire.';
$string['addtocalendardesc'] = 'Les événements du calendrier du cours sont visibles par TOUS les utilisateurs au sein d\'un cours. Si vous ne souhaitez pas qu\'ils soient créés du tout,
vous pouvez désactiver ce paramètre et le verrouiller par défaut. Ne vous inquiétez pas : les événements du calendrier utilisateur pour les options réservées seront toujours créés de toute façon.';

$string['automaticcoursecreation'] = 'Création automatique de cours Moodle ' . $string['badge:pro'];
$string['newcoursecategorycfield'] = 'Champ personnalisé de l\'option de réservation à utiliser comme catégorie de cours';
$string['newcoursecategorycfielddesc'] = 'Choisissez un champ personnalisé de l\'option de réservation qui sera utilisé comme catégorie de cours pour les cours automatiquement créés
 en utilisant l\'entrée déroulante "Créer un nouveau cours..." dans le formulaire de création de nouvelles options de réservation.';

$string['allowoverbooking'] = 'Autoriser la sur-réservation';
$string['allowoverbookingheader'] = 'Sur-réservation des options de réservation ' . $string['badge:pro'];
$string['allowoverbookingheader_desc'] = 'Permettre aux administrateurs et aux utilisateurs autorisés de sur-réserver des options de réservation.
  (Attention : Cela peut entraîner un comportement inattendu. N\'activez cette option que si vous en avez vraiment besoin.)';

$string['appearancesettings'] = 'Apparence ' . $string['badge:pro'];
$string['appearancesettings_desc'] = 'Configurer l\'apparence du plugin de réservation.';
$string['turnoffwunderbytelogo'] = 'Ne pas afficher le logo et le lien Wunderbyte';
$string['turnoffwunderbytelogo_desc'] = 'Si vous activez ce paramètre, le logo Wunderbyte et le lien vers le site Wunderbyte ne seront pas affichés.';

$string['turnoffmodals'] = "Désactiver les modales";
$string['turnoffmodals_desc'] = "Certaines étapes du processus de réservation ouvriront des modales. Ce paramètre affichera les informations en ligne, aucune modale ne s'ouvrira.
<b>Veuillez noter :</b> Si vous utilisez la <b>vue cartes</b> de la réservation, alors les modales seront toujours utilisées. Vous pouvez <b>seulement les désactiver pour la vue liste</b>.";

$string['collapseshowsettings'] = "Réduire 'afficher les dates' avec plus de x dates.";
$string['collapseshowsettings_desc'] = "Pour éviter une vue encombrée avec trop de dates, une limite inférieure pour les dates réduites peut être définie ici.";

$string['teachersettings'] = 'Enseignants ' . $string['badge:pro'];
$string['teachersettings_desc'] = 'Paramètres spécifiques aux enseignants.';

$string['teacherslinkonteacher'] = 'Ajouter des liens vers les pages des enseignants';
$string['teacherslinkonteacher_desc'] = 'Lorsqu\'il y a des enseignants ajoutés aux options de réservation, ce paramètre ajoutera un lien vers une page de présentation pour chaque enseignant.';

$string['teachersnologinrequired'] = 'Connexion non requise pour les pages des enseignants';
$string['teachersnologinrequired_desc'] = 'Si vous activez ce paramètre, tout le monde peut accéder aux pages des enseignants, qu\'il soit connecté ou non.';
$string['teachersshowemails'] = 'Toujours afficher les adresses e-mail des enseignants à tout le monde';
$string['teachersshowemails_desc'] = 'Si vous activez ce paramètre, chaque utilisateur peut voir
    l\'adresse e-mail de tout enseignant - même s\'il n\'est pas connecté. <span class="text-danger"><b>Attention :</b> Cela peut être
    un problème de confidentialité. Activez ce paramètre uniquement si vous êtes sûr qu\'il correspond à la politique de confidentialité de votre organisation.</span>';
$string['bookedteachersshowemails'] = 'Afficher les adresses e-mail des enseignants aux utilisateurs réservés';
$string['bookedteachersshowemails_desc'] = 'Si vous activez ce paramètre, les utilisateurs réservés peuvent voir
l\'adresse e-mail de leur enseignant.';
$string['teachersallowmailtobookedusers'] = 'Autoriser les enseignants à envoyer un e-mail à tous les utilisateurs réservés en utilisant leur propre client de messagerie';
$string['teachersallowmailtobookedusers_desc'] = 'Si vous activez ce paramètre, les enseignants peuvent cliquer sur un bouton pour envoyer un e-mail
    à tous les utilisateurs réservés en utilisant leur propre client de messagerie - les adresses e-mail de tous les utilisateurs seront visibles.
    <span class="text-danger"><b>Attention :</b> Cela peut être un problème de confidentialité. Activez ce paramètre uniquement
    si vous êtes sûr qu\'il correspond à la politique de confidentialité de votre organisation.</span>';

$string['cancellationsettings'] = 'Paramètres d\'annulation ' . $string['badge:pro'];
$string['canceldependenton'] = 'Période d\'annulation dépendante de';
$string['canceldependenton_desc'] = 'Choisissez la date à utiliser comme "début" pour le paramètre
"Interdire aux utilisateurs d\'annuler leur réservation n jours avant le début. Moins signifie que les utilisateurs peuvent toujours annuler n
jours APRÈS le début du cours.".<br>
Cela définira également la <i>période de service</i> des cours dans le panier en conséquence (si le panier est installé).';
$string['cdo:coursestarttime'] = 'Début de l\'option de réservation (coursstarttime)';
$string['cdo:semesterstart'] = 'Début du semestre';
$string['cdo:bookingopeningtime'] = 'Début de l\'inscription à la réservation (bookingopeningtime)';
$string['cdo:bookingclosingtime'] = 'Fin de l\'inscription à la réservation (bookingclosingtime)';

$string['duplicatemoodlecourses'] = 'Dupliquer le cours Moodle';
$string['duplicatemoodlecourses_desc'] = 'Lorsque ce paramètre est activé et que vous dupliquez une option de réservation,
alors le cours Moodle connecté sera également dupliqué. Cela sera fait avec une tâche ad hoc,
assurez-vous donc que CRON s\'exécute régulièrement.';

// Mobile.
$string['next'] = 'Suivant';
$string['previous'] = 'Précédent';

// Privacy API.
$string['privacy:metadata:booking_answers'] = 'Représente une réservation d\'un événement';
$string['privacy:metadata:booking_answers:userid'] = 'Utilisateur réservé pour cet événement';
$string['privacy:metadata:booking_answers:bookingid'] = 'ID de l\'instance de réservation';
$string['privacy:metadata:booking_answers:optionid'] = 'ID de l\'option de réservation';
$string['privacy:metadata:booking_answers:timemodified'] = 'Horodatage de la dernière modification de la réservation';
$string['privacy:metadata:booking_answers:timecreated'] = 'Horodatage de la création de la réservation';
$string['privacy:metadata:booking_answers:waitinglist'] = 'Vrai si l\'utilisateur est sur la liste d\'attente';
$string['privacy:metadata:booking_answers:status'] = 'Informations sur le statut de cette réservation';
$string['privacy:metadata:booking_answers:notes'] = 'Notes supplémentaires';
$string['privacy:metadata:booking_ratings'] = 'Votre évaluation d\'un événement';
$string['privacy:metadata:booking_ratings:userid'] = 'Utilisateur ayant évalué cet événement';
$string['privacy:metadata:booking_ratings:optionid'] = 'ID de l\'option de réservation évaluée';
$string['privacy:metadata:booking_ratings:rate'] = 'Évaluation attribuée';
$string['privacy:metadata:booking_teachers'] = 'Enseignant(s) d\'un événement';
$string['privacy:metadata:booking_teachers:userid'] = 'Utilisateur enseignant cet événement';
$string['privacy:metadata:booking_teachers:optionid'] = 'ID de l\'option de réservation enseignée';
$string['privacy:metadata:booking_teachers:completed'] = 'Si la tâche est terminée pour l\'enseignant';
$string['privacy:metadata:booking_answers:completed'] = 'L\'utilisateur ayant réservé a terminé la tâche';
$string['privacy:metadata:booking_answers:frombookingid'] = 'ID de la réservation connectée';
$string['privacy:metadata:booking_answers:numrec'] = 'Numéro de registre';
$string['privacy:metadata:booking_icalsequence'] = 'Séquence iCal';
$string['privacy:metadata:booking_icalsequence:userid'] = 'ID utilisateur pour iCal';
$string['privacy:metadata:booking_icalsequence:optionid'] = 'ID de l\'option de réservation pour iCal';
$string['privacy:metadata:booking_icalsequence:sequencevalue'] = 'Valeur de la séquence iCal';
$string['privacy:metadata:booking_teachers:bookingid'] = 'ID de l\'instance de réservation pour l\'enseignant';
$string['privacy:metadata:booking_teachers:calendarid'] = 'ID de l\'événement de calendrier pour l\'enseignant';
$string['privacy:metadata:booking_userevents'] = 'Événements utilisateur dans le calendrier';
$string['privacy:metadata:booking_userevents:userid'] = 'ID utilisateur pour l\'événement utilisateur';
$string['privacy:metadata:booking_userevents:optionid'] = 'ID de l\'option de réservation pour l\'événement utilisateur';
$string['privacy:metadata:booking_userevents:optiondateid'] = 'ID de l\'optiondate (session) pour l\'événement utilisateur';
$string['privacy:metadata:booking_userevents:eventid'] = 'ID de l\'événement dans la table des événements';

$string['privacy:metadata:booking_optiondates_teachers'] = 'Suivre les enseignants pour chaque session.';
$string['privacy:metadata:booking_optiondates_teachers:optiondateid'] = 'ID de la date de l\'option';
$string['privacy:metadata:booking_optiondates_teachers:userid'] = 'L\'ID utilisateur de l\'enseignant.';

$string['privacy:metadata:booking_subbooking_answers'] = 'Enregistre les réponses (les réservations) d\'un utilisateur pour une sous-réservation particulière.';
$string['privacy:metadata:booking_subbooking_answers:itemid'] = 'itemid peut être identique à sboptionid, mais il existe certains types (par exemple, des créneaux horaires qui fournissent des créneaux) où un sboptionid fournit de nombreux itemids.';
$string['privacy:metadata:booking_subbooking_answers:optionid'] = 'L\'ID de l\'option';
$string['privacy:metadata:booking_subbooking_answers:sboptionid'] = 'ID de la sous-réservation réservée';
$string['privacy:metadata:booking_subbooking_answers:userid'] = 'ID utilisateur de l\'utilisateur réservé.';
$string['privacy:metadata:booking_subbooking_answers:usermodified'] = 'L\'utilisateur qui a modifié';
$string['privacy:metadata:booking_subbooking_answers:json'] = 'données supplémentaires si nécessaire';
$string['privacy:metadata:booking_subbooking_answers:timestart'] = 'Horodatage pour l\'heure de début de cette réservation';
$string['privacy:metadata:booking_subbooking_answers:timeend'] = 'Horodatage pour l\'heure de fin de cette réservation';
$string['privacy:metadata:booking_subbooking_answers:status'] = 'Le statut de la réservation, comme réservé, liste d\'attente, dans le panier, sur une liste de notification ou supprimé';
$string['privacy:metadata:booking_subbooking_answers:timecreated'] = 'L\'heure de création';
$string['privacy:metadata:booking_subbooking_answers:timemodified'] = 'L\'heure de la dernière modification';

$string['privacy:metadata:booking_odt_deductions'] = 'Cette table est utilisée pour enregistrer si nous voulons déduire une partie du salaire d\'un enseignant s\'il lui manque des heures.';
$string['privacy:metadata:booking_odt_deductions:optiondateid'] = 'L\'ID de la date de l\'option';
$string['privacy:metadata:booking_odt_deductions:userid'] = 'ID utilisateur de l\'enseignant qui reçoit une déduction pour cette date d\'option.';
$string['privacy:metadata:booking_odt_deductions:reason'] = 'Raison de la déduction.';
$string['privacy:metadata:booking_odt_deductions:usermodified'] = 'L\'utilisateur qui a modifié';
$string['privacy:metadata:booking_odt_deductions:timecreated'] = 'L\'heure de création';
$string['privacy:metadata:booking_odt_deductions:timemodified'] = 'L\'heure de la dernière modification';

// Calendar.php.
$string['usercalendarentry'] = 'Vous êtes réservé pour <a href="{$a}">cette session</a>.';
$string['bookingoptioncalendarentry'] = '<a href="{$a}" class="btn btn-primary">Réservez maintenant...</a>';

// Mybookings.php.
$string['status'] = 'Statut';
$string['active'] = 'Actif';
$string['terminated'] = 'Terminé';
$string['notstarted'] = 'Pas encore commencé';

// Subscribeusersactivity.php.
$string['transefusers'] = 'Transférer les utilisateurs';
$string['transferhelp'] = 'Transférer les utilisateurs qui n\'ont pas terminé l\'activité de l\'option sélectionnée à {$a}.';
$string['sucesfullytransfered'] = 'Les utilisateurs ont été transférés avec succès.';

$string['confirmactivtyfrom'] = 'Confirmer l\'activité des utilisateurs à partir de';
$string['sucesfullcompleted'] = 'L\'activité a été complétée avec succès pour les utilisateurs.';
$string['enablecompletion'] = 'Nombre d\'entrées';
$string['confirmuserswith'] = 'Confirmer les utilisateurs qui ont terminé l\'activité ou reçu un badge';
$string['confirmusers'] = 'Confirmer l\'activité des utilisateurs';

// Optiontemplatessettings.php.
$string['optiontemplatessettings'] = 'Modèles d\'options de réservation';
$string['defaulttemplate'] = 'Modèle par défaut';
$string['defaulttemplatedesc'] = 'Modèle d\'option de réservation par défaut lors de la création d\'une nouvelle option de réservation.';
$string['dontuse'] = 'Ne pas utiliser de modèle';

// Instancetemplateadd.php.
$string['saveinstanceastemplate'] = 'Ajouter une instance de réservation au modèle';
$string['thisinstance'] = 'Cette instance de réservation';
$string['instancetemplate'] = 'Modèle d\'instance';
$string['instancesuccessfullysaved'] = 'Cette instance de réservation a été enregistrée avec succès en tant que modèle.';
$string['instance_not_saved_no_valid_license'] = 'L\'instance de réservation n\'a pas pu être enregistrée en tant que modèle. Passez à la version PRO pour enregistrer un nombre illimité de modèles.';
$string['bookinginstancetemplatessettings'] = 'Réservation : Modèles d\'instance';
$string['bookinginstancetemplatename'] = 'Nom du modèle d\'instance de réservation';
$string['managebookinginstancetemplates'] = 'Gérer les modèles d\'instance de réservation';
$string['populatefromtemplate'] = 'Remplir à partir du modèle';

// Mybookings.
$string['mybookingsbooking'] = 'Réservation (Cours)';
$string['mybookingsoption'] = 'Option';

// Custom report templates.
$string['managecustomreporttemplates'] = 'Gérer les modèles de rapport personnalisé';
$string['customreporttemplates'] = 'Modèles de rapport personnalisé';
$string['customreporttemplate'] = 'Modèle de rapport personnalisé';
$string['addnewreporttemplate'] = 'Ajouter un nouveau modèle de rapport';
$string['templatefile'] = 'Fichier de modèle';
$string['templatesuccessfullysaved'] = 'Le modèle a été enregistré.';
$string['customdownloadreport'] = 'Télécharger le rapport';
$string['bookingoptionsfromtemplatemenu'] = 'Nouvelle option de réservation à partir du modèle';

// Automatic option creation.
$string['autcrheader'] = '[DÉPRÉCIÉ] Création automatique d\'options de réservation';
$string['autcrwhatitis'] = 'Si cette option est activée, elle crée automatiquement une nouvelle option de réservation et assigne un utilisateur en tant que gestionnaire / enseignant de réservation. Les utilisateurs sont sélectionnés en fonction de la valeur d\'un champ de profil utilisateur personnalisé.';
$string['enable'] = 'Activer';
$string['customprofilefield'] = 'Champ de profil personnalisé à vérifier';
$string['customprofilefieldvalue'] = 'Valeur du champ de profil personnalisé à vérifier';
$string['optiontemplate'] = 'Modèle d\'option';

// Link.php.
$string['bookingnotopenyet'] = 'Votre événement commence dans {$a} minutes. Le lien que vous avez utilisé vous redirigera si vous cliquez dessus à nouveau dans les 15 minutes avant le début.';
$string['bookingpassed'] = 'Votre événement est terminé.';
$string['linknotvalid'] = 'Ce lien ou cette réunion n\'est pas accessible. Si c\'est une réunion que vous avez réservée, veuillez vérifier à nouveau, peu avant le début.';

// Booking_utils.php.
$string['linknotavailableyet'] = 'Le lien pour accéder à la réunion est disponible uniquement 15 minutes avant le début jusqu\'à la fin de la session.';
$string['changeinfochanged'] = ' a changé :';
$string['changeinfoadded'] = ' a été ajouté :';
$string['changeinfodeleted'] = ' a été supprimé :';
$string['changeinfocfchanged'] = 'Un champ a changé :';
$string['changeinfocfadded'] = 'Un champ a été ajouté :';
$string['changeinfocfdeleted'] = 'Un champ a été supprimé :';
$string['changeinfosessionadded'] = 'Une session a été ajoutée :';
$string['changeinfosessiondeleted'] = 'Une session a été supprimée :';

// Bookingoption_changes.mustache.
$string['changeold'] = '[SUPPRIMÉ] ';
$string['changenew'] = '[NOUVEAU] ';

// Bookingoption_description.php.
$string['gotobookingoption'] = 'Aller à l\'option de réservation';
$string['dayofweektime'] = 'Jour & Heure';
$string['showdates'] = 'Afficher les dates';

// Bookingoptions_simple_table.php.
$string['bsttext'] = 'Option de réservation';
$string['bstcoursestarttime'] = 'Date / Heure';
$string['bstlocation'] = 'Lieu';
$string['bstinstitution'] = 'Institution';
$string['bstparticipants'] = 'Participants';
$string['bstteacher'] = 'Enseignant(s)';
$string['bstwaitinglist'] = 'Sur liste d\'attente';
$string['bstmanageresponses'] = 'Gérer les réservations';
$string['bstcourse'] = 'Cours';
$string['bstlink'] = 'Afficher';

// All_options.php.
$string['infoalreadybooked'] = '<div class="infoalreadybooked"><i>Vous êtes déjà réservé pour cette option.</i></div>';
$string['infowaitinglist'] = '<div class="infowaitinglist"><i>Vous êtes sur la liste d\'attente pour cette option.</i></div>';

$string['tableheader_text'] = 'Nom du cours';
$string['tableheader_teacher'] = 'Enseignant(s)';
$string['tableheader_maxanswers'] = 'Places disponibles';
$string['tableheader_maxoverbooking'] = 'Places en liste d\'attente';
$string['tableheader_minanswers'] = 'Nombre minimum de participants';
$string['tableheader_coursestarttime'] = 'Début du cours';
$string['tableheader_courseendtime'] = 'Fin du cours';

// Customfields.
$string['booking_customfield'] = 'Champs personnalisés pour les options de réservation';

// Optiondates_only.mustache.
$string['sessions'] = 'Session(s)';

// Message_sent.php.
$string['message_sent'] = 'Message envoyé';

// Price.php.
$string['nopricecategoriesyet'] = 'Aucune catégorie de prix n\'a encore été créée.';
$string['priceformulaisactive'] = 'Lors de la sauvegarde, calculer les prix avec la formule de prix (cela écrasera les prix actuels).';
$string['priceformulainfo'] = '<a data-toggle="collapse" href="#priceformula" role="button" aria-expanded="false" aria-controls="priceformula">
<i class="fa fa-code"></i> Afficher le JSON pour la formule de prix...
</a>
<div class="collapse" id="priceformula">
<samp>{$a->formula}</samp>
</div><br>
<a href="' . $CFG->wwwroot . '/admin/settings.php?section=modsettingbooking" target="_blank"><i class="fa fa-edit"></i> Modifier la formule...</a><br><br>
Ci-dessous, vous pouvez ajouter en supplément un facteur manuel (multiplication) et une valeur absolue (addition) à ajouter à la formule.';
$string['priceformulamultiply'] = 'Facteur manuel';
$string['priceformulamultiply_help'] = 'Valeur supplémentaire pour <strong>multiplier</strong> le résultat.';
$string['priceformulaadd'] = 'Valeur absolue';
$string['priceformulaadd_help'] = 'Valeur supplémentaire à <strong>ajouter</strong> au résultat.';
$string['priceformulaoff'] = 'Empêcher le recalcul des prix';
$string['priceformulaoff_help'] = 'Activez cette option pour empêcher la fonction "Calculer tous les prix à partir de l\'instance avec la formule" de recalculer les prix pour cette option de réservation.';

// Pricecategories_form.php.
$string['price'] = 'Prix';
$string['additionalpricecategories'] = 'Ajouter ou modifier des catégories de prix';
$string['defaultpricecategoryname'] = 'Nom de la catégorie de prix par défaut';
$string['nopricecategoryselected'] = 'Entrez le nom d\'une nouvelle catégorie de prix';
$string['pricecategoryidentifier'] = 'Identifiant de la catégorie de prix';
$string['pricecategoryidentifier_help'] = 'Entrez un texte court pour identifier la catégorie, par exemple "étud" ou "acad".';
$string['pricecategoryname'] = 'Nom de la catégorie de prix';
$string['pricecategoryname_help'] = 'Entrez le nom complet de la catégorie de prix à afficher dans les options de réservation, par exemple "Prix étudiant".';
$string['defaultvalue'] = 'Valeur de prix par défaut';
$string['defaultvalue_help'] = 'Entrez une valeur par défaut pour chaque prix de cette catégorie. Bien sûr, cette valeur peut être remplacée plus tard.';
$string['pricecatsortorder'] = 'Ordre de tri (nombre)';
$string['pricecatsortorder_help'] = 'Entrez un nombre entier. "1" signifie que la catégorie de prix sera affichée en premier, "2" en deuxième place, etc.';
$string['disablepricecategory'] = 'Désactiver la catégorie de prix';
$string['disablepricecategory_help'] = 'Lorsque vous désactivez une catégorie de prix, vous ne pourrez plus l\'utiliser.';
$string['addpricecategory'] = 'Ajouter une catégorie de prix';
$string['erroremptypricecategoryname'] = 'Le nom de la catégorie de prix ne doit pas être vide.';
$string['erroremptypricecategoryidentifier'] = 'L\'identifiant de la catégorie de prix ne doit pas être vide.';
$string['errorduplicatepricecategoryidentifier'] = 'Les identifiants des catégories de prix doivent être uniques.';
$string['errorduplicatepricecategoryname'] = 'Les noms des catégories de prix doivent être uniques.';
$string['errortoomanydecimals'] = 'Seuls 2 décimales sont autorisées.';
$string['pricecategoriessaved'] = 'Les catégories de prix ont été enregistrées';
$string['pricecategoriessubtitle'] = '<p>Ici, vous pouvez définir différentes catégories de prix, par exemple des catégories de prix spéciales pour les étudiants, les employés ou les externes.
    <b>Faites attention :</b> Une fois que vous avez ajouté une catégorie, vous ne pouvez pas la supprimer.
    Vous ne pouvez que la désactiver ou la renommer.</p>';

// Price formula.
$string['defaultpriceformula'] = 'Formule de prix';
$string['priceformulaheader'] = 'Formule de prix ' . $string['badge:pro'];
$string['priceformulaheader_desc'] = 'Utilisez une formule de prix pour calculer automatiquement les prix des options de réservation.';
$string['defaultpriceformuladesc'] = 'L\'objet JSON permet la configuration du calcul automatique des prix avec une option de réservation.';

// Semesters.
$string['booking:semesters'] = 'Réservation : Semestres';
$string['semester'] = 'Semestre';
$string['semesters'] = 'Semestres';
$string['semesterssaved'] = 'Les semestres ont été enregistrés';
$string['semesterssubtitle'] = 'Ici, vous pouvez ajouter, modifier ou supprimer <strong>des semestres et des vacances</strong>.
    Après enregistrement, les entrées seront triées par leur <strong>date de début en ordre décroissant</strong>.';
$string['addsemester'] = 'Ajouter un semestre';
$string['semesteridentifier'] = 'Identifiant';
$string['semesteridentifier_help'] = 'Texte court pour identifier le semestre, par exemple "hiver22".';
$string['semestername'] = 'Nom';
$string['semestername_help'] = 'Entrez le nom complet du semestre, par exemple "Semestre d\'hiver 2021/22"';
$string['semesterstart'] = 'Premier jour du semestre';
$string['semesterstart_help'] = 'Le jour où le semestre commence.';
$string['semesterend'] = 'Dernier jour du semestre';
$string['semesterend_help'] = 'Le jour où le semestre se termine';
$string['deletesemester'] = 'Supprimer le semestre';
$string['erroremptysemesteridentifier'] = 'L\'identifiant du semestre est requis!';
$string['erroremptysemestername'] = 'Le nom du semestre ne doit pas être vide';
$string['errorduplicatesemesteridentifier'] = 'Les identifiants de semestre doivent être uniques.';
$string['errorduplicatesemestername'] = 'Les noms de semestre doivent être uniques.';
$string['errorsemesterstart'] = 'Le début du semestre doit être avant la fin du semestre.';
$string['errorsemesterend'] = 'La fin du semestre doit être après le début du semestre.';
$string['choosesemester'] = 'Choisir le semestre';
$string['choosesemester_help'] = 'Choisissez le semestre pour lequel les vacances doivent être créées.';
$string['holidays'] = 'Vacances';
$string['holiday'] = 'Vacance';
$string['holidayname'] = 'Nom de la vacance (facultatif)';
$string['holidaystart'] = 'Début des vacances';
$string['holidayend'] = 'Fin';
$string['holidayendactive'] = 'La fin n\'est pas le même jour';
$string['addholiday'] = 'Ajouter des vacances';
$string['errorholidaystart'] = 'Les vacances ne peuvent pas commencer après la date de fin.';
$string['errorholidayend'] = 'Les vacances ne peuvent pas se terminer avant la date de début.';
$string['deleteholiday'] = 'Supprimer les vacances';

// Cache.
$string['cachedef_bookedusertable'] = 'Tableau des utilisateurs réservés (cache)';
$string['cachedef_bookingoptions'] = 'Options de réservation (cache)';
$string['cachedef_bookingoptionsanswers'] = 'Réponses des options de réservation (cache)';
$string['cachedef_bookingoptionstable'] = 'Tables des options de réservation avec des requêtes SQL hachées (cache)';
$string['cachedef_cachedpricecategories'] = 'Catégories de prix de réservation (cache)';
$string['cachedef_cachedprices'] = 'Prix en réservation (cache)';
$string['cachedef_cachedbookinginstances'] = 'Instances de réservation (cache)';
$string['cachedef_bookingoptionsettings'] = 'Paramètres des options de réservation (cache)';
$string['cachedef_cachedsemesters'] = 'Semestres (cache)';
$string['cachedef_cachedteachersjournal'] = 'Journal des enseignants (cache)';
$string['cachedef_subbookingforms'] = 'Formulaires de sous-réservation (cache)';
$string['cachedef_conditionforms'] = 'Formulaires de condition (cache)';
$string['cachedef_confirmbooking'] = 'Réservation confirmée (cache)';
$string['cachedef_electivebookingorder'] = 'Ordre de réservation élective (cache)';
$string['cachedef_customformuserdata'] = 'Données utilisateur du formulaire personnalisé (cache)';
$string['cachedef_eventlogtable'] = 'Table du journal des événements (cache)';

// Dates_handler.php.
$string['chooseperiod'] = 'Sélectionnez la période';
$string['chooseperiod_help'] = 'Sélectionnez une période pour créer la série de dates.';
$string['dates'] = 'Dates';
$string['reoccurringdatestring'] = 'Jour de la semaine, heure de début et de fin (Jour, HH:MM - HH:MM)';
$string['reoccurringdatestring_help'] = 'Entrez un texte au format suivant : "Jour, HH:MM - HH:MM", par exemple "Lundi, 10:00 - 11:00" ou "Dim 09:00-10:00" ou "bloc" pour les événements bloqués.';

// Weekdays.
$string['monday'] = 'Lundi';
$string['tuesday'] = 'Mardi';
$string['wednesday'] = 'Mercredi';
$string['thursday'] = 'Jeudi';
$string['friday'] = 'Vendredi';
$string['saturday'] = 'Samedi';
$string['sunday'] = 'Dimanche';

// Dynamicoptiondateform.php.
$string['add_optiondate_series'] = 'Créer une série de dates';
$string['reoccurringdatestringerror'] = 'Entrez un texte au format suivant : Jour, HH:MM - HH:MM ou "bloc" pour les événements bloqués.';
$string['customdatesbtn'] = '<i class="fa fa-plus-square"></i> Dates personnalisées...';
$string['aboutmodaloptiondateform'] = 'Créer des dates personnalisées (par exemple, pour des événements bloqués ou pour des dates uniques différentes de la série de dates).';
$string['modaloptiondateformtitle'] = 'Dates personnalisées';
$string['optiondate'] = 'Date';
$string['addoptiondate'] = 'Ajouter une date';
$string['deleteoptiondate'] = 'Supprimer la date';
$string['optiondatestart'] = 'Début';
$string['optiondateend'] = 'Fin';
$string['erroroptiondatestart'] = 'La date de début doit être avant la date de fin.';
$string['erroroptiondateend'] = 'La date de fin doit être après la date de début.';

// Optiondates_teachers_report.php & optiondates_teachers_table.php.
$string['accessdenied'] = 'Accès refusé';
$string['nopermissiontoaccesspage'] = '<div class="alert alert-danger" role="alert">Vous n\'avez pas la permission d\'accéder à cette page.</div>';
$string['optiondatesteachersreport'] = 'Remplacements / Dates annulées';
$string['optiondatesteachersreport_desc'] = 'Ce rapport donne un aperçu de quel enseignant était présent à quelle date spécifique.<br>
Par défaut, chaque date sera remplie avec l\'enseignant de l\'option. Vous pouvez remplacer des dates spécifiques par des enseignants remplaçants.';
$string['linktoteachersinstancereport'] = '<p><a href="{$a}" target="_self">&gt;&gt; Aller au rapport des enseignants pour l\'instance de réservation</a></p>';
$string['noteacherset'] = 'Aucun enseignant';
$string['reason'] = 'Raison';
$string['error:reasonfornoteacher'] = 'Entrez une raison pour laquelle aucun enseignant n\'était présent à cette date.';
$string['error:reasontoolong'] = 'La raison est trop longue, entrez un texte plus court.';
$string['error:reasonforsubstituteteacher'] = 'Entrez une raison pour les enseignant(s) remplaçant(s).';
$string['error:reasonfordeduction'] = 'Entrez une raison pour la déduction.';

$string['confirmbooking'] = 'Confirmation de cette réservation';
$string['confirmbookinglong'] = 'Voulez-vous vraiment confirmer cette réservation?';
$string['unconfirm'] = 'Supprimer la confirmation';
$string['unconfirmbooking'] = 'Supprimer la confirmation de cette réservation';
$string['unconfirmbookinglong'] = 'Voulez-vous vraiment supprimer la confirmation de cette réservation?';

$string['deletebooking'] = 'Supprimer cette réservation';
$string['deletebookinglong'] = 'Voulez-vous vraiment supprimer cette réservation?';

$string['successfullysorted'] = 'Tri réussi';

// Teachers_instance_report.php.
$string['teachers_instance_report'] = 'Rapport des enseignants';
$string['error:invalidcmid'] = 'Le rapport ne peut pas être ouvert car aucun identifiant de module de cours valide (cmid) n\'a été fourni. Il doit s\'agir du cmid d\'une instance de réservation!';
$string['teachingreportforinstance'] = 'Rapport d\'aperçu de l\'enseignement pour ';
$string['teachersinstancereport:subtitle'] = '<strong>Conseil :</strong> Le nombre d\'unités d\'un cours (option de réservation) est calculé en fonction de la durée d\'une unité éducative
 que vous pouvez <a href="' . $CFG->wwwroot . '/admin/settings.php?section=modsettingbooking" target="_blank">définir dans les paramètres de réservation</a> et de la chaîne de dates spécifiée (par exemple, "Mar, 16:00-17:30").
 Pour les événements bloqués ou les options de réservation manquant cette chaîne, le nombre d\'unités ne peut pas être calculé!';
$string['units'] = 'Unités';
$string['sum_units'] = 'Somme des unités';
$string['units_courses'] = 'Cours / Unités';
$string['units_unknown'] = 'Nombre d\'unités inconnu';
$string['missinghours'] = 'Heures manquantes';
$string['substitutions'] = 'Remplacement(s)';

// Teachers_instance_config.php.
$string['teachers_instance_config'] = 'Modifier le formulaire d\'option de réservation';
$string['teachingconfigforinstance'] = 'Modifier le formulaire d\'option de réservation pour ';
$string['dashboard_summary'] = 'Général';
$string['dashboard_summary_desc'] = 'Contient les paramètres et les statistiques pour l\'ensemble du site Moodle';

// Optionformconfig.php / optionformconfig_form.php.
$string['optionformconfig'] = 'Configurer les formulaires d\'option de réservation (PRO)';
$string['optionformconfig_infotext'] = 'Avec cette fonctionnalité PRO, vous pouvez créer vos propres formulaires d\'option de réservation en utilisant le glisser-déposer
et les cases à cocher. Les formulaires sont stockés à un niveau de contexte spécifique (par exemple, instance de réservation, site entier...). Les utilisateurs ne peuvent accéder aux formulaires
que s\'ils ont les capacités appropriées.';
$string['optionformconfig_getpro'] = 'Avec Booking ' . $string['badge:pro'] . ' vous avez la possibilité de créer des formulaires individuels avec glisser-déposer
pour des rôles utilisateurs spécifiques et des contextes (par exemple, pour une instance de réservation spécifique ou au niveau du système).';
$string['optionformconfigsaved'] = 'Configuration du formulaire d\'option de réservation enregistrée.';
$string['optionformconfigsubtitle'] = '<p>Désactivez les fonctionnalités dont vous n\'avez pas besoin, afin de rendre le formulaire d\'option de réservation plus compact pour vos administrateurs.</p>
<p><strong>ATTENTION :</strong> Ne désactivez les champs que si vous êtes absolument sûr de ne pas en avoir besoin!</p>';
$string['optionformconfig:nobooking'] = 'Vous devez créer au moins une instance de réservation avant de pouvoir utiliser ce formulaire!';

$string['optionformconfigsavedsystem'] = 'Votre définition de formulaire a été enregistrée au niveau du contexte système';
$string['optionformconfigsavedcoursecat'] = 'Votre définition de formulaire a été enregistrée au niveau du contexte catégorie de cours';
$string['optionformconfigsavedmodule'] = 'Votre définition de formulaire a été enregistrée au niveau du contexte module';
$string['optionformconfigsavedcourse'] = 'Votre définition de formulaire a été enregistrée au niveau du contexte cours';
$string['optionformconfigsavedother'] = 'Votre définition de formulaire a été enregistrée au niveau du contexte {$a}';

$string['optionformconfignotsaved'] = 'Aucune configuration spéciale n\'a été enregistrée pour votre formulaire';

$string['prepare_import'] = 'Préparer l\'importation';
$string['id'] = 'Id';
$string['json'] = 'Stocke des informations supplémentaires';
$string['returnurl'] = 'Url de retour';
$string['youareusingconfig'] = 'Vous utilisez la configuration de formulaire suivante : {$a}';
$string['formconfig'] = 'Afficher le formulaire utilisé.';
$string['template'] = 'Modèles';
$string['text'] = 'Titre';
$string['maxanswers'] = 'Limite pour les réponses';
$string['identifier'] = 'Identification';
$string['easy_text'] = 'Texte simple, non modifiable';
$string['easy_bookingopeningtime'] = 'Heure d\'ouverture de la réservation facile';
$string['easy_bookingclosingtime'] = 'Heure de fermeture de la réservation facile';
$string['easy_availability_selectusers'] = 'Condition d\'utilisateurs sélectionnés facile';
$string['easy_availability_previouslybooked'] = 'Condition de réservation précédente facile';
$string['invisible'] = 'Invisible';
$string['annotation'] = 'Annotation interne';
$string['courseid'] = 'Cours à inscrire';
$string['entities'] = 'Choisir des lieux avec le plugin d\'entités';
$string['optiondates'] = 'Dates';
$string['actions'] = 'Actions de réservation';
$string['attachment'] = 'Pièces jointes';
$string['howmanyusers'] = 'Limite de réservation pour d\'autres utilisateurs';
$string['recurringoptions'] = 'Options de réservation récurrentes';
$string['bookusers'] = 'Pour l\'importation, réserver des utilisateurs directement';
$string['timemodified'] = 'Heure modifiée';
$string['waitforconfirmation'] = 'Réserver uniquement après confirmation';

// Tasks.
$string['task_adhoc_reset_optiondates_for_semester'] = 'Tâche ad hoc : Réinitialiser et générer de nouvelles dates d\'option pour le semestre';
$string['task_remove_activity_completion'] = 'Réservation : Supprimer l\'achèvement de l\'activité';
$string['task_enrol_bookedusers_tocourse'] = 'Réservation : Inscrire les utilisateurs réservés au cours';
$string['task_send_completion_mails'] = 'Réservation : Envoyer des e-mails d\'achèvement';
$string['task_send_confirmation_mails'] = 'Réservation : Envoyer des e-mails de confirmation';
$string['task_send_notification_mails'] = 'Réservation : Envoyer des e-mails de notification';
$string['task_send_reminder_mails'] = 'Réservation : Envoyer des e-mails de rappel';
$string['task_send_mail_by_rule_adhoc'] = 'Réservation : Envoyer un e-mail par règle (tâche ad hoc)';
$string['task_clean_booking_db'] = 'Réservation : Nettoyer la base de données';
$string['task_purge_campaign_caches'] = 'Réservation : Nettoyer les caches pour les campagnes de réservation';
$string['optionbookabletitle'] = '{$a->title} est de nouveau disponible';
$string['optionbookablebody'] = '{$a->title} est maintenant disponible. <a href="{$a->url}">Cliquez ici</a> pour y accéder directement.<br><br>
(Vous recevez ce mail parce que vous avez cliqué sur le bouton de notification pour cette option.)<br><br>
<a href="{$a->unsubscribelink}">Se désabonner des e-mails de notification pour "{$a->title}".</a>';

// Calculate prices.
$string['recalculateprices'] = 'Calculer tous les prix à partir de l\'instance avec la formule';
$string['recalculateall'] = 'Calculer tous les prix';
$string['alertrecalculate'] = '<b>Attention!</b> Tous les prix seront recalculés et tous les anciens prix seront écrasés.';
$string['nopriceformulaset'] = 'Aucune formule définie sur la page des paramètres. <a href="{$a->url}" target="_blank">Définissez-la ici.</a>';
$string['successfulcalculation'] = 'Calcul des prix réussi!';
$string['applyunitfactor'] = 'Appliquer le facteur d\'unité';
$string['applyunitfactor_desc'] = 'Si ce paramètre est actif, la durée de l\'unité éducative (par exemple, 45 min) définie ci-dessus sera utilisée
 pour calculer le nombre d\'unités éducatives. Ce nombre sera utilisé comme facteur pour la formule de prix.
 Exemple : Une option de réservation a une série de dates comme "Lun, 15:00 - 16:30". Elle dure donc 2 unités éducatives (45 min chacune).
 Un facteur d\'unité de 2 sera donc appliqué à la formule de prix. (Le facteur d\'unité ne sera appliqué que si une formule de prix est présente.)';
$string['roundpricesafterformula'] = 'Arrondir les prix (formule de prix)';
$string['roundpricesafterformula_desc'] = 'Si activé, les prix seront arrondis à des nombres entiers (sans décimales) après que la <strong>formule de prix</strong> ait été appliquée.';

// Col_availableplaces.mustache.
$string['manageresponses'] = 'Gérer les réservations';

// Bo conditions.
$string['availabilityconditions'] = 'Conditions de disponibilité';
$string['availabilityconditionsheader'] = '<i class="fa fa-fw fa-key" aria-hidden="true"></i>&nbsp;Conditions de disponibilité';
$string['apply'] = 'Appliquer';
$string['delete'] = 'Supprimer';

$string['bo_cond_alreadybooked'] = 'déjàréservé : Déjà réservé par cet utilisateur';
$string['bo_cond_alreadyreserved'] = 'déjàréservé : Déjà ajouté au panier par cet utilisateur';
$string['bo_cond_selectusers'] = 'Seuls les utilisateurs sélectionnés peuvent réserver';
$string['bo_cond_booking_time'] = 'Réservable uniquement pendant une certaine période';
$string['bo_cond_fullybooked'] = 'Complet';
$string['bo_cond_bookingpolicy'] = 'Politique de réservation';
$string['bo_cond_notifymelist'] = 'Liste de notification';
$string['bo_cond_max_number_of_bookings'] = 'nombre_maximum_de_réservations : Nombre maximum de réservations par utilisateur atteint';
$string['bo_cond_onwaitinglist'] = 'surlistattente : Utilisateur sur la liste d\'attente';
$string['bo_cond_askforconfirmation'] = 'demanderconfirmation : Confirmer manuellement la réservation';
$string['bo_cond_previouslybooked'] = 'L\'utilisateur a déjà réservé une certaine option';
$string['bo_cond_enrolledincourse'] = 'L\'utilisateur est inscrit à un ou plusieurs cours spécifiques';
$string['bo_cond_priceisset'] = 'prixfixé : Le prix est fixé';
$string['bo_cond_userprofilefield_1_default'] = 'Le champ de profil utilisateur a une certaine valeur';
$string['bo_cond_userprofilefield_2_custom'] = 'Le champ de profil utilisateur personnalisé a une certaine valeur';
$string['bo_cond_isbookable'] = 'réservable : La réservation est autorisée';
$string['bo_cond_isloggedin'] = 'connecté : L\'utilisateur est connecté';
$string['bo_cond_fullybookedoverride'] = 'complet_overwrite : Peut être surbooké par le personnel';
$string['bo_cond_iscancelled'] = 'annulé : Option de réservation annulée';
$string['bo_cond_subbooking_blocks'] = 'Sous-réservation bloque cette option de réservation';
$string['bo_cond_subbooking'] = 'Des sous-réservations existent';
$string['bo_cond_bookitbutton'] = 'bookitbutton : Afficher le bouton de réservation normal.';
$string['bo_cond_isloggedinprice'] = 'isloggedinprice : Afficher tous les prix lorsqu\'il n\'est pas connecté.';
$string['bo_cond_optionhasstarted'] = 'A déjà commencé';
$string['bo_cond_customform'] = 'Remplir le formulaire';

$string['bo_cond_booking_time_available'] = 'Dans les heures de réservation normales.';
$string['bo_cond_booking_time_not_available'] = 'Pas dans les heures de réservation normales.';
$string['bo_cond_booking_opening_time_not_available'] = 'Réservable à partir de {$a}.';
$string['bo_cond_booking_opening_time_full_not_available'] = 'Réservable à partir de {$a}.';
$string['bo_cond_booking_closing_time_not_available'] = 'Ne peut plus être réservé (terminé le {$a}).';
$string['bo_cond_booking_closing_time_full_not_available'] = 'Ne peut plus être réservé (terminé le {$a}).';

$string['bo_cond_alreadybooked_available'] = 'Pas encore réservé';
$string['bo_cond_alreadybooked_full_available'] = 'L\'utilisateur n\'a pas encore réservé';
$string['bo_cond_alreadybooked_not_available'] = 'Réservé';
$string['bo_cond_alreadybooked_full_not_available'] = 'Réservé';

$string['bo_cond_alreadyreserved_available'] = 'Pas encore ajouté au panier';
$string['bo_cond_alreadyreserved_full_available'] = 'Pas encore ajouté au panier';
$string['bo_cond_alreadyreserved_not_available'] = 'Ajouté au panier';
$string['bo_cond_alreadyreserved_full_not_available'] = 'Ajouté au panier';

$string['bo_cond_fullybooked_available'] = 'Réserver';
$string['bo_cond_fullybooked_full_available'] = 'La réservation est possible';
$string['bo_cond_fullybooked_not_available'] = 'Complet';
$string['bo_cond_fullybooked_full_not_available'] = 'Complet';

$string['bo_cond_allowedtobookininstance_available'] = 'Réserver';
$string['bo_cond_allowedtobookininstance_full_available'] = 'La réservation est possible';
$string['bo_cond_allowedtobookininstance_not_available'] = 'Pas le droit de réserver';
$string['bo_cond_allowedtobookininstance_full_not_available'] = 'Pas le droit de réserver sur cette instance de réservation';

$string['bo_cond_fullybookedoverride_available'] = 'Réserver';
$string['bo_cond_fullybookedoverride_full_available'] = 'La réservation est possible';
$string['bo_cond_fullybookedoverride_not_available'] = 'Complet';
$string['bo_cond_fullybookedoverride_full_not_available'] = 'Complet - mais vous avez le droit de réserver un utilisateur malgré tout.';

$string['bo_cond_max_number_of_bookings_available'] = 'Réserver';
$string['bo_cond_max_number_of_bookings_full_available'] = 'La réservation est possible';
$string['bo_cond_max_number_of_bookings_not_available'] = 'Nombre maximum de réservations atteint';
$string['bo_cond_max_number_of_bookings_full_not_available'] = 'L\'utilisateur a atteint le nombre maximum de réservations';

$string['bo_cond_onnotifylist_available'] = 'Réserver';
$string['bo_cond_onnotifylist_full_available'] = 'La réservation est possible';
$string['bo_cond_onnotifylist_not_available'] = 'Nombre maximum de réservations atteint';
$string['bo_cond_onnotifylist_full_not_available'] = 'L\'utilisateur a atteint le nombre maximum de réservations';

$string['bo_cond_askforconfirmation_available'] = 'Réserver';
$string['bo_cond_askforconfirmation_full_available'] = 'La réservation est possible';
$string['bo_cond_askforconfirmation_not_available'] = 'Réserver - en liste d\'attente';
$string['bo_cond_askforconfirmation_full_not_available'] = 'Réserver - en liste d\'attente';

$string['bo_cond_onwaitinglist_available'] = 'Réserver';
$string['bo_cond_onwaitinglist_full_available'] = 'La réservation est possible';
$string['bo_cond_onwaitinglist_not_available'] = 'Complet - Vous êtes sur la liste d\'attente';
$string['bo_cond_onwaitinglist_full_not_available'] = 'Complet - L\'utilisateur est sur la liste d\'attente';

$string['bo_cond_userprofilefield_available'] = 'Réserver';
$string['bo_cond_userprofilefield_full_available'] = 'La réservation est possible';
$string['bo_cond_userprofilefield_not_available'] = 'Non autorisé à réserver';
$string['bo_cond_userprofilefield_full_not_available'] = 'Seuls les utilisateurs dont le champ de profil utilisateur {$a->profilefield} est défini à la valeur {$a->value} sont autorisés à réserver.
    <br>Mais vous avez le droit de réserver un utilisateur de toute façon.';

$string['bo_cond_customuserprofilefield_available'] = 'Réserver';
$string['bo_cond_customuserprofilefield_full_available'] = 'La réservation est possible';
$string['bo_cond_customuserprofilefield_not_available'] = 'Non autorisé à réserver';
$string['bo_cond_customuserprofilefield_full_not_available'] = 'Seuls les utilisateurs dont le champ de profil utilisateur personnalisé {$a->profilefield} est défini à la valeur {$a->value} sont autorisés à réserver.
    <br>Mais vous avez le droit de réserver un utilisateur de toute façon.';

$string['bo_cond_previouslybooked_available'] = 'Réserver';
$string['bo_cond_previouslybooked_full_available'] = 'La réservation est possible';
$string['bo_cond_previouslybooked_not_available'] = 'Seuls les utilisateurs qui ont déjà réservé <a href="{$a}">cette option</a> sont autorisés à réserver.';
$string['bo_cond_previouslybooked_full_not_available'] = 'Seuls les utilisateurs qui ont déjà réservé <a href="{$a}">cette option</a> sont autorisés à réserver.
    <br>Mais vous avez le droit de réserver un utilisateur de toute façon.';

$string['bo_cond_enrolledincourse_available'] = 'Réserver';
$string['bo_cond_enrolledincourse_full_available'] = 'La réservation est possible';
$string['bo_cond_enrolledincourse_not_available'] = 'Réservation non autorisée car vous n\'êtes pas inscrit à au moins un des cours suivants : {$a}';
$string['bo_cond_enrolledincourse_full_not_available'] = 'Seuls les utilisateurs inscrits à au moins un des cours suivants sont autorisés à réserver : {$a}
    <br>Mais vous avez le droit de réserver un utilisateur de toute façon.';
$string['bo_cond_enrolledincourse_not_available_and'] = 'Réservation non autorisée car vous n\'êtes pas inscrit à tous les cours suivants : {$a}';
$string['bo_cond_enrolledincourse_full_not_available_and'] = 'Seuls les utilisateurs inscrits à tous les cours suivants sont autorisés à réserver : {$a}
    <br>Mais vous avez le droit de réserver un utilisateur de toute façon.';

$string['bo_cond_isbookable_available'] = 'Réserver';
$string['bo_cond_isbookable_full_available'] = 'La réservation est possible';
$string['bo_cond_isbookable_not_available'] = 'Non autorisé à réserver';
$string['bo_cond_isbookable_full_not_available'] = 'La réservation est interdite pour cette option de réservation.
    <br>Mais vous avez le droit de réserver un utilisateur de toute façon.';

$string['bo_cond_subisbookable_available'] = 'Réserver';
$string['bo_cond_subisbookable_full_available'] = 'La réservation est possible';
$string['bo_cond_subisbookable_not_available'] = 'Réserver l\'option d\'abord';
$string['bo_cond_subisbookable_full_not_available'] = 'La réservation n\'est pas possible pour cette sous-réservation car l\'option correspondante n\'est pas réservée.';

$string['bo_cond_iscancelled_available'] = 'Réserver';
$string['bo_cond_iscancelled_full_available'] = 'La réservation est possible';
$string['bo_cond_iscancelled_not_available'] = 'Annulé';
$string['bo_cond_iscancelled_full_not_available'] = 'Annulé';

$string['bo_cond_isloggedin_available'] = 'Réserver';
$string['bo_cond_isloggedin_full_available'] = 'La réservation est possible';
$string['bo_cond_isloggedin_not_available'] = 'Connectez-vous pour réserver cette option.';
$string['bo_cond_isloggedin_full_not_available'] = 'L\'utilisateur n\'est pas connecté.';

$string['bo_cond_optionhasstarted_available'] = 'Réserver';
$string['bo_cond_optionhasstarted_full_available'] = 'La réservation est possible';
$string['bo_cond_optionhasstarted_not_available'] = 'Déjà commencé - la réservation n\'est plus possible';
$string['bo_cond_optionhasstarted_full_not_available'] = 'Déjà commencé - la réservation pour les utilisateurs n\'est plus possible';

$string['bo_cond_selectusers_available'] = 'Réserver';
$string['bo_cond_selectusers_full_available'] = 'La réservation est possible';
$string['bo_cond_selectusers_not_available'] = 'Réservation non autorisée';
$string['bo_cond_selectusers_full_not_available'] = 'Seuls les utilisateurs suivants sont autorisés à réserver :<br>{$a}';

$string['bo_cond_subbookingblocks_available'] = 'Réserver';
$string['bo_cond_subbookingblocks_full_available'] = 'La réservation est possible';
$string['bo_cond_subbookingblocks_not_available'] = 'Non autorisé à réserver.';
$string['bo_cond_subbookingblocks_full_not_available'] = 'La sous-réservation bloque cette option de réservation.';

// Cela ne bloque pas vraiment, il s'agit juste de gérer les sous-réservations disponibles.
$string['bo_cond_subbooking_available'] = 'Réserver';
$string['bo_cond_subbooking_full_available'] = 'La réservation est possible';
$string['bo_cond_subbooking_not_available'] = 'Réserver';
$string['bo_cond_subbooking_full_not_available'] = 'La réservation est possible';

$string['bo_cond_customform_restrict'] = 'Le formulaire doit être rempli avant de réserver';
$string['bo_cond_customform_available'] = 'Réserver';
$string['bo_cond_customform_full_available'] = 'La réservation est possible';
$string['bo_cond_customform_not_available'] = 'Réserver';
$string['bo_cond_customform_full_not_available'] = 'La réservation est possible';

// Conditions BO dans mform.
$string['bo_cond_selectusers_restrict'] = 'Seuls certains utilisateurs sont autorisés à réserver';
$string['bo_cond_selectusers_userids'] = 'Utilisateurs autorisés à réserver';
$string['bo_cond_selectusers_userids_help'] = '<p>Si vous utilisez cette condition, seules les personnes sélectionnées pourront réserver cet événement.</p>
<p>Cependant, vous pouvez également utiliser cette condition pour permettre à certaines personnes de contourner d\'autres restrictions :</p>
<p>(1) Pour ce faire, cochez la case "A une relation avec une autre condition".<br>
(2) Assurez-vous que l\'opérateur "OU" est sélectionné.<br>
(3) Choisissez toutes les conditions à contourner.</p>
<p>Exemples :<br>
"Complet" => La personne sélectionnée est autorisée à réserver même si l\'événement est déjà complet.<br>
"Réservable uniquement pendant une certaine période" => La personne sélectionnée est autorisée à réserver en dehors des heures de réservation normales.</p>';

$string['userinfofieldoff'] = 'Aucun champ de profil utilisateur sélectionné';
$string['bo_cond_userprofilefield_1_default_restrict'] = 'Un champ de profil utilisateur choisi doit avoir une certaine valeur';
$string['bo_cond_previouslybooked_restrict'] = 'L\'utilisateur a déjà réservé une certaine option';
$string['bo_cond_userprofilefield_field'] = 'Champ de profil';
$string['bo_cond_userprofilefield_value'] = 'Valeur';
$string['bo_cond_userprofilefield_operator'] = 'Opérateur';

$string['bo_cond_userprofilefield_2_custom_restrict'] = 'Un champ de profil utilisateur personnalisé doit avoir une certaine valeur';
$string['bo_cond_customuserprofilefield_field'] = 'Champ de profil';
$string['bo_cond_customuserprofilefield_value'] = 'Valeur';
$string['bo_cond_customuserprofilefield_operator'] = 'Opérateur';

$string['equals'] = 'a exactement cette valeur (texte ou nombre)';
$string['contains'] = 'contient (texte)';
$string['lowerthan'] = 'est inférieur à (nombre)';
$string['biggerthan'] = 'est supérieur à (nombre)';
$string['equalsnot'] = 'n\'a pas exactement cette valeur (texte ou nombre)';
$string['containsnot'] = 'ne contient pas (texte)';
$string['inarray'] = 'l\'utilisateur a une de ces valeurs séparées par des virgules';
$string['notinarray'] = 'l\'utilisateur n\'a aucune de ces valeurs séparées par des virgules';
$string['isempty'] = 'le champ est vide';
$string['isnotempty'] = 'le champ n\'est pas vide';

$string['overrideconditioncheckbox'] = 'A une relation avec une autre condition';
$string['overridecondition'] = 'Condition';
$string['overrideoperator'] = 'Opérateur';
$string['overrideoperator:and'] = 'ET';
$string['overrideoperator:or'] = 'OU';
$string['bo_cond_previouslybooked_optionid'] = 'Doit déjà être réservé';
$string['allcoursesmustbefound'] = 'L\'utilisateur doit être inscrit à tous les cours';
$string['onecoursemustbefound'] = 'L\'utilisateur doit être inscrit à au moins un de ces cours';

$string['noelement'] = 'Aucun élément';
$string['checkbox'] = 'Case à cocher';
$string['displaytext'] = 'Afficher le texte';
$string['textarea'] = 'Zone de texte';
$string['shorttext'] = 'Texte court';
$string['formtype'] = 'Type de formulaire';
$string['bo_cond_customform_label'] = 'Étiquette';

// Teacher_performed_units_report.php.
$string['error:wrongteacherid'] = 'Erreur : Aucun utilisateur n\'a pu être trouvé pour l\'"ID de l\'enseignant" fourni.';
$string['duration:minutes'] = 'Durée (minutes)';
$string['duration:units'] = 'Unités ({$a} min)';
$string['teachingreportfortrainer:subtitle'] = '<strong>Conseil :</strong> Vous pouvez modifier la durée d\'une
unité éducative dans les paramètres du plugin (par exemple, 45 au lieu de 60 minutes).<br/>
<a href="' . $CFG->wwwroot . '/admin/settings.php?section=modsettingbooking" target="_blank">
&gt;&gt; Aller aux paramètres du plugin...
</a>';
$string['error:missingteacherid'] = 'Erreur : Le rapport ne peut pas être chargé en raison de l\'absence d\'ID d\'enseignant.';

// Teacher_performed_units_report_form.php.
$string['filterstartdate'] = 'De';
$string['filterenddate'] = 'Jusqu\'à';
$string['filterbtn'] = 'Filtrer';

// Booking campaigns.
$string['bookingcampaignswithbadge'] = 'Réservation : Campagnes ' . $string['badge:pro'];
$string['bookingcampaigns'] = 'Réservation : Campagnes (PRO)';
$string['bookingcampaign'] = 'Campagne';
$string['bookingcampaignssubtitle'] = 'Les campagnes vous permettent de réduire les prix des options de réservation sélectionnées
pour une période spécifiée et d\'augmenter la limite de réservation pour cette période. Pour que les campagnes fonctionnent, le
travail cron de Moodle doit être exécuté régulièrement.';
$string['campaigntype'] = 'Type de campagne';
$string['editcampaign'] = 'Modifier la campagne';
$string['addbookingcampaign'] = 'Ajouter une campagne';
$string['deletebookingcampaign'] = 'Supprimer la campagne';
$string['deletebookingcampaign_confirmtext'] = 'Voulez-vous vraiment supprimer la campagne suivante ?';
$string['campaign_name'] = 'Nom personnalisé pour la campagne';
$string['campaign_customfield'] = 'Modifier le prix ou la limite de réservation';
$string['campaign_customfield_descriptiontext'] = 'Affecte : Champ personnalisé de l\'option de réservation "{$a->fieldname}"
ayant la valeur "{$a->fieldvalue}".';
$string['campaignfieldname'] = 'Champ';
$string['campaignfieldvalue'] = 'Valeur';
$string['campaignstart'] = 'Début de la campagne';
$string['campaignend'] = 'Fin de la campagne';

$string['campaign_blockbooking'] = 'Bloquer certaines options de réservation';
$string['campaign_blockbooking_descriptiontext'] = 'Affecte : Champ personnalisé de l\'option de réservation "{$a->fieldname}"
ayant la valeur "{$a->fieldvalue}".';

$string['blockoperator'] = 'Opérateur';
$string['blockoperator_help'] = '<b>Bloquer au-dessus</b> ... La réservation en ligne sera bloquée une fois que le pourcentage donné
de réservations sera atteint. La réservation ne sera possible que pour un caissier ou un administrateur par la suite.<br>
<b>Bloquer en dessous</b> ... La réservation en ligne sera bloquée jusqu\'à ce que le pourcentage donné
de réservations soit atteint. Avant que cela n\'arrive, la réservation n\'est possible que pour le caissier ou l\'administrateur.';
$string['blockabove'] = 'Bloquer au-dessus';
$string['blockbelow'] = 'Bloquer en dessous';
$string['percentageavailableplaces'] = 'Pourcentage de places disponibles';
$string['percentageavailableplaces_help'] = 'Vous devez entrer un pourcentage valide entre 0 et 100 (sans le signe %).';
$string['hascapability'] = 'Sauf a la capacité';
$string['blockinglabel'] = 'Message lors du blocage';
$string['blockinglabel_help'] = 'Entrez le message qui doit être affiché lorsque la réservation est bloquée.
Si vous souhaitez localiser ce message, vous pouvez utiliser
<a href="https://docs.moodle.org/403/en/Multi-language_content_filter" target="_blank">les filtres de langue</a>.';

// Boutons d\'aide des campagnes de réservation.
$string['campaign_name_help'] = 'Spécifiez un nom pour la campagne - par exemple, "Campagne de Noël 2023" ou "Réduction de Pâques 2023".';
$string['campaignfieldname_help'] = 'Sélectionnez le champ personnalisé de l\'option de réservation dont la valeur doit être comparée.';
$string['campaignfieldvalue_help'] = 'Sélectionnez la valeur du champ. La campagne s\'applique à toutes les options de réservation ayant cette valeur dans le champ sélectionné.';
$string['campaignstart_help'] = 'Quand commence la campagne ?';
$string['campaignend_help'] = 'Quand se termine la campagne ?';
$string['pricefactor_help'] = 'Spécifiez une valeur par laquelle multiplier le prix. Par exemple, pour réduire les prix de 20%, entrez la valeur <b>0.8</b>.';
$string['limitfactor_help'] = 'Spécifiez une valeur par laquelle multiplier la limite de réservation. Par exemple, pour augmenter la limite de réservation de 20%, entrez la valeur <b>1.2</b>.';

// Erreurs des campagnes de réservation.
$string['error:pricefactornotbetween0and1'] = 'Vous devez entrer une valeur comprise entre 0 et 1, par exemple 0.9 pour réduire les prix de 10%.';
$string['error:limitfactornotbetween1and2'] = 'Vous devez entrer une valeur comprise entre 1 et 2, par exemple 1.2 pour ajouter 20% de places réservables en plus.';
$string['error:missingblockinglabel'] = 'Veuillez entrer le message à afficher lorsque la réservation est bloquée.';
$string['error:percentageavailableplaces'] = 'Vous devez entrer un pourcentage valide entre 0 et 100 (sans le signe %).';
$string['error:campaignstart'] = 'Le début de la campagne doit être avant la fin de la campagne.';
$string['error:campaignend'] = 'La fin de la campagne doit être après le début de la campagne.';

// Règles de réservation.
$string['bookingruleswithbadge'] = 'Réservation : Règles ' . $string['badge:pro'];
$string['bookingrules'] = 'Réservation : Règles (PRO)';
$string['bookingrule'] = 'Règle';
$string['addbookingrule'] = 'Ajouter une règle';
$string['deletebookingrule'] = 'Supprimer la règle';
$string['deletebookingrule_confirmtext'] = 'Voulez-vous vraiment supprimer la règle suivante ?';

$string['rule_event'] = 'Événement';
$string['rule_mailtemplate'] = 'Modèle de courriel';
$string['rule_datefield'] = 'Champ de date';
$string['rule_customprofilefield'] = 'Champ personnalisé du profil utilisateur';
$string['rule_operator'] = 'Opérateur';
$string['rule_value'] = 'Valeur';
$string['rule_days'] = 'Nombre de jours avant';

$string['rule_optionfield'] = 'Champ de l\'option à comparer';
$string['rule_optionfield_coursestarttime'] = 'Début (heure de début du cours)';
$string['rule_optionfield_courseendtime'] = 'Fin (heure de fin du cours)';
$string['rule_optionfield_bookingopeningtime'] = 'Début de la période de réservation autorisée (heure d\'ouverture des réservations)';
$string['rule_optionfield_bookingclosingtime'] = 'Fin de la période de réservation autorisée (heure de fermeture des réservations)';
$string['rule_optionfield_text'] = 'Nom de l\'option de réservation (texte)';
$string['rule_optionfield_location'] = 'Lieu (location)';
$string['rule_optionfield_address'] = 'Adresse (address)';

$string['rule_sendmail_cpf'] = '[Aperçu] Envoyer un courriel à l\'utilisateur avec le champ personnalisé du profil';
$string['rule_sendmail_cpf_desc'] = 'Choisissez un événement qui doit déclencher la règle "Envoyer un courriel". Entrez un modèle de courriel
 (vous pouvez utiliser des espaces réservés comme {bookingdetails}) et définissez à quels utilisateurs le courriel doit être envoyé.
  Exemple : Tous les utilisateurs ayant la valeur "Centre de Vienne" dans un champ de profil utilisateur personnalisé appelé "Centre d\'études".';

$string['rule_daysbefore'] = 'Déclencher n jours avant une certaine date';
$string['rule_daysbefore_desc'] = 'Choisissez un champ de date des options de réservation et le nombre de jours AVANT cette date.';
$string['rule_react_on_event'] = 'Réagir à l\'événement';
$string['rule_react_on_event_desc'] = 'Choisissez un événement qui doit déclencher la règle.<br>
<b>Conseil :</b> Vous pouvez utiliser l\'espace réservé <code>{eventdescription}</code> pour afficher une description de l\'événement.';

$string['error:nofieldchosen'] = 'Vous devez choisir un champ.';
$string['error:mustnotbeempty'] = 'Ne doit pas être vide.';

// Conditions des règles de réservation.
$string['rule_name'] = 'Nom personnalisé pour la règle';
$string['bookingrulecondition'] = 'Condition de la règle';
$string['bookingruleaction'] = 'Action de la règle';
$string['enter_userprofilefield'] = 'Sélectionnez les utilisateurs en entrant une valeur pour le champ de profil utilisateur personnalisé.';
$string['condition_textfield'] = 'Valeur';
$string['match_userprofilefield'] = 'Sélectionnez les utilisateurs en faisant correspondre le champ de l\'option de réservation et le champ de profil utilisateur.';
$string['select_users'] = 'Sélectionnez directement les utilisateurs sans lien avec l\'option de réservation';
$string['select_student_in_bo'] = 'Sélectionnez les utilisateurs d\'une option de réservation';
$string['select_teacher_in_bo'] = 'Sélectionnez les enseignants d\'une option de réservation';
$string['select_user_from_event'] = 'Sélectionnez un utilisateur à partir de l\'événement';
$string['send_mail'] = 'Envoyer un courriel';
$string['bookingcondition'] = 'Condition';
$string['condition_select_teacher_in_bo_desc'] = 'Sélectionnez les enseignants de l\'option de réservation (affectée par la règle).';
$string['condition_select_student_in_bo_desc'] = 'Sélectionnez tous les étudiants de l\'option de réservation (affectée par la règle) ayant un certain rôle.';
$string['condition_select_student_in_bo_roles'] = 'Choisir le rôle';
$string['condition_select_users_userids'] = 'Sélectionnez les utilisateurs que vous souhaitez cibler';
$string['condition_select_user_from_event_desc'] = 'Choisissez un utilisateur qui est lié d\'une manière ou d\'une autre à l\'événement';
$string['studentbooked'] = 'Utilisateurs qui ont réservé';
$string['studentwaitinglist'] = 'Utilisateurs sur la liste d\'attente';
$string['studentnotificationlist'] = 'Utilisateurs sur la liste de notification';
$string['studentdeleted'] = 'Utilisateurs déjà supprimés';
$string['useraffectedbyevent'] = 'Utilisateur affecté par l\'événement';
$string['userwhotriggeredevent'] = 'Utilisateur ayant déclenché l\'événement';
$string['condition_select_user_from_event_type'] = 'Choisir le rôle';

// Actions des règles de réservation.
$string['bookingaction'] = 'Action';
$string['sendcopyofmailsubjectprefix'] = 'Préfixe de l\'objet pour la copie';
$string['sendcopyofmailmessageprefix'] = 'Préfixe du message pour la copie';
$string['send_copy_of_mail'] = 'Envoyer une copie du courriel';

// Annuler l\'option de réservation.
$string['canceloption'] = 'Annuler l\'option de réservation';
$string['canceloption_desc'] = 'Annuler une option de réservation signifie qu\'elle n\'est plus réservable, mais qu\'elle est toujours affichée dans la liste.';
$string['confirmcanceloption'] = 'Confirmer l\'annulation de l\'option de réservation';
$string['confirmcanceloptiontitle'] = 'Changer le statut de l\'option de réservation';
$string['cancelthisbookingoption'] = 'Annuler cette option de réservation';
$string['usergavereason'] = '{$a} a donné la raison suivante pour l\'annulation :';
$string['undocancelthisbookingoption'] = 'Annuler l\'annulation de cette option de réservation';
$string['cancelreason'] = 'Raison de l\'annulation de cette option de réservation';
$string['undocancelreason'] = 'Voulez-vous vraiment annuler l\'annulation de cette option de réservation ?';
$string['nocancelreason'] = 'Vous devez donner une raison pour annuler cette option de réservation';

// Access.php.
$string['booking:bookforothers'] = 'Réserver pour les autres';
$string['booking:canoverbook'] = 'A la permission de surbooker';
$string['booking:canreviewsubstitutions'] = 'Autorisé à examiner les remplacements des enseignants (case à cocher de contrôle)';
$string['booking:conditionforms'] = 'Soumettre des formulaires de condition comme la politique de réservation ou les sous-réservations';
$string['booking:view'] = 'Voir les instances de réservation';
$string['booking:viewreports'] = 'Permettre l\'accès à la visualisation des rapports';
$string['booking:manageoptiondates'] = 'Gérer les dates des options';
$string['booking:limitededitownoption'] = 'Moins que ajoutmodifpropreoption, ne permet que des actions très limitées';

// Booking_handler.php.
$string['error:newcoursecategorycfieldmissing'] = 'Vous devez créer un <a href="{$a->bookingcustomfieldsurl}"
 target="_blank">champ personnalisé de réservation</a> pour les nouvelles catégories de cours d\'abord. Après en avoir créé un, assurez-vous
 qu\'il est sélectionné dans les <a href="{$a->settingsurl}" target="_blank">paramètres du plugin de réservation</a>.';
$string['error:coursecategoryvaluemissing'] = 'Vous devez choisir une valeur ici car elle est nécessaire en tant que catégorie de cours
 pour le cours Moodle créé automatiquement.';

 // Subbookings.
$string['bookingsubbookingsheader'] = 'Sous-réservations';
$string['bookingsubbooking'] = 'Sous-réservation';
$string['subbooking_name'] = 'Nom de la sous-réservation';
$string['bookingsubbookingadd'] = 'Ajouter une sous-réservation';
$string['bookingsubbookingedit'] = 'Modifier';
$string['editsubbooking'] = 'Modifier la sous-réservation';
$string['bookingsubbookingdelete'] = 'Supprimer la sous-réservation';

$string['onlyaddsubbookingsonsavedoption'] = 'Vous devez enregistrer cette option de réservation avant de pouvoir ajouter des sous-réservations.';
$string['onlyaddentitiesonsavedsubbooking'] = 'Vous devez enregistrer cette sous-réservation avant de pouvoir ajouter une entité.';

$string['subbooking_timeslot'] = 'Réservation de créneaux horaires';
$string['subbooking_timeslot_desc'] = 'Cela ouvre des créneaux horaires pour chaque date de réservation avec une durée définie.';
$string['subbooking_duration'] = 'Durée en minutes';

$string['subbooking_additionalitem'] = 'Réservation d\'élément supplémentaire';
$string['subbooking_additionalitem_desc'] = 'Cela vous permet d\'ajouter des éléments réservable optionnels à cette option de réservation,
 par exemple, vous pouvez réserver un siège spécial de meilleure qualité, etc., ou le petit déjeuner dans votre chambre d\'hôtel.';
$string['subbooking_additionalitem_description'] = 'Décrivez l\'élément réservable supplémentaire :';

$string['subbooking_additionalperson'] = 'Réservation de personne supplémentaire';
$string['subbooking_additionalperson_desc'] = 'Cela vous permet d\'ajouter d\'autres personnes à cette option de réservation,
 par exemple, pour réserver des places pour les membres de votre famille.';
$string['subbooking_additionalperson_description'] = 'Décrivez l\'option de réservation de personne supplémentaire';

$string['subbooking_addpersons'] = 'Ajouter des personnes supplémentaires';
$string['subbooking_bookedpersons'] = 'Les personnes suivantes sont ajoutées :';
$string['personnr'] = 'Personne n° {$a}';

// Shortcodes.
$string['recommendedin'] = 'Shortcode pour afficher une liste d\'options de réservation qui devraient être recommandées dans un cours donné.
 Pour utiliser cela, ajoutez un champ personnalisé de réservation avec le nom court "recommendedin" et des valeurs séparées par des virgules avec les noms courts
 des cours pour lesquels vous souhaitez montrer ces recommandations. Ainsi : Lorsque vous souhaitez recommander l\'option1 aux participants inscrits
 au cours 1 (course1), vous devez définir le champ personnalisé "recommendedin" depuis l\'option de réservation sur "course1".';
$string['fieldofstudyoptions'] = 'Shortcode pour afficher toutes les options de réservation d\'un domaine d\'étude.
 Elles sont définies par une synchronisation de cohorte commune & la condition de disponibilité de réservation de
 devoir être inscrit à un de ces cours.';
$string['fieldofstudycohortoptions'] = 'Shortcode pour afficher toutes les options de réservation d\'un domaine d\'étude.
 Elles sont définies par un groupe de cours avec le même nom. Les options de réservation sont définies en ayant des noms courts séparés par des virgules
 d\'au moins un de ces cours dans le champ personnalisé des options de réservation "recommendedin".';
$string['nofieldofstudyfound'] = 'Aucun domaine d\'étude n\'a pu être déterminé via les cohortes';
$string['shortcodenotsupportedonyourdb'] = 'Ce shortcode n\'est pas supporté sur votre base de données. Il fonctionne uniquement sur postgres & mariadb';
$string['definefieldofstudy'] = 'Vous pouvez afficher ici toutes les options de réservation du domaine d\'étude entier. Pour que cela fonctionne,
 utilisez des groupes avec le nom de votre domaine d\'étude. Dans un cours utilisé dans "Psychologie" et "Philosophie",
 vous aurez deux groupes, nommés comme ces domaines d\'étude. Suivez ce schéma pour tous vos cours.
 Ajoutez maintenant le champ personnalisé de réservation avec le nom court "recommendedin", où vous ajoutez les noms courts
 séparés par des virgules de ces cours, dans lesquels une option de réservation doit être recommandée. Si un utilisateur est inscrit
 en "philosophie", il verra toutes les options de réservation dans lesquelles au moins un des cours "philosophie" est recommandé.';

// Électif.
$string['elective'] = 'Électif';
$string['selected'] = 'Sélectionné';
$string['bookelectivesbtn'] = 'Réserver les électifs sélectionnés';
$string['electivesbookedsuccess'] = 'Vos électifs sélectionnés ont été réservés avec succès.';
$string['errormultibooking'] = 'Une ERREUR est survenue lors de la réservation des électifs.';
$string['selectelective'] = 'Sélectionnez l\'électif pour {$a} crédits';
$string['electivedeselectbtn'] = 'Désélectionner l\'électif';
$string['confirmbookingtitle'] = 'Confirmer la réservation';
$string['sortbookingoptions'] = 'Veuillez trier vos réservations dans le bon ordre. Vous ne pourrez accéder aux cours associés que les uns après les autres. Le premier est en haut.';
$string['selectoptionsfirst'] = 'Veuillez d\'abord sélectionner des options de réservation.';
$string['electivesettings'] = 'Paramètres des électifs';
$string['iselective'] = 'Utiliser l\'instance comme électif';
$string['iselective_help'] = 'Cela vous permet de forcer les utilisateurs à réserver plusieurs options de réservation à la fois dans un ordre spécifique
 ou en relation spécifique les unes avec les autres. De plus, vous pouvez forcer l\'utilisation des crédits.';
$string['maxcredits'] = 'Crédits maximum à utiliser';
$string['maxcredits_help'] = 'Vous pouvez définir le montant maximum de crédits que les utilisateurs peuvent ou doivent utiliser lors de la réservation des options. Vous pouvez définir dans chaque option de réservation combien de crédits elle vaut.';
$string['unlimitedcredits'] = 'Ne pas utiliser de crédits';
$string['enforceorder'] = 'Imposer l\'ordre de réservation';
$string['enforceorder_help'] = 'Les utilisateurs seront inscrits uniquement une fois qu\'ils auront complété l\'option de réservation précédente';
$string['consumeatonce'] = 'Tous les crédits doivent être consommés en une seule fois';
$string['consumeatonce_help'] = 'Les utilisateurs ne peuvent réserver qu\'une seule fois et doivent réserver toutes les options en une étape.';
$string['credits'] = 'Crédits';
$string['bookwithcredits'] = '{$a} crédits';
$string['bookwithcredit'] = '{$a} crédit';
$string['notenoughcreditstobook'] = 'Pas assez de crédits pour réserver';
$string['electivenotbookable'] = 'Non réservable';
$string['credits_help'] = 'Le nombre de crédits qui sera utilisé en réservant cette option.';
$string['mustcombine'] = 'Options de réservation nécessaires';
$string['mustcombine_help'] = 'Options de réservation qui doivent être combinées avec cette option';
$string['mustnotcombine'] = 'Options de réservation exclues';
$string['mustnotcombine_help'] = 'Options de réservation qui ne peuvent pas être combinées avec cette option';
$string['nooptionselected'] = 'Aucune option de réservation sélectionnée';
$string['creditsmessage'] = 'Il vous reste {$a->creditsleft} sur {$a->maxcredits} crédits.';
$string['notemplateyet'] = 'Pas encore de modèle';
$string['electiveforcesortorder'] = 'L\'enseignant peut imposer l\'ordre de tri';
$string['enforceteacherorder'] = 'Imposer l\'ordre des enseignants';
$string['enforceteacherorder_help'] = 'Les utilisateurs ne pourront pas définir l\'ordre des options sélectionnées, mais elles seront déterminées par l\'enseignant';
$string['notbookablecombiantion'] = 'Cette combinaison d\'électifs n\'est pas autorisée';

// Actions de réservation.
$string['bookingactionsheader'] = 'Actions après réservation [EXPÉRIMENTAL]';
$string['selectboactiontype'] = 'Sélectionner l\'action après réservation';
$string['bookingactionadd'] = 'Ajouter une action';
$string['boactions_desc'] = 'Les actions après réservation sont encore une fonctionnalité EXPÉRIMENTALE. Vous pouvez les essayer si vous le souhaitez.
Mais ne les utilisez pas encore dans un environnement de production !';
$string['boactions'] = 'Actions après réservation ' . $string['badge:pro'] . ' ' . $string['badge:experimental'];
$string['onlyaddactionsonsavedoption'] = 'Les actions après réservation ne peuvent être ajoutées qu\'une fois l\'option de réservation enregistrée.';
$string['boactionname'] = 'Nom de l\'action';
$string['showboactions'] = 'Activer les actions après réservation';
$string['boactionselectuserprofilefield'] = 'Choisir le champ de profil';
$string['boactioncancelbookingvalue'] = 'Activer l\'annulation immédiate';
$string['boactioncancelbooking_desc'] = 'Utilisé lorsqu\'une option peut être achetée plusieurs fois.';
$string['boactionuserprofilefieldvalue'] = 'Valeur';
$string['actionoperator:set'] = 'Remplacer';
$string['actionoperator:subtract'] = 'Soustraire';
$string['actionoperator'] = 'Action';
$string['actionoperator:adddate'] = 'Ajouter une date';

// Classe Dates.
$string['adddatebutton'] = 'Ajouter une date';
$string['nodatesstring'] = 'Il n\'y a actuellement aucune date associée à cette option de réservation';
$string['nodatesstring_desc'] = 'aucune date';

// Accès.
$string['mod/booking:expertoptionform'] = 'Option de réservation pour les experts';
$string['mod/booking:reducedoptionform1'] = 'Option de réservation réduite 1';
$string['mod/booking:reducedoptionform2'] = 'Option de réservation réduite 2';
$string['mod/booking:reducedoptionform3'] = 'Option de réservation réduite 3';
$string['mod/booking:reducedoptionform4'] = 'Option de réservation réduite 4';
$string['mod/booking:reducedoptionform5'] = 'Option de réservation réduite 5';

// Chaînes Vue.
$string['vue_dashboard_name'] = 'Nom';
$string['vue_dashboard_course_count'] = 'Nombre de cours';
$string['vue_dashboard_path'] = 'Chemin';
$string['vue_dashboard_create_oe'] = 'Créer un nouvel OE';
$string['vue_dashboard_assign_role'] = 'Attribuer des rôles';
$string['vue_dashboard_new_course'] = 'Créer un nouveau cours';
$string['vue_not_found_route_not_found'] = 'Itinéraire non trouvé';
$string['vue_not_found_try_again'] = 'Veuillez réessayer plus tard';
$string['vue_booking_stats_capability'] = 'Capacité';
$string['vue_booking_stats_back'] = 'Retour';
$string['vue_booking_stats_save'] = 'Enregistrer';
$string['vue_booking_stats_restore'] = 'Restaurer';
$string['vue_booking_stats_select_all'] = 'Tout sélectionner';
$string['vue_booking_stats_booking_options'] = 'Options de réservation';
$string['vue_booking_stats_booked'] = 'Réservé';
$string['vue_booking_stats_waiting'] = 'Liste d\'attente';
$string['vue_booking_stats_reserved'] = 'Réservé';
$string['vue_capability_options_cap_config'] = 'Configuration de la capacité';
$string['vue_capability_options_necessary'] = 'nécessaire';
$string['vue_capability_unsaved_changes'] = 'Il y a des changements non enregistrés';
$string['vue_capability_unsaved_continue'] = 'Voulez-vous vraiment réinitialiser cette configuration ?';
$string['vue_booking_stats_restore_confirmation'] = 'Voulez-vous vraiment réinitialiser cette configuration ?';
$string['vue_booking_stats_yes'] = 'Oui';
$string['vue_booking_stats_no'] = 'Non';
$string['vue_confirm_modal'] = 'Êtes-vous sûr de vouloir revenir en arrière ?';
$string['vue_heading_modal'] = 'Confirmation';
$string['vue_notification_title_unsave'] = 'Aucun changement non enregistré détecté';
$string['vue_notification_text_unsave'] = 'Aucun changement non enregistré détecté.';
$string['vue_notification_title_action_success'] = 'La configuration a été {$a}';
$string['vue_notification_text_action_success'] = 'La configuration a été {$a} avec succès.';
$string['vue_notification_title_action_fail'] = 'La configuration n\'a pas été {$a}';
$string['vue_notification_text_action_fail'] = 'Une erreur s\'est produite lors de l\'enregistrement. Les modifications n\'ont pas été apportées.';
