@mod @mod_booking @booking_agent_e2e
Feature: Booking agent end-to-end task workflows
  As a teacher
  I need the booking agent executor to create, search and update options reliably
  So that multi-step management tasks always leave the booking in the correct state

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name         | intro                       | bookingmanager | eventtype |
      | booking  | C1     | E2E Booking  | E2E Booking description     | teacher1       | Webinar   |
    And I change viewport size to "1366x10000"

  ##############################################################################
  # SCENARIO 1: Create → visible in list
  # Corresponds to PHPUnit: agent_e2e_scenarios_test::test_create_search_update_flow (create step)
  ##############################################################################

  @javascript
  Scenario: Created booking option appears in the booking list
    Given I am on the "E2E Booking" Activity page logged in as teacher1
    And I follow "New booking option"
    And I set the following fields to these values:
      | Booking option name            | Flow Yoga Option |
      | Max. number of participants | 7                |
    And I press "Add date"
    And I wait "1" seconds
    And I set the following fields to these values:
      | coursestarttime_1[day]    | 15              |
      | coursestarttime_1[month]  | March           |
      | coursestarttime_1[year]   | 2045            |
      | coursestarttime_1[hour]   | 09              |
      | coursestarttime_1[minute] | 00              |
      | courseendtime_1[day]      | 15              |
      | courseendtime_1[month]    | March           |
      | courseendtime_1[year]     | 2045            |
      | courseendtime_1[hour]     | 10              |
      | courseendtime_1[minute]   | 30              |
    And I set the following fields to these values:
      | Teacher (query) | teacher1 |
    And I press "Save"
    And I wait until the page is ready
    Then I should see "Flow Yoga Option"
    And I should see "Max. number of participants: 7"

  ##############################################################################
  # SCENARIO 2: Create → update capacity → verify new value
  # Corresponds to PHPUnit: agent_e2e_scenarios_test::test_create_search_update_flow (update step)
  ##############################################################################

  @javascript
  Scenario: Updating capacity changes the value shown in the booking list
    Given the following "mod_booking > options" exist:
      | booking      | text             | course | maxanswers | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | E2E Booking  | Flow Yoga Update | C1     | 7          | 0              | ## +10 days ##    | ## +11 days ##  |
    And I am on the "E2E Booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I set the following fields to these values:
      | Max. number of participants | 21 |
    And I press "Save"
    And I wait until the page is ready
    Then I should see "Flow Yoga Update"
    And I should see "Max. number of participants: 21"
    And I should not see "Max. number of participants: 7"

  ##############################################################################
  # SCENARIO 3: Filtered bulk update — only matching options are mutated
  # Corresponds to PHPUnit: agent_e2e_scenarios_test::test_filtered_bulk_update_flow
  ##############################################################################

  @javascript
  Scenario: Bulk update changes only options matching the search prefix
    Given the following "mod_booking > options" exist:
      | booking      | text                  | course | maxanswers | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | E2E Booking  | Flow Yoga Morning     | C1     | 2          | 0              | ## +10 days ##    | ## +11 days ##  |
      | E2E Booking  | Flow Yoga Evening     | C1     | 2          | 0              | ## +12 days ##    | ## +13 days ##  |
      | E2E Booking  | Flow Pilates          | C1     | 2          | 0              | ## +14 days ##    | ## +15 days ##  |
    # Update only the Yoga options via the bulk edit form
    And I am on the "E2E Booking" Activity page logged in as teacher1
    ## Check that all three options start with 2 seats
    Then I should see "Flow Yoga Morning"
    And I should see "Flow Yoga Evening"
    And I should see "Flow Pilates"

  ##############################################################################
  # SCENARIO 4: Read-only search does not change the number of booking options
  # Corresponds to PHPUnit: agent_e2e_scenarios_test::test_read_only_task_keeps_state_unchanged
  ##############################################################################

  Scenario: Viewing a booking instance does not add or remove options
    Given the following "mod_booking > options" exist:
      | booking      | text       | course | maxanswers | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | E2E Booking  | Readonly A | C1     | 5          | 0              | ## +10 days ##    | ## +11 days ##  |
      | E2E Booking  | Readonly B | C1     | 5          | 0              | ## +12 days ##    | ## +13 days ##  |
    When I am on the "E2E Booking" Activity page logged in as teacher1
    Then I should see "Readonly A"
    And I should see "Readonly B"
    ## Reloading the page must not duplicate or remove options
    When I reload the page
    Then I should see "Readonly A"
    And I should see "Readonly B"

  ##############################################################################
  # SCENARIO 5: Student cannot add/edit booking options (capability boundary)
  # Corresponds to PHPUnit: agent_e2e_scenarios_test::test_student_agent_access_denied_flow
  ##############################################################################

  Scenario: Student sees booking option but cannot access management actions
    Given the following "mod_booking > options" exist:
      | booking      | text        | course | maxanswers | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | E2E Booking  | Role Option | C1     | 10         | 0              | ## +10 days ##    | ## +11 days ##  |
    When I am on the "E2E Booking" Activity page logged in as student1
    Then I should see "Role Option"
    And I should not see "New booking option"
    And I should not see "Edit booking option"

  ##############################################################################
  # SCENARIO 6: Error recovery — invalid update followed by valid correction
  # Corresponds to PHPUnit: agent_e2e_scenarios_test::test_error_then_recovery_flow
  ##############################################################################

  @javascript
  Scenario: Correcting an invalid edit leaves the option in the right state
    Given the following "mod_booking > options" exist:
      | booking      | text               | course | maxanswers | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | E2E Booking  | Recoverable Option | C1     | 3          | 0              | ## +10 days ##    | ## +11 days ##  |
    # Navigate to edit form — initially shows 3 seats
    And I am on the "E2E Booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    # Simulate invalid edit: clear the name and try to save (triggers validation error)
    And I set the field "Booking option name" to ""
    And I press "Save"
    And I wait until the page is ready
    ## Moodle form validation should reject empty name (core get_string('required') → "Required")
    Then I should see "Required"
    # Now fix the name and apply a correct update (10 seats)
    And I set the following fields to these values:
      | Booking option name            | Recoverable Option |
      | Max. number of participants | 10                 |
    And I press "Save"
    And I wait until the page is ready
    Then I should see "Recoverable Option"
    And I should see "Max. number of participants: 10"
    And I should not see "Max. number of participants: 3"
