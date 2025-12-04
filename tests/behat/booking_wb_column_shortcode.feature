@mod @mod_booking @booking_wb_column_shortcode
Feature: As admin - apply a shortcode for processing a column in wb_table.

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
    ## Force timezone
    And the following config values are set as admin:
      | timezone      | Europe/Berlin |
      | forcetimezone | Europe/Berlin |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro               | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingCMP | Booking description | teacher1       | Webinar   | All bookings                     |
      | booking  | C1     | BookingXYZ | Booking description | teacher1       | Webinar   | All bookings                     |
    And the following "custom field categories" exist:
      | name     | component   | area    | itemid |
      | SportArt | mod_booking | booking | 0      |
    And the following "custom fields" exist:
      | name    | category | type | shortname | configdata |
      | Sport1  | SportArt | text | cfspt1    | defsport1  |
      | Format1 | SportArt | text | cffrm1    | defformat1 |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 81           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 72           | 0        | 2                 |
      | 3        | discount2  | Disc2 | 63           | 0        | 3                 |
    And the following "mod_booking > options" exist:
      | booking    | text       | course | description    | importing | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | useprice | cfspt1   | cffrm1  | institution | titleprefix |
      | BookingCMP | Option01-t | C1     | Price-tenis    | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 1        | tenis    | indoor  | hall 2      | 0001   |
      | BookingCMP | Option02-f | C1     | Price-football | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 1        | football | outside | place 2     | 123-s  |
      | BookingCMP | Option03-y | C1     | Yoga-noprice   | 1         | 1          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0        | yoga     | indoor  | OUTSIDE     | 23-s   |
      | BookingCMP | Option04-t | C1     | Price-tenis    | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 1        | tenis    | indoor  | hall 1      | 2345-c |
      | BookingCMP | Option05-f | C1     | Price-football | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 1        | auto     | outside | hall 1      | 1-g    |
      | BookingCMP | Option06-y | C1     | Yoga-noprice   | 1         | 1          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0        | yoga     | indoor  | hall 2      | 14-t   |
      | BookingCMP | Option07-t | C1     | Price-tenis    | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 1        | tenis    | indoor  | hall 2      | 0002   |
      | BookingCMP | Option08-f | C1     | Price-football | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 1        | football | outside | hall 2      | 0003   |
      | BookingCMP | Option09-y | C1     | Yoga-noprice   | 1         | 1          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0        | yoga     | indoor  | hall 2      | 0004   |
      | BookingCMP | Option10-t | C1     | Price-tenis    | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 1        | swim     | pool    | hall 2      | 0005   |
      | BookingXYZ | Option11-f | C1     | Price-football | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 1        | polo     | outside | hall 2      | 0006   |
      | BookingXYZ | Option12-y | C1     | Yoga-noprice   | 1         | 1          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0        | yoga     | indoor  | hall 2      | 0007   |
    And I change viewport size to "1366x6000"

  @javascript
  Scenario: Booking shortcodes: create a shortcode for altering a wb_table column
    Given I am logged in as admin
    And I create a page "wb_column_shortcode1" in course "C1" that refers booking "BookingCMP" with shortcode "[courselist cmid=BookingCMP includecustomfields=\"cfspt1|leftside|fas|fa-running,cffrm1|leftside|far|fa-futbol fa-fw text-gray\"]"
    And I am on the "wb_column_shortcode1" Activity page
    
    ## Verify options visibility along with customfields
    And I should see "0001 - Option01-t" in the "//div[contains(@class, 'mod-booking-row') and contains(@class, '_r1')]" "xpath_element"
    And I should see "tenis" in the "//div[contains(@class, 'mod-booking-row') and contains(@class, '_r1')]" "xpath_element"
    And I should see "indoor" in the "//div[contains(@class, 'mod-booking-row') and contains(@class, '_r1')]" "xpath_element"
    And I should see "0005 - Option10-t" in the "//div[contains(@class, 'mod-booking-row') and contains(@class, '_r10')]" "xpath_element"
    And I should see "swim" in the "//div[contains(@class, 'mod-booking-row') and contains(@class, '_r10')]" "xpath_element"
    And I should see "pool" in the "//div[contains(@class, 'mod-booking-row') and contains(@class, '_r10')]" "xpath_element"
    And I should not see "Option11-f"
    And I should not see "Option12-y"
    ## Verify icons presence
    And "//div[contains(@class, 'mod-booking-row') and contains(@class, '_r1')]//i[contains(@class, 'fas') and contains(@class, 'fa-running')]" "xpath_element" should exist
    And "//div[contains(@class, 'mod-booking-row') and contains(@class, '_r1')]//i[contains(@class, 'far') and contains(@class, 'fa-futbol') and contains(@class, 'text-gray')]" "xpath_element" should exist
    And "//div[contains(@class, 'mod-booking-row') and contains(@class, '_r10')]//i[contains(@class, 'fas') and contains(@class, 'fa-running')]" "xpath_element" should exist
    And "//div[contains(@class, 'mod-booking-row') and contains(@class, '_r10')]//i[contains(@class, 'far') and contains(@class, 'fa-futbol') and contains(@class, 'text-gray')]" "xpath_element" should exist
