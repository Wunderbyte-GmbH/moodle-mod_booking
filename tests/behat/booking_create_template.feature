@mod @mod_booking @booking_create_template
Feature: In a booking create a template
  As a teacher
  I need to add booking and event to a booking.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
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
      | student1 | C1     | student        |
    And I clean booking cache
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name       | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Base Price | 70           | 0        | 1                 |
      | 2        | special    | Spec Price | 80           | 0        | 1                 |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking option template: create one and use it to create new option
    Given I am on the "My booking" Activity page logged in as teacher1
    ## Prepare option
    And I follow "New booking option"
    And I set the following fields to these values:
      | Booking option name | Option template |
    And I set the field "Add to course calendar" to "Add to calendar (visible only to course participants)"
    And I press "Add date"
    And I wait "1" seconds
    And I set the following fields to these values:
      | coursestarttime_1[day]   | 15   |
      | coursestarttime_1[month] | May  |
      | coursestarttime_1[year]  | 2050 |
      | courseendtime_1[day]     | 16   |
      | courseendtime_1[month]   | May  |
      | courseendtime_1[year]    | 2050 |
    And I press "applydate_1"
    And I wait "1" seconds
    And I set the following fields to these values:
      | chooseorcreatecourse | Connected Moodle course |
    And I wait "1" seconds
    And I set the field with xpath "//*[contains(@id, 'fitem_id_courseid_')]//*[contains(@id, 'form_autocomplete_input-')]" to "Course 1"
    And I set the field "Assign teachers:" to "Teacher 1"
    ## Set as template
    ## And I follow "Add as template"
    And I set the field "addastemplate" to "Use as global template"
    And I press "Save"
    ## Required to avoid erros like "invalid session id" on the step next to "New option"
    And I wait "1" seconds
    ## Edit template
    And I click on "More" "text" in the ".secondary-navigation .moremenu.navigation" "css_element"
    And I follow "Manage booking option templates"
    And I should see "Option template"
    And I press "Edit"
    And I set the following fields to these values:
      | useprice | 1 |
    And I press "Save"
    ## Use template
    And I follow "New booking option"
    And I set the field "optiontemplateid" to "Option template"
    And I wait "1" seconds
    And I set the following fields to these values:
      | Booking option name | New option - by template |
    And I press "Save"
    And I wait "1" seconds
    ## Verify template
    Then I should see "New option - by template" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Teacher 1" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "15 May 2050" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "16 May 2050" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "70.00 EUR" in the ".allbookingoptionstable_r1" "css_element"
    ## Delete template
    And I click on "More" "text" in the ".secondary-navigation .moremenu.navigation" "css_element"
    And I follow "Manage booking option templates"
    And I press "Delete"
