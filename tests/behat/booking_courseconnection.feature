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
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | autoenrol |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | 1         |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking courseconnection: connect existing course and enroll users immediately
    Given the following "mod_booking > options" exist:
      | booking    | text         | course | description  | chooseorcreatecourse | course | enrolmentstatus | limitanswers | maxanswers | teachersforoption | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 |
      | My booking | Enroll_later | C1     | Enroll_later | 1                    | C2     | 0               | 0            | 0          | teacher1          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | My booking | Enroll_now   | C1     | Enroll_now   | 1                    | C3     | 2               | 0            | 0          | teacher1          | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
    ## enrolmentstatus: 0 enrol at coursestart; 1 enrolment done; 2 immediately enrol
    And the following "mod_booking > answers" exist:
      | booking    | option       | user     |
      | My booking | Enroll_later | student1 |
    And I am on the "My courses" page logged in as student1
    And I should see "Course 1" in the "#region-main" "css_element"
    And I should not see "Course 2" in the "#region-main" "css_element"
    And I log out
    And I am on the "My booking" Activity page logged in as student2
    When I click on "Book now" "text" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r2" "css_element"
    Then I should see "Booked" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Go to Moodle course" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "Course 3" in the "#page-header" "css_element"
    And I should see "Topic 1" in the ".course-content" "css_element"
