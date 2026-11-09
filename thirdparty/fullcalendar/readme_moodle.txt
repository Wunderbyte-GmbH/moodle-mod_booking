FullCalendar for mod_booking
============================

Used by the compact slot calendar ([bookingoptionview ... calendar="compact"]), see
amd/src/slotbooking/fullcalendar.js (loads it) and amd/src/slotbooking/calendar_view.js (uses it).

Source:    npm package "fullcalendar" (global build with all standard plugins)
Version:   5.11.3
License:   MIT (LICENSE.txt)
Tarball:   https://registry.npmjs.org/fullcalendar/-/fullcalendar-5.11.3.tgz
Integrity: sha512-SgqiMEA+lWLyEd2jEwtIxdfx41j2CZr4KK00D2Gepj1MnGOjaEi13athnU6xvqMQXXjgJNj+vmlUP69QiuGncQ==

Files taken unchanged from the package:
- main.min.js         the library, sets the global FullCalendar
- main.min.css        its stylesheet
- locales-all.min.js  all languages, adds them to FullCalendar.globalLocales
- LICENSE.txt

To update:
1. npm pack fullcalendar@<version> and unpack it.
2. Copy the four files above from package/ into this folder, unchanged.
3. Update the version here and in thirdpartylibs.xml.
4. Check the compact slot calendar (behat: booking_slotbooking_compact_calendar.feature). A new major
   version (6 and up) changes the API and brings its CSS through JS: calendar_view.js has to be
   adapted then.
