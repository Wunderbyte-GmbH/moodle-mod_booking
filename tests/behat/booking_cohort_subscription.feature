@mod @mod_booking @booking_cohort_subscription
Feature: Booking option cohort subscription from book other users page
  As a privileged user
  I need to book users from cohorts through the booking option settings menu

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                      |
      | teacher1 | Teacher   | One      | teacher1@example.com       |
      | teacher2 | Teacher   | Two      | teacher2@example.com       |
      | student1 | Student   | One      | student1@example.com       |
      | student2 | Student   | Two      | student2@example.com       |
      | student3 | Student   | Three    | student3@example.com       |
      | student4 | Student   | Four     | student4@example.com       |
    And the following "roles" exist:
      | name             | shortname       | description     | archetype |
      | ViewSiteCohorts  | viewsitecohorts | viewsitecohorts |           |
    And the following "permission overrides" exist:
      | capability         | permission | role            | contextlevel | reference |
      | moodle/cohort:view | Allow      | viewsitecohorts | System       |           |
    And the following "role assigns" exist:
      | user     | role            | contextlevel | reference |
      | teacher1 | viewsitecohorts | System       |           |
      | teacher2 | manager         | System       |           |
    And the following "cohorts" exist:
      | name                        | idnumber            | contextlevel | reference | visible |
      | Cohort enrolled users       | cohort_enrolled_001 | System       |           | 1       |
      | Cohort with non-enrolled    | cohort_mixed_001    | System       |           | 1       |
    And the following "cohort members" exist:
      | user     | cohort              |
      | student1 | cohort_enrolled_001 |
      | student2 | cohort_enrolled_001 |
      | student3 | cohort_enrolled_001 |
      | student1 | cohort_mixed_001    |
      | student2 | cohort_mixed_001    |
      | student3 | cohort_mixed_001    |
      | student4 | cohort_mixed_001    |
    And the following "categories" exist:
      | name  | category | idnumber |
      | Cat1  | 0        | CAT1     |
    And the following "courses" exist:
      | fullname              | shortname | category |
      | Cohort booking course | CBC1      | CAT1     |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | CBC1   | editingteacher |
      | teacher2 | CBC1   | editingteacher |
      | student1 | CBC1   | student        |
      | student2 | CBC1   | student        |
      | student3 | CBC1   | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name            | intro                    | bookingmanager | eventtype |
      | booking  | CBC1   | Cohort booking  | Cohort booking instance  | teacher1       | Webinar   |
    And the following "mod_booking > options" exist:
      | booking        | text           | course | description      | limitanswers | maxanswers | teachersforoption |
      | Cohort booking | Cohort option  | CBC1   | Cohort option 1  | 0            | 0          | teacher1          |
      ## We must use only "editingteacher" + "teachersforoption" to get error below. Any higher role (even local manager) - has "bookanyone" capability
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking cohorts: Editing teacher (with cohort:view override) books all users from a fully enrolled cohort
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
  Scenario: Booking cohorts: Editing teacher (with cohort:view override) receives error when selected cohort contains users not enrolled in the course
    ## We must use only "editingteacher" + "teachersforoption" to get this error. Any higher role (even local manager) - has "bookanyone" capability
    Given I am on the "Cohort booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field with xpath "//*[contains(@id,'fitem_id_cohortids')]//input[contains(@id,'form_autocomplete_input-')]" to "Cohort with non-enrolled"
    When I press "Book cohort(s) or group(s)"
    Then I should see "4 users found in the selected cohorts" in the "#user-notifications" "css_element"
    And I should see "0 users found in the selected groups" in the "#user-notifications" "css_element"
    And I should see "3 users where booked for this option" in the "#user-notifications" "css_element"
    And I should see "Not all users could be booked with cohort booking:" in the "#user-notifications" "css_element"
    And I should see "1 users are not enrolled in the course" in the "#user-notifications" "css_element"
    And I should see "student1@example.com" in the "#removeselect" "css_element"
    And I should see "student2@example.com" in the "#removeselect" "css_element"
    And I should see "student3@example.com" in the "#removeselect" "css_element"
    And I should not see "student4@example.com" in the "#removeselect" "css_element"

  @javascript
  Scenario: Booking cohorts: Managers can book users from a cohort with non-enrolled users without enrollment error
    Given I am on the "Cohort booking" Activity page logged in as teacher2
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
