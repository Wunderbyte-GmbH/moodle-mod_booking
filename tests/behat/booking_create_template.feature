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
    And I create booking option "New option" in "My booking"

  @javascript
  Scenario: Add booking template
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I should see "My booking"
    And I follow "My booking"
    ## And I follow "Actions menu"
    And I follow "New booking option"
    And I set the following fields to these values:
      | Booking option name | New option - Webinar |
    Then I click on "Start and end time of course are known" "checkbox"
    Then I set the field "Add to course calendar" to "Add to calendar (visible only to course participants)"
    And I set the following fields to these values:
      | coursestarttime[day]    | 31       |
      | coursestarttime[month]  | December |
      | coursestarttime[year]   | 2022     |
      | coursestarttime[hour]   | 09       |
      | coursestarttime[minute] | 00       |
    And I set the following fields to these values:
      | courseendtime[day]    | 31       |
      | courseendtime[month]  | December |
      | courseendtime[year]   | 2023     |
      | courseendtime[hour]   | 09       |
      | courseendtime[minute] | 00       |
    Then I set the field "Add as template" to "Use as global template"
    And I press "Save and go back"
    ## And I follow "Actions menu"
    And I follow "New booking option"
    And I set the following fields to these values:
      | Populate from template | New option - Webinar         |
      | Booking option name    | Option created from template |
    And I press "Save and go back"
    Then I should see "Option created from template"
