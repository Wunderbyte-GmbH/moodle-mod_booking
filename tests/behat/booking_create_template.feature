@mod @mod_booking @booking_create_template
Feature: In a booking create a template
  As a teacher
  I need to add booking and event to a booking.

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
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                      |

  @javascript
  Scenario: Booking option template: create one and use it to create new option
    Given I am on the "My booking" Activity page logged in as teacher1
    And I follow "New booking option"
    And I set the following fields to these values:
      | Booking option name | New option - Template |
    And I click on "Start and end time of course are known" "checkbox"
    And I set the field "Add to course calendar" to "Add to calendar (visible only to course participants)"
    And I set the following fields to these values:
      | coursestarttime[day]    | ##tomorrow##%d## |
      | coursestarttime[month]  | ##tomorrow##%B## |
      | coursestarttime[year]   | ##tomorrow##%Y## |
    And I set the following fields to these values:
      | courseendtime[day]    | ##tomorrow##%d##     |
      | courseendtime[month]  | ##tomorrow##%B##     |
      | courseendtime[year]   | ## + 1 year ## %Y ## |
    And I set the field "Add as template" to "Use as global template"
    And I press "Save and go back"
    And I wait until the page is ready
    And I follow "New booking option"
    And I set the following fields to these values:
      | Populate from template | New option - Template        |
      | Booking option name    | Option created from template |
    And I press "Save and go back"
    Then I should see "Option created from template"
