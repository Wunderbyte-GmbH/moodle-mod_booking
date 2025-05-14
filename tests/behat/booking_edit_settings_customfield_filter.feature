@mod @mod_booking @booking_edit_settings_customfield_filter
Feature: As admin - configure customfield filter for booking instance and validate it as student.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S1       |
      | student3 | Student   | 3        | student3@example.com | S1       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And I clean booking cache
    And the following "custom field categories" exist:
      | name     | component   | area    | itemid |
      | SportArt | mod_booking | booking | 0      |
      | UserN    | mod_booking | booking | 1      |
    And the following "custom fields" exist:
      | name     | category | type          | shortname   | configdata                                          |
      | Sport1   | SportArt | text          | spt1        | defsport1                                           |
      | Second   | SportArt | text          | scnd1       | def1                                                |
      | DynamicU | UserN    | dynamicformat | dynamicuser | {"required":"0","uniquevalues":"0","dynamicsql":"SELECT id, username as data FROM {user}","autocomplete":"0","defaultvalue":"1","multiselect":"1"} |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 88           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 77           | 0        | 2                 |
      | 3        | discount2  | Disc2 | 66           | 0        | 3                 |
    And the following "activities" exist:
      | activity | course | name     | intro               | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail | json                                                                                                  |
      | booking  | C1     | Booking0 | Booking description | teacher1       | Webinar   | All bookings                     | Yes                      | {"customfieldsforfilter":{"sport":"Sport","sportsdivision":"sportsdivision","sportuser":"sportuser"}} |
    And the following "mod_booking > options" exist:
      | booking   | text       | course | description       | importing | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 | useprice | spt1  | dynamicuser       |
      | Booking0  | Option01-t | C1     | tenis,teach,stud1 | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | tenis | teacher1,student1 |
      | Booking0  | Option02-f | C1     | yoga,stud2        | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | yoga  | student2          |
      | Booking0  | Option03-y | C1     | chess,stud1,stud3 | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | chess | student1,student3 |
      | Booking0  | Option04-c | C1     | rugby,stud2       | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | rugby | student2          |
      | Booking0  | Option05-r | C1     | tenis,stud3       | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | tenis | student3          |
      | Booking0  | Option06-d | C1     | yoga,stud2        | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | yoga  | student2          |
      | Booking0  | Option07-t | C1     | chess,teac        | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | chess | teacher1          |
      | Booking0  | Option08-t | C1     | polo,teac         | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 1        | polo  | teacher1          |
      | Booking0  | Option09-y | C1     | tenis,stud1       | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | tenis | student1          |
      | Booking0  | Option10-c | C1     | yoga,stud2,stud3  | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | yoga  | student2,student3 |
      | Booking0  | Option11-y | C1     | chess,stud1       | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 1        | chess | student1          |
      | Booking0  | Option12-c | C1     | rugby,stud2       | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 1        | rugby | student2          |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking: configure customfield filter for booking instance and validate it as student
    Given I am on the "Booking0" "booking activity editing" page logged in as admin
    And I expand all fieldsets
    And I set the following fields to these values:
      | id_customfieldsforfilter  | spt1,dynamicuser |
    And I press "Save and display"
    And I log out
    ## Verify max booking options for 1st instance as a student
    When I am on the "Booking0" Activity page logged in as admin
    And I click on "//button[contains(@class, 'asidecollapse-cmid_')]" "xpath_element"
    And I click on "[aria-controls=\"id_collapse_spt1\"]" "css_element"
    And I should see "chess (3)" in the "//label[contains(@for, 'idchessspt1')]" "xpath_element"
    And I should see "polo (1)" in the "//label[contains(@for, 'idpolospt1')]" "xpath_element"
    And I should see "tenis (3)" in the "//label[contains(@for, 'idtenisspt1')]" "xpath_element"
    And I set the field "yoga (3)" to "checked"
    And I should see "3 of 12 records found" in the ".wb-records-count-label" "css_element"
    And I should see "1 filter(s) on: Sport1" in the ".wb-records-count-label" "css_element"
    And I set the field "yoga (3)" to ""
    ## Check 2nf customfield filter:
    And I click on ".wunderbyte_table_components" "css_element"
    And I click on "[aria-controls=\"id_collapse_dynamicuser\"]" "css_element"
    And I should see "student1 (4)" in the "//label[contains(@for, 'idstudent1dynamicuser')]" "xpath_element"
    And I should see "teacher1 (3)" in the "//label[contains(@for, 'idteacher1dynamicuser')]" "xpath_element"
