@mod @mod_booking @booking_contact_persons
Feature: In a booking - create options with different contact persons settings and validate it

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | teacher2 | Teacher   | 2        | teacher2@example.com | T2       |
      | teacher3 | Teacher   | 3        | teacher3@example.com | T3       |
      | admin1   | Admin     | 1        | admin1@example.com   | A1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | rcp1     | RCP       | 1        | rcp1@example.com     | RCP1     |
      | rcp2     | RCP       | 2        | rcp2@example.com     | RCP2     |
      | rcp3     | RCP       | 3        | rcp3@example.com     | RCP3     |
      | rcp4     | RCP       | 4        | rcp4@example.com     | RCP4     |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | teacher2 | C1     | editingteacher |
      | teacher2 | C1     | manager        |
      | teacher3 | C1     | editingteacher |
      | teacher3 | C1     | manager        |
      | admin1   | C1     | editingteacher |
      | admin1   | C1     | manager        |
      | student1 | C1     | student        |
      | rcp1     | C1     | editingteacher |
      | rcp2     | C1     | teacher        |
      | rcp3     | C1     | student        |
      | rcp4     | C1     | manager        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name      | intro               | bookingmanager | eventtype | Default view for booking options | Booking option name  |
      | booking  | C1     | MyBooking | Booking description | admin1         | Webinar   | All bookings                     | New option - Webinar |
    And the following "mod_booking > options" exist:
      | booking   | text                    | course | description  | teachersforoption | responsiblecontact  | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | MyBooking | Option1: RCP only       | C1     | Option deskr |                   | rcp1,rcp2,rcp3,rcp4 | 0              | 0              | 2346937200        | 2347110000      |
      | MyBooking | Option2: Teachers only  | C1     | Option deskr | teacher2,teacher1 |                     | 0              | 0              | 2347110000        | 2347282800      |
      | MyBooking | Option3: Teachers & RCP | C1     | Option deskr | teacher3,teacher1 | rcp1,rcp4           | 0              | 0              | 2347369200        | 2347542000      |
    ## 2044/05/15 - 2044/05/17
    ## 2044/05/17 - 2044/05/19
    ## 2044/05/20 - 2044/05/22
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking option: validate list of responsible contact persons
    Given I am on the "MyBooking" Activity page logged in as teacher1
    ## Validate teachers and rcps on the list page
    And I should not see "Teacher" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "RCP 1" in the ".allbookingoptionstable_r1 .col-repsoniblecontact-repsonsiblecontacts-container" "css_element"
    And I should see "RCP 2" in the ".allbookingoptionstable_r1 .col-repsoniblecontact-repsonsiblecontacts-container" "css_element"
    And I should see "RCP 3" in the ".allbookingoptionstable_r1 .col-repsoniblecontact-repsonsiblecontacts-container" "css_element"
    And I should see "RCP 4" in the ".allbookingoptionstable_r1 .col-repsoniblecontact-repsonsiblecontacts-container" "css_element"
    And I should see "Teacher 1" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Teacher 2" in the ".allbookingoptionstable_r2" "css_element"
    And I should not see "RCP" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Teacher 1" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Teacher 3" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "RCP 1" in the ".allbookingoptionstable_r3 .col-repsoniblecontact-repsonsiblecontacts-container" "css_element"
    And I should see "RCP 4" in the ".allbookingoptionstable_r3 .col-repsoniblecontact-repsonsiblecontacts-container" "css_element"
    ## Validate oprion with rcps only
    And I click on "Option1: RCP only" "text" in the ".allbookingoptionstable_r1" "css_element"
    ## Onlw "switch to a second window" working and it is mandatory!
    ##And I switch to "Option1: RCP only | Acceptance test site" tab
    And I switch to a second window
    And I should see "rcp1@example.com" in the ".mod-booking-row .infolist" "css_element"
    And I should see "rcp2@example.com" in the ".mod-booking-row .infolist" "css_element"
    And I should see "rcp3@example.com" in the ".mod-booking-row .infolist" "css_element"
    And I should see "rcp4@example.com" in the ".mod-booking-row .infolist" "css_element"
    And I should not see "Teacher" in the ".mod-booking-row" "css_element"
    And I close all opened windows
    ## Validate oprion with teachers only
    And I click on "Option2: Teachers only" "text" in the ".allbookingoptionstable_r2" "css_element"
    And I switch to a second window
    And I should see "teacher1@example.com" in the ".mod-booking-row .infolist" "css_element"
    And I should not see "teacher2@example.com" in the ".mod-booking-row .infolist" "css_element"
    And I should not see "RCP" in the ".mod-booking-row" "css_element"
    And I should see "Teacher 1" in the ".mod-booking-row" "css_element"
    And I should see "Teacher 2" in the ".mod-booking-row" "css_element"
    And I close all opened windows
    ## Validate oprion with teachers and rcps
    And I click on "Option3: Teachers & RCP" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I switch to a second window
    And I should see "rcp1@example.com" in the ".mod-booking-row .infolist" "css_element"
    And I should see "rcp4@example.com" in the ".mod-booking-row .infolist" "css_element"
    And I should not see "teacher1@example.com" in the ".mod-booking-row .infolist" "css_element"
    And I should not see "teacher3@example.com" in the ".mod-booking-row .infolist" "css_element"
    And I should see "Teacher 1" in the ".mod-booking-row" "css_element"
    And I should see "Teacher 3" in the ".mod-booking-row" "css_element"
    And I close all opened windows
    And I log out
