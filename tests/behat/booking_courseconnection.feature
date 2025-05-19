@mod @mod_booking @booking_courseconnection
Feature: Configure and validate different course connection settings for booking option
  As a teacher
  I need to configure course sonnection settins for booking options
  As a student
  I want to ensure of proper enrollment processes

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | teacher2 | Teacher   | 2        | teacher2@example.com | T2       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
    And the following "categories" exist:
      | name     | category | idnumber |
      | BookCat1 | 0        | BCAT1    |
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
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | autoenrol |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | 1         |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking courseconnection: connect existing course and enroll users
    Given the following config values are set as admin:
      | config                           | value | plugin  |
      | linktomoodlecourseonbookedbutton | 1     | booking |
    ## New behavior - direct link to the connected course
    And the following "mod_booking > options" exist:
      | booking    | text         | description  | importing | chooseorcreatecourse | course | enrolmentstatus | limitanswers | maxanswers | teachersforoption | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Enroll_later | Enroll_later | 1         | 1                    | C2     | 0               | 0            | 0          | teacher1          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | My booking | Enroll_now   | Enroll_now   | 1         | 1                    | C3     | 2               | 0            | 0          | teacher1          | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
    ## enrolmentstatus: 0 enrol at coursestart; 1 enrolment done; 2 immediately enrol
    ## Verify enroll later (at course start)
    And the following "mod_booking > answers" exist:
      | booking    | option       | user     |
      | My booking | Enroll_later | student1 |
    And I am on the "My courses" page logged in as student1
    And I should see "Course 1" in the "#region-main" "css_element"
    And I should not see "Enroll_later" in the "#region-main" "css_element"
    And I log out
    And I am on the "My booking" Activity page logged in as student2
    When I click on "Book now" "text" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r2" "css_element"
    Then I should see "Start" in the ".allbookingoptionstable_r2" "css_element"
    ## Verify enrolled immediately
    And I click on "Start" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Course 3" in the "#page-header" "css_element"
    And I should see "General" in the ".course-content" "css_element"

  @javascript
  Scenario: Booking courseconnection: create default empty course and enroll users
    Given the following config values are set as admin:
      | config                           | value | plugin  |
      | linktomoodlecourseonbookedbutton | 0     | booking |
    ## OLD behavior - "Booked" label and "Go to course" link to the connected course
    And the following "mod_booking > options" exist:
      | booking    | text         | description  | importing | course | chooseorcreatecourse | enrolmentstatus | limitanswers | maxanswers | teachersforoption | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Enroll_later | Enroll_later | 1         | C1     | 2                    | 0               | 0            | 0          | teacher1          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | My booking | Enroll_now   | Enroll_now   | 1         | C1     | 2                    | 2               | 0            | 0          | teacher1          | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
    ## enrolmentstatus: 0 enrol at coursestart; 1 enrolment done; 2 immediately enrol
    ## Verify enroll later (at course start)
    And the following "mod_booking > answers" exist:
      | booking    | option       | user     |
      | My booking | Enroll_later | student1 |
    And I am on the "My courses" page logged in as student1
    And I should see "Course 1" in the "#region-main" "css_element"
    And I should not see "Enroll_later" in the "#region-main" "css_element"
    And I log out
    And I am on the "My booking" Activity page logged in as student2
    When I click on "Book now" "text" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r2" "css_element"
    Then I should see "Booked" in the ".allbookingoptionstable_r2" "css_element"
    ## Verify enrolled immediately
    And I click on "Go to Moodle course" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Enroll_now" in the "#page-header" "css_element"
    And I should see "General" in the ".course-content" "css_element"

  @javascript
  Scenario: Booking courseconnection: create empty course under category and enroll users immediately
    Given the following "custom field categories" exist:
      | name    | component   | area    | itemid |
      | bookcat | mod_booking | booking | 0      |
    And the following "custom fields" exist:
      | name      | category | type | shortname | configdata[defaultvalue] |
      | coursecat | bookcat  | text | coursecat |                          |
    And the following config values are set as admin:
      | config                           | value     | plugin  |
      | newcoursecategorycfield          | coursecat | booking |
      | linktomoodlecourseonbookedbutton | 0         | booking |
    ## OLD behavior - "Booked" label and "Go to course" link to the connected course
    And the following "mod_booking > options" exist:
      | booking    | text            | description | importing | course | chooseorcreatecourse | coursecat  | enrolmentstatus | limitanswers | maxanswers | teachersforoption | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Enroll_existcat | existcat    | 1         | C1     | 2                    | BookCat1   | 2               | 0            | 0          | teacher1          | 0              | 0              | ## +1 days ##     | ## +3 days ##   |
      | My booking | Enroll_newcat   | newcat      | 1         | C1     | 2                    | NewBookCat | 2               | 0            | 0          | teacher1          | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
    And I am on the "My booking" Activity page logged in as student1
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Booked" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Go to Moodle course" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Enroll_existcat" in the "#page-header" "css_element"
    And I log out
    And I am on the "My booking" Activity page logged in as student2
    When I click on "Book now" "text" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r2" "css_element"
    Then I should see "Booked" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Go to Moodle course" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Enroll_newcat" in the "#page-header" "css_element"
    And I log out
    ## Verify is course categories are correct
    And I am logged in as admin
    And I am on the "Enroll_existcat" "course editing" page
    And I should see "BookCat1"
    And I am on the "Enroll_newcat" "course editing" page
    And I should see "NewBookCat"

  @javascript
  Scenario: Booking courseconnection: create course from template and enroll users immediately
    Given the following "tags" exist:
      | name           | isstandard |
      | optiontemplate | 1          |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion | tags           |
      | Course 4 | C4        | 0        | 1                | optiontemplate |
    And the following "activities" exist:
      | activity | name      | intro     | course | idnumber |
      | page     | TempPage1 | PageDesc1 | C4     | PAGE1    |
    And the following "custom field categories" exist:
      | name    | component   | area    | itemid |
      | bookcat | mod_booking | booking | 0      |
    And the following "custom fields" exist:
      | name      | category | type | shortname | configdata[defaultvalue] |
      | coursecat | bookcat  | text | coursecat |                          |
    And the following config values are set as admin:
      | config                           | value     | plugin  |
      | linktomoodlecourseonbookedbutton | 0         | booking |
    ## OLD behavior - "Booked" label and "Go to course" link to the connected course
      | newcoursecategorycfield          | coursecat | booking |
    ## The "templatetags" value must be set only visually OR customstep required (name to id conversion).
    And I log in as "admin"
    And I set the following administration settings values:
      | templatetags | optiontemplate |
    And I log out
    And the following "mod_booking > options" exist:
      | booking    | text            | description | importing | course | chooseorcreatecourse | coursecat  | enrolmentstatus | limitanswers | maxanswers | teachersforoption | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Enroll_newcat   | newcat      | 1         | C1     | 3                    | NewBookCat | 2               | 0            | 0          | teacher1          | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
    ## Set course template visually to ensure all above defaults are OK.
    And I am on the "My booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the following fields to these values:
      | Connected Moodle course                | Create new Moodle course from template |
      | Create new Moodle course from template | Course 4  |
    And I press "Save"
    And I log out
    # Book as student and verify course content.
    And I am on the "My booking" Activity page logged in as student1
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Booked" in the ".allbookingoptionstable_r1" "css_element"
    And I wait "1" seconds
    And I click on "Go to Moodle course" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Enroll_newcat" in the "#page-header" "css_element"
    And I should see "TempPage1" in the ".course-content" "css_element"
    And I log out
    ## Verify that course category is correct
    And I am logged in as admin
    And I am on the "Enroll_newcat" "course editing" page
    And I should see "NewBookCat"
