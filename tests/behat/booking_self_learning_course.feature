@mod @mod_booking @booking_self_learning_course
Feature: Configure and validate self-learning course feature for booking option
  As a teacher
  I need to configure self-learning course sonnection settins for booking options
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
  Scenario: Booking Self-learning: connect existing course and enroll users
    Given the following config values are set as admin:
      | config                           | value | plugin  |
      | linktomoodlecourseonbookedbutton | 1     | booking |
      | selflearningcourseactive         | 1     | booking |
    And the following "mod_booking > options" exist:
      | booking    | text         | description  | chooseorcreatecourse | course | limitanswers | maxanswers | teachersforoption |
      | My booking | SelfLearning | SelfLearning | 1                    | C2     | 0            | 0          | teacher1          |
    ## enrolmentstatus: 0 enrol at coursestart; 1 enrolment done; 2 immediately enrol
    ## Configure self-learning option
    And I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Moodle course"
    And I set the field "Self-learning course" to "checked"
    And I wait "1" seconds
    And I set the field "duration[number]" to "3"
    And I set the field "duration[timeunit]" to "seconds"
    And I should not see "Enrol users at course start time"
    And I follow "Dates"
    And I should see "Sorting date"
    And I should not see "Add date"
    And I press "Save"
    And I log out
    ## Verify self-enrollment
    And I am on the "My booking" Activity page logged in as student1
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Start" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Course 2" in the "#page-header" "css_element"
    And I should see "General" in the ".course-content" "css_element"
    ## Verify ending of self-enrollment
    And I wait "3" seconds
    And I reload the page
    And I should see "Course 2"
    And I follow "Course 2"
    And I should see "You cannot enrol yourself in this course."
