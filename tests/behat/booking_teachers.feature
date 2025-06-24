@mod @mod_booking @booking_teachers
Feature: In a booking - create options and assign or substituing teachers

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname        | name             |
      | text     | teacherforoption | teacherforoption |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber | profile_field_teacherforoption |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       | yes                            |
      | teacher2 | Teacher   | 2        | teacher2@example.com | T2       | yes                            |
      | teacher3 | Teacher   | 3        | teacher3@example.com | T3       |                                |
      | admin1   | Admin     | 1        | admin1@example.com   | A1       |                                |
      | student1 | Student   | 1        | student1@example.com | S1       |                                |
      | student2 | Student   | 2        | student2@example.com | S2       |                                |
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
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Activate e-mails (confirmations, notifications and more) | Booking option name  |
      | booking  | C1     | My booking | My booking description | admin1         | Webinar   | All bookings                     | Yes                                                      | New option - Webinar |
    And the following "mod_booking > options" exist:
      | booking    | text                      | course | description  | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 |
      | My booking | Booking option - Teachers | C1     | Option deskr | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking option: add and remove single teacher via substitutions
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Substitutions / Cancelled dates" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Booking option - Teachers" in the "#region-main" "css_element"
    And I should see "No teacher" in the "[id^=optiondates_teachers_table] td.teacher" "css_element"
    And I click on "Edit" "link" in the "[id^=optiondates_teachers_table] td.edit" "css_element"
    And I wait "1" seconds
    And I should see "Teachers" in the ".modal-header" "css_element"
    When I set the following fields to these values:
      | Teachers | teacher1   |
      | Reason   | Assign one |
    And I press "Save changes"
    Then I should see "Teacher 1" in the "[id^=optiondates_teachers_table] td.teacher" "css_element"
    And I should see "Assign one" in the "[id^=optiondates_teachers_table] td.reason" "css_element"
    And I click on "Edit" "link" in the "[id^=optiondates_teachers_table] td.edit" "css_element"
    And I click on "Teacher 1" "text" in the ".form-autocomplete-selection.form-autocomplete-multiple" "css_element"
    And I set the field "Reason" to "Remove one"
    And I press "Save changes"
    And I should see "No teacher" in the "[id^=optiondates_teachers_table] td.teacher" "css_element"
    And I should see "Remove one" in the "[id^=optiondates_teachers_table] td.reason" "css_element"

  @javascript
  Scenario: Booking option: add three and remove two teachers via substitutions
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Substitutions / Cancelled dates" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Booking option - Teachers" in the "#region-main" "css_element"
    And I should see "No teacher" in the "[id^=optiondates_teachers_table] td.teacher" "css_element"
    When I click on "Edit" "link" in the "[id^=optiondates_teachers_table] td.edit" "css_element"
    And I wait "1" seconds
    And I should see "Teachers" in the ".modal-header" "css_element"
    And I set the following fields to these values:
      | Teachers    | teacher1,teacher2,teacher3 |
      | Reason      | Assign three |
    And I press "Save changes"
    Then I should see "Teacher 1" in the "[id^=optiondates_teachers_table] td.teacher" "css_element"
    And I should see "Teacher 2" in the "[id^=optiondates_teachers_table] td.teacher" "css_element"
    And I should see "Teacher 3" in the "[id^=optiondates_teachers_table] td.teacher" "css_element"
    And I should see "Assign three" in the "[id^=optiondates_teachers_table] td.reason" "css_element"
    And I click on "Edit" "link" in the "[id^=optiondates_teachers_table] td.edit" "css_element"
    And I click on "Teacher 2" "text" in the ".form-autocomplete-selection.form-autocomplete-multiple" "css_element"
    And I wait "1" seconds
    And I click on "Teacher 3" "text" in the ".form-autocomplete-selection.form-autocomplete-multiple" "css_element"
    And I set the field "Reason" to "Remove two"
    And I press "Save changes"
    And I should see "Teacher 1" in the "[id^=optiondates_teachers_table] td.teacher" "css_element"
    And I should see "Remove two" in the "[id^=optiondates_teachers_table] td.reason" "css_element"

  @javascript
  Scenario: Booking option: set teachers availability by custom profilefield value
    Given the following config values are set as admin:
       | config                                      | value        | plugin  |
       | selectteacherswithprofilefieldonly          | 1            | booking |
    And I log in as "admin"
    And I set the following administration settings values:
      | selectteacherswithprofilefieldonlyfield | teacherforoption |
      | selectteacherswithprofilefieldonlyvalue | yes              |
    ## Given the following config values are set as admin:
    ##   | config                                  | value            | plugin  |
    ##   | selectteacherswithprofilefieldonlyfield | teacherforoption | booking |
    ##   | selectteacherswithprofilefieldonlyvalue | yes              | booking |
    And I am on the "My booking" Activity page
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I expand all fieldsets
    And I expand the "Assign teachers:" autocomplete
    And I should see "Teacher 1" in the "//div[contains(@id, 'fitem_id_teachersforoption_')]//ul[contains(@class, 'form-autocomplete-suggestions')]" "xpath_element"
    And I should see "Teacher 2" in the "//div[contains(@id, 'fitem_id_teachersforoption_')]//ul[contains(@class, 'form-autocomplete-suggestions')]" "xpath_element"
    And I should not see "Teacher 3" in the "//div[contains(@id, 'fitem_id_teachersforoption_')]//ul[contains(@class, 'form-autocomplete-suggestions')]" "xpath_element"
