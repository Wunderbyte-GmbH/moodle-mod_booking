@mod @mod_booking @booking_ticket
Feature: As admin - configure entry tickets on a booking option and let a user find their tickets.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0         | 1               |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
    And the following certificate templates exist:
      | name              |
      | Ticket design one |
    And the following config values are set as admin:
      | config           | value | plugin  |
      | bookingticketon  | 1     | booking |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name        | intro               | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingTick | Booking description | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking     | text        | course | description  | importing | maxanswers |
      | BookingTick | TicketOptn1 | C1     | Entry ticket | 1         | 5          |

  @javascript
  Scenario: Ticketing section is available in the booking option form when the feature is on
    Given I log in as "admin"
    And I open the new booking option page for booking "BookingTick"
    And I wait until the page is ready
    When I expand all fieldsets
    Then I should see "Ticketing"
    And I should see "Ticket design"
    ## The dependent settings only make sense once a design is chosen.
    And I should not see "Personalised ticket"
    And I should not see "Require identity confirmation"
    When I set the field "Ticket design" to "Ticket design one"
    Then I should see "Personalised ticket"
    And I should see "Require identity confirmation"
    And I should see "Additional ticket information"

  @javascript
  Scenario: Ticketing section is hidden when the feature is switched off
    Given the following config values are set as admin:
      | config          | value | plugin  |
      | bookingticketon | 0     | booking |
    And I log in as "admin"
    And I open the new booking option page for booking "BookingTick"
    And I wait until the page is ready
    When I expand all fieldsets
    Then I should not see "Ticket design"

  Scenario: A user reaches their tickets from the profile and sees the empty state
    Given I log in as "student1"
    And I am on the "student1" "user > profile" page
    When I follow "My tickets"
    Then I should see "My tickets"
    And I should see "You do not have any tickets yet."

  Scenario: The my tickets link is gone when the feature is switched off
    Given the following config values are set as admin:
      | config          | value | plugin  |
      | bookingticketon | 0     | booking |
    And I log in as "student1"
    When I am on the "student1" "user > profile" page
    Then I should not see "My tickets"
