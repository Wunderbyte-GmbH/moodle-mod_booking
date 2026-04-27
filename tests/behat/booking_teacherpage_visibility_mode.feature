@mod @mod_booking @booking_teacherpage_visibility
Feature: Teacher profile option visibility follows visibility mode setting

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                 | idnumber |
      | teacher1 | Teacher   | One      | teacher1@example.com  | T1       |
      | teacher2 | Teacher   | Two      | teacher2@example.com  | T2       |
      | admin1   | Admin     | One      | admin1@example.com    | A1       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | teacher2 | C1     | editingteacher |
      | teacher2 | C1     | manager        |
      | admin1   | C1     | editingteacher |
      | admin1   | C1     | manager        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | My booking | My booking description | admin1         | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking    | text                     | course | description | teachersforoption | invisible |
      | My booking | Visible assigned option  | C1     | Option desc | teacher1          | 0         |
      | My booking | Hidden assigned option   | C1     | Option desc | teacher1          | 1         |
      | My booking | Direct assigned option   | C1     | Option desc | teacher1          | 2         |
      | My booking | Hidden unassigned option | C1     | Option desc | teacher2          | 1         |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Mode 0 keeps hidden options hidden for assigned teacher
    Given the following config values are set as admin:
      | config                    | value | plugin  |
      | teacherpagevisibilitymode | 0     | booking |
    And I log in as "admin"
    When I visit "/mod/booking/teachers.php"
    And I follow "Teacher One"
    Then I should see "Visible assigned option" in the "#region-main" "css_element"
    And I should not see "Hidden assigned option" in the "#region-main" "css_element"
    And I should not see "Direct assigned option" in the "#region-main" "css_element"
    And I should not see "Hidden unassigned option" in the "#region-main" "css_element"

  @javascript
  Scenario: Mode 1 shows fully invisible options for assigned teacher
    Given the following config values are set as admin:
      | config                    | value | plugin  |
      | teacherpagevisibilitymode | 1     | booking |
    And I log in as "admin"
    When I visit "/mod/booking/teachers.php"
    And I follow "Teacher One"
    Then I should see "Visible assigned option" in the "#region-main" "css_element"
    And I should see "Hidden assigned option" in the "#region-main" "css_element"
    And I should not see "Direct assigned option" in the "#region-main" "css_element"
    And I should not see "Hidden unassigned option" in the "#region-main" "css_element"

  @javascript
  Scenario: Mode 2 shows direct-link-only options for assigned teacher
    Given the following config values are set as admin:
      | config                    | value | plugin  |
      | teacherpagevisibilitymode | 2     | booking |
    And I log in as "admin"
    When I visit "/mod/booking/teachers.php"
    And I follow "Teacher One"
    Then I should see "Visible assigned option" in the "#region-main" "css_element"
    And I should not see "Hidden assigned option" in the "#region-main" "css_element"
    And I should see "Direct assigned option" in the "#region-main" "css_element"
    And I should not see "Hidden unassigned option" in the "#region-main" "css_element"

  @javascript
  Scenario: Mode 3 shows all hidden types for assigned teacher
    Given the following config values are set as admin:
      | config                    | value | plugin  |
      | teacherpagevisibilitymode | 3     | booking |
    And I log in as "admin"
    When I visit "/mod/booking/teachers.php"
    And I follow "Teacher One"
    Then I should see "Visible assigned option" in the "#region-main" "css_element"
    And I should see "Hidden assigned option" in the "#region-main" "css_element"
    And I should see "Direct assigned option" in the "#region-main" "css_element"
    And I should not see "Hidden unassigned option" in the "#region-main" "css_element"
