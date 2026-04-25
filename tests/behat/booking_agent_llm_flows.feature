@mod @mod_booking @booking_agent_llm
Feature: Booking agent LLM integration workflows via AI chat
  As a teacher
  I need to create and update booking options using natural language via the AI agent
  So that I can manage bookings through conversational commands

  ##############################################################################
  # NOTE: Scenarios tagged @real_llm require a live LLM API connection.
  # They are skipped in standard CI runs and are only activated when
  # BOOKING_AI_REAL_LLM=1 is set in the environment.
  ##############################################################################

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
      | booking  | C1     | LLM Booking  | LLM Booking description     | teacher1       | Webinar   |
    And I change viewport size to "1366x10000"

  ##############################################################################
  # UI RENDERING (no LLM needed)
  # Verifies that the AI chat interface is accessible and rendered correctly
  ##############################################################################

  @javascript
  Scenario: AI chat interface renders all required elements for teacher
    Given I am on the AI instructions page for booking "LLM Booking" logged in as teacher1
    Then "#booking-ai-wrapper" "css_element" should exist
    And "#booking-ai-input" "css_element" should exist
    And "#booking-ai-send" "css_element" should exist
    And "#booking-ai-messages" "css_element" should exist

  @javascript
  Scenario: Confirmation panel exists and buttons are present
    Given I am on the AI instructions page for booking "LLM Booking" logged in as teacher1
    Then "#booking-ai-confirm-panel" "css_element" should exist
    And "#booking-ai-btn-confirm" "css_element" should exist
    And "#booking-ai-btn-cancel" "css_element" should exist

  Scenario: Student cannot access AI instructions page
    Given I visit the AI instructions page for booking "LLM Booking" as "student1" and expect access denied

  ##############################################################################
  # LLM FLOW: Create booking option via natural language
  # Corresponds to PHPUnit: agent_wave3_real_llm_test::test_create_option_via_real_llm
  ##############################################################################

  @javascript @real_llm
  Scenario: Teacher creates a Yoga booking option via natural language and it appears in the activity
    Given I am on the AI instructions page for booking "LLM Booking" logged in as teacher1
    When I send the AI message "Erstelle eine neue Yoga-Klasse für Anfänger mit maximal 15 Teilnehmern. Mittwochs um 18:00 Uhr, Dauer 90 Minuten."
    And I wait for the AI response
    ## LLM should propose a create_option command → confirm panel becomes visible.
    Then "#booking-ai-confirm-panel" "css_element" should be visible
    When I confirm the AI action
    And I wait for the AI response
    ## After execution the new option must be listed in the booking activity.
    Then I am on the "LLM Booking" Activity page
    And I should see "Yoga"

  ##############################################################################
  # LLM FLOW: Search options via natural language
  # Corresponds to PHPUnit: agent_wave3_real_llm_test::test_search_options_via_llm
  ##############################################################################

  @javascript @real_llm
  Scenario: Teacher searches for Pilates options via natural language
    Given the following "mod_booking > options" exist:
      | booking      | text                   | description                  | course | maxanswers | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | LLM Booking  | Test Pilates Session 0 | Test Pilates Session 0 descr | C1     | 10         | 0              | ## +10 days ##    | ## +11 days ##  |
      | LLM Booking  | Test Pilates Session 1 | Test Pilates Session 1 descr | C1     | 11         | 0              | ## +12 days ##    | ## +13 days ##  |
      | LLM Booking  | Test Pilates Session 2 | Test Pilates Session 2 descr | C1     | 12         | 0              | ## +14 days ##    | ## +15 days ##  |
    And I am on the AI instructions page for booking "LLM Booking" logged in as teacher1
    When I send the AI message "Zeige mir alle Pilates Kurse an"
    And I wait for the AI response
    ## Search is a read-only task — it auto-executes without a confirm panel.
    Then I should see "Pilates" in the "#booking-ai-messages" "css_element"

  ##############################################################################
  # LLM FLOW: Create then update via natural language (multi-step workflow)
  # Corresponds to PHPUnit: agent_wave3_real_llm_test::test_create_then_update_workflow_via_llm
  ##############################################################################

  @javascript @real_llm
  Scenario: Teacher creates an option and then raises its capacity via a second message
    Given the following "mod_booking > options" exist:
      | booking      | text                 | description                  | course | maxanswers | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | LLM Booking  | LLM Workflow Option  | LLM Workflow Option descr    | C1     | 5          | 0              | ## +10 days ##    | ## +11 days ##  |
    And I am on the AI instructions page for booking "LLM Booking" logged in as teacher1
    ## Step 1: Ask LLM to increase capacity of the existing option.
    When I send the AI message "Erhöhe die Kapazität des Kurses 'LLM Workflow Option' auf 20 Teilnehmer"
    And I wait for the AI response
    Then "#booking-ai-confirm-panel" "css_element" should be visible
    When I confirm the AI action
    And I wait for the AI response
    ## Verify updated capacity appears in the activity.
    Then I am on the "LLM Booking" Activity page
    And I should see "LLM Workflow Option"
    And I should see "Max. number of participants: 20"
    And I should not see "Max. number of participants: 5"

  ##############################################################################
  # LLM FLOW: Cancel proposed action — no changes should be applied
  ##############################################################################

  @javascript @real_llm
  Scenario: Teacher cancels a proposed AI action and the option stays unchanged
    Given the following "mod_booking > options" exist:
      | booking      | text              | description              | course | maxanswers | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | LLM Booking  | Unchanged Option  | Unchanged Option descr   | C1     | 5          | 0              | ## +10 days ##    | ## +11 days ##  |
    And I am on the AI instructions page for booking "LLM Booking" logged in as teacher1
    When I send the AI message "Ändere die maximale Teilnehmerzahl der Option 'Unchanged Option' auf 99"
    And I wait for the AI response
    Then "#booking-ai-confirm-panel" "css_element" should be visible
    When I cancel the AI action
    ## Confirm panel disappears after cancelling.
    Then "#booking-ai-confirm-panel" "css_element" should not be visible
    ## Option must remain at 5 seats.
    And I am on the "LLM Booking" Activity page
    And I should see "Max. number of participants: 5"
    And I should not see "Max. number of participants: 99"
