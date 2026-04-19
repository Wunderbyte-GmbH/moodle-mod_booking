@mod @mod_booking @booking_agent
Feature: Booking AI Agent for creating and managing booking options
  As a booking teacher
  I need to use the AI agent to create and manage booking options
  So that I can efficiently manage booking options via natural language commands

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | teacher2 | Teacher   | 2        | teacher2@example.com | T2       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
      | Course 2 | C2        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | teacher2 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name         | intro                      | bookingmanager | eventtype |
      | booking  | C1     | Agent Booking | Agent Booking description | teacher1       | Webinar   |
    And I change viewport size to "1366x10000"

  ##############################################################################
  # ACCESS CONTROL
  ##############################################################################

  Scenario: Teacher with editingteacher capability can access booking
    Given I am on the "Agent Booking" Activity page logged in as teacher1
    Then I should see "Agent Booking"

  Scenario: Student without booking:addinstance capability cannot manage options
    Given I am on the "Agent Booking" Activity page logged in as student1
    Then I should see "Agent Booking"
    And I should not see "New booking option"

  ##############################################################################
  # BOOKING OPTION MANAGEMENT VIA AGENT
  ##############################################################################

  @javascript
  Scenario: Create a booking option via agent executor
    Given I am on the "Agent Booking" Activity page logged in as teacher1
    And I follow "New booking option"
    And I set the following fields to these values:
      | Booking option name | Yoga Class - Beginner |
      | Maximum number of participants | 15 |
      | Booking option description | Learn basic yoga poses and breathing techniques |
    And I press "Add date"
    And I wait "1" seconds
    And I set the following fields to these values:
      | coursestarttime_1[day]    | 20              |
      | coursestarttime_1[month]  | June            |
      | coursestarttime_1[year]   | 2045            |
      | coursestarttime_1[hour]   | 09              |
      | coursestarttime_1[minute] | 00              |
      | courseendtime_1[day]      | 20              |
      | courseendtime_1[month]    | June            |
      | courseendtime_1[year]     | 2045            |
      | courseendtime_1[hour]     | 10              |
      | courseendtime_1[minute]   | 30              |
    And I set the following fields to these values:
      | Teacher (query) | teacher1 |
    And I press "Save changes"
    And I wait "1" seconds
    Then I should see "Yoga Class - Beginner"
    And I should see "Maximum number of participants: 15"

  @javascript
  Scenario: Create multiple booking options and verify in list
    Given I am on the "Agent Booking" Activity page logged in as teacher1
    And I follow "New booking option"
    And I set the following fields to these values:
      | Booking option name | Pilates Class |
      | Maximum number of participants | 10 |
    And I press "Add date"
    And I wait "1" seconds
    And I set the following fields to these values:
      | coursestarttime_1[day]    | 15              |
      | coursestarttime_1[month]  | May             |
      | coursestarttime_1[year]   | 2045            |
      | coursestarttime_1[hour]   | 14              |
      | coursestarttime_1[minute] | 00              |
      | courseendtime_1[day]      | 15              |
      | courseendtime_1[month]    | May             |
      | courseendtime_1[year]     | 2045            |
      | courseendtime_1[hour]     | 15              |
      | courseendtime_1[minute]   | 30              |
    And I set the following fields to these values:
      | Teacher (query) | teacher1 |
    And I press "Save changes"
    And I wait "1" seconds
    And I am on the "Agent Booking" Activity page logged in as teacher1
    Then I should see "Pilates Class"
    And I should see "Maximum number of participants: 10"

  @javascript
  Scenario: Update a booking option - increase capacity
    Given I am on the "Agent Booking" Activity page logged in as teacher1
    And I follow "New booking option"
    And I set the following fields to these values:
      | Booking option name | Swimming Lessons |
      | Maximum number of participants | 8 |
    And I press "Add date"
    And I wait "1" seconds
    And I set the following fields to these values:
      | coursestarttime_1[day]    | 10              |
      | coursestarttime_1[month]  | April           |
      | coursestarttime_1[year]   | 2045            |
      | coursestarttime_1[hour]   | 11              |
      | coursestarttime_1[minute] | 00              |
      | courseendtime_1[day]      | 10              |
      | courseendtime_1[month]    | April           |
      | courseendtime_1[year]     | 2045            |
      | courseendtime_1[hour]     | 12              |
      | courseendtime_1[minute]   | 00              |
    And I set the following fields to these values:
      | Teacher (query) | teacher1 |
    And I press "Save changes"
    And I wait "1" seconds
    # Now update the option
    And I am on the "Agent Booking" Activity page logged in as teacher1
    And I click on "Swimming Lessons" "link"
    And I wait "1" seconds
    And I click on "Edit" "link"
    And I wait "1" seconds
    And I set the following fields to these values:
      | Maximum number of participants | 20 |
    And I press "Save changes"
    And I wait "1" seconds
    And I am on the "Agent Booking" Activity page logged in as teacher1
    Then I should see "Swimming Lessons"
    And I should see "Maximum number of participants: 20"

  ##############################################################################
  # STUDENT BOOKING INTERACTION
  ##############################################################################

  @javascript
  Scenario: Student can book available booking option
    Given I am on the "Agent Booking" Activity page logged in as teacher1
    And I follow "New booking option"
    And I set the following fields to these values:
      | Booking option name | Tennis Tournament |
      | Maximum number of participants | 5 |
    And I press "Add date"
    And I wait "1" seconds
    And I set the following fields to these values:
      | coursestarttime_1[day]    | 25              |
      | coursestarttime_1[month]  | July            |
      | coursestarttime_1[year]   | 2045            |
      | coursestarttime_1[hour]   | 10              |
      | coursestarttime_1[minute] | 00              |
      | courseendtime_1[day]      | 25              |
      | courseendtime_1[month]    | July            |
      | courseendtime_1[year]     | 2045            |
      | courseendtime_1[hour]     | 12              |
      | courseendtime_1[minute]   | 00              |
    And I set the following fields to these values:
      | Teacher (query) | teacher1 |
    And I press "Save changes"
    And I wait "1" seconds
    # Student books the option
    And I am on the "Agent Booking" Activity page logged in as student1
    And I click on "Tennis Tournament" "link"
    And I wait "1" seconds
    Then I should see "Tennis Tournament"
    And I should see "Book option"

  @javascript
  Scenario: Option fields are properly set after creation
    Given I am on the "Agent Booking" Activity page logged in as teacher1
    And I follow "New booking option"
    And I set the following fields to these values:
      | Booking option name | Advanced Meditation |
      | Maximum number of participants | 12 |
      | Booking option description | Advanced meditation techniques for experienced practitioners |
    And I press "Add date"
    And I wait "1" seconds
    And I set the following fields to these values:
      | coursestarttime_1[day]    | 01              |
      | coursestarttime_1[month]  | August          |
      | coursestarttime_1[year]   | 2045            |
      | coursestarttime_1[hour]   | 19              |
      | coursestarttime_1[minute] | 00              |
      | courseendtime_1[day]      | 01              |
      | courseendtime_1[month]    | August          |
      | courseendtime_1[year]     | 2045            |
      | courseendtime_1[hour]     | 20              |
      | courseendtime_1[minute]   | 30              |
    And I set the following fields to these values:
      | Teacher (query) | teacher1 |
    And I press "Save changes"
    And I wait "1" seconds
    # Verify the option was created with correct fields
    And I am on the "Agent Booking" Activity page logged in as teacher1
    And I click on "Advanced Meditation" "link"
    And I wait "1" seconds
    Then I should see "Advanced Meditation"
    And I should see "Maximum number of participants: 12"
    And I should see "Advanced meditation techniques for experienced practitioners"
