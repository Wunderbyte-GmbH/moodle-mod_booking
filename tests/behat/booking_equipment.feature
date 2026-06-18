@mod @mod_booking @booking_equipment
Feature: Book equipment that is available at a location on a booking option
  As a teacher I want to choose equipment offered at the option's location and book a quantity of it

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name      | intro | bookingmanager | eventtype |
      | booking  | C1     | BookingEq | desc  | teacher1       | Webinar   |
    And the following "mod_booking > options" exist:
      | booking   | text          | course | description | teachersforoption | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingEq | Option: equip | C1     | Equip       | teacher1          | 5          | 0              | 0              | 2346937200        | 2347110000      |
    ## 2044/05/15 - 2044/05/17
    And the following "local_entities > entities" exist:
      | name    | shortname | entitytype | parent | allocationmode | capacitysource | maxallocation |
      | Gym     | gym       | location   |        |                |                |               |
      | Beamers | beamers   | equipment  | Gym    | capacity       | manual         | 2             |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Equipment fields appear for the chosen location and the quantity persists
    Given I am on the "BookingEq" Activity page logged in as admin
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I set the following fields to these values:
      | Entity | Gym |
    And I press "Show equipment for the selected location"
    Then I should see "Quantity: Beamers"
    And I set the field "Quantity: Beamers" to "1"
    And I press "Save"
    ## Re-open the option and verify the equipment quantity was stored.
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I press "Show equipment for the selected location"
    Then the field "Quantity: Beamers" matches value "1"
