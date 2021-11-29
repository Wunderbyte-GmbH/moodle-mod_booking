## Version 7.0.22 (2021112900)
**Bugfixes:**
* Fixed a broken SQL statement which caused an error in the Quickfinder Block.

## Version 7.0.21 (2021112600)
**Bugfixes:**
* Fixed broken phpunit tests.
* Use correct version number for Moodle 3.11 compatibility.
* Fix bug where custom fields where not shown in modal.
* Remove obsolete $plugin->cron.
* Fix datestring to interpret HTML in coursepage_available_options template.

## Version 7.0.20 (2021111602)
**Improvements:**
* Added better feedback for CSV importer.

## Version 7.0.19 (2021110200)
**Bugfixes:**
* Fixed a bug where wrong poll url messages where sent (to both participants and teachers).
* Fixed a function in observer.php which didn't make sense.
* Fixed wrong inclusion of config.php in several files.
* Fixed deprecation of user_picture::fields in Moodle 3.11 and kept compatibility for 3.10, 3.9 and earlier.
* Fixed a bug where poll URL message was not sent to teachers.

## Version 7.0.18 (2021102500)
**Bugfixes:**
* Displaying booking option name now without separator on course page.
* Description for booked users was rendered like for unbooked in calendar.
* Fixed a bug where new bookingmanager list led to error on instantiation.
* Fixed deprecation of user_pictures in Moodle 3.11 and kept compatibility for 3.10, 3.9 and earlier.

## Version 7.0.17 (2021101900)
**Improvements:**
* Added "Department" to "Fields to display in different contexts" for report download.
* Minor code quality improvements.

## Version 7.0.16 (2021101800)
**Improvements:**
* Generic booking_options_simple_table (currently used by Bookings Quickfinder block).

## Version 7.0.15 (2021101500)
**Bugfixes:**
* Fixed deprecated implode => switch params.

**Improvements:**
* Removed "institution" from bookingoptions_simple_table (for compatibility with Bookings Quickfinder block).

## Version 7.0.14 (2021101300)
**Bugfixes:**
* Webservice only targets booking instances which are not in deletion progress.
* Minor code fixes.
* If sort by is set to coursestarttime but coursestarttime column is missing, we still order by coursestarttime.

## Version 7.0.13 (2021100400)
**Bugfixes:**
* Fix bug where calendar event was not created when course was set.

**Improvements:**
* Code quality: More logical deletion sequence.

## Version 7.0.12 (2021092900)
**Improvements:**
* Improved calendar event descriptions.
* Send status change notifications when limits (max. answers, places on waiting list) change.
* Turn off change notifications by setting the template to "0".
* Allow setting of bookingclosingtime via webservice

**Bugfixes:**
* Fixed a bug where a deleted user got 2 mails.

## Version 7.0.11 (2021092800)
**Improvements:**
* Improved availability info texts when events lie in the past.
* Bookings Quickfinder Block: number of participants, waiting list and manage responses in bookingoptions_simple_table.

**Bugfixes:**
* Always send emails from booking manager if a valid booking manager (needs to be an admin user) was defined.
  (Please keep in mind that you still need to set an outgoing noreply-address, add the domain of the booking
   manager's email to the allowed domains in outgoing email settings and set the booking manager's email address
   visible to all users in the user profile.)

## Version 7.0.10 (2021092700)
**Improvements:**
* Webservice: Add possibility to distinguish between courseid & targetcourseid
* Use uniqe booking option name with key for group creation

**Bugfixes:**
* Fix some bugs & potential bugs
* Fixed unwanted group creation

## Version 7.0.9 (2021092200)
**Improvements:**
* Only show "already booked" or "on waiting list" text in modal but not inline.

**Bugfixes:**
* Added missing fields in backup (duplication) of booking instances
* Fixed context and deletion methods in provider.php (Privacy API)

**Other:**
* Added RELEASENOTES, CHANGES and updated README

## Version 7.0.8 (2021092100)
**New features:**
* Sending of mails can be disabled by leaving the message template empty (Known issue: Currently only
  working with mails using the task send_confirmation_mails).

**Improvements:**
* Added metadata to classes/privacy/provider.php

**Bugfixes:**
* Removed "All places are booked" - as we already have new explanation string functionality (PRO) for available
  places and waiting list.
* Only show points in business_card and instance_description if there are any.

## Version 7.0.7 (2021092000)
**Improvements:**
* Added ids to rows in booking options search, so they can be hidden via CSS if needed.
* Booking instance description and business card enhancements.

**Bugfixes:**
* Fixed a bug with unique option names (Offset issue: only do "explode" if separator is part of the option name.)

**Other:**
* Introduced new table bookingoptions_simple_table which will be used by the new Booking Quickfinder block.
* Introduced CHANGES.md

## Version 7.0.6 (2021091400)
**Bugfixes:**
* Fixed a bug where courseid was always set to 0 when adding new booking options.

## Version 7.0.5 (2021091000)
**New features:**
* New cohort and group subscription (within "Book other users") for booking options.
* Unique option names
  When using CSV import for booking options, option names need to be unique. If there are multiple options with the
  same name, a unique key will be added internally to the option name. In the plugin settings, you can now define the
  separator (default: #?#) between the option name and the key.

**New PRO features:**
* Availability info texts for booking places and waiting list
  Instead of showing the numbers of available booking places and waiting list places, you can now go to the plugin
  config and activate availability info texts (you can activate them separately for available places and waiting list
  places). You can also define a percentage for the places low message. If the available booking places reach or get
  below this percentage a booking places low message will be shown. (You need to activate this feature with a PRO
  license.)

**Bugfixes:**
* Hide list of custom fields in booking option description when there are none.

## Version 7.0.3 (2021090800)
**Improvements:**
* New redirect script which fixes links that didn't work before (e.g. links in MS Outlook event texts
  after importing via {usercalendarurl}).
* Add teachers to booking option description.

**Bugfixes:**
* Fixed a bug where $booking object was null.
* Fixed a bug where description was not shown whithout organizatorname.

## Version 7.0.1 (2021090600)
**Bugfixes:**
* Fixed a bug with the placeholders in the completion mails template.
* Completion mails will only be sent if setting for sending confirmation mails is active.
* Only update start end date (of booking options) depending on sessions IF there actually ARE sessions.

## Version 7.0 (2021090100)
**New features:**
* License key checker in plugin config to activate PRO version.
* New dropdown for calendar event types.
* Up to 3 individual custom fields for multiple date sessions with autocomplete functionality.
  (Including special functionality for "TeamsMeeting", "ZoomMeeting" and "BigBlueButtonMeeting").
* Show detailed description of booking option either via modal (little info button) or inline within the
  options table (can be configured in instance settings).
* Show a "business card" of the teacher who is defined via autocomplete "Organizer name" (instance setting).
* Send change notification mails (including new mail template and new placeholder {changes} which will
  create a summary of all changes made to the booking option. The summary includes explanation texts and
  "\[DELETED\]" and "\[NEW\]" strings for text-only mails.
* Links to video meetings will only redirect to the link of the video meeting 15 minutes before until
  the end of the session.
* Session reminder e-mails (Including new mail template and functionality to set the number of days before the
  session when the e-mail should be sent.)
* Show course name, short info and a button redirecting to the available booking options on course page.
  (Can be turned on in instance settings. Short info text is customizable.)
* New placeholders {usercalendarurl} and {coursecalendarurl} (can be used in e-mail templates) to enable
  subscription to Moodle calendar via Outlook or similar calendar tool. Subscription links are made not clickable
  (styled via CSS), because they should be copied and pasted.
* New placeholder {bookingdetails} for detailed booking description including session and custom field data.
* New placeholder {gotobookingoption} linking only to the booking option.
* Booking option completion e-mails
  When you change the completion status of a user on the "Manage responses" page to "completed", an automatically
  generated e-mail will be sent to the user(s) letting them know that they have completed the booking option.
  You can edit the template for this in booking instance settings.

**New PRO features:**
* Global mail templates - each booking instance can define its source of mail templates:
  (Option 1) From within the booking instance (default)
  (Option 2) Use global mail templates defined in plugin settings
  This feature allows you to define global mail templates within the plugin config and use them within every booking
  instance on the whole platform. (You need to activate this feature with a PRO license.)
* Teacher notification e-mails including a new mail template in booking instance settings, number of
  days before the event start to notify teachers and to new placeholders to include in the template:
  {numberparticipants}: The number of successfully booked participants for the option.
  {numberwaitinglist}: The number of people on the waiting list for the option. (You need to activate this feature
  with a PRO license.)
* Webservice importer - it is now possible to import a massive amount of booking options using a CSV file
  in combination with the new importer web service. (Web service will only work with a PRO license. Contact
  info@wunderbyte.at if you need support with that.)

**Improvements:**
* Added missing German localization strings.
* Improved calendar features - show events, booked events and multiple date sessions in Moodle calendar.
  Calendar events include detailed description (supporting multiple dates sessions) and a button linking to the
  booking option.
* Booking option is prefilled with "coursename - eventtype"-Scheme
* Added autocomplete dropdown for location, institution (in booking option settings)
  and event type, organizer name (in booking instance settings).
* It is now possible to add a list of available booking options to the course page (can be turned off
  in instance settings).
* Added classes to columns and buttons in order to enable individual CSS styling.
* Redirect to view.php instead of report.php after editing options or sessions.
* Added localized help buttons for organizer name, event type, institution and location.
* Add string when neither waitinglist nor booking is possible.
* New bookingoption_completed event gets triggered when completion status of a user changes.
* CSV importer now imports optiondates (multisession) & customfields for multisessions
* Show cancel button below booked button.
* Modal is showing the info if a user is already booked or on the waiting list for an option.
* When there are no multisessions defined, the {times} parameter for notification e-mails will use the
  single date defined within the booking option.
* Added new fields to backup.
* Show button redirecting to the booking option at upper right of the calendar modal.
* iCal attachments including detailed summary of the booking option and improved session iCals.
  Known issue: Updating events still does not work as expected with Microsoft Outlook. If you rely on
  Outlook, please use calendar subscription with the e-mail placeholders {usercalendarurl} (or {coursecalendarurl})
  instead and turn iCal attachments off in the plugin settings.
* Also duplicate associated teachers on booking option duplication.

**Bugfixes:**
* Do not add option templates twice.
* Fixed wrong calculation of available places.
* Show "Save as template" only for new booking options, not for existing ones.
* Calculate duration if not set while saving.
* Update calendar events of sessions when a booking option is edited.
* Fix bug when addtogroup is not set on saving new instance templates.
* Fix bug where booking name (->text) was required unique not only in instance, but everywhere.
* Fixed autofill of option templates (JavaScript-based).
* Fixed autofill of instance templates (JavaScript-based).
* Fixed duplicate creation of option templates.
* Fixed missing link on {bookinglink} placeholder.
* Fixed issues in backup and duplication.