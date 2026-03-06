@mod @mod_booking @booking_cohort_subscription
Feature: Booking option cohort subscription from book other users page
  As a privileged user
  I need to book users from cohorts through the booking option settings menu

  Background: 
    Given the following "categories" exist:
      | name  | category | idnumber |
      | Cat1  | 0        | CAT1     |
    And the following "users" exist:
      | username | firstname | lastname | email                      |
      | teacher1 | Teacher   | One      | teacher1@example.com       |
      | student1 | Student   | One      | student1@example.com       |
      | student2 | Student   | Two      | student2@example.com       |
      | student3 | Student   | Three    | student3@example.com       |
      | student4 | Student   | Four     | student4@example.com       |
    And the following "courses" exist:
      | fullname              | shortname | category |
      | Cohort booking course | CBC1      | CAT1     |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | CBC1   | editingteacher |
      | student1 | CBC1   | student        |
      | student2 | CBC1   | student        |
      | student3 | CBC1   | student        |
    And the following "system role assigns" exist:
      | user     | course | role |
      | teacher1 | Acceptance test site | manager |
    And the following "cohorts" exist:
      | name                        | idnumber            | contextlevel | reference | visible |
      | Cohort enrolled users       | cohort_enrolled_001 | System       |           | 1       |
      | Cohort with non-enrolled    | cohort_mixed_001    | System       |           | 1       |
    And the following "cohort members" exist:
      | user     | cohort                    |
      | student1 | cohort_enrolled_001     |
      | student2 | cohort_enrolled_001     |
      | student3 | cohort_enrolled_001     |
      | student1 | cohort_mixed_001    |
      | student2 | cohort_mixed_001    |
      | student3 | cohort_mixed_001    |
      | student4 | cohort_mixed_001    |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name            | intro                    | bookingmanager | eventtype |
      | booking  | CBC1   | Cohort booking  | Cohort booking instance  | teacher1       | Webinar   |
    And the following "mod_booking > options" exist:
      | booking        | text           | course | description      | limitanswers | maxanswers |
      | Cohort booking | Cohort option  | CBC1   | Cohort option 1  | 0            | 0          |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking cohorts: Manager - editing teacher books all users from a fully enrolled cohort
    Given I am on the "Cohort booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Cohort subscription"
    And I set the field with xpath "//*[contains(@id,'fitem_id_cohortids')]//input[contains(@id,'form_autocomplete_input-')]" to "Cohort enrolled users"
    When I press "Book cohort(s) or group(s)"
    Then I should see "3 users found in the selected cohorts" in the "#user-notifications" "css_element"
    And I should see "0 users found in the selected groups" in the "#user-notifications" "css_element"
    And I should see "3 users where booked for this option" in the "#user-notifications" "css_element"
    And I should see "student1@example.com" in the "#removeselect" "css_element"
    And I should see "student2@example.com" in the "#removeselect" "css_element"
    And I should see "student3@example.com" in the "#removeselect" "css_element"

  @javascript
  Scenario: Booking cohorts: Manager (editing teacher) not receives error when selected cohort contains users not enrolled in the course
    ## Site-wide manager has permission "bookanyone" 
    ## It was editing teacher receives error when selected cohort contains users not enrolled in the course
    Given I am on the "Cohort booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field with xpath "//*[contains(@id,'fitem_id_cohortids')]//input[contains(@id,'form_autocomplete_input-')]" to "Cohort with non-enrolled"
    When I press "Book cohort(s) or group(s)"
    Then I should see "4 users found in the selected cohorts" in the "#user-notifications" "css_element"
    And I should see "0 users found in the selected groups" in the "#user-notifications" "css_element"
    And I should see "4 users where booked for this option" in the "#user-notifications" "css_element"
    And I should see "student1@example.com" in the "#removeselect" "css_element"
    And I should see "student2@example.com" in the "#removeselect" "css_element"
    And I should see "student3@example.com" in the "#removeselect" "css_element"
    And I should see "student4@example.com" in the "#removeselect" "css_element"

  @javascript
  Scenario: Admin can book users from a cohort with non-enrolled users without enrollment error
    Given I am on the "Cohort booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field with xpath "//*[contains(@id,'fitem_id_cohortids')]//input[contains(@id,'form_autocomplete_input-')]" to "Cohort with non-enrolled"
    When I press "Book cohort(s) or group(s)"
    Then I should see "4 users found in the selected cohorts" in the "#user-notifications" "css_element"
    And I should see "0 users found in the selected groups" in the "#user-notifications" "css_element"
    And I should see "4 users where booked for this option" in the "#user-notifications" "css_element"
