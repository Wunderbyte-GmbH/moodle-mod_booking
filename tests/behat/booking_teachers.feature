@mod @mod_booking @booking_teachers
Feature: In a booking - create options and assign or substituing teachers

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | teacher2 | Teacher   | 2        | teacher2@example.com | T2       |
      | teacher3 | Teacher   | 3        | teacher3@example.com | T3       |
      | admin1   | Admin     | 1        | admin1@example.com   | A1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | teacher2 | C1     | editingteacher |
      | teacher2 | C1     | manager        |
      | teacher3 | C1     | editingteacher |
      | teacher3 | C1     | manager        |
      | admin1   | C1     | editingteacher |
      | admin1   | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Activate e-mails (confirmations, notifications and more) | Booking option name  |
      | booking  | C1     | My booking | My booking description | admin1         | Webinar   | All bookings                     | Yes                                                      | New option - Webinar |
    And the following "mod_booking > options" exist:
      | booking    | text                      | course | description  | startendtimeknown | coursestarttime  | courseendtime | optiondatestart[0] | optiondateend[0] | optiondatestart[1] | optiondateend[1] |
      | My booking | Booking option - Teachers | C1     | Option deskr | 1                 | ## yesterday ##  | ## +4 days ## | ## tomorrow ##     | ## +2 days ##    | ## +3 days ##      | ## +4 days ##    |

  @javascript
  Scenario: Booking option: add and remove single teacher via substitutions
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    And I follow "My booking"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Substitutions / Cancelled dates" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Booking option - Teachers" in the "#region-main" "css_element"
    And I should see "No teacher" in the "#optiondates_teachers_table_r1 .teacher" "css_element"
    And I click on "Edit" "link" in the "#optiondates_teachers_table_r1 .edit" "css_element"
    And I wait "1" seconds
    And I should see "Teachers" in the ".modal-header" "css_element"
    And I set the following fields to these values:
      | Teachers | teacher1   |
      | Reason   | Assign one |
    And I press "Save changes"
    And I wait until the page is ready
    Then I should see "Teacher 1" in the "#optiondates_teachers_table_r1 .teacher" "css_element"
    And I should see "Assign one" in the "#optiondates_teachers_table_r1 .reason" "css_element"
    And I click on "Edit" "link" in the "#optiondates_teachers_table_r1 .edit" "css_element"
    And I click on "Teacher 1" "text" in the ".form-autocomplete-selection.form-autocomplete-multiple" "css_element"
    And I set the field "Reason" to "Remove one"
    And I press "Save changes"
    And I wait until the page is ready
    And I should see "No teacher" in the "#optiondates_teachers_table_r1 .teacher" "css_element"
    And I should see "Remove one" in the "#optiondates_teachers_table_r1 .reason" "css_element"
    And I log out

  @javascript
  Scenario: Booking option: add three and remove two teachers via substitutions
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "My booking"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Substitutions / Cancelled dates" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Booking option - Teachers" in the "#region-main" "css_element"
    And I should see "No teacher" in the "#optiondates_teachers_table_r1 .teacher" "css_element"
    When I click on "Edit" "link" in the "#optiondates_teachers_table_r1 .edit" "css_element"
    And I wait "1" seconds
    And I should see "Teachers" in the ".modal-header" "css_element"
    And I set the following fields to these values:
      | Teachers    | teacher1,teacher2,teacher3 |
      | Reason      | Assign three |
    And I press "Save changes"
    And I wait until the page is ready
    Then I should see "Teacher 1" in the "#optiondates_teachers_table_r1 .teacher" "css_element"
    And I should see "Teacher 2" in the "#optiondates_teachers_table_r1 .teacher" "css_element"
    And I should see "Teacher 3" in the "#optiondates_teachers_table_r1 .teacher" "css_element"
    And I should see "Assign three" in the "#optiondates_teachers_table_r1 .reason" "css_element"
    And I click on "Edit" "link" in the "#optiondates_teachers_table_r1 .edit" "css_element"
    And I click on "Teacher 2" "text" in the ".form-autocomplete-selection.form-autocomplete-multiple" "css_element"
    And I click on "Teacher 3" "text" in the ".form-autocomplete-selection.form-autocomplete-multiple" "css_element"
    And I set the field "Reason" to "Remove two"
    And I press "Save changes"
    And I wait until the page is ready
    And I should see "Teacher 1" in the "#optiondates_teachers_table_r1 .teacher" "css_element"
    And I should see "Remove two" in the "#optiondates_teachers_table_r1 .reason" "css_element"
    And I log out
