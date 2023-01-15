@mod @mod_booking @booking_delete
Feature: In a booking delete
  As a teacher
  I need to add a booking option and event to a booking instance.

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
      | admin    | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     |
    And I create booking option "New option" in "My booking"

  @javascript
  Scenario: Delete user from Course
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I follow "My booking"
    And I follow "My booking"
    ## And I follow "Settings"
    And I click on "Settings" "icon"
    And I follow "Book other users"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Student 2 (student2@example.com)" "text"
    And I click on "Add" "button"
    And I follow "<< Back to responses"
    And I click on "selectall" "checkbox"
    And I click on "Delete responses" "button"
    Then I trigger cron
    ## Then I open the link "webserver/admin/cron.php?password=opensesame"
    And I wait "10" seconds

  @javascript @email
  Scenario: Send email for user
    Given I open the link "webserver/_/mail"
    And I should see "Connected"
    ## I can not see the sent email
    And I should see "Student 1 (via Acceptance test site)"
