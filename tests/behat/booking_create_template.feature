@mod @mod_booking @booking_create_template
Feature: In a booking create a template
  As a teacher
  I need to create a booking option template validate and use it.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | teacher2 | Teacher   | 2        | teacher2@example.com | T2       |
      | teacher3 | Teacher   | 3        | teacher3@example.com | T3       |
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
      | teacher3 | C1     | teacher        |
      | student1 | C1     | student        |
    And I clean booking cache
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name       | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Base Price | 70           | 0        | 1                 |
      | 2        | special    | Spec Price | 80           | 0        | 1                 |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking option template: create one and use it to create new option
    Given I am on the "My booking" Activity page logged in as teacher1
    ## Prepare option
    And I follow "New booking option"
    And I set the field "Booking option name" to "Option template"
    And I follow "Dates"
    And I set the field "Add to course calendar" to "Add to calendar (visible only to participants of moodle course)"
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
    And I follow "Price"
    And I set the field "Only book with price" to "checked"
    ##And I set the following fields to these values:
    ##  | useprice | 1 |
    And I press "Save"
    ## Use template
    And I follow "New booking option"
    And I set the field "optiontemplateid" to "Option template"
    And I wait "1" seconds
    And I set the field "Booking option name" to "New option - by template"
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

  @javascript
  Scenario: Booking option template: create self-learning template and use it for new option
    Given the following config values are set as admin:
      | config                           | value | plugin  |
      | linktomoodlecourseonbookedbutton | 1     | booking |
      | selflearningcourseactive         | 1     | booking |
    And the following "mod_booking > options" exist:
      | booking    | text         | description  | importing | useprice | course | chooseorcreatecourse | selflearningcourse | duration | coursestarttime | maxanswers | teachersforoption | responsiblecontact |
      | My booking | SelfLearning | SelfLearning | 1         | 1        | C2     | 1                    | 1                  | 10       | ## +2 days ##   |5           | teacher1, admin   | teacher2, teacher3 |
    ## Customize option and add it as template
    And I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Price"
    ## And I set the field "Base Price" to "65"
    And I set the field with xpath "//div[contains(@id, 'id_bookingoptionprice_')]//input[@aria-label='Base Price']" to "65"
    And I press "Save"
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "More" "text" in the ".secondary-navigation .moremenu.navigation" "css_element"
    And I follow "Save booking option as template"
    ## TODO: validate rediection if will be fixed
    And I am on the "My booking" Activity page
    And I click on "More" "text" in the ".secondary-navigation .moremenu.navigation" "css_element"
    And I follow "Manage booking option templates"
    And I should see "SelfLearning" in the "#optiontemplatessettings_r0" "css_element"
    ## Apply template to a new option
    And I am on the "My booking" Activity page
    And I follow "New booking option"
    And I set the field "Populate from template" to "SelfLearning"
    And I wait "1" seconds
    ## Validate self-learning options
    And I expand all fieldsets
    And the field "Self-learning course" matches value "1"
    And the field "duration[number]" matches value "10"
    And the field "duration[timeunit]" matches value "seconds"
    And I should not see "Enrol users at course start time"
    And I should see "Sorting date"
    And I should not see "Add date"
    ## Validate price options
    And the field "useprice" matches value "1"
    ## TODO: validate custom price values if will be fixed
    ## Validate responsible contacts and assigned teachers
    And I should see "teacher2@example.com" in the "//div[contains(@id, 'fitem_id_responsiblecontact_')]" "xpath_element"
    And I should see "teacher3@example.com" in the "//div[contains(@id, 'fitem_id_responsiblecontact_')]" "xpath_element"
    And I should see "admin@example.com" in the "//div[contains(@id, 'fitem_id_teachersforoption_')]" "xpath_element"
    And I should see "teacher1@example.com" in the "//div[contains(@id, 'fitem_id_teachersforoption_')]" "xpath_element"
    And I log out
