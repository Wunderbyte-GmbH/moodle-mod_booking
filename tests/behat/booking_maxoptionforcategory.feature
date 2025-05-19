@mod @mod_booking @booking_maxoptionforcategory
Feature: As admin - configure max option for category and validate it as student.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
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
      | student2 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
    ## Base64 of customfield value must be used as key in json:
      | activity | course | name     | intro               | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail | json                                                                                                                 |
      | booking  | C1     | Booking0 | Booking description | teacher1       | Webinar   | All bookings                     | Yes                      | {"maxoptionsfromcategory":"{\"dGVuaXM=\":{\"count\":2,\"localizedstring\":\"tenis\"}}","maxoptionsfrominstance":"0"} |
      | booking  | C1     | Booking1 | Booking description | teacher1       | Webinar   | All bookings                     | Yes                      | {"maxoptionsfromcategory":"{\"dGVuaXM=\":{\"count\":3,\"localizedstring\":\"tenis\"}}","maxoptionsfrominstance":"0"} |
    And the following "custom field categories" exist:
      | name     | component   | area    | itemid |
      | SportArt | mod_booking | booking | 0      |
    And the following "custom fields" exist:
      | name   | category | type | shortname | configdata[defaultvalue] |
      | Sport1 | SportArt | text | spt1      | defsport1                |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 88           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 77           | 0        | 2                 |
      | 3        | discount2  | Disc2 | 66           | 0        | 3                 |
    And the following "mod_booking > options" exist:
      | booking   | text       | course | description     | importing | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 | useprice | spt1     |
      | Booking0  | Option01-t | C1     | Tenis (limited) | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | tenis    |
      | Booking0  | Option02-f | C1     | Football        | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | football |
      | Booking0  | Option03-y | C1     | Yoga (limited)  | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | yoga     |
      | Booking0  | Option04-c | C1     | Chess           | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | chess    |
      | Booking0  | Option05-r | C1     | Rugby           | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | rugby    |
      | Booking0  | Option06-d | C1     | Darth           | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | darth    |
      | Booking0  | Option07-t | C1     | Tenis (limited) | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | tenis    |
      | Booking0  | Option08-t | C1     | Tenis (limited) | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 1        | tenis    |
      | Booking0  | Option09-y | C1     | Yoga (limited)  | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | yoga     |
      | Booking0  | Option10-c | C1     | Chess           | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | chess    |
      | Booking0  | Option11-y | C1     | Yoga (limited)  | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 1        | yoga     |
      | Booking0  | Option12-c | C1     | Chess           | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 1        | chess    |
      | Booking1  | Option11-t | C1     | Tenis (limited) | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | tenis    |
      | Booking1  | Option12-f | C1     | Football        | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | football |
      | Booking1  | Option13-y | C1     | Yoga            | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | yoga     |
      | Booking1  | Option14-c | C1     | Chess           | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | chess    |
      | Booking1  | Option15-t | C1     | Tenis (limited) | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | tenis    |
      | Booking1  | Option16-t | C1     | Tenis (limited) | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | tenis    |
    And the following config values are set as admin:
      | config                      | value         | plugin  |
      | maxoptionsfromcategory      | 1             | booking |
      | maxoptionsfromcategoryfield | spt1          | booking |
    And I change viewport size to "1366x16000"

  @javascript
  Scenario: Booking: configure max option for category and validate it as student
    Given I am on the "Booking0" "booking activity editing" page logged in as admin
    And I expand all fieldsets
    And I set the following fields to these values:
      | maxoptionsfromcategorycount                          | 2          |
      | id_maxoptionsfromcategoryvalue                       | tenis,yoga |
      | Limitation applies only to bookings of this instance | 1          |
    And I press "Save and display"
    ## Might be necessary in order to get latest sanitized customfield values
    ## And I am on the "Booking1" "booking activity editing" page
    ## And I press "Save and display"
    And I log out
    ## Verify max booking options for 1st instance as a student
    When I am on the "Booking0" Activity page logged in as admin
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r7 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r7" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r7" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r7" "css_element"
    Then I should see "You have reached the maximum of 2 bookings of type \"tenis\" (in category \"spt1\")" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "You have reached the maximum of 2 bookings of type \"tenis\" (in category \"spt1\")" in the ".allbookingoptionstable_r7" "css_element"
    And I should see "You have reached the maximum of 2 bookings of type \"tenis\" (in category \"spt1\")" in the ".allbookingoptionstable_r8" "css_element"
    ## Verify max booking options for 2nd instance as a student
    And I am on the "Booking1" Activity page
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "You have reached the maximum of 3 bookings of type \"tenis\" (in category \"spt1\")" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "You have reached the maximum of 3 bookings of type \"tenis\" (in category \"spt1\")" in the ".allbookingoptionstable_r5" "css_element"
    And I should see "You have reached the maximum of 3 bookings of type \"tenis\" (in category \"spt1\")" in the ".allbookingoptionstable_r6" "css_element"

  @javascript
  Scenario: Booking: configure max option across both instances and validate it as student
    ## Might be necessary in order to get latest sanitized customfield values
    ## Given I am on the "Booking0" "booking activity editing" page logged in as admin
    ## And I press "Save and display"
    ## And I am on the "Booking1" "booking activity editing" page
    ## And I press "Save and display"
    ## And I log out
    ## Verify max booking options as a student
    Given I am on the "Booking1" Activity page logged in as student1
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    ## Validate blocking after 2 booked options for Booking0
    And I am on the "Booking0" Activity page
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "You have reached the maximum of 2 bookings of type \"tenis\" (in category \"spt1\")" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "You have reached the maximum of 2 bookings of type \"tenis\" (in category \"spt1\")" in the ".allbookingoptionstable_r7" "css_element"
    And I should see "You have reached the maximum of 2 bookings of type \"tenis\" (in category \"spt1\")" in the ".allbookingoptionstable_r8" "css_element"
    ## Validate blocking after 3 booked options for Booking1
    And I am on the "Booking1" Activity page
    And I click on "Book now" "text" in the ".allbookingoptionstable_r5 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r5" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r5" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r5" "css_element"
    And I should see "You have reached the maximum of 3 bookings of type \"tenis\" (in category \"spt1\")" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "You have reached the maximum of 3 bookings of type \"tenis\" (in category \"spt1\")" in the ".allbookingoptionstable_r5" "css_element"
    And I should see "You have reached the maximum of 3 bookings of type \"tenis\" (in category \"spt1\")" in the ".allbookingoptionstable_r6" "css_element"
