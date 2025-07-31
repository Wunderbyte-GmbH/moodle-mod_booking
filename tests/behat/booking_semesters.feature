@mod @mod_booking @booking_semesters
Feature: As a teacher - configure and use booking's semesters feature.

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
      | admin1   | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache
    And the following "mod_booking > semesters" exist:
      | identifier | name       | startdate                         | enddate                            |
      | nextmay    | NextMay    | ## first day of May next year ##  | ## last day of May next year ##    |
      | nextsummer | NextSummer | ## first day of June next year ## | ## last day of August next year ## |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | semester   | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | nextsummer | All bookings                     | Yes                      |
    And the following "mod_booking > options" exist:
      | booking    | text                                         | course | description  | semester   |
      | My booking | Price formula option - Dates In timeslot     | C1     | Option deskr | nextsummer |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking settings - change semester in booking option
    Given I am on the "My booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Dates"
    And I should see "NextSummer (nextsummer)" in the "//div[contains(@id, 'id_datesheader_') and contains(@class, 'fcontainer')]" "xpath_element"
    And I open the autocomplete suggestions list in the "//div[contains(@id, 'id_datesheader_')]//div[contains(@id, 'fitem_id_semesterid_')]" "xpath_element"
    And I wait "1" seconds
    And I should see "NextMay (nextmay)" in the "//div[contains(@id, 'id_datesheader_')]//ul[contains(@class, 'form-autocomplete-suggestions')]" "xpath_element"
    And I click on "NextMay (nextmay)" "text" in the "//div[contains(@id, 'id_datesheader_')]//ul[contains(@class, 'form-autocomplete-suggestions')]" "xpath_element"
    And I should see "NextMay (nextmay)" in the "//div[contains(@id, 'id_datesheader_')]//div[contains(@id, 'form_autocomplete_selection')]" "xpath_element"

  @javascript
  Scenario: Booking settings - use semester in booking option
    Given I am on the "My booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Dates"
    And I should see "NextSummer (nextsummer)" in the "//div[contains(@id, 'id_datesheader_') and contains(@class, 'fcontainer')]" "xpath_element"
    And I set the following fields to these values:
      | Booking option name                              | Option - Test Semester  |
      | Select time period                               | NextSummer (nextsummer) |
      | Weekday, start and end time (Day, HH:MM - HH:MM) | Friday, 13:00-14:00     |
    And I press "Create date series"
    And I wait "1" seconds
    And I should see "## + 1 year ##%Y##" in the "#booking_optiondate_1" "css_element"
    And I should see "1:00 PM - 2:00 PM" in the "#booking_optiondate_1" "css_element"
    And I should see "Friday" in the "#booking_optiondate_1" "css_element"
    And I should see "June" in the "#booking_optiondate_1" "css_element"
    And I should see "July" in the "#booking_optiondate_6" "css_element"
    And I press "Save"
    Then I should see "Option - Test Semester" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Friday, 13:00 - 14:00" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Show dates" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I wait "1" seconds
    And I should see "## + 1 year ##%Y##" in the ".allbookingoptionstable_r1 .showdates" "css_element"
    And I should see "1:00 PM - 2:00 PM" in the ".allbookingoptionstable_r1 .showdates" "css_element"
    And I should see "June" in the ".allbookingoptionstable_r1 .showdates" "css_element"
    And I should see "July" in the ".allbookingoptionstable_r1 .showdates" "css_element"
