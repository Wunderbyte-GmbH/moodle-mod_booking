@mod @mod_booking @booking_ai_instructions
Feature: AI instructions chat interface for booking managers
  As a booking manager
  I need to access the AI instructions chat page
  So that I can create and update booking options via natural language

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                   | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com    | T1       |
      | student1 | Student   | 1        | student1@example.com    | S1       |
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
      | activity | course | name        | intro                   | bookingmanager | eventtype |
      | booking  | C1     | AI Booking  | AI Booking description  | teacher1       | Webinar   |
    And I change viewport size to "1366x10000"

  ##############################################################################
  # ACCESS CONTROL (no @javascript needed — plain page load tests)
  ##############################################################################

  Scenario: Teacher with capability can access AI instructions page
    Given I am on the AI instructions page for booking "AI Booking" logged in as teacher1
    Then I should see "AI Booking"

  Scenario: Student without useaiinstructions capability is denied access
    Given I visit the AI instructions page for booking "AI Booking" as "student1" and expect access denied

  ##############################################################################
  # UI RENDERING (@javascript — DOM element checks, no LLM needed)
  ##############################################################################

  @javascript
  Scenario: AI chat interface renders all required elements on page load
    Given I am on the AI instructions page for booking "AI Booking" logged in as teacher1
    Then the AI instructions page should render the expected readiness UI

  @javascript
  Scenario: Confirmation panel is hidden by default
    Given I am on the AI instructions page for booking "AI Booking" logged in as teacher1
    Then the AI instructions page should render confirmation controls when chat is ready
    ## The panel must not be actively visible on first load.
    And the AI confirmation panel should be hidden on initial load when chat is ready

  ##############################################################################
  # FULL-FLOW SCENARIOS (opt-in only — require real LLM token)
  # Run these only with: BOOKING_AI_REAL_LLM=1
  # Behat tag: @real_llm
  ##############################################################################

  @javascript @real_llm
  Scenario: Teacher sends create instruction, confirms, option appears in activity
    Given the following "mod_booking > options" exist:
      | booking     | text        | description      | course | maxanswers |
      | AI Booking  | Existing 1  | Existing 1 descr | C1     | 5          |
    And I am on the AI instructions page for booking "AI Booking" logged in as teacher1
    And I wait "55" seconds
    When I send the AI message "Erstelle eine neue Buchungsoption namens KI-Test mit 10 Plätzen"
    And I wait for the AI response
    ## LLM should propose a command_proposal, triggering the confirm panel.
    Then "#booking-ai-confirm-panel" "css_element" should be visible
    When I confirm the AI action
    And I wait for the AI response
    ## After execution the option should exist.
    Then I am on the "AI Booking" Activity page
    And I should see "KI-Test"

  @javascript @real_llm
  Scenario: Teacher sends bulk update instruction, confirms, maxanswers updated on all options
    Given the following "mod_booking > options" exist:
      | booking    | text      | description    | course | maxanswers |
      | AI Booking | Option A  | Option A descr | C1     | 1          |
      | AI Booking | Option B  | Option B descr | C1     | 1          |
    And I am on the AI instructions page for booking "AI Booking" logged in as teacher1
    When I send the AI message "Setze für alle Buchungsoptionen 8 buchbare Plätze und 3 Wartelistenplätze"
    And I wait for the AI response
    Then "#booking-ai-confirm-panel" "css_element" should be visible
    When I confirm the AI action
    And I wait for the AI response
    Then I am on the "AI Booking" Activity page
    ## Both options should now reflect the updated maxanswers in the activity list.
    And I should see "8" in the ".booking-option-list" "css_element"

  @javascript @real_llm
  Scenario: Teacher cancels proposed AI action and no changes are made
    Given the following "mod_booking > options" exist:
      | booking    | text        | description     | course | maxanswers |
      | AI Booking | No Change   | No Change descr | C1     | 5          |
    And I am on the AI instructions page for booking "AI Booking" logged in as teacher1
    When I send the AI message "Ändere bei allen Optionen die maximale Teilnehmerzahl auf 99"
    And I wait for the AI response
    Then "#booking-ai-confirm-panel" "css_element" should be visible
    When I cancel the AI action
    ## Confirm panel should be hidden after cancel.
    Then "#booking-ai-confirm-panel" "css_element" should not be visible
    ## The option must not have changed.
    And I am on the "AI Booking" Activity page
    And I should not see "99" in the ".booking-option-maxanswers" "css_element"

  @javascript @real_llm
  Scenario: Read-only search auto-executes without showing confirmation panel
    Given the following "mod_booking > options" exist:
      | booking    | text       | description      | course | maxanswers |
      | AI Booking | AutoSearch | AutoSearch descr | C1     | 5          |
    And I am on the AI instructions page for booking "AI Booking" logged in as teacher1
    When I send the AI message "Zeige mir alle vorhandenen Buchungsoptionen"
    And I wait for the AI response
    ## For a read-only task, the confirm panel must NOT appear.
    Then "#booking-ai-confirm-panel" "css_element" should not be visible
    ## The option name should appear in the AI response message.
    And I should see "AutoSearch" in the "#booking-ai-messages" "css_element"
