@mod @mod_booking @booking_cohort_subscription
Feature: Booking option cohort subscription from book other users page
  As a privileged user
  I need to book users from cohorts through the booking option settings menu

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                      |
      | admin    | Admin     | User     | admin@example.com          |
      | teacher1 | Teacher   | One      | teacher1@example.com       |
      | student1 | Student   | One      | student1@example.com       |
      | student2 | Student   | Two      | student2@example.com       |
      | student3 | Student   | Three    | student3@example.com       |
      | student4 | Student   | Four     | student4@example.com       |
    And the following "courses" exist:
      | fullname             | shortname | category |
      | Cohort booking course | CBC1      | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | CBC1   | editingteacher |
      | teacher1 | CBC1   | manager        |
      | student1 | CBC1   | student        |
      | student2 | CBC1   | student        |
      | student3 | CBC1   | student        |
      | student4 | CBC1   | student        |
    And the following "cohorts" exist:
      | name                        | idnumber            | contextlevel | reference |
      | Cohort enrolled users       | cohort_enrolled_001 | System       |           |
      | Cohort with non-enrolled    | cohort_mixed_001    | System       |           |
    And the following "cohort members" exist:
      | user     | cohort                    |
      | student1 | Cohort enrolled users     |
      | student2 | Cohort enrolled users     |
      | student3 | Cohort enrolled users     |
      | student4 | Cohort enrolled users     |
      | student1 | Cohort with non-enrolled  |
      | student2 | Cohort with non-enrolled  |
      | student3 | Cohort with non-enrolled  |
      | student4 | Cohort with non-enrolled  |
      | admin    | Cohort with non-enrolled  |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name            | intro                    | bookingmanager | eventtype |
      | booking  | CBC1   | Cohort booking  | Cohort booking instance  | teacher1       | Webinar   |
    And the following "mod_booking > options" exist:
      | booking        | text           | course | description      | limitanswers | maxanswers |
      | Cohort booking | Cohort option  | CBC1   | Cohort option 1  | 0            | 0          |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Editing teacher books all users from a fully enrolled cohort
    Given I am on the "Cohort booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Cohort subscription"
    And I set the field with xpath "//*[contains(@id,'fitem_id_cohortids')]//input[contains(@id,'form_autocomplete_input-')]" to "Cohort enrolled users"
    And I click on "Cohort enrolled users" "text" in "//*[contains(@id,'fitem_id_cohortids')]//ul[contains(@class,'form-autocomplete-suggestions')]" "xpath_element"
    When I press "Book cohort(s) or group(s)"
    Then I should see "This is the result of your cohort booking"
    And I should see "4 users found in the selected cohorts"
    And I should see "4 users where booked for this option"

  @javascript
  Scenario: Editing teacher receives error when selected cohort contains users not enrolled in the course
    Given I am on the "Cohort booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field with xpath "//*[contains(@id,'fitem_id_cohortids')]//input[contains(@id,'form_autocomplete_input-')]" to "Cohort with non-enrolled"
    And I click on "Cohort with non-enrolled" "text" in "//*[contains(@id,'fitem_id_cohortids')]//ul[contains(@class,'form-autocomplete-suggestions')]" "xpath_element"
    When I press "Book cohort(s) or group(s)"
    Then I should see "This is the result of your cohort booking"
    And I should see "5 users found in the selected cohorts"
    And I should see "4 users where booked for this option"
    And I should see "Not all users could be booked with cohort booking"
    And I should see "1 users are not enrolled in the course"

  @javascript
  Scenario: Admin can book users from a cohort with non-enrolled users without enrollment error
    Given I am on the "Cohort booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field with xpath "//*[contains(@id,'fitem_id_cohortids')]//input[contains(@id,'form_autocomplete_input-')]" to "Cohort with non-enrolled"
    And I click on "Cohort with non-enrolled" "text" in "//*[contains(@id,'fitem_id_cohortids')]//ul[contains(@class,'form-autocomplete-suggestions')]" "xpath_element"
    When I press "Book cohort(s) or group(s)"
    Then I should see "This is the result of your cohort booking"
    And I should see "5 users found in the selected cohorts"
    And I should see "5 users where booked for this option"
    And I should not see "Not all users could be booked with cohort booking"
