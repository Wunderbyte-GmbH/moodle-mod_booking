@mod @mod_booking @booking_bulkoperations
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
      | activity | course | name       | intro               | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | BookingCMP | Booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
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
      | booking     | text       | course | description     | importing | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 | useprice | spt1     |
      | BookingCMP  | Option01-t | C1     | Tenis (limited) | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | tenis    |
      | BookingCMP  | Option02-f | C1     | Football        | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | football |
      | BookingCMP  | Option03-y | C1     | Yoga (limited)  | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | yoga     |
      | BookingCMP  | Option04-c | C1     | Chess           | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | chess    |
      | BookingCMP  | Option05-r | C1     | Rugby           | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | rugby    |
      | BookingCMP  | Option06-d | C1     | Darth           | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | darth    |
      | BookingCMP  | Option07-a | C1     | Tenis (limited) | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | tenis    |
      | BookingCMP  | Option08-m | C1     | Tenis (limited) | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 1        | tenis    |
      | BookingCMP  | Option09-p | C1     | Yoga (limited)  | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | yoga     |
      | BookingCMP  | Option10-b | C1     | Chess           | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | chess    |
      | BookingCMP  | Option11-j | C1     | Yoga (limited)  | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 1        | yoga     |
      | BookingCMP  | Option12-s | C1     | Chess           | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 1        | chess    |
    And I change viewport size to "1366x10000"
    ## Unfortunately, TinyMCE is slow and has misbehavior which might cause number of site-wide issues. So - we disable it.
    And the following config values are set as admin:
      | config                      | value         | plugin  |
      | texteditors                 | atto,textarea |         |
      | maxoptionsfromcategory      | 1             | booking |
      | maxoptionsfromcategoryfield | spt1          | booking |

  @javascript
  Scenario: Booking: configure max option for category and validate it as student
    Given I am on the "BookingCMP" "booking activity editing" page logged in as admin
    And I expand all fieldsets
    And I set the following fields to these values:
      | maxoptionsfromcategorycount    | 2          |
      | id_maxoptionsfromcategoryvalue | tenis,yoga |
    And I press "Save and display"
    And I log out
    ## Verify max options availability as a student
    When I am on the "BookingCMP" Activity page logged in as student1
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
