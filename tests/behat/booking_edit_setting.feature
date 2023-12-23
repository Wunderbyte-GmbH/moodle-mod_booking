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
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And I create booking option "New option" in "My booking"

  @javascript
  Scenario: Edit booking instance settings
    Given I am on the "My booking" Activity page logged in as teacher1
    Then I follow "Settings"
    And I set the following fields to these values:
      | pollurl | https://example.com |
    And I set the field "Send confirmation e-mail" to "Yes"
    And I set the following fields to these values:
      | Booking confirmation          | {bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option) |
      | Max current bookings per user | 30                                                                                                    |
    And I press "Save and display"

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
    And I wait until the page is ready
    And I set the field "Event type" to "Sport class"
    And I set the field "showlistoncoursepage" to "Hide extra information on course page"
    Then I should not see "Short info"
    And I press "Save and return to course"
    And I wait until the page is ready
    And I should not see "My booking description"
    And I follow "My booking"
    And I follow "Settings"
    And I wait until the page is ready
    And I set the field "showlistoncoursepage" to "Show course name, short info and a button redirecting to the available booking options"
    And I set the field "Short info" to "Click on View available options, choose a booking option and click Book now"
    And I press "Save and return to course"
    Then I should see "Course 1" in the ".section .modtype_booking .coursename" "css_element"
    And I should see "Sport class" in the ".section .modtype_booking .eventtype" "css_element"
    And I should see "Click on View available options, choose a booking option and click Book now" in the ".section .modtype_booking .shortinfo" "css_element"

  @javascript
  Scenario: Booking settings - create semester
    Given I log in as "admin"
    And I visit "/admin/category.php?category=modbookingfolder"
    And I follow "Booking: Semesters"
    And I set the following fields to these values:
      | semesteridentifier[0]   | nextjune           |
      | semestername[0]         | Next June          |
      | semesterstart[0][day]   | 1                  |
      | semesterstart[0][month] | June               |
      | semesterstart[0][year]  | ## + 1 year ##%Y## |
      | semesterend[0][day]     | 30                 |
      | semesterend[0][month]   | June               |
      | semesterend[0][year]    | ## + 1 year ##%Y## |
    ## Need to overrider potential bug:
    And I set the field "semesterend[0][day]" to "30"
    And I press "Save changes"
    Then I should see "Semester 1"
    And the following fields match these values:
      | semesteridentifier[0]   | nextjune           |
      | semestername[0]         | Next June          |
      | semesterstart[0][day]   | 1                  |
      | semesterstart[0][month] | June               |
      | semesterstart[0][year]  | ## + 1 year ##%Y## |
      | semesterend[0][day]     | 30                 |
      | semesterend[0][month]   | June               |
      | semesterend[0][year]    | ## + 1 year ##%Y## |
    And I log out

  @javascript
  Scenario: Booking settings - access the teacher pages without login
    Given the following "mod_booking > options" exist:
      | booking    | text                      | course | description  | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 | teachersforoption |
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
    Then I should see "Teacher 1" in the ".page-allteachers-card" "css_element"
    And I follow "Teacher"
    And I should see "Teacher 1" in the ".card-title" "css_element"

  @javascript
  Scenario: Booking settings - display teachers email pages without login
    Given the following "mod_booking > options" exist:
      | booking    | text                      | course | description  | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 | teachersforoption |
      | My booking | Booking option - Teachers | C1     | Option deskr | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | teacher1          |
    And I log in as "admin"
    And I set the following administration settings values:
      | Login for teacher pages not necessary             | 1 |
      | Always show teacher's email addresses to everyone |   |
    And I log out
    When I visit "/mod/booking/teachers.php"
    And I wait until the page is ready
    Then I should see "Teacher 1" in the ".page-allteachers-card" "css_element"
    And I should not see "Mail" in the ".page-allteachers-card" "css_element"
    And I follow "Teacher"
    And I should see "Teacher 1" in the ".card-title" "css_element"
    And I should not see "teacher1@example.com" in the ".card-title" "css_element"
    And I log in as "admin"
    And I set the following administration settings values:
      | Always show teacher's email addresses to everyone | 1 |
    And I press "Save changes"
    And I log out
    And I visit "/mod/booking/teachers.php"
    And I wait until the page is ready
    And I should see "Teacher 1" in the ".page-allteachers-card" "css_element"
    And I should see "Mail" in the ".page-allteachers-card" "css_element"
    And I follow "Teacher"
    And I should see "Teacher 1" in the ".card-title" "css_element"
    And I should see "teacher1@example.com" in the ".card-body" "css_element"

  @javascript
  Scenario: Booking settings - hide branding info
    Given I log in as "admin"
    When I set the following administration settings values:
      | Do not show Wunderbyte logo und link | |
    And I am on the "My booking" Activity page
    And I should see "Booking module created by Wunderbyte GmbH" in the "#region-main" "css_element"
    And I set the following administration settings values:
      | Do not show Wunderbyte logo und link | 1 |
    And I am on the "My booking" Activity page
    Then I should not see "Booking module created by Wunderbyte GmbH" in the "#region-main" "css_element"
