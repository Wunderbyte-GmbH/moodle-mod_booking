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
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     |
    And I create booking option "New option" in "My booking"
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Delete booking option as teacher
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Delete this booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Do you really want to delete this booking option New option?" in the ".modal-dialog" "css_element"
    And I click on "Delete" "button" in the ".modal-dialog" "css_element"
    And I wait until the page is ready
    And I should not see "New option"
    And I run all booking adhoc tasks
    Then the events log should contain "Booking option deleted"
    And I log out

  @javascript
  Scenario: Cancel the delete confirmation modal keeps the booking option
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Delete this booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Do you really want to delete this booking option New option?" in the ".modal-dialog" "css_element"
    When I click on "Cancel" "button" in the ".modal-dialog" "css_element"
    Then I should see "New option" in the ".allbookingoptionstable_r1" "css_element"
    And I log out

  @javascript
  Scenario: Delete booking option with booked users shows a warning in the confirmation modal
    Given the following "mod_booking > answers" exist:
      | booking    | option     | user     |
      | My booking | New option | student1 |
      | My booking | New option | student2 |
    And I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Delete this booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Do you really want to delete this booking option New option (2 users are booked)?" in the ".modal-dialog" "css_element"
    And I click on "Delete" "button" in the ".modal-dialog" "css_element"
    And I wait until the page is ready
    And I should not see "New option"
    And I log out

  @javascript
  Scenario: Delete user from booking option as teacher
    Given the following "mod_booking > answers" exist:
      | booking    | option     | user     |
      | My booking | New option | student1 |
      | My booking | New option | student2 |
    And I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Manage bookings" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Student 1"
    And I should see "Student 2"
    And I click on "selectall" "checkbox"
    And I click on "Delete responses" "button"
    And I should not see "Student 1"
    And I should not see "Student 2"
    And I run all booking adhoc tasks
    Then the events log should contain "The user \"Teacher 1 (ID:"
    And the events log should contain "cancelled \"Student 1 (ID:"
    And the events log should contain "cancelled \"Student 2 (ID:"
    And the events log should contain "from \"New option (ID:"
    And I log out
