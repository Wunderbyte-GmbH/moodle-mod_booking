## Version 8.4.2 (2024061400)
* Improvement: Bookingoption_confirmed event & rule for "Book only after confirmation" with price set.
* Improvement: Show "on waitinglist" in report php also when we just use the confirmation feature.
* Bugfix: Keep digits in tabnames.

## Version 8.4.1 (2024061200)
* Improvement: Add customform placeholder.
* Improvement: Trash icon in collapsable optiondate in bookingoption.
* Improvement: Enable enclosing placeholders ({#ph} {ph} {/ph}).
* Improvement: Failing rules trigger event not error (except in debug mode).
* Bugfix: Improve performance regarding attached files.
* Bugfix: Always use userlanguage for calendar entries.
* Bugfix: Smaller SQL fixes.

## Version 8.4.0 (2024060600)
* Improvement: Add rank number to waiting list.
* Improvement: New placeholder eventdescription.
* Improvement: Mobile customform extensions.
* Improvement: Individual campaigns, depending on user profile field.
* Improvement: Display messages sent for bookingoptions.
* Improvement: Add possibility to autmatically buy pro license.
* Bugfix: Subbookings moving through prepages.

## Version 8.3.9 (2024052900)
* Bugfix: Make sure deleted cohort does not break system.

## Version 8.3.8 (2024052800)
* Feature: New moodle course template handling
* Feature: New waitinglist handling & notifications (via booking rules)

## Version 8.3.6 (2024052400)
* Improvement: Upgrade fields pollurlteachers & pollurl up to 1000 chars.
* Improvement: Display sent messages in bo report.
* Improvement: Add coolingoffperiod to avoid unintentional double clicks.
* Bugfix: Avoid errors for restorement.
* Bugfix: Fieldofstudy Display.
* Bugfix: Fix prepage for subbooking.
* Bugfix: Fix filter display on view.php.

## Version 8.3.5 (2024052300)
* Bugfix: Look at cancel until date also for cancel purchase button

## Version 8.3.4 (2024052201)
* Bugfix: Update events on booking optiondate change (instead of insert).
* Bugfix: Don't impose dark text in bookingoption form.

## Version 8.3.3 (2024052200)
* Improvement: Add warning when exceeding max n of cohorts (set to 500).
* Improvement: Checkbox for booking instance.
* Bugfix: Check for berta settings.

## Version 8.3.1 (2024052101)
* Bugfix: Sorting of waitinglist in mysql dbfamily.

## Version 8.3.0 (2024052100)
* Improvement: Add placeholders for installments (local_shopping_cart)
* Improvement: Support moodle mobile app. Support for booking options and using custom forms.
* Improvement: Enable rule for timestamps (days) after dueday.
* Improvement: Enable sql filter for mariadb/mysql.
* Bugfix: For teachers responsible for a bookingoption, link to teacherpage instead of user profile.

## Version 8.2.9 (2024050800)
* Improvement: For teachers responsible for a bookingoption, link to teacherpage instead of user profile.
* Improvement: Allow confirmation when there is is no pre booking page.
* Bugfix: Users on notification list must not be enroled in moodle course.
* Bugfix: Check if already booked before booking again.
* Bugfix: Remove unnecessary inclusion of question/category from requirements.
* Bugfix: Create bookingcampaign via DB and view as teacher.

## Version 8.2.8 (2024042900)
* Bugfix: Cashiers can still overbook when waitinglist is full

## Version 8.2.7 (2024042600)
* Improvement: Clean up ical settings and remove deprecated handling of options with single-session.
* Improvement: Mark invisible optiondates in entity calendar (gray and with [invisible] prefix).
* Improvement: Make sure that only authorized users (with capability booking:canseeinvisibleoptions) are able to see invisible options in entity calendar.
* Improvement: Create method to destroy instance of user object (because of fails in phpunit tests).
* Improvement: Make sure entities are restored for optiondates when duplicating courses and kept when recreating new date series.
* Improvement: Always show title with prefix.
* Bugfix: Move context_system to context_module.
* Bugfix: Fix customform.
* Bugfix: Fix possible problem with missing titleprefix.
* Bugfix: Fix validation of course category when creating new Moodle course together with booking option.
* Bugfix: Fix credits when there is also a price.
* Bugfix: Fix deprecation warning.

## Version 8.2.6 (2024042200)
* Improvement: New php unit tests and behat tests and improvements for existing ones.
* Improvement: Add book anyone capability.
* Bugfix: Fix missing $bookingsettings in function after_successful_booking_routine of class booking_option.

## Version 8.2.5 (2024041700)
* New feature: Shortcode [courselist cmid=23]
* Bugfix: feature addtogroup working now
* Improvement: Add a lot of new phpunit tests

## Version 8.2.4 (2024041100)
* Improvement: Migrate placeholders in message controller to new placeholder classes.
* Improvement: Separate string params from placeholder param {changes}.
* Bugfix: Profile picture is destroyed by format_text so do not use it in placeholders_info but only directly where we need it.
* Bugfix: Try to fix strings for AMOS.
* Bugfix: Add missing !empty check for datafromevent property.

## Version 8.2.3 (2024041000)
* New feature: Show attachments right within booking options.
* New feature: New setting to also duplicate Moodle course when a booking option gets duplicated.
* New feature: Send copies of mails for custom messages or for bulk custom messages via booking rules.
* New feature: Context-based booking rules (e.g. rules for a specific Booking instance).
* Improvement: New event custom_message_sent.
* Improvement: New booking rule action send_copy_of_mail.
* Improvement: Allow search for ID without pattern matching. (Will also work with numeric last names in behat tests.)
* Improvement: Allow search for full course id and booking option id in autocompletes.
* Improvement: Purge bookingoptionstable cache when encodedtables are purged.
* Improvement: New send_copy_of_mail action will only be shown for compatible events.
* Improvement: Fix eventlog to better reflect booking of other users.
* Improvement: Allow waitinglist for paid options.
* Improvement: Get rid of outdated shorturl field.
* Improvement: Several improvements and fixes for the new placeholders.
* Improvement: Possibility to confirm users which are on waitinglist with price.
* Improvement: Show hint for new placeholder {eventdescription} and show optionid in event description.
* Bugfix: Some small bugs in editteachersforoptiondate form.
* Bugfix: Report.php (user bookings page) cannot use the same pagination value that is used for Booking view - fixes #439.
* Bugfix: Possible bugfix for strange nullpointer bug on name property of module.
* Bugfix: Avoid error if class does not exist in fields_info.php.
* Bugfix: Bugfixes for waitinglist with price.
* Bugfix: Nullpointer bug in notifymelist.
* Bugfix: Fix Exception - Warning: Undefined array key "freeonwaitinglist".
* Bugfix: Fix is_available of fullybooked.php.

## Version 8.2.2 (2024040200)
* New feature: New setting to allow responsible contacts to edit booking options.
* Improvement: Issue #407 blocks booking via moodle capability
* Improvement: Add cancel for bought items.
* Improvement: Allow Filter a users bookingoption for more than one booking status param
* Improvement: Better way to check for price.
* Improvement: Apply rules again after every time a user has booked (only for option & userid).
* Improvement: Make bookit button accessible via TAB - fixes #436.
* Bugfix: Cancel myself not shown on reserved items.
* Bugfix: Description is actually a necessary field
* Bugfix: Several fixes for missing cmid in formdata when creating context_module.
* Bugfix: Add missing empty check for capability string identifier.
* Bugfix: Fix some minor errors in subbookings - fixes #356.
* Bugfix: Debug Booking rules.
* Bugfix: Fix error "ERROR:  column u.userid does not exist" which affects booking rules #445.
* Bugfix: Fix CSV import for entities.
* Bugfix: In confirmcancel we need to check useprice from JSON. (Price item check is legacy.)
* Bugfix: Switch from counting priceitems to just check useprice.

## Version 8.2.1 (2024032700)
* Improvement: Show which form currently overrides the standard form definition on edit.
* Improvement: Usability improvements for option form config with and without PRO license.
* Improvement: Allow csv import with column username.
* Improvement: Add capability to edit option form config (mod/booking:editoptionformconfig).
* Improvement: Add more rights to manager role (manager should have the same rights as enrolled teacher).
* Bugfix: Add enrolmentstatus field again.
* Test: New behat tests for Booking rules.

## Version 8.2.0 (2024032500)
* New feature: Config Dashboard implementation.
* New feature: Add manually managed waitinglist.
* Improvement: New Webservice to save config forms - unfinished.
* Improvement: Adjust incompatibilities of option form fields.
* Improvement: Better treatment auf where array values.
* Improvement: Support for new configurable Wunderbyte Table filters.
* Improvement: Move to contextids instead of coursecategoryids.
* Improvement: Use capabilities to adjust option form.
* Improvement: Get rid of old expertform mode.
* Improvement: Issue #407 blocks booking via moodle capability.
* Improvement: Make config sortable & add delete functionality.
* Improvement: Add possibility to turn off single customfields.
* Improvement: Use new WB Table functionality for using filters.
* Improvement: Add optionformconfig to context 1 (systemwide).
* Improvement: Allow cachier to book users from waitinglist, if it's a "confirm waitinglist" setting.
* Improvement: New booked users information with wb table.
* Improvement: More efficient handling of actions.
* Improvement: Show booked users only if there are some.
* Improvement: Add edit component to tables.
* Improvement: New setting for Booking debug mode.
* Improvement: Add debug event for send_mail_by_rule_adhoc.
* Improvement: New language strings.
* Bugfix: Fix deprecation warning for usort.
* Bugfix: Make sure html tags won't destroy the functionality.
* Bugfix: Implement new WB Table API.
* Bugfix: Drag & drop only for waitinglist.
* Bugfix: Fix errors in datepickers.
* Bugfix: Crucial Bugfix for booking process.
* Bugfix: Fix askforconfirmation booking button.
* Bugfix: Confusion between optionid & id.
* Bugfix: Fix option config form.
* Bugfix: Add missing cache definitions.
* Bugfix: Fix #447.
* Bugfix: Don't try to create event if optiondatesid is 0.
* Bugfix: Only access waitforconfirmation if it is set.

## Version 8.1.40 (2024031801)
* Bugfix: If shoppingcarthistory already has a canceluntil date set it may not be overriden by the option's canceluntil date again!

## Version 8.1.39 (2024031800)
* Improvement: Make save and cancel buttons in edit forms sticky at bottom.

## Version 8.1.38 (2024031400)
* Improvement: Accelerate view page via lazy loading.
* Improvement: Meeting link buttons should have secondary color.
* Bugfix: Service period currently always needs to be courseendtime.
* Bugfix: Accelerate slow teacher page.
* Bugfix: don't render all tables to show teacher page.

## Version 8.1.37 (2024031300)
* Bugfix: Fix privacy provider.
* Bugfix: Fix special fields for optiondates (Teams meeting, Zoom meeting...).
* Improvement: Several improvements for sign-in sheet, including better display of e-mail addresses and line breaks for too long titles.
* Improvement: Better debugging for errors with availability json.
* Improvement: Setting to not count users with presence status "no show" for max. number of bookings per instance.

## Version 8.1.36 (2024031200)
* Improvement: New settings for max. per user and improvements to general settings.
* Tests: New phpunit tests for reminder mails and teacher reminders.

## Version 8.1.35 (2024031100)
* Bugfix: No need to destroy singleton when creating new booking instances.
* Bugfix: Add missing strings (assesstimestart, assesstimefinish).

## Version 8.1.34 (2024030801)
* Improvement: Only use cached data for enrolled in course check.
* Improvement: Make sure calendar events are always stored as optiondates.
* Improvement: Get rid of old form modes (toggle between simple and expert form).
* Improvement: New function to purge all booking instance caches and destroy booking instance singleton.
* Bugfix: Make sure institution stores only empty strings.
* Bugfix: Fix bugs with showlistoncoursepage field.

## Version 8.1.33 (2024030700)
* Improvement: Add possibility to format personal messages with HTML editor.
* Improvement: Make sure images in cards view always have the same height of 220px.

## Version 8.1.32 (2024030601)
* Improvement: Layout fix for cards.
* Improvement: Show "Choose..." string in autocompletes if value is missing.

## Version 8.1.31 (2024030500)
* New feature: New placeholder classes for better performance and maintainability.
* New feature: Show full entity name of each optiondate in optionview (booking option description).
* New feature: New setting for sync_waiting_list to keep users booked when reducing limits.
* Improvement: Import now works with "startdate" & "enddate" keys.
* Improvement: Inline modals (turn off modals functionality) will only work with list view.
With cards view, we always have modals.
* Improvement: Apply waitinglist on increasing maxanswers for booking option
* Improvement: Add string that modals cannot be turned off for new cards view.
* Improvement: When purging caches for option we also need to destroy singletons for booking option settings.
* Improvement: Show teachers emails to booked users only (with config setting).
* Improvement: Better strings.
* Bugfix: Add to Calendar needed to be in postsave mode.
* Bugfix: Set importing on true when noticing an import.
* Bugfix: Default boavenrolledincourse during import to OR, not AND, support for boavenrolledincourseoperator (Values OR, AND).
* Bugfix: Don't call add to calendar on template.
* Bugfix: Add isset check for addtocalendar.
* Bugfix: Don't block booking options without price with prepages.
* Bugfix: Destroy all instances of booking options when calling destroy_booking_option_singleton.
* Bugfix: Make sure we use correct context when creating booking option sql.
* Bugfix: Wrong settings were used on report.php.
* Bugfix: Use context_system if context_module can't be found (eg for template).
* Testing: Temporarily take out failing behat test - to be reintroduced.

## Version 8.1.30 (2024030400)
* Bugfix: Several fixes for indexes and foreign keys.

## Version 8.1.29 (2024030100)
* Bugfix: Fix for leap year bug.
* Bugfix: Pre- and Post-page conditions like "Bookingpolicy" should not block "allow_add_item_to_cart".
* Bugfix: Fix download.php of Booking view.

## Version 8.1.28 (2024022900)
* New feature: You may now switch between list and cards layout in Booking instance settings.
* Improvement: Change tab order an make all booking options tab the default.
* Improvement: showall should be the default tab - also in install.xml.
* Bugfix: Make sure menubar is always at correct position using CSS.
* Bugfix: We always want to see institution - regardless if entitity manager is installed or not.

## Version 8.1.27 (2024022800)
* Improvement: Better layout for new menu bar (we cannot show it at the top right, as this would lead to several layouting problems).

## Version 8.1.26 (2024022701)
* Improvement: In Booking, we show the edit button at the top right.
* New feature: New pencil button to directly edit booking options.

## Version 8.1.25 (2024022700)
* Improvement: Make alert to show which option is edited sticky at top.
* Bugfix: Missing cache definitions.
* Bugfix: Fix descriptionformat via upgrade.

## Version 8.1.24 (2024022600)
* Improvement: Better layout for table row.
* Improvement: Second sign-in sheet button for download only and layout improvements.
* Improvement: Linting and layout improvements for report.php and all_userbookings.php
* Bugfix: Fix language string for "general" in English language pack - closes #410.
* Bugfix: Also check for already reserved in allow_add_item_to_cart.

## Version 8.1.23 (2024022301)
* Bugfix: Save availability on newly created option as well.
* Bugfix: Notification list is NO hard block!
* Improvement: Throw exceptions on import for better quality.

## Version 8.1.22 (2024022300)
* Bugfix: Fix allow_add_item_to_cart => do not block is_available anymore with notifyme-list.

## Version 8.1.21 (2024022200)
* New feature: Add new possibility to create new option or stay in edit option form after submit.
* Improvement: Show sub-entities and location from entity in sign-in-sheet.
* Improvement: Show signin sheet download button and area at more prominent place.
* Improvement: Get name for filter and address from entity manager.
* Improvement: Show an info alert in option form with option title, so we always know which option we edit.
* Improvement: Make sure we can only fill new options with template data, so existing options won't get overwritten.
* Improvement: Add Event to see if and possibly why afteractions failed.
* Improvement: Add Event to see if and possibly why afteractions failed
* Bugfix: For config settings, we always use 'booking' as plugin string.
* Bugfix: Add missing logic to addtocalendar field, so course calendar events will be created and deleted when saving form.
* Bugfix: For plugin settings, we use 'booking' not 'mod_booking'.
* Bugfix: If maxanswers are 0 the option is unlimited, so is_fully_booked has to always return false in this case.
* Bugfix: Don't override address & location if entities exist.
* Bugfix: Fix Webservice importer (bookingopening & closingtime etc).
* Bugfix: Remove broken mobile app support.
* Bugfix: Fix behat (button "Save and go back" was renamed to "Save").

## Version 8.1.20 (2024021900)
* Improvement: priceisalwayson setting will always block booking now.
* Improvement: Slightly better caching policy.
* Bufix: New wunderbyte constructor rules implemented.
* Bugfix: No warning when user in selectuser condition is deleted.
* Bugfix: Save and display bookingoptionimages & attachments.
* Bugfix: Visibility has to be public on bookingoptiondescription.
* Bugfix: Revert "Save lowest entity in location (not parent)".

## Version 8.1.19 (2024021601)
* Bugfix: Special availability import fixed

## Version 8.1.18 (2024021600)
* Improvement: Adoption of new wb table constructor
* Bugfix: Download bookings on report.php fixed, also for exactly one instance
* Bugfix: Active Bookingoptions are not yet finished or have finished today

## Version 8.1.17 (2024021401)
* Improvement: Add easy option form functionality.
* Improvement: Add reduced optionforms capabilities.
* Improvement: Every editing teacher has the right to see the standard form (default setting).
* Improvement: Add new table to store configs for forms.
* Improvement: Add option form config webservice.
* Improvement: Add necessary json class to make sure we don't lose json values even when the classes are not there.
* Improvement: Add alternative import strings to instantiate classes.
* Improvement: Add Pro Flag to report over all booking options feature.
* Improvement: Add eventslist field.
* Improvement: Better handling of empty $PAGE.
* Bugfix: Make sure prices are not set involuntarily on import.
* Bugfix: Fix dates import on Webservice and mergeparams.
* Bugfix: Make sure not to delete unaffected availability conditions.
* Bugfix: Fix merge error on feature branch.
* Bugfix: On webservice & ajax, always use the standard fields.
* Bugfix: Save lowest entity in location (not parent).
* Bugfix: Fix CSV importer.
* Bugfix: Fix issue #405.
* Bugfix: Make new option form logic work in easy availability modal.
* Bugfix: Do not show elective settings in form if instance is no elective instance.
* Bufix: Fix check for $PAGE.
* Bugfix: Lots of little fixes for option form and CSV import.

## Version 8.1.16 (2024021300)
* Bugfix: Make sure price does not get lost when using the CSV importer.
* Bugfix: Fix update of price and canceluntil.
* Bugfix: Make sure settings class is instantiated during import.
* Bugfix: Make sure location can be saved again.
* Bugfix: Make sure bookingopeningtime and bookingclosingtime do not get lost on import.
* Bugfix: Fix set_data functions for bookingopeningtime, bookingclosingtime and responsiblecontact.

## Version 8.1.15 (2024021200)
* Bugfix: Fix bug with wrong usage of strpos with "~" and "!~" operators.
* Bugfix: Fix booking_navigation_setting behat scenarios to use precise navigation element selector.
* Bugfix: Fix behat test - add datesmarker field.

## Version 8.1.14 (2024020900)
* Improvement: Show updated string for send custom message button.
* Improvement: Use less space for availability condition notification warnings.
* Improvement: Slightly smaller Moodle course button.
* Improvement: Do not execute fullybooked check if item is already in cart or already booked.
* Improvement: Only show dates when dates exist in option view. Also get rid of string duplicates.
* Improvement: Show responsible contact in change logs.
* Bugfix: Fix an exception when creating date series with no semester set.
* Bugfix: Make sure, we respect module visibility and activity restrictions on the booking instance - closes #385.
* Bugfix: Fix bugs with entities and optiondates when called with different form.
* Bugfix: Fix a bug when price was null ins save_from_form of price.php.

## Version 8.1.13 (2024020601)
* Improvement: Get rid of startendtimeknown param as it is legacy code.
* Improvement: Collapse the full description and do not show it twice.
* Bugfix: Text depending on status was not shown anymore at all.

## Version 8.1.12 (2024020600)
* Improvement: Better feedback for import.
* Improvement: Report with all booking answers - closes #386.
* Bugfix: Fix import for canceluntil #401.
* Bugfix: Fix wrong variable bug.
* Bugfix: Fix course enrolement.
* Bugfix: Loosen to strict import rules.
* Bugfix: Catch error for task.
* Bugfix: fix elective enrolement.
* Bugfix: Remove unnecessary redundancy.

## Version 8.1.11 (2024020100)
* Improvement: Styling of booking description in musi_table.
* Improvement: Harmonize and restore save (create) and delete functions for optiondates and remove redundancies.
* Improvement: For new optiondates we use the entity of the parent option as default.
* Improvement: Don't use the ? typecast to null for functions, as it's not yet supported in PHP 7.4.
* Improvement: Add no semester option.
* Bugfix: Fix context bug in optiondate class.
* Bugfix: Don't access entities constant without actually having called the handler.
* Bugfix: Don't trigger events when cmid is empty (as for global templates)
* Bugfix: Load responsible contact.

## Version 8.1.10 (2024013001)
* Bugfix: Add missing isset check in booking_handler.
* Bugfix: Fixed a bug that sent status change notifications to ALL users on waiting list.

## Version 8.1.9 (2024013000)
**Bugfixes:**
* Bugfix: Fix strings in behat tests.

**Improvements:**
* Improvement: From calendar events we now link to optionview.php.

## Version 8.1.8 (2024012901)
**Improvements:**
* Improvement: Show more information of availability conditions to users and fix some strings.

**Bugfixes:**
* Bugfix: Fix legacy code in option_optiondate_update_event and bookingoption_updated and use singleton service.
* Bugfix: Fix deletion and recreation of course events (uuid is used to store optionid-optiondateid pattern).

## Version 8.1.7 (2024012900)
**New features:**
* New feature: Add setting to collapse descriptions of booking options in table.
* New feature: Add possibility to set canceluntil date for individual booking options.

**Improvements:**
* Improvement: Improve performance on instances with a lot of options.
* Improvement: Cache "showdates" for much better performance.
* Improvement: Store useprice flag in JSON so that it works correctly.

**Bugfixes:**
* Bugfix: Change semester adHoc task threw an error when non existing courseid was defined.
* Bugfix: Setting the active booking options filter on end of this day, not time() will improve cached working.
* Bugfix: Do not save custom form condition if checkbox is turned off.
* Bugfix: Fix and improve canceluntil functionality and make sure that it works with cancelmyself (for options without price).

## Version 8.1.6 (2024012400)
**Improvements:**
* Improvement: Fix layout bugs with signin sheet.

## Version 8.1.5 (2024012200)
**Bugfixes:**
* Bugfix: Fix save_data function of option field "elective".
* Bugfix: Fix bugs in option field "actions".
* Bugfix: Fix bug in option field "addtogroup".
* Bugfix: Fix wrong usage of cmid in booking_option class.
* Bugfix: Fix change semester functionality (reset and create new optiondates).
* Bugfix: Fix several bugs with fields classes.
* Bugfix: Fix for dynamic custom fields that allow multiple values (multiselect).

## Version 8.1.4 (2024011900)
**Improvements:**
* Improvement: Improve quality of sign-in sheets.
* Improvement: Speed-up performance by deleting the right caches (booking answers cache instead of whole booking option cache).

**Bugfixes:**
* Bugfix: Fix "showdates" misbehavior as well as template creation issue - both caused by TinyMCE - so disabled it.

## Version 8.1.3 (2024011700)
**Bugfixes:**
* Bugfix: Fix button to allow booking of users who are not enrolled in course.

## Version 8.1.2 (2024011600)
**Bugfixes:**
* Bugfix: No userid needed in option_allows_overbooking_for_user (we always use logged-in user here).
* Bugfix: Fix exception for old options with only one date stored in the booking option.
* Bugfix: Fix automatic creation of new Moodle courses with new option form.

## Version 8.1.1 (2024011500)
**Improvements:**
* Improvement: React on changes in new booking_option update function.
* Improvement: phpunittest - bring back "dayofweek" in csv and assertion.

**Bugfixes:**
* Bugfix: Collapsible not opened properly.
* Bugfix: Make sure constants are present when needing them.
* Bugfix: Fix collapsible for bootstrap 4 & 5.
* Bugfix: Store correct info in dayofweek column.
* Bugfix: Add missing string for booking:view capability.
* Bugfix: Submit buttons not working in new option form - we comment them out for now.
* Bugfix: Fix a bug in booking_option.php where optionid was retrieved incorrectly.
* Bugfix: Semester not used from booking settings for new option.
* Bugfix: Fix for undefined property: stdClass::$addtocalendar booking_utils.php.
* Bugfix: No "id" in csv file. So if no ID provided we threat record as new and set id to "0".
* Bugfix: Fix warning because of null in explode.
* Bugfix: Fix broken cancel button in option form.

## Version 8.1.0 (2024011000)
**New features:**
* New feature: In Booking 8.1.0, we completely re-wrote the booking option form in a more modern and object oriented way.
This will allow us, to individually adapt the booking option form for differenct clients and use cases dynamically and easily.
* New feature: In Booking 8.1.0, we also changed the way templates work in the option form.
They are no longer filled out using JavaScript (which was quite buggy and incomplete) but we use the new classes for templates and CSV import too.
* New feature: In Booking 8.1.0, optiondates (sessions of an option) are created using a new dynamic form.
So you can now add entities, custom fields, comments and the number of days for session notifications directly to each date.
Also, you will now always have optiondates, even if there is only one session (so there is no need to show coursestarttime and
courseendtime of the booking option anymore).

**Improvements:**
* Improvement: Logs of little UI, Usability and layout changes to make the booking option form cleaner and more beautiful.
* Improvement: Recommendedin show only options where coursesendtime is > $now. (arg 'all' to turn off).
* Improvement: Lots of code quality improvements and linting (e.g. PHPdoc).

## Version 8.0.56 (2023122000)
**New features:**
* New feature: Shortcode [recommendedin] - Better default settings and new params for configuration.

**Improvements:**
* Improvement: If we have dates with "entity outliers" we show an additional checkbox to confirm overwriting.
* Improvement: Add and remove teachers only from future dates, but keep them in past dates - so we have a valid history.
* Improvement: Change string for changed behavior (teachers only added/removed for FUTURE optiondates).
* Improvement: Recommendedin show only options where coursesendtime is > $now. (arg 'all' to turn off)
* Improvement: Remove RELEASENOTES as it is redundant to CHANGES.md.

**Bugfixes:**
* Bugfix: Also purge encoded tables (wunderbyte table cache) when purging cache for a specific option.
* Bugfix: Only purge wbtable cache when a booking option gets updated. Not generally.
* Bugfix: Fix some strings for booking instance action logs.
* Bugfix: Fix behat.

## Version 8.0.55 (2023121100)
**Bugfixes:**
* Bugfix: Add missing string 'semesterid'.
* Bugfix: Fix a bug that lead to teacher notifications not being sent anymore.

## Version 8.0.54 (2023120700)
**Improvements:**
* Improvement: Refactor action names for wbtable.
* Improvement: Make sure wbtable container is aligned left by adding left margin of 0 (ml-0).

**Bugfixes:**
* Bugfix: Fix bug with duplication of booking instances when optionid or userid of teacher is missing or cannot be mapped.

## Version 8.0.53 (2023120400)
**New features:**
* New feature: Show booking opening and closing time in all relevant views and add possibility to sort by them.
* New feature: Filters for booking time and course time.

**Improvements:**
* Improvement: No reload button on teacher page and no login required for table.
* Improvement: Links in entity calendar now point to preview page (optionview.php).
* Improvement: No entity shortname on booking option preview page (optionview.php).

**Bugfixes:**
* Bugfix: Fix {teachers} placeholder.
* Bugfix: Add some fixes for course calendar events and refactor some legacy code.

## Version 8.0.52 (2023112800)
**New features:**
* New feature: Add possibility to set default sort order for booking instances.
* New feature: Choose date field for cancellation period, new fields: bookingopeningtime, bookingclosingtime.

**Improvements:**
* Improvement: Make sure, we never send mails for invisible booking options.

**Bugfixes:**
* Bugfix: All plugin constants must start with uppercase frankenstyle prefix.
* Bugfix: Remove table prefix and use curly brackets.

## Version 8.0.51 (2023112700)
**Improvements:**
* Improvement: Add sortby and sortorder to recommendedin shortcode.
* Improvement: Stop hiding default Moodle menu entries.
* Improvement: Fixed and improved functionality to create new dates for a semester.
* Improvement: Add capability "viewreports" to manage responses.

**Bugfixes:**
* Bugfix: Recommended table doesn't lose sorting etc.
* Bugfix: Fix format_text so filters will work with text depending on option status.
* Bugfix: Refactor pass data to js.
* Bugfix: Pass JSON using base64 encoding.
* Bugfix: If a booking instance is hidden, we do not show it on teacher pages.

## Version 8.0.50 (2023112201)
**Bugfixes:**
* Bugfix: Fix potential empty arrays in settings.php.

## Version 8.0.49 (2023112200)
**New features:**
* New feature: Show unsubscribe link in notification mails.

**Improvements:**
* Improvement: Added support for Moodle 4.3 and PHP 8.2.
* Improvement: Do not send notification mails and remove user from notification list if booking option is already over.

**Bugfixes:**
* Bugfix: Cashier always has to be able to book options without prices - even when blocked by a condition.

## Version 8.0.48 (2023111300)
**New features:**
* New feature: Campaign Limits take into account overbooking at the time of campaign start and add overbooked places to limit.

**Improvements:**
* Improvement: Some improvements to new cost center feature.
* Improvement: Code quality: Always use int and bool - never integer or boolean.
* Improvement: Get rid of deprecated institutions autocomplete js.

**Bugfixes:**
* Bugfix: Make sure that booking and cancelling of options without a price is possible even when shopping cart is installed.
* Bugfix: Fix bugs with prepages (both modal and inline) in combination with new cost center feature.
* Bugfix: Fix behat tests and issues related to prepages (both modal and inline).
* Bugfix: Fix error "Exception - Warning: Undefined array key "serviceperiodstart".
* Bugfix: Fix Javascript for Prepage Modals.
* Bugfix: Add shoppingcartisinstalled to example json.
* Bugfix: Fix normal booking button js.
* Bugfix: fix prepage JS for multiple tables on one page.
* Bugfix: Fix namespaces.
* Bugfix: Fix param definition in external services.

## Version 8.0.47 (2023110200)
**New features:**
* New feature: Introduce a new setting to tell booking which booking option custom field is used to store the cost center for each booking option.
In shopping cart, a new setting can then be activated to avoid booking of items with different cost centers.

## Version 8.0.46 (2023102000)
**New features:**
* New feature: Add new blocking campaign which allows to block booking for students depending on booking status (e.g. half of places gone).
* New feature: Actions logs for booking options.
* New feature: Actions logs for booking instances.

**Improvements:**
* Improvement: Show users and teachers in autocomplete in one single line.
* Improvement: Better campaign strings.
* Improvement: Complete re-writing of sync_waiting_list with singleton, etc.
* Improvement: Missing hours and substitutions for teachers in instance report work better now.

**Bugfixes:**
* Bugfix: Booking of any users feature was broken - used user preferences to fix it.
* Bugfix: Fix broken automatic loading of custom field values in campaign modal.
* Bugfix: Fix some strings for github actions.
* Bugfix: Make sure we have a string to replace, for str_replace in message_controller.
* Bugfix: When we use format_text, we need to set $PAGE->context first!
* Bugfix: Empty select on settings.php.

## Version 8.0.45 (2023101300)
**Improvements:**
* Improvement: booking_check_if_teacher function can now be used with optionid too.
* Improvement: Better user selectors for teachers.
* Improvement: Availability info texts now work on optionview.php (booking option detail page) too.
* Improvement: Booking option detail page (optionview.php) can now be accessed without login.

**Code quality:**
* Linting: Example context for col_availableplaces and better param documentation for booking_check_if_teacher.
* Linting: Fix example context for col_availableplaces.
* Linting: No trailing comma allowed in JSON.
* Linting: Fix form-user-selector-suggestion.mustache for github actions.

## Version 8.0.44 (2023100900)
**New features:**
* New feature: Lock editing of substitution once the "reviewed" checkbox has been clicked.

**Improvements:**
* Improvement: New function to lazy load teacher list for autocomplete.
* Improvement: New template for smaller user suggestions in autocomplete.
* Improvement: Add responsible contact to booking option description.
* Improvement: Return educational units without label.
* Improvement: Access restrictions for "Go to Moodle course" now make more sense.
* Improvement: Add optiondatesteacherstable templates to mustache ignore list.
* Improvement: Make sure mailto link gets encoded correctly.
* Improvement: Fix signin sheets, only Lastname, Firstname, No profiletext anymore.

**Bugfixes:**
* Bugfix: Fixes for GH-325 (Pull request).
* Bugfix: Fix bug in event description.
* Bugfix: New teacher syntax.
* Bugfix: We need module context in the teacher substitutions form!
* Bugfix: Fix caching bug with substitutions table (optiondates teachers report).
* Bugfix: Fix some bugs with cmid and reloading of substitution report (optiondates teachers report).
* Bugfix: Fix broken behat tests (because of changed CSS selector).
* Bugfix: Fix warning if sendmail is not set.
* Bugfix: Use table row not table header in behat tests for substitutions.

## Version 8.0.43 (2023100300)
**Bugfixes:**
* Bugfix: Use semicolon in mailto function, not comma - for compatibility with some mail clients.
* Bugfix: Fix return type declaration for PHP7.4
* Bugfix: Linting: Switch to short array syntax [] instead of array().
* Bugfix: No more error message when a teacher substitution is reviewed.

## Version 8.0.42 (2023092700)
**Improvements:**
* Improvement: Make sure that cmid always is the one corresponding with the optionid for "showonlyone" links.

## Version 8.0.41 (2023092100)
**New features:**
* New feature: New tab "field of study" (PRO feature).

**Improvements:**
* Improvement: Add margins for bookit button areas.
* Improvement: Show countlabel for filter.

**Bugfixes:**
* Bugfix: No error on non existing option by because of callback.
* Bugfix: Do not use legacy get_user_status function anymore. We use the booking answers from singleton service now!
* Bugfix: Use right variablename ($itemid instead of $optionid) in is_available function call.

## Version 8.0.40 (2023091801)
**Bugfixes:**
* Bugfix: First tab in teachers table is active

## Version 8.0.39 (2023091800)
**Improvements:**
* Improvement: Add "fieldofstudy" tab & corresponding fucntionality.
* Improvement: Dont show hidden instances on teachers page.
* Improvement: Add operator to enrolled in courses condition to decide if at least one or all of them have to be met.
* Improvement: More behat tests.

## Version 8.0.38 (2023091500)
**Bugfixes:**
* Bugfix: Usernames were not shown correctly for some user because of missing rights in opiton form

## Version 8.0.37 (2023091401)
**Improvements:**
* Improvement: Use entity parent name in location, if existant.

## Version 8.0.36 (2023091400)
**New features:**
* New feature: It is now possible to turn off modals and book "inline".
* New feature: New shortcode [fieldofstudy].
* New feature: Disable cancellation for individual bookings.
* New feature: Disable cancellation for a whole booking instance.
* New feature: Disable cancellation of individual booking options or of the whole booking instance.

**Improvements:**
* Improvement: Create option date series via DB.
* Improvement: Some adjustments for PHP 8.2 and Moodle 4.2.
* Improvement: We add the price to every normal button when a) we can't book for others & b) when there is a price.
* Improvement: Style prices in subarea with h6.
* Improvement: Improve get_instance_of_booking_by functions (avoid db calls).
* Improvement: Speed up working of conditions.
* Improvement: Add singleton service for user price category to speed up things during a single call.
* Improvement: Code quality.

**Bugfixes:**
* Bugfix: Only call JS when records are found.
* Bugfix: Add missing JS and replace <a> with <div>.
* Bugfix: Fix prepage also for cashier.
* Bugfix: Shortcodes via webservices need the right imports.
* Bugfix: Fix errors when no user is found.
* Bugfix: Recreate build folder (grunt).

**Tests:**
* Test: Runtime optimizations and fixes for behat tests.

## Version 8.0.35 (2023090800)
**Bugfixes:**
* Bugfix: If boactions or jsonobject are not set, we set them to null.
* Bugfix: Before sending change notificaiton, we need to purge answer cache, so {status} placeholder will be updated correctly.
* Bugfix: Catch possible SMTP exceptions with email_to_user so send_confirmation_mails task does not fail anymore.

## Version 8.0.34 (2023090600)
**New features:**
* New feature: Calculate cancel until date from semester start instead of booking option start (coursestarttime).
* New feature: Use semester dates for service period in shopping cart.

**Improvements:**
* Improvement: Always gender using a colon (":").
* Improvement: Show tags for "PRO" and "Experimental" settings.

**Bugfixes:**
* Bugfix: Fix broken behat tests.
* Bugfix: Yet another fix for entity import via entity id - store entity name in location, NOT the entity ID (as this does not make sense).

## Version 8.0.32 (2023083000)
**Improvements:**
* Improvement: Teachers filter - Show lastname before firstname and separate with comma.
* Improvement: Show search, filter and sorting in wb-tables generated by shortcodes.

## Version 8.0.33 (2023083100)
**Improvements:**
* Improvement: Use singleton service to get users in autocompletes.
* Improvement: Better availability conditions update process (soft update - do not delete missing conditions).
  Only if checkbox (advcheckbox) is actually "0" they will be removed.
* Improvement: Add clean string function - in case we need it to remove special chars.

**Bugfixes:**
* Bugfix: Separate tablename with space so tests don't fail.
* Bugfix: Remove institution name from uniqueid of myinstitutiontable as it might contain special chars.
* Bugfix: Custom form cannot be overridable.

## Version 8.0.32 (2023083000)
**Improvements:**
* Improvement: Teachers filter - Show lastname before firstname and separate with comma.
* Improvement: Show search, filter and sorting in wb-tables generated by shortcodes.

**Bugfixes:**
* Bugfix: Use core function email_to_user instead of phpmailer_email_to_user and stop supporting multiple ical attachments.
* Bugfix: Fix potential caching problems with wbtables on view.
* Bugfix: Fix reload of my bookings table.
* Bugfix: Decode availability string instead of using strpos which is deprecated in PHP 8.1.
* Bugfix: Add !empty check for location value and add comment for issue #310 (re-write importer to support updates).
* Bugfix: If columns for download or view.php are missing we use all columns as fallback - closes #302.
* Bugfix: 'relateuserid' must be used with event::create().
* Bugfix: 'identifier' field added to pricecategories generator.
* Bugfix: Proper verification if option description has been set and trimming of it as well (empty string is valid).
* Bugfix: Fix get_options_filter_sql() method to really process searchtext parameter.
* Bugfix: Do not throw error message if location entity not found (like it IS in UI).

**Tests:**
* Test: New phpunit test to cover csv_import->process_data.
* Test: Add features to create booking semester for tests.

## Version 8.0.31 (2023082301)
**New features:**
* New feature: Custom forms for individual booking options (via availability condition) - e.g. for individual booking policies.

**Improvements:**
* Improvement: Better icon for "create options from optiondates" functionality.

**Bugfixes:**
* Bugfix: Fix add to cart when overbooking.
* Bugfix: Fix DB for new json column in table booking_options.

## Version 8.0.30 (2023082200)
**Improvements:**
* Improvement: Remove unused code artifacts for cleaner code.
* Improvement: Use singleton service for get_all_users_booked and make sure we always use the correct user id.
* Improvement: Create truly unique identifier and CSV import fixes for identifier.

**Bugfixes:**
* Bugfix: Make sure identifier of booking options is REALLY unique.
* Bugfix: Create entity relations for each optiondate with importer.
* Bugfix: Fix waiting list bug which deleted users if option was fully booked.
* Bugfix: Check if identifier is really unique in webservice importer.

## Version 8.0.29 (2023081600)
**Bugfixes:**
* Bugfix: Fix exception with $PAGE context modification and move function to new booking_context_helper class.

## Version 8.0.28 (2023081100)
**Improvements:**
* Improvement: Support mulitple teacheremails in csv import, separated by comma.
* Improvement: Always use singleton_service instead of instantiation for booking_option.
* Improvement: Always use singleton_service instead of direct instantiation for booking instances.
* Improvement: Better strings for feedback URL (pollurl) and teacher's feedback URL (pollurlteachers).

**Bugfixes:**
* Bugfix: Fix bug where customfields were not shown anymore.
* Bugfix: Fix page context modifications.

## Version 8.0.27 (2023080700)
**Bugfixes:**
* Bugfix: Allow loading of already loaded item (in case cache was invalidated)
* Bugfix: Fix semester caching and import of semester-based option date series.

## Version 8.0.24 (2023072101)
**Improvements:**
* Improvement: Cache a flag to check if we already have applied campaigns, so we don't do it several times.

**Bugfixes:**
* Bugfix: Closes #44 (local_shopping_cart bug) "Adhoc tasks fails on testing site".
* Bugfix: get_in_or_equal needs an array as input param.

## Version 8.0.23 (2023072100)
**New features:**
* New feature: Entity import now works with both full name or entity id.

**Improvements:**
* Improvement: Stop creating placeholder params from view.php for better performance and move the function to booking_option class.
* Improvement: Code quality: missing isset checks for iselective and maxcredits.
* Improvement: Decision: we only show entity full name in location field.
* Improvement: Renamed get_entity_by_id to get_entities_by_id (there can be more than one because of join with address table).

**Bugfixes:**
* Bugfix: Fixed initialization of pricecategoryfield setting if user profile fields were missing.
* Bugfix: Wrong check for is_elective().

## Version 8.0.22 (2023071700)
**Bugfixes:**
* Bugfix: Added string for message provider
* Bugfix: Fixed CSV Importer vor bookingopeningtime & bookingclosingtime

## Version 8.0.21 (2023071200)
**New features:**
* New feature: New settings to show teacher pages for not logged-in users and to show teacher e-mails to everyone.
* New feature: Turn off waiting list globally by config setting.
* New feature: New possibility to book with credits.
* New feature: Send direct mails via mail client to all booked users.

**Improvements:**
* Improvement: Cashier is now able to overbook booking options for other users (not herself).
* Improvement: Code quality: commented out deprecated functions.
* Improvement: Filter in Wunderbyte table inactive on loading.
* Improvement: Code quality: Rename col_text_link to musi_bookingoption_menu and move it to local_musi.
* Improvement: Use singleton service to retrieve users.
* Improvement: Better strings for book with credits settings.
* Improvement: Also allow access to connected Moodle course for teachers with 'mod/booking:limitededitownoption' capability.

**Bugfixes:**
* Bugfix: Fix error with missing username or email in message_controller.
* Bugfix: Fix wrong userid when cashier books for others with prepage modals.
* Bugfix: Fix the following error for subbooking: "Exception - Warning: Undefined property:
  stdClass::$id in [dirroot]/mod/booking/classes/subbookings/sb_types/subbooking_additionalperson.php on line 173"
* Bugfix: Fixed availability problem with subbookings that lead to unexpected errors with availability conditions.
* Bugfix: Fix prepage modal bug with subbookings and rename not_blocked to has_soft_subbookings.
* Bugfix: Normal subbookings are not overridable as they need to do a "soft block" so they appear in prepage modals.
* Bugfix: Make sure empty url does not trigger db request.
* Bugfix: Fix override conditions logic.
* Bugfix: With override conditions we need to check the ORIGINAL value!

**Tests:**
* Behat: 3 scenarios have been added to cover turning off branding and make teacher pages (teacher.php and teachers.php) available to not logged-in users and force the display of teacher e-mail addresses
* GitHub: fix of the Moodle CodeChecker errors.
* Behat: new scenario Add single subbooking option for a booking option as a teacher

## Version 8.0.20 (2023062600)
**Improvements:**
* Improvement: Some more funcationalities for webservice importer

## Version 8.0.19 (2023062200)
**Improvements:**
* Improvement: Fix deprecation warnings for PHP 8.1.
* Improvement: Moodle 4.2 has been added to the github workflow.
* Improvement: New PRO feature to turn off Wunderbyte logo and link.

**Bugfixes:**
* Bugfix: Fix for Moodle 4.2 compatibility - set userid in the event.
* Bugfix: Fix for Moodle 4.2 compatibility - legacy methods removed from event classes.
* Bugfix: When limiting to 0 participants sync_waiting_list() deleted answers.
* Bugfix: Notify list also needs to be an overridable condition.

## Version 8.0.18 (2023061600)
**Improvements:**
* Improvement: Code quality for elective.

**Bugfixes:**
* Bugfix: Missing check if instance is elective.
* Bugfix: Elective fix for DB: add necessary fields to install.xml
* Bugfix: If user is on notification list, we always need to show unsubscribe toggle bell.
* Bugfix: Fix error when not an elective.
* Bugfix: Fix missing $PAGE->context error.
* Bugfix: If an option gets deleted, we want option settings to return null - no debug message.
* Bugfix: Fixes for Github actions.
* Bugfix: elective modal - if cache expires, we need to reset it.

## Version 8.0.17 (2023061201)
**Bugfixes:**
* Bugfix: Fix elective combinations.

## Version 8.0.16 (2023061200)
**New features:**
* New feature: Elective functionality implemented

## Version 8.0.15 (2023060901)
**Bugfixes:**
* Bugfix: Context in booking_bookit was set incorrectly!

## Version 8.0.14 (2023060900)
**Improvements:**
* Improvement: Code quality, and new timespan filter on view.php

## Version 8.0.13 (2023060500)
**Improvements:**
* Improvement: Code quality, behat tests, mustache linting, PHPunit fixes and more.

## Version 8.0.12 (2023052400)
**Bugfixes:**
* Bugfix: Add require_once to avoid warning from campaign_info with shortcodes use.

## Version 8.0.11 (2023052200)
**Improvements:**
* Improvmenet: Add failed booking event when using shopping cart

## Version 8.0.10 (2023051700)
**Improvements:**
* Improvement: Adjustment of capabilities for better finetuning

## Version 8.0.9 (2023051200)
**New features:**
* New feature: Recommandation feature via shortcodes, to 'push' booking options in selected Moodle courses.

## Version 8.0.8 (2023042400)
**New features:**
* New feature: Booking campaings - Reduce booking prices and increase booking limit for a specified time period for specific booking options.

**Improvements:**
* Improvement: Mustache linting for github actions.
* Improvement: New tabs for visible/invisible booking options. (Tabs will only be shown to users with 'canseeinvisibleoptions' capability.)
* Improvement: Added duplication and backup of subbooking options.

**Bugfixes:**
* Bugfix: Small SQL fixes for teachers instance report.
* Bugfix: Fix several bugs with subbookings and prepage modals.
* Bugfix: Fix broken entity backup.
* Bugfix: Fix bugs with continue button and prepage modals.

## Version 8.0.7 (2023040602)
**New features:**
* New feature: Additional person subbooking (still an experimental feature).
* New feature: New possibility to react on changes on teachers report via booking rules (e.g. to send e-mails).
* New feature: Introduce new {journal} placeholder to directly link to "substitutions / cancelled dates" (training journal).
* New feature: New config setting to force prices to be always turned on. Also added price validation.
* New feature: New possibility to review changes teachers report (substitutions / missing hours) via checkbox.
  Introduced new capability 'mod/booking:canreviewsubstitutions'.

**Improvements:**
* Improvement: Add help button for select users condition.
* Improvement: Added get_renderer function to singleton_service for improved performance.
* Improvement: Lots of little improvements to additional person subbooking.
* Improvement: Use new way to instantiate table from wunderbyte_table.
* Improvement: Migrated teachers report from table_sql to wunderbyte_table.
* Improvement: New behat tests.

**Bugfixes:**
* Bugfix: Lots of little bugfixes to additional person subbooking.
* Bugfix: Fixed an exception that occurred on self-cancellation of students.
* Bugfix: Undefined status for "confirm cancel" condition.
* Bugfix: Fix error in delete_item_task if no subbooking is found.
* Bugfix: German and English strings were mixed up for 'allowoverbooking'.

## Version 8.0.6 (2023032700)
**New features:**
* New feature: New "select users" availability condition.
* New feature: New possibilities for override conditions (e.g.: "fullybooked" can now be overriden if combined with "OR").
* New feature: Introduced a new setting to allow overbooking of booking options if a user has the "mod/booking:canoverbook" capability.

**Improvements:**
* Improvement: Define default pagination setting and use it.
* Improvement: Remove intro description from business card. It's now part of the new activity header.
* Improvement: Usability improvements for price formula and make price formula a PRO feature.
* Improvement: Added a helper function to check if a user is allowed to overbook an option.

**Bugfixes:**
* Bugfix: Fix 2 behat navigations' tests to use aria-label="Page" string obtained from Moodle core.
* Bugfix: Add default string to transform_msgparam function if msgparam is not found.
* Bugfix: MSGPARAM constants were not found in message_sent.php because of missing lib.php inclusion - closes #265
* Bugfix: Support both two-letter (German) and three-letter (English) abbreviations for date strings (date series).
* Bugfix: JS was lost on extra button conditions.
* Bugfix: Make sure import via CSV works.

## Version 8.0.5 (2023032100)
**Improvements:**
* Improvement: Differentiate between checkout and booking complete confirmation in header.
* Improvement: the $booking->get_pagination_setting() method introduced to get number of booking options per page for rendering.

**Bugfixes:**
* Bugfix: Add missing isset checks.
* Bugfix: Mustache's HTML validation fixes and little github styling.

**Testing:**
* Test: 2 behat scenarios have been added for testing settings.
* Test: 2 behat scenarios have been added for testing navigation - paging and filtering.

## Version 8.0.4 (2023032000)
**Improvements:**
* Improvement: Hide activity header on view confirmation page and show menu in full width.
* Improvement: Remove duration from bookingoption_description and put image into paragraph.
* Improvement: Remove unused attribute defaultdownloadformat.

**Bugfixes:**
* Bugfix: Fixed broken send reminder mails task.
* Bugfix: Remove wrong login function.

## Version 8.0.3 (2023031600)
**New features:**
* New feature: New placeholder {profilepicture} to add user profile picture to confirmation mails.

**Improvements:**
* Improvement: Added and updated behat tests.
* Improvement: Link to teachers page on report.php instead of user profile.

**Bugfixes:**
* Bugfix: Fix broken confirm activity functionality.
* Bugfix: Missing isset for $booking->bookingpolicy.
* Bugfix: Fixed errors found with behat tests.
* Bugfix: Fixed some mustache warnings.

## Version 8.0.2 (2023031500)
**New features:**
* New feature: Add possibility to book anyone - even if not enrolled (for site admins only).

**Improvements:**
* Improvement: Better invisibility label with eye icon.
* Improvement: Disable activity header in report.php.
* Improvement: Hide activity header on book other users page.

**Bugfixes:**
* Bugfix: Fix some bugs in automatic number generation of report.php.
* Bugfix: Add missing string 'nopriceisset'.
* Bugfix: Fix warning on deleting last item in shopping cart.
* Bugfix: Added isset check for missing bookingpolicy.

## Version 8.0.1 (2023031301)
**Improvements:**
* Improvement: If shopping cart plugin is not installed, but a price is set, we just show the price.

**Bugfixes:**
* Bugfix: Do not show attachments string if there are no attachments in booking instance.

## Version 8.0.0 (2023031300)
**New features:**
* New feature: New view.php now working with Wunderbyte Table (local_wunderbyte_table) with lots of improved features.
* New feature: Show text depending on status description right in new booking overview.
* New feature: Finished download for new view.php.
* New feature: Add possibility to configure fields for booking options download.
* New feature: Booking now supports prepagemodals with booking policy, a confirmation page and support for the "Book now" and "Add to cart" buttons.
* New feature: Re-implemented ratings, attachments and tag functionality for new view.php.
* New feature: Intelligent differntiation between price and no-price booking options.
* New feature: Implemented new "cancel myself" condition and settings.

**Improvements:**
* Improvement: Lots of improvements for the new view.php which now works with the local_wunderbyte_table plugin.
* Improvement: Set old features to DEPRECATED which will be removed (or replaced) in the future.
* Improvement: Remove deprecated JS stuff from view_actions.js - we only use it in report.php (as it breaks stuff in view).
* Improvement: Show Wunderbyte logo in footer.
* Improvement: Additional conditions now supporting booking, waiting list, confirmation of booking and cancelling and much more...
* Improvement: Harmonized and improved menus for Moodle 4.0 and higher.
* Improvement: Make fields of new view configurable.
* Improvement: Improved some strings.
* Improvement: Hide activity header using $PAGE->activityheader->disable() instead of CSS.

**Bugfixes:**
* Bugfix: Fix a bug where available places or minanswers were not shown correctly.
* Bugfix: Fixed broken ratings in report.php.
* Bugfix: Fix edit option link.
* Bugfix: Poll URL (feedback link) was never saved for booking options.
* Bugfix: Bookingid was missing in some tables because of incorrect array creation.
* Bugfix: Function booking_updatestartenddate was not called on CSV import.
* Bugfix: Fixed many tiny errors in the new prepagemodals.

## Version 7.9.0 (2023022000)
**New features:**
* New feature: Subbookings (not yet finished, but can be activated as preview).
* New feature: New overview page of booking options making use of Wunderbyte Table.
* New feature: Booking is now able to handle prices in combination with Wunderbyte Shopping Cart plugin.
* New feature: New teachers pages, teachers overview and teachers instance report now part of booking.
* New feature: Make cancelling of booking options work both the normal way as also the shopping cart way.
* New feature: Person sub-booking (still unfinished).
* New feature: New button linking to connected Moodle course (shown only, if user has booked or is admin).
* New feature: Turn off automatic moving up from waiting list after option has started.
* New feature: Sorting of booking options is now possible via new sorting feature (sorting options: prefix, title, start, location, institution).

**Improvements:**
* Improvement: Lots of layout and usability improvements, especially with menus.
* Improvement: New action menu.
* Improvement: If unlimited, we still want to see the number of bookings.
* Improvement: Better handling of the number of available bookings. Also including a manage responses link now.
* Improvement: Re-implemented lots of functionalities of the old view.php (some still missing, will be added in later versions).
* Improvement: Improved optionview.php and got rid of old info modal.
* Improvement: Behat tests.
* Improvement: Added pre- and postpage logic for subbookings (still unfinished).
* Improvement: Collapse dates only if there are at least 3, several fixes, layout improvements.
* Improvement: Layout improvements, bugfixes, better menus.
* Improvement: Temporarily removed "move to other instance" feature until we are sure, it works.
* Improvement: Code style and code quality improvements.
* Improvement: Better condition alert colors.
* Improvement: Waiting list places will now also be shown in new view.php.
* Improvement: As of Moodle 4.0 activity description will be shown automatically in header, so we remove it form business card.
* Improvement: Better display of minimum number of participants (minanswers).
* Improvement: Better styling of teacher icons.

**Bugfixes:**
* Bugfix: Fix and improve option templates, menu entries and checkbox for limit answers.
* Bugfix: Delete booking_teachers artifacts when a booking instance gets deleted.
* Bugfix: Fixed a bug where users could not be booked for unlimited options and a wrong error message was shown.
* Bugfix: waitinglist < 2 for booking answers in viewconfirmation.php
* Bugfix: Fixed an issue with external functions.
* Bugfix: Fix faulty upgrade of subbooking answer table.
* Bugfix: Fix booking_time condition.
* Bugfix: Avoid js execution for alert buttons in conditions.
* Bugfix: Fix override conditions in form (combine availability conditions).
* Bugfix: Fix available places in option view and also show manage respones link there (admins only).

## Version 7.8.7 (2023012700)
**Bugfixes:**
* Bugfix: Fix navigation menu entry to delete booking option and add an entry to manage responses.
* Bugfix: Fix and improve option templates, menu entries and checkbox for limit answers.

## Version 7.8.6 (2023012600)
**Bugfixes:**
* Bugfix: Sending mail copies to the booking manager feature has been fixed.

## Version 7.8.5 (2023012500)
**Bugfixes:**
* Bugfix: Remove old institutions from restoring stepslib and fix crash.
* Bugfix: Placeholder {address} was showing address of user instead of address of booking option.

## Version 7.8.4 (2023012000)
**Improvements:**
* Improvement: Better code quality.

**Bugfixes:**
* Bugfix: Fixed caching problem with {status} placeholder.

## Version 7.8.3 (2023011600)
**Improvements:**
* Improvement: Shorter string for unlimited places.
* Improvement: Old institution functionality removed, as it is not needed anymore.

**Bugfixes:**
* Bugfix: Some elements were not hidden in simple mode.

## Version 7.8.2 (2023011300)
**Improvements:**
* Improvement: Prepare process_booking_price function in restore_booking_stepslib for new price areas.

**Bugfixes:**
* Bugfix: Purge caches after a user is added to a booking option.
* Bugfix: Fix duplication of booking instances by removing 'area' from set_source_table function.

## Version 7.8.1 (2023011200)
**Improvements:**
* Improvement: Add warning when you can only add users from one institution.
* Improvement: Deleted old teachers.php (not needed anymore).
* Improvement: Design improvements and improved code quality.
* Improvement: Some layout improvements for buttons, alerts and prices.

**Bugfixes:**
* Bugfix: Remove wrong capability check for user events in order to fix "nopermissiontoupdatecalendar" bug.
* Bugfix: Fixed an error in upgrade.php.
* Bugfix: Fix php 8 deprecation warnings (optional before required param).
* Bugfix: Fixed a bug in dynamicoptiondateform where JS was not passed.
* Bugfix: Fixed broken report reminders (custom reminder from report.php).

## Version 7.8.0 (2022122300)
**New features:**
* New feature: Add teachers directly in option_form.
* New feature: New possibility to set sorting order for price categories.

**Improvements:**
* Improvement: Get rid of old way to edit teachers.
* Improvement: When an option gets duplicated, teachers will get duplicated too.
* Improvement: When an option gets duplicated and we choose a new course, teachers now get enrolled into the new course.

**Bugfixes:**
* Bugfix: Fix bug where optiondate series were not created (js param missing).

## Version 7.7.9 (2022122100)
**Improvements:**
* Improvement: Further improvements to event-based rules (only tested combinations are supported).

**Bugfixes:**
* Bugfix: If the whole option was cancelled, we do not want to send status change mails.

## Version 7.7.8 (2022122000)
**New features:**
* New feature: New progress bars feature (PRO) including configuration in plugin settings.
* New feature: New booking rule condition to select user directly from event (affected user / triggering user).

**Improvements:**
* Improvement: Show booked, reserved, etc. users of a booking option via template.
* Improvement: Show  reserved & waitinglist users of a booking option via template on the "book other users" page.
* Improvement: Rule combination check and rule validation.

**Bugfixes:**
* Bugfix: Consumed quota - if option has not yet started, the quota is 0.
* Bugfix: Fix adding of calendar events for options without sessions (but with a "fake" session).
* Bugfix: Fix and improve cancel / undo cancel of booking options

## Version 7.7.7 (2022121500)
**New features:**
* New feature: get back consumed quota of booking option to local_shopping_cart service provider
* New feature: Support areas for local_shopping_cart service provider

## Version 7.7.6 (2022121300)
**Bugfixes:**
* Bugfix: Fix bug where canceluntil date was wrongly calculated from $now instead of $coursestarttime.
* Bugfix: Fix auto enrolment of teachers, improve defaults and automatic course creation.

## Version 7.7.5 (2022120900)
**New features:**
* New feature: Add setting to turn off creation of user calendar events, if wanted.
* New feature: Better German language strings ("Buchungen" instead of "Antworten").
* New feature: Turn messages off by entering 0.

**Improvements:**
* Improvement: Optimized and improved DB performance (added keys, indexes etc.)
* Improvement: Use caching for booking option description.
* Improvement: Better settings for automatic course creation category custom field.

**Bugfixes:**
* Bugfix: Fix problem with static functions
* Bugfix: Do not trigger bookingoption_updated when a booking option is cancelled.
* Bugfix: Fixed some errors in prettify_datetime.
* Bugfix: Typo in {eventtype} of fieldmapping.
* Bugfix: Correctly retrieve sessions via singleton_service of booking_option_settings.
* Bugfix: Dates spanning over more than one day did not show second date.
* Bugfix: Calendar events were created twice on creation of booking options.
* Bugfix: We need to purge option settings cache after updating.
* Bugfix: Fix a bug were options without dates showed Jan 1st, 1970.
* Bugfix: Fixed some bugs with automatic course creation.
* Bugfix: Fixed some behat test (issue #217).
* Bugfix: Fixed error string in CSV import.
* Bugfix: Fix missing userid in send notification mails task (function return_all_booking_information) - issue #218
* Bugfix: Optionid was missing when creating new sessions in optiondates.php (multi-session manager).

## Version 7.7.4 (2022120200)
**New features:**
* New feature: New placeholders from user profile:
  username, firstname, lastname, department, address, city, country.

**Improvements:**
* Improvement: Fixed and renamed placeholders: {times} are now {dates},
  introduced {teachers} for list of teachers, and fixed {teacher} and {teacherN}
* Improvement: Introduced price areas to support subbookings in the future.
* Improvement: several changes to optiondates handler.
* Improvement: Add missing capability strings.
* Improvement: Improve performance by more extensive use of caching.
* Improvement: Better function for condition messages.
* Improvement: Performance improvements in answers and option (user_submit_response)
* Improvement: Reduce sql for performance. Booking_answers class has now no further
  information about the users, apart from the id.
* Improvement: Add resilience to booking_answers class
* Improvement: Show titleprefix on "book other users" page.

**Bugfixes:**
* Bugfix: Fix a lot of little bug with booking rules.
* Bugifx: Fixed a param in toggle_notify_user webservice.
* Bugfix: Use correct message providers.
* Bugfix: fixed call of rule->execute()
* Bugfix: catch potential error on user deletion.
* Bugfix: Add userid to check_if_limit function to fix caching problem with booking answers.
* Bugfix: Small fix with user status function.
* Bugfix: first column not unique.

## Version 7.7.3 (2022112300)
**Improvements:**
* Improvement: Correctly use availability conditions in optionview.php
* Improvement: Add indexes to tables where necessary
* Improvement: Delete user events when booking option is cancelled and more.
* Improvement: Show manage responses in menu.

**Bugfixes:**
* Bugfix: Invalidate caches when a booking option is deleted.
* Bugfix: Adhoc tasks failed when booking options were deleted.
* Bugfix: Adhoc tasks failed when booking options were deleted.

## Version 7.7.2 (2022111600)
**New features:**
* New feature: Cancel booking options

## Version 7.7.1 (2022111400)
**New features:**
* New feature: Bew booking rule condition to select specific users via autocomplete.

**Improvements:**
* Improvement: More efficient implementation of rule conditions.

## Version 7.7.0 (2022111001)
**New features:**
* New feature: New booking rules allowing to differentiate between rules, conditions and actions.
  This is really cool and will enable booking to do great things in the near future!
* New feature: New event-based rules allosing to react to any booking option event.
* New feature: Cancelling of booking options without deleting them, the reason will be stored into
  internal annotations. Cancelling of booking options can be undone too.
* New feature: New rule condition allowing to enter the text to be compared (contain/equal)
  with a custom user profile field.

**Improvements:**
* Improvement: Collapsible overview of placeholders like {bookingdetails} for "Send mail" action of booking rules.
* Improvement: More beautiful menu of booking options in view.php.
* Improvement: New event bookingoption_cancelled is triggered when a booking option gets cancelled.

**Bugfixes:**
* Bugfix: When all optiondates were removed in optionform, they were not deleted at all.
* Bugfix: Fix type error in payment service provider.
* Bugfix: Restored Moodle 3.11 compatibility for booking rules.
* Bugfix: Minor code quality fixes.

## Version 7.6.3 (2022110400)
**Improvements:**
* Improvement: Improved conflict handling for entities at same date.
* Improvement: Better styling for customdates button.
* Improvement: For new options automatically check the checkbox to save entities for all optiondates.

**Bugfixes:**
* Bugfix: Fix entity conflicts for different areas (option / optiondate).

## Version 7.6.2 (2022110200)
**Bugfixes:**
* Bugfix: Fixed issue #213 - privacy provider get_contexts_for_userid() - MariaDB, SQL doesn't work.

## Version 7.6.1 (2022103100)
**Improvements:**
* Improvement: Use caching for serving images.

**Bugfixes:**
* Bugfix: Fix formula calculation with non iterable custom fields.

## Version 7.6.0 (2022102700)
**New features:**
* New feature: Entities can now be set for sessions of booking options (a.k.a. optiondates) too.
* New feature: Entities can conflict with each other if on the same date.

**Improvements:**
* Improvement: Entity shortnames (abbreviations like WBO for Wunderbyte Office) are now supported.
* Improvement: If an entity is set, we show it (name + shortname) instead of the value stored in "location".
* Improvement: Better handling of entities associated with booking options.
* Improvement: Better interface for optiondate manager.

**Bugfixes:**
* Bugfix: Duplication with conditions caused an error (optionid: -1).
* Bugfix: Fix undefined index for blocked events (start and endtime cannot be retrieved from string).
* Bugfix: Fix undefined index for blocked events (start and endtime cannot be retrieved from string).
* Bugfix: Postgres fix for teachers instance report.

## Version 7.5.5 (2022101200)
**New features:**
* New feature: Min. number of participants can now be set (currently only shown, no logic).

**Improvements:**
* Improvement: Add support for new shopping cartitem functionality (serviceperiodstart & end).
* Improvement: Header for "actions" in booking option cards settings menu.
* Improvement: New price formula setting to apply unit factor (is now set via config setting,
  not needed in price formula anymore).
* Improvement: Show educational units in tables and cards too.

## Version 7.5.4 (2022100500)
**Improvements:**
* Improvement: Booking rules => use classic moodleform so we can use editor.

## Version 7.5.3 (2022100400)
**New features:**
* New feature: New teachers report for booking instances,
  including courses (booking options), missing hours and substitutions.

## Version 7.5.2 (2022092901)
**Bugfixes:**
* Bugfix: Fix issue #212 - upgrade script for field 'availability' had wrong version number.

## Version 7.5.1 (2022092900)
**Bugfixes:**
* Bugfix: Fix language strings.

## Version 7.5.0 (2022092800)
**New features:**
* New feature: Global Roles (PRO) - Rules can now be added globally.
  The rule 'Send e-mail n days before a certain date' now allows to define
  to send e-mails n days before a certain date within an option (e.g. coursestarttime,
  bookingopeningtime...) to all users who have a custom profile field matching (or
  containing) the same value as a certain field of an option. The mail templates also
  support placeholders (e.g. {bookingdetails}).

## Version 7.4.3 (2022092700)
**Improvements:**
* Improvement: Added notification when a semester is saved in form.

**Bugfixes:**
* Bugfix: Fix bug where error was shown in optionformconfig_form.

## Version 7.4.2 (2022091902)
**Improvements:**
* Improvement: Restored holiday names.

## Version 7.4.1 (2022091900)
**New features:**
* New feature: Add user profile fields to e-mail params.

**Improvements:**
* Improvement: PRO availability conditions and info texts (and fixed correct order).

**Bugfixes:**
* Bugfix: Cleaning override of override concept
* Bugfix: Deal with missing attachments.
* Bugfix: If user profile fields are missing, we need to load them correctly.

## Version 7.4.0 (2022091500)
**New features:**
* New feature: New availability condition for custom profile fields.
* New feature: New performance report for teachers (performed hours/units).
* New feature: CSV Import now works with "identifier" and "titleprefix".
* New feature:

**Improvements:**
* Improvement: New operators for user profile field availability condition.
* Improvement: Added German translations for availability condition strings.
* Improvement: Added titleprefix ("course number") for previously booked availability condition.
* Improvement: Migrate old option names containing separator and identifier and use new "identifier" field.
* Improvement: Better optiondates handling for quickfinder block (bookingoptions_simple_table).

**Bugfixes:**
* Bugfix: Do not show or count reserved and deleted bookings (view.php / coursepage_available_options).
* Bugfix: Fixed Moodle 3.9 compatibility issues.
* Bugfix: Missing titleprefix caused quickfinder block not to work.
* Bugfix: Fixed yet another waitinglist bug on view.php.
* Bugfix: Unique option names are not necessary anymore (we use identifier now).
* Bugfix: Better cachedef strings - closes #210
* Bugfix: Fixed an SQL bug.
* Bugfix: Fixed "isbookable" availability condition.

## Version 7.3.0 (2022090100)
**New features:**
* New feature: Booking availability conditions introduced.
* New feature: New report for teachers (performed units).
* New feature: Manage instance templates (from plugin settings).
* New feature: New setting to round prices after price formula application.

**Improvements:**
* Improvement: Price formula - add support for multiple custom fields.
* Improvement: get_options_filter_sql function now support userid and bookingparam (booked, waitinglist etc.)
* Improvement: More intuitive and simpler holidays interface.
* Improvement: Better displaying of prices.
* Improvement: Now allowing up to 100 option dates.
* Improvement: Search in view.php is now case-insensitive.
* Improvement: Correct feedback when teacher user does not exist (in CSV import).
* Improvement: New scheduled task to clean DB and better task names.
* Improvement: Better string for invalid link (for booked meetings, e.g. teams customfield).
* Improvement: Add possibility to fetch filtersql for special user with booked params.

**Bugfixes:**
* Bugfix: Fix mybookings view to not show reserved and deleted bookings.
* Bugfix: Fix issue #193 (stuck on settings page).
* Bugfix: Correctly delete entries in booking_optiondates_teachers for 'change semester' function.

## Version 7.2.7 (2022080900)
**New features:**
* New feature: Added booking opening time (can be used like booking closing time).

**Improvements:**
* Improvement: New event listener for price category identifier changes updates prices of booking options automatically.
* Improvement: Also delete header images from DB when deleting an instance.#
* Improvement: Added a warning text for semester change.
* Improvement: Better display of course duration (days, hours, minutes).
* Improvement: Better display of search button.

**Bugfixes:**
* Bugfix: Fixed 'book other users' feature of booking (broken since 'unreal' deletion of booking answers).
* Bugfix: Booked out courses may not be bookable.
* Bugfix: Fixed some bugs with simple / expert mode and showing entitities.
* Bugfix: Bugfix where canceluntil didn't work on negative values (after course started).
* Bugfix: Fix errors in create_option_from_optionid.

## Version 7.2.6 (2022072500)
**New features:**
* New feature: Prevent option from recalculating prices.
* New feature: Cancel for all participants.
* New feature: Image duplication (both for options and booking instance header images).

**Improvements:**
* Improvement: Correctly delete image files when deleting booking options.
* Improvement: Duplication of images for individual booking options now working wiht backup/restore.

**Bugfixes:**
* Bugfix: When duplicating or restoring options create new random unique identifiers.
* Bugfix: Fix sql problem in the book for others panel.
* Bugfix: Correct duplication, restoring and deleting of custom fields.
* Bugfix: Fix SQL bug for image files.
* Bugfix: Fix SQL comma bug with get_options_filter_sql.

## Version 7.2.5 (2022071801)
**Improvements:**
* New price calculations with entity factor from entity manager.

**Bugfixes:**
* Hotfix - Missing quote character in install.xml.
* Added missing "dayofweek" in backup.

## Version 7.2.4 (2022071800)
**New features:**
* Added automatic course creation.
* Added price calculation for all options of instance.

**Improvements:**
* Updated automated tests config for M4.
* Performance improvement for construction of booking_settings.
* Added missing language strings.

**Bugfixes:**
* Fixed unit testing fail in externallib.
* Fixed possible error with price on guest view.
* Fixed postgres errors.
* Fixed broken commenting feature for booking options.

## Version 7.2.3 (2022070500)
**New features:**
* Calculate prices for specific booking options using a sophisticated JSON formula.
* Direct connection between booking instances and semesters.
* If we have a semester, only enrol from semester start until semester end.

**Improvements:**
* New identifier field for booking options.
* New annotation field for booking options for internal remarks and comments.
* New title prefix field for booking options (e.g. for non-unique course number).
* Show collapsible optiondates in all_options.php.
* Several improvements for handling of semesters.
* Implement user data deletion requests for Privacy API - closes #197
* Better notification button (for observer list), fixed toggle and improved strings for notification mails.

**Bugfixes:**
* Fix bug where no invisible was provided in webservice.
* Also create optiondates for new booking options.
* Added strings for Privacy API - closes #198

## Version 7.2.2 (2022062900)
**New features:**
* Internal annotations for booking options.

**Improvements:**
* Moved 'description' up to a more prominent place in booking option form.

**Bugfixes:**
* When no teacher was set for a booking option, teacher notifications were sent to participants.
* Fixed broken duplication of booking options.

## Version 7.2.1 (2022062200)
**Bugfixes:**
* Fixed bug relating to invisible options.
* Fixed bugs relating to (missing) entitities (removed dependencies to local_entitities).
* Fixed missing JavaScript.

## Version 7.2.0 (2022062100)
**New features:**
* Possibility to reduce booking option form to necessary elements only (configure simple mode).
* Toggle between simple mode and expert mode for booking option form.
* Notification list (observer list) functionality.

**Improvements:**
* Add support for returnurl for the booking options form

**Bugfixes:**
* Fixed an error with image URL.
* Make sure entities are only used when they are installed
* Fix some unset properties.
* Fixed bug in shopping cart where wrong price was taken.
* Fixed JavaScript for Moodle 4.0.
* Fixed broken URLs for Moodle 4.0.
* Commented out helpbuttons in repeat_elements groups as they cause problems with Moodle 4.0.
* Fixed navigation nodes for Moodle 4.0.

## Version 7.1.5 (2022060700)
**New features:**
* Added possibility to backup/duplicate/restore entities relations.

**Improvements:**
* If entity is set, we use it to set location and address.

**Bugfixes:**
* Restored correct order of upgrades.
* Fixed issue #190 (Upgrade fails) - https://github.com/Wunderbyte-GmbH/moodle-mod_booking/issues/190

## Version 7.1.4 (2022060200)
**Bugfixes:**
* If there are multiple image files with the same name, the first one found will be used.

## Version 7.1.3 (2022060101)
**New features:**
* Added possibility to import entities via CSV.

**Improvements:**
* Better language strings.

## Version 7.1.2 (2022060100)
**New features:**
* New teaching report (teaching journal) - allowing to define different teachers for each session, including export functionality-
* Change the semester for a booking instance - all dates of booking options will be generated newly for the selected semester.
* Added possibility to turn duplication / restoring of prices on and off in plugin settings (if turned off, default prices will be used)-

**Improvements:**
* Better presentation of teachers and link to profiles.
* Added prices to the backup routine, so they will be duplicated and backed up (if turned on in the plugin settings).

**Bugfixes:**
* Do not show separator and unique id in bookingoption_description.
* Fix a bug where the mobile service didn't get all booking options.

## Version 7.1.1 (2022050501)
**Improvements:**
* Add entities relation handler.

**Bugfixes:**
* CSV-import: add default value for column 'invisible'.
* Fix table sort order for PostgreSQL.
* Fix a bug where users couldn't see the corresponding prices.

## Version 7.1.0 (2022050400)
**New features:**
* New possibility to make options invisible for users without permission.
* Add and edit holidays (dynamic form).
* Create date series and take care of holidays.
* Add custom dates to date series (and support blocked events).

**Improvements:**
* Do not show list of booking options on course page by default.

**Bugfixes:**
* Fixed a CSS bug which disabled scrolling possibility.
* Hide dates title in signin sheet did not work correctly.

## Version 7.0.30 (2022042100)
**New features:**
* Add new config setting to include/not-include teachers.
* New default setting for addtocalendar with locking possibility.

**Improvements:**
* New dynamic semesters form.
* Add collapsible option dates to booking option description.
* New edit button in listofbookings and listofbookingscards.
* Improved sign-in-sheet with possibility to add columns for every optiondate.
* Display all prices for users which are not logged in.
* Take out shortcodes default instance.

**Bugfixes:**
* Fix a bug where prices were not imported.
* use no-reply email if no booking manager was set.
* Fix nullpointer when saving booking instances.
* department still missing in SQL
* Excel download not working with special characters.
* Missing minified files for sign-in-sheet.
* Fixed broken sessions in sign-in-sheet.
* Fix issue #185 - Error enrol users in scheduled task
* Fix missing {bookingdetails}-placeholder on viewconfirmation.php
* Option menu hidden behind top of table (if there's only one option).
* Fixed teacher duplication.
* Show images for users which are not logged in.
* Fix bug where edioptionsurl was specific to user who generated cache.
* Small fix if addtocalendar is not found in config.

**Other:**
* Fixed typo: subecribeusersctivity => subecribeusersactivity.

## Version 7.0.28 (2022032800)
**New features:**
* Add new shortcode 'mybookings'.

**Improvements:**
* Improve booking creation via singleton service.

**Bugfixes:**
* Fix a typo in settings.php which led to an error.
* Fix fallback to default image.
* Fix auto enrolment.
* Show 'booked' string when booked in booking option description.

## Version 7.0.27 (2022032601)
**New features:**
* New interface to add and edit semesters.
* Create date series with a string for reoccurring dates (e.g. 'Mo, 18:00 - 19:30').
* Upload images to booking options and show them in bookingoption_description.
* Image fallbacks: define images for a certain category (defined by custom field) and define a default image for booking options.
* New possibility to show a list of bookings (also as cards) via shortcodes.
* Display a booking option on a separate page (including possibility to buy the option, see the price etc.)

**Improvements:**
* Show booked places (instead of free ones).
* Added import of custom fields, dayofweektime string and prices to CSV importer (identified by keys).
* Refactoring for better performance.
* New singleton_service for better performance.
* Nicer presentation of booking options.
* Improved caching.
* Added collapsible description in manager view of block_booking.
* Better descriptions of booking options.
* Better date interface.
* Don't show 'booked' instead of available places.
* Added price and price category to booking option description.
* Only show booking-specific custom fields.

**Bugfixes:**
* Fixed a bug which broke the instance duplication feature.
* Fixed several bugs in caching.
* Fixed several rendering bugs.
* Added missing department to responses fields.
* Fixed badge styling.
* Fixed JS for modal loading.
* Do not show sports badge if no value exists.
* Display correct price in modal in buyforuser scenario.
* Fixed cashier's checkout.
* Fix in CSV-importer: Only run through prices if default column is present.

## Version 7.0.26 (2022021601)
**New features:**
* Nicer presentation of available places.

## Version 7.0.25 (2022021600)
**New features:**
* New sports badge
* Caching of shortcodes table data
* Show description modal in shortcodes pages

**Improvements:**
* Implement shopping cart & transition towards "unreal" deletion of booking_answers
* Support shortcode without category (returns all options)

**Bugfixes:**
* Fix a bug with PostgreSQL

## Version 7.0.24 (2022021500)
**Improvements:**
* Use message_controller for custom messages.

**Bugfixes:**
* Cancel button now works correctly.
* Wrong index in message logs of bookingoption_completed.
* Missing string in message logs of custom messages.
* Closed #183 - Inconsistancy between install.xml and upgrades

## Version 7.0.23 (2022020300)
**New features:**
* New shortcodes class enables dynamic embedding of new bookingoptions_table (using wunderbyte_table).
* Added prices to booking options.
* Show prices and action button in shortcodes table.
* Implement shopping_cart service provider & template.
* Added shopping cart functionality.
* Use new wunderbyte table caching.
* Better message logging: Sent messages get logged by Moodle event logger.
* Add possibility to choose currency globally via plugin settings.
* Add price categories to booking settings (including default category).
* Define a user profile field where the price category for each user is stored.
* Disable price categories and add default values.

**Improvements:**
* New settings classes for booking instances and booking options.
* Refactoring: New message controller class in charge of all notification e-mails.
* Placeholder {optiontimes} now works for both single and multisessions
* Add function to booking_option_settings to get display-text instead of text with separator.
* Use new wunderbyte_table with mustache.js support.

**Bugfixes:**
* Fixed broken view.php.
* Updated deprecated code.
* Book other users: Fixed a bug where selected users where not shown anymore.
* Fixed a bug where we had a duplicated admin page name.
* Fixed a bug where empty prices led to an error.
* Fixed customfields page.
* Fixed an infinite loop caused by message controller.
* Fixed message data preparation.

**Other:**
* Added behat tests.

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
* When there are no multisessions defined, the {dates} parameter for notification e-mails will use the
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
