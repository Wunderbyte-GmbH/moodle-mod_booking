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

  ##@javascript
  ##Scenario: Settings - create two semester settings and see it in booking options
  ##  Given I log in as "admin"
  ##  And I visit "/admin/category.php?category=modbookingfolder"
  ##  And I follow "Booking: Semesters"
  ##  ## And I press "Add semester"
  ##  And I set the following fields to these values:
  ##    | semesteridentifier[0]   | nextmay            |
  ##    | semestername[0]         | Next May           |
  ##    | semesterstart[0][day]   | 1                  |
  ##    | semesterstart[0][month] | May                |
  ##    | semesterstart[0][year]  | ## + 1 year ##%Y## |
  ##    | semesterend[0][day]     | 31                 |
  ##    | semesterend[0][month]   | May                |
  ##    | semesterend[0][year]    | ## + 1 year ##%Y## |
  ##  ## Need to overrider potential bug:
  ##  And I set the field "semesterend[0][day]" to "31"
  ##  And I press "Add semester"
  ##  And I set the following fields to these values:
  ##    | semesteridentifier[1]   | nextjune           |
  ##    | semestername[1]         | Next June          |
  ##    | semesterstart[1][day]   | 1                  |
  ##    | semesterstart[1][month] | June               |
  ##    | semesterstart[1][year]  | ## + 1 year ##%Y## |
  ##    | semesterend[1][day]     | 30                 |
  ##    | semesterend[1][month]   | June               |
  ##    | semesterend[1][year]    | ## + 1 year ##%Y## |
  ##  ## Need to overrider potential bug:
  ##  And I set the field "semesterend[1][day]" to "30"
  ##  And I press "Save changes"
  ##  Then I should see "Semester 1"
  ##  And the following fields match these values:
  ##    | semesteridentifier[0]   | nextjune           |
  ##    | semestername[0]         | Next June          |
  ##    | semesterstart[0][day]   | 1                  |
  ##    | semesterstart[0][month] | June               |
  ##    | semesterstart[0][year]  | ## + 1 year ##%Y## |
  ##    | semesterend[0][day]     | 30                 |
  ##    | semesterend[0][month]   | June               |
  ##    | semesterend[0][year]    | ## + 1 year ##%Y## |
  ##  And I should see "Semester 2"
  ##  And the following fields match these values:
  ##    | semesteridentifier[1]   | nextmay            |
  ##    | semestername[1]         | Next May           |
  ##    | semesterstart[1][day]   | 1                  |
  ##    | semesterstart[1][month] | May                |
  ##    | semesterstart[1][year]  | ## + 1 year ##%Y## |
  ##    | semesterend[1][day]     | 31                 |
  ##    | semesterend[1][month]   | May                |
  ##    | semesterend[1][year]    | ## + 1 year ##%Y## |
  ##  And I log out
  ##  Given I log in as "teacher1"
  ##  When I am on "Course 1" course homepage
  ##  And I follow "My booking"
  ##  And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
  ##  And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
  ##  And I follow "Dates"
  ##  And I should see "Next May (nextmay)" in the "#id_datesheadercontainer .form-autocomplete-selection" "css_element"
  ##  And I expand the "Select time period" autocomplete
  ##  ## And I open the autocomplete suggestions list in the "#id_datesheadercontainer" "css_element"
  ##  And I wait "1" seconds
  ##  And I should see "Next June (nextjune)" in the "#id_datesheadercontainer .form-autocomplete-suggestions" "css_element"
  @javascript
  Scenario: Booking settings - use semester in booking option
    Given I log in as "admin"
    And the following "mod_booking > semesters" exist:
      | identifier | name      | startdate                         | enddate                          |
      | nextjune   | Next June | ## first day of June next year ## | ## last day of June next year ## |
    When I am on the "My booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Dates"
    And I should see "Next June (nextjune)" in the "#id_datesheadercontainer .form-autocomplete-selection" "css_element"
    And I set the following fields to these values:
      | Booking option name   | Option - Test Semester |
      | Select time period    | Next June (nextjune)   |
      | reoccurringdatestring | Friday, 13:00-14:00    |
    And I press "Create date series"
    And I wait "1" seconds
    And I should see "## + 1 year ##%Y##" in the ".reoccurringdates" "css_element"
    And I should see "1:00 PM - 2:00 PM" in the ".reoccurringdates" "css_element"
    And I should see "Friday, 7" in the ".reoccurringdates" "css_element"
    And I should see "Friday, 14" in the ".reoccurringdates" "css_element"
    And I press "Save and go back"
    And I wait until the page is ready
    Then I should see "Option - Test Semester" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Friday, 13:00-14:00" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Show dates" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I wait "1" seconds
    And I should see "## + 1 year ##%Y##" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "1:00 PM - 2:00 PM" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "7 June" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "14 June" in the ".allbookingoptionstable_r1" "css_element"

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
      | booking    | text                      | course | description  | startendtimeknown | coursestarttime  | courseendtime | optiondatestart[0] | optiondateend[0] | teachersforoption |
      | My booking | Booking option - Teachers | C1     | Option deskr | 1                 | ## yesterday ##  | ## +4 days ## | ## tomorrow ##     | ## +2 days ##    | teacher1          |
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
      | booking    | text                      | course | description  | startendtimeknown | coursestarttime  | courseendtime | optiondatestart[0] | optiondateend[0] | teachersforoption |
      | My booking | Booking option - Teachers | C1     | Option deskr | 1                 | ## yesterday ##  | ## +4 days ## | ## tomorrow ##     | ## +2 days ##    | teacher1          |
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
