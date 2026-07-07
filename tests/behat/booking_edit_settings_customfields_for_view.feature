@mod @mod_booking @booking_edit_settings_customfields_for_view
Feature: As admin - configure customfields to be displayed for each booking option in the overview and validate them as student.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And I clean booking cache
    And the following "custom field categories" exist:
      | name     | component   | area    | itemid |
      | SportArt | mod_booking | booking | 0      |
    And the following "custom fields" exist:
      | name     | category | type | shortname | configdata |
      | Sport1   | SportArt | text | spt1      | defsport1  |
      | Second   | SportArt | text | scnd1     | def1       |
      | Third    | SportArt | text | thrd1     | def2       |
    And the following config values are set as admin:
      | config               | value       | plugin  |
      | customfieldicon_spt1 | fa-futbol-o | booking |
    And the following "activities" exist:
      | activity | course | name     | intro               | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | Booking0 | Booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And the following "mod_booking > options" exist:
      | booking  | text       | course | description | importing | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | spt1  | scnd1  | thrd1   |
      | Booking0 | Option01-t | C1     | tenis       | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | tenis | extra1 | hidden1 |
      | Booking0 | Option02-y | C1     | yoga        | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | yoga  | extra2 | hidden2 |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking: configure customfields for view in booking instance and validate them in list view as student
    Given I am on the "Booking0" "booking activity editing" page logged in as admin
    And I expand all fieldsets
    And I set the following fields to these values:
      | Custom fields that are to be displayed for each booking option in the overview | Sport1 (spt1),Second (scnd1) |
    And I press "Save and display"
    And I log out
    ## Verify that the selected customfields are shown with their icons for each booking option.
    When I am on the "Booking0" Activity page logged in as student1
    Then I should see "tenis" in the ".allbookingoptionstable_r1" "css_element"
    And "i.fa-futbol-o" "css_element" should exist in the ".allbookingoptionstable_r1" "css_element"
    ## The customfield scnd1 has no icon configured, so the default icon (puzzle piece) is used.
    And I should see "extra1" in the ".allbookingoptionstable_r1" "css_element"
    And "i.fa-puzzle-piece" "css_element" should exist in the ".allbookingoptionstable_r1" "css_element"
    And I should see "yoga" in the ".allbookingoptionstable_r2" "css_element"
    ## The customfield thrd1 was not selected, so its value must not be shown.
    And I should not see "hidden1" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Booking: configure customfields for view in booking instance and validate them in cards view as student
    Given I am on the "Booking0" "booking activity editing" page logged in as admin
    And I expand all fieldsets
    And I set the following fields to these values:
      | View type                                                                       | Cards view                   |
      | Custom fields that are to be displayed for each booking option in the overview | Sport1 (spt1),Second (scnd1) |
    And I press "Save and display"
    And I log out
    ## In the cards view, each customfield is rendered on its own line in the card list.
    When I am on the "Booking0" Activity page logged in as student1
    Then I should see "tenis" in the ".infolist" "css_element"
    And I should see "extra1" in the ".infolist" "css_element"
    And "i.fa-futbol-o" "css_element" should exist in the ".infolist" "css_element"
    And "i.fa-puzzle-piece" "css_element" should exist in the ".infolist" "css_element"
    ## The customfield thrd1 was not selected, so its value must not be shown.
    And I should not see "hidden1" in the ".infolist" "css_element"
