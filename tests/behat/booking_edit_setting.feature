@mod @mod_booking @booking_edit_setting
Feature: In a booking edit settings
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
      | admin    | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And I create booking option "New option" in "My booking"

  @javascript
  Scenario: Edit Settings
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I follow "My booking"
    And I should see "New booking option"
    ## And I follow "Actions menu"
    And I follow "Settings"
    And I set the following fields to these values:
      | pollurl | https://example.com |
    Then I set the field "Send confirmation e-mail" to "Yes"
    And I set the field "" to ""
    And I should see "Booking confirmation"
    And I set the following fields to these values:
      | Booking confirmation          | {bookingdetails} - Detailed summary of the booking option (incl. sessions und link to booking option) |
      | Max current bookings per user | 30                                                                                                    |
    And I press "Save and display"
