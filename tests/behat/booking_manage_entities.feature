@mod @mod_booking @booking_manage_entities
Feature: In a course add a booking option and manage its entities
  As an administrator or a teacher I need to manage entities for booking options

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
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
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype |
      | booking  | C1     | BookingEnt | My booking description | teacher1       | Webinar   |
    And the following "mod_booking > options" exist:
      | booking    | text           | course | description  | teachersforoption | maxanswers | maxoverbooking | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 |
      | BookingEnt | Option: entity | C1     | Entity       | teacher1          | 5          | 5              | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
    And the following "local_entities > entities" exist:
      | name    | shortname | description |
      | Entity1 | entity1   | Ent1desc    |
      | Entity2 | entity2   | Ent2desc    |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking option: select and display entity
    Given I am on the "BookingEnt" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I set the following fields to these values:
      | Entity         | Entity2 |
    And I press "Save"
    And I should see "Entity2" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Entity2"
    And I wait until the page is ready
    And I should see "Ent2desc"
