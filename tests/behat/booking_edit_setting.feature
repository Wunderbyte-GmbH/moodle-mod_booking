@mod @mod_booking @booking_edit_setting
Feature: Edit booking's organizer, info and semester settings as a teacher or admin.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | admin1   | Admin     | 1        | admin1@example.com   | A1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | admin1   | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And I create booking option "New option" in "My booking"
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Edit booking instance title
    Given I am on the "My booking" Activity page logged in as teacher1
    Then I follow "Settings"
    And I set the following fields to these values:
      | Booking instance name         | BookingUpd          |
    And I press "Save and display"
    And I should see "BookingUpd"

  @javascript
  Scenario: Settings - show organizer
    Given I am on the "My booking" Activity page logged in as admin1
    And I should not see "Organizer name"
    When I follow "Settings"
    And I expand the "Organizer name" autocomplete
    And I should see "Teacher 1" in the "#fitem_id_organizatorname .form-autocomplete-suggestions" "css_element"
    And I click on "Teacher 1" "text" in the "#fitem_id_organizatorname .form-autocomplete-suggestions" "css_element"
    And I wait "1" seconds
    And I press "Save and display"
    Then I should see "Organizer name" in the "#booking-business-card" "css_element"
    And I should see "Teacher 1" in the "#booking-business-card" "css_element"
    ## Verify as teacher
    Given I am on the "My booking" Activity page logged in as teacher1
    And I should see "Organizer name" in the "#booking-business-card" "css_element"
    And I should see "Teacher 1" in the "#booking-business-card" "css_element"

  @javascript
  Scenario: Settings - show info on course page
    Given I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I set the field "Event type" to "Sport class"
    And I set the field "showlistoncoursepage" to "Hide extra information on course page"
    Then I should not see "Short info"
    And I press "Save and return to course"
    And I should not see "My booking description"
    And I follow "My booking"
    And I follow "Settings"
    And I set the field "showlistoncoursepage" to "Show course name, short info and a button redirecting to the available booking options"
    And I set the field "Short info" to "Click on View available options, choose a booking option and click Book now"
    And I press "Save and return to course"
    Then I should see "Course 1" in the ".section .modtype_booking .coursename" "css_element"
    And I should see "Sport class" in the ".section .modtype_booking .eventtype" "css_element"
    And I should see "Click on View available options, choose a booking option and click Book now" in the ".section .modtype_booking .shortinfo" "css_element"

  @javascript
  Scenario: Booking settings - create semester
    Given I log in as "admin"
    And I visit "/mod/booking/semesters.php"
    And I set the following fields to these values:
      | semesteridentifier[0]   | nextjune           |
      | semestername[0]         | Next June          |
      | semesterstart[0][day]   | 1                  |
      | semesterstart[0][month] | June               |
      | semesterstart[0][year]  | 2050               |
      | semesterend[0][day]     | 30                 |
      | semesterend[0][month]   | June               |
      | semesterend[0][year]    | 2050               |
    ## Need to overrider potential bug:
    And I set the field "semesterend[0][day]" to "30"
    And I press "Save changes"
    Then I should see "Semester 1"
    And the following fields match these values:
      | semesteridentifier[0]   | nextjune           |
      | semestername[0]         | Next June          |
      | semesterstart[0][day]   | 1                  |
      | semesterstart[0][month] | June               |
      | semesterstart[0][year]  | 2050               |
      | semesterend[0][day]     | 30                 |
      | semesterend[0][month]   | June               |
      | semesterend[0][year]    | 2050               |
    And I log out

  @javascript
  Scenario: Booking settings - access the teacher pages without login
    Given the following "mod_booking > options" exist:
      | booking    | text                      | course | description  | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | teachersforoption |
      | My booking | Booking option - Teachers | C1     | Option deskr | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | teacher1          |
    And I log in as "admin"
    And I set the following administration settings values:
      | Login for teacher pages not necessary | |
    And I log out
    And I visit "/mod/booking/teachers.php"
    And I wait to be redirected
    And I should see "Log in to" in the "#region-main" "css_element"
    And I log in as "admin"
    And I set the following administration settings values:
      | Login for teacher pages not necessary | 1 |
    And I log out
    And I visit "/mod/booking/teachers.php"
    And I wait until the page is ready
    Then I should see "1 Teacher" in the ".page-allteachers-card" "css_element"
    And I follow "Teacher"
    And I should see "1 Teacher" in the ".card-title" "css_element"

  @javascript
  Scenario: Booking settings - display teachers email pages without login
    Given the following "mod_booking > options" exist:
      | booking    | text                      | course | description  | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | teachersforoption |
      | My booking | Booking option - Teachers | C1     | Option deskr | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | teacher1          |
    And I log in as "admin"
    And I set the following administration settings values:
      | Login for teacher pages not necessary             | 1 |
      | Always show teacher's email addresses to everyone |   |
    And I log out
    When I visit "/mod/booking/teachers.php"
    Then I should see "1 Teacher" in the ".page-allteachers-card" "css_element"
    And I should not see "Mail" in the ".page-allteachers-card" "css_element"
    And I follow "Teacher"
    And I should see "1 Teacher" in the ".card-title" "css_element"
    And I should not see "teacher1@example.com" in the ".card-title" "css_element"
    And I log in as "admin"
    And I set the following administration settings values:
      | Always show teacher's email addresses to everyone | 1 |
    And I log out
    And I visit "/mod/booking/teachers.php"
    And I should see "1 Teacher" in the ".page-allteachers-card" "css_element"
    And I should see "Mail" in the ".page-allteachers-card" "css_element"
    And I follow "Teacher"
    And I should see "1 Teacher" in the ".card-title" "css_element"
    And I should see "teacher1@example.com" in the ".card-body" "css_element"

  @javascript
  Scenario: Booking settings - hide branding info
    Given I log in as "admin"
    When I set the following administration settings values:
      | Do not show Wunderbyte logo and link | |
    And I am on the "My booking" Activity page
    And I should see "Booking module created by Wunderbyte GmbH" in the "#region-main" "css_element"
    And I set the following administration settings values:
      | Do not show Wunderbyte logo and link | 1 |
    And I am on the "My booking" Activity page
    Then I should not see "Booking module created by Wunderbyte GmbH" in the "#region-main" "css_element"

  @javascript
  Scenario: Booking settings: create an additional price category via UI
    Given the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 50           | 0        | 1                 |
    And I log in as "admin"
    And I visit "/mod/booking/pricecategories.php"
    And I set the field "pricecategoryname[0]" to "DefPrice"
    And I set the field "defaultvalue[0]" to "30"
    And I press "Add price category"
    And I set the field "pricecategoryidentifier[1]" to "2ndprice"
    And I set the field "pricecategoryname[1]" to "2ndPrice"
    And I set the field "defaultvalue[1]" to "40"
    And I set the field "pricecatsortorder[1]" to "2"
    And I press "Save changes"
    ## Validate the price categories
    And I reload the page
    And the field "pricecategoryidentifier[0]" matches value "default"
    And the field "pricecategoryname[0]" matches value "DefPrice"
    And the field "defaultvalue[0]" matches value "30"
    And the field "pricecatsortorder[0]" matches value "1"
    And the field "pricecategoryidentifier[1]" matches value "2ndprice"
    And the field "pricecategoryname[1]" matches value "2ndPrice"
    And the field "defaultvalue[1]" matches value "40"
    And the field "pricecatsortorder[1]" matches value "2"
    And I set the field "disablepricecategory[1]" to "1"
    And I press "Save changes"
    And I reload the page
    And the field "disablepricecategory[1]" matches value "1"

  @javascript
  Scenario: Booking settings: control presence of strings on all settings pages
    Given I log in as "admin"
    And I visit "/admin/search.php#linkmodules"
    And I wait "1" seconds
    And I visit "/mod/booking/optionformconfig.php?cmid=0"
    And I wait "1" seconds
    And I visit "/mod/booking/customfield.php"
    And I wait "1" seconds
    ## Recommended from G.M.
    And I visit "/admin/webservice/testclient.php"
    And I wait "1" seconds
    And I visit "/admin/webservice/documentation.php"
    And I wait "1" seconds
    And I visit "/cache/admin.php"
    And I wait "1" seconds
    And I visit "/admin/tool/behat/index.php"
    And I set the field "component" to "behat_mod_booking"
    And I press "Filter"
    And I should see "Create booking option in booking instance" in the ".steps-definitions .step" "css_element"
    ## Already tested in other feature/
    ##And I visit "/mod/booking/instancetemplatessettings.php"
    ##And I visit "/mod/booking/semesters.php"
    ##And I visit "/mod/booking/pricecategories.php"
    ##And I visit "/mod/booking/edit_rules.php"
    ##And I visit "/mod/booking/edit_campaigns.php"
    ##And I visit "/admin/category.php?category=modbookingfolder"
    ##And I visit "/admin/settings.php?section=modsettingbooking"
    ## Recommended admin pages
    And I log out

  @javascript
  Scenario: Booking settings: control deprecated email templates
    Given the following config values are set as admin:
      | config                 | value | plugin      |
      | uselegacymailtemplates | 1     | mod_booking |
    And I am on the "My booking" Activity page logged in as admin
    And I follow "Settings"
    And I should see "E-mail settings" in the "#id_emailsettings" "css_element"
    And I should see "Deprecated" in the "#id_emailsettings" "css_element"
    And I expand all fieldsets
    And I should see "Booking confirmation" in the "#id_emailsettings" "css_element"
    And I should see "Teacher notification before start" in the "#id_emailsettings" "css_element"
    And I should see "Status change message" in the "#id_emailsettings" "css_element"
    ## The only way to remove setting for some reason
    And I visit "/admin/category.php?category=modbookingfolder"
    And I set the field "Still use legacy mail templates" to ""
    And I press "Save"
    And I am on the "My booking" Activity page
    And I follow "Settings"
    And I wait until the page is ready
    And "#id_emailsettings" "css_element" should not exist

  @javascript
  Scenario: Booking settings - display link to Moodle course on booked button
    Given the following "mod_booking > options" exist:
      | booking    | text         | course | description  | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | teachersforoption |
      | My booking | LinkOnBooked | C1     | Option deskr | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | teacher1          |
    And the following "mod_booking > answers" exist:
      | booking    | option       | user     |
      | My booking | LinkOnBooked | student1 |
    And I am on the "My booking" Activity page logged in as student1
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    And I log in as "admin"
    And I set the following administration settings values:
      | Show Link to Moodle course directly on booked button |  |
    And I log out
    And I am on the "My booking" Activity page logged in as student1
    And I should see "Booked" in the ".allbookingoptionstable_r1" "css_element"
