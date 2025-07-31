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
      | rcp5     | RCP       | 5        | rcp5@example.com     | RCP5     |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
      | Course 2 | C2        | 0        | 1                |
      | Course 3 | C3        | 0        | 1                |
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
      | activity | course | name      | intro               | bookingmanager | eventtype | autoenrol | Default view for booking options | Booking option name  |
      | booking  | C1     | MyBooking | Booking description | admin1         | Webinar   | 1         | All bookings                     | New option - Webinar |
    ## "autoenrol = 1" is required to enroll in the connected course
    And the following "mod_booking > options" exist:
      | booking   | text                    | course | description  | teachersforoption | responsiblecontact  | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | MyBooking | Option1: RCP only       | C1     | Option deskr |                   | rcp1,rcp2,rcp3,rcp4 | 0              | 0              | 2346937200        | 2347110000      |
      | MyBooking | Option2: Teachers only  | C1     | Option deskr | teacher2,teacher1 |                     | 0              | 0              | 2347110000        | 2347282800      |
      | MyBooking | Option3: Teachers & RCP | C1     | Option deskr | teacher3,teacher1 | rcp1,rcp4,rcp5      | 0              | 0              | 2347369200        | 2347542000      |
    ## 2044/05/15 - 2044/05/17
    ## 2044/05/17 - 2044/05/19
    ## 2044/05/20 - 2044/05/22
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking option: validate list of responsible contact persons
    ## Validate if responsible contact persons can edit booking options
    Given the following config values are set as admin:
      | config                    | value | plugin  |
      | responsiblecontactcanedit | 1     | booking |
    ## Validate rcp with no role (should not happens in reality)
    And I am on the "MyBooking" Activity page logged in as rcp5
    And I should see "You cannot enrol yourself in this course"
    ## Validate rcp with "manager" role
    And I am on the "MyBooking" Activity page logged in as rcp4
    And "//div[contains(@class, 'allbookingoptionstable_r1')]//i[contains(@class, 'fa-pen')]" "xpath_element" should exist
    And "//div[contains(@class, 'allbookingoptionstable_r2')]//i[contains(@class, 'fa-pen')]" "xpath_element" should exist
    And "//div[contains(@class, 'allbookingoptionstable_r3')]//i[contains(@class, 'fa-pen')]" "xpath_element" should exist
    ## Validate rcp with "student" role (should not happens in reality)
    And I am on the "MyBooking" Activity page logged in as rcp3
    And "//div[contains(@class, 'allbookingoptionstable_r1')]//i[contains(@class, 'fa-pen')]" "xpath_element" should not exist
    And "//div[contains(@class, 'allbookingoptionstable_r2')]//i[contains(@class, 'fa-pen')]" "xpath_element" should not exist
    And "//div[contains(@class, 'allbookingoptionstable_r3')]//i[contains(@class, 'fa-pen')]" "xpath_element" should not exist
    ## Validate rcp with "editingteacher" role
    And I am on the "MyBooking" Activity page logged in as rcp1
    And "//div[contains(@class, 'allbookingoptionstable_r1')]//i[contains(@class, 'fa-pen')]" "xpath_element" should exist
    And "//div[contains(@class, 'allbookingoptionstable_r2')]//i[contains(@class, 'fa-pen')]" "xpath_element" should not exist
    And "//div[contains(@class, 'allbookingoptionstable_r3')]//i[contains(@class, 'fa-pen')]" "xpath_element" should exist
    And I am on the "MyBooking" Activity page logged in as teacher1
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
    And I should see "RCP 1" in the ".mod-booking-row .infolist" "css_element"
    And I should see "RCP 2" in the ".mod-booking-row .infolist" "css_element"
    And I should see "RCP 3" in the ".mod-booking-row .infolist" "css_element"
    And I should see "RCP 4" in the ".mod-booking-row .infolist" "css_element"
    And I should not see "Teacher" in the ".mod-booking-row" "css_element"
    And I close all opened windows
    ## Validate oprion with teachers only
    And I click on "Option2: Teachers only" "text" in the ".allbookingoptionstable_r2" "css_element"
    And I switch to a second window
    And I should not see "RCP" in the ".mod-booking-row" "css_element"
    And I should see "Teacher 1" in the ".mod-booking-row" "css_element"
    And I should see "Teacher 2" in the ".mod-booking-row" "css_element"
    And I close all opened windows
    ## Validate oprion with teachers and rcps
    And I click on "Option3: Teachers & RCP" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I switch to a second window
    And I should see "RCP 1" in the ".mod-booking-row .infolist" "css_element"
    And I should see "RCP 4" in the ".mod-booking-row .infolist" "css_element"
    And I should not see "Teacher 1" in the ".mod-booking-row .infolist" "css_element"
    And I should not see "Teacher 3" in the ".mod-booking-row .infolist" "css_element"
    And I should see "Teacher 1" in the ".mod-booking-row" "css_element"
    And I should see "Teacher 3" in the ".mod-booking-row" "css_element"
    And I close all opened windows
    And I log out

  @javascript
  Scenario: Booking option: manage responsible contact persons for courseconnection
    Given the following config values are set as admin:
      | config                          | value | plugin  |
      | responsiblecontactenroltocourse | 1     | booking |
    And I log in as "admin"
    And I set the following administration settings values:
      | definedresponsiblecontactrole | Non-editing teacher |
    ## New behavior - direct link to the connected course
    And the following "mod_booking > options" exist:
      | booking   | text         | description       | importing | chooseorcreatecourse | course | enrolmentstatus | limitanswers | maxanswers | teachersforoption | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | MyBooking | Option4: new | Enrol_now-new     | 1         | 2                    | C1     | 0               | 0            | 0          | teacher1          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | MyBooking | Option5: CC3 | Enrol_now-existed | 1         | 1                    | C3     | 2               | 0            | 0          | teacher1          | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
    ## enrolmentstatus: 0 enrol at coursestart; 1 enrolment done; 2 immediately enrol
    And I am on the "MyBooking" Activity page
    ## Add 2 RCPs and validate enrolments
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r5" "css_element"
    And I follow "Responsible contact(s)"
    And I set the field "Responsible contact(s)" to "rcp2@example.com,rcp3@example.com"
    And I press "Save"
    And I am on "Course 3" course homepage
    And I follow "Participants"
    And the following should exist in the "participants" table:
      | Email address    | Roles               | Status |
      | rcp2@example.com | Non-editing teacher | Active |
      | rcp3@example.com | Non-editing teacher | Active |
    ## Remove 1 RCP, leave another 1 and validate enrolment / unenrolment
    And I am on the "MyBooking" Activity page
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r5" "css_element"
    And I follow "Responsible contact(s)"
    And I set the field "Responsible contact(s)" to "rcp2@example.com"
    And I press "Save"
    And I am on "Course 3" course homepage
    And I follow "Participants"
    And the following should exist in the "participants" table:
      | Email address    | Roles               | Status |
      | rcp2@example.com | Non-editing teacher | Active |
    And the following should not exist in the "participants" table:
      | Email address    |
      | rcp3@example.com |
    And I log out

  @javascript
  Scenario: Booking option: manage responsible contact persons for newly created connected course
    Given the following config values are set as admin:
      | config                          | value | plugin  |
      | responsiblecontactenroltocourse | 1     | booking |
    And I log in as "admin"
    And I set the following administration settings values:
      | definedresponsiblecontactrole | Non-editing teacher |
    ## New behavior - direct link to the connected course
    And the following "mod_booking > options" exist:
      | booking   | text        | description       | importing | chooseorcreatecourse | course | enrolmentstatus | limitanswers | maxanswers | teachersforoption | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | MyBooking | Option4-new | Enrol_now-new     | 1         | 2                    | C1     | 0               | 0            | 0          | teacher1          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
    ## enrolmentstatus: 0 enrol at coursestart; 1 enrolment done; 2 immediately enrol
    And I am on the "MyBooking" Activity page
    ## Add 2 RCPs and validate enrolments
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r4" "css_element"
    And I follow "Responsible contact(s)"
    And I set the field "Responsible contact(s)" to "rcp2@example.com,rcp3@example.com"
    And I press "Save"
    When I click on "Book now" "text" in the ".allbookingoptionstable_r4 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r4" "css_element"
    ##Then I should see "Booked" in the ".allbookingoptionstable_r4" "css_element"
    ## Verify enrolled immediately
    Then I click on "Start" "link" in the ".allbookingoptionstable_r4" "css_element"
    And I wait to be redirected
    And I should see "Option4-new" in the "#page-header" "css_element"
    And I follow "Participants"
    And the following should exist in the "participants" table:
      | Email address    | Roles               | Status |
      | rcp2@example.com | Non-editing teacher | Active |
      | rcp3@example.com | Non-editing teacher | Active |
    ## Remove 1 RCP, leave another 1 and validate enrolment / unenrolment
    And I am on the "MyBooking" Activity page
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r4" "css_element"
    And I follow "Responsible contact(s)"
    And I set the field "Responsible contact(s)" to "rcp2@example.com"
    And I press "Save"
    And I click on "Start" "link" in the ".allbookingoptionstable_r4" "css_element"
    And I wait to be redirected
    And I follow "Participants"
    And the following should exist in the "participants" table:
      | Email address    | Roles               | Status |
      | rcp2@example.com | Non-editing teacher | Active |
    And the following should not exist in the "participants" table:
      | Email address    |
      | rcp3@example.com |
    And I log out
