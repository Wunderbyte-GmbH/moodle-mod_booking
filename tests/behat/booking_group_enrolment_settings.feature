@mod @mod_booking @booking_group_enrolment_settings
Feature: Configure automatic course and group enrolment settings of a booking instance
  In order to enrol users in courses and groups automatically
  As a teacher
  I need to be able to configure the group enrolment settings of a booking instance.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
      | Group B | C1     | GB       |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | autoenrol |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | 1         |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Group enrolment settings show their dependencies and can be saved
    Given I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    When I follow "Connected Moodle course"
    And I wait "1" seconds
    Then I should see "Automatically enrol users in connected course"
    ## The group setting of the connected course is only shown when autoenrol is active.
    And I set the field "Automatically enrol users in connected course" to ""
    And I should not see "Automatically enrol users in group of connected course"
    And I set the field "Automatically enrol users in connected course" to "1"
    And I should see "Automatically enrol users in group of connected course"
    ## The source course settings stay in the advanced options.
    And I follow "Advanced options"
    And I wait "1" seconds
    And I should see "Unenrol from group when user is unenrolled from corresponding booking option?"
    ## The multiselect offers the specific group per booked option together with the course groups.
    And I set the field "Automatically enrol users in group(s) of the course in which this booking instance is located" to "Enrol in specific group for each booked option,Group A,Group B"
    And I press "Save and display"
    And I follow "Settings"
    And I follow "Advanced options"
    And I wait "1" seconds
    Then the field "Automatically enrol users in group(s) of the course in which this booking instance is located" matches value "Enrol in specific group for each booked option,Group A,Group B"

  @javascript
  Scenario: Manually select groups of the connected course in the booking option
    Given the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 2 | C2        | 0        | 1                |
    And the following "groups" exist:
      | name     | course | idnumber |
      | CGroup A | C2     | CGA      |
      | CGroup B | C2     | CGB      |
    And the following "mod_booking > options" exist:
      | booking    | text     | description | importing | chooseorcreatecourse | course | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Option 1 | Option 1    | 1         | 1                    | C2     | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
    And I am on the "My booking" Activity page logged in as teacher1
    ## Manual selection is possible while the instance does not create groups automatically.
    When I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I wait "1" seconds
    And I follow "Moodle course"
    Then I should see "Enrol users in group(s) of the connected course"
    And I set the field "Enrol users in group(s) of the connected course" to "CGroup A"
    And I press "Save"
    And I wait "1" seconds
    When I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I wait "1" seconds
    And I follow "Moodle course"
    Then I should see "CGroup A" in the "//div[contains(@id, 'fitem_id_addtogroupsofconnectedcourse')]" "xpath_element"
    ## Without a connected course, the manual selection is not offered.
    And I set the field "Connected Moodle course" to "No connection to Moodle course"
    And I should not see "Enrol users in group(s) of the connected course"
    And I am on the "My booking" Activity page
    ## With the automatic group of the connected course active, only the notice is shown.
    And I follow "Settings"
    And I follow "Connected Moodle course"
    And I wait "1" seconds
    And I set the field "Automatically enrol users in group of connected course" to "1"
    And I press "Save and display"
    When I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I wait "1" seconds
    And I follow "Moodle course"
    Then I should see "The group in the connected course is created automatically for this booking option"
    And I should not see "Enrol users in group(s) of the connected course"
