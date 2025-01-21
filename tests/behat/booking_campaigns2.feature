@mod @mod_booking @booking_campaigns2
Feature: Create booking campaigns2 for booking options as admin and booking it as a student.

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname | name     |
      | text     | ucustom1  | ucustom1 |
      | text     | ucustom2  | ucustom2 |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber | profile_field_ucustom1 | profile_field_ucustom2 |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |                        |                        |
      | student1 | Student   | 1        | student1@example.com | S1       | student                | no                     |
      | student2 | Student   | 2        | student2@example.com | S2       | teacher                |                        |
      | student3 | Student   | 3        | student3@example.com | S3       |                        | yes                    |
      | student4 | Student   | 4        | student4@example.com | S4       |                        |                        |
      | student5 | Student   | 5        | student5@example.com | S5       |                        |                        |
      | student6 | Student   | 6        | student6@example.com | S6       |                        |                        |
      | student7 | Student   | 7        | student7@example.com | S7       |                        |                        |
      | student8 | Student   | 8        | student8@example.com | S8       |                        |                        |
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
      | student4 | C1     | student        |
      | student5 | C1     | student        |
      | student6 | C1     | student        |
      | student7 | C1     | student        |
      | student8 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro               | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | BookingCMP | Booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And the following "custom field categories" exist:
      | name           | component   | area    | itemid |
      | BookCustomCat1 | mod_booking | booking | 0      |
    And the following "custom fields" exist:
      | name     | category       | type | shortname | configdata[defaultvalue] |
      | bcustom1 | BookCustomCat1 | text | bcustom1  |                          |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 88           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 77           | 0        | 2                 |
      | 3        | discount2  | Disc2 | 66           | 0        | 3                 |
    And the following "mod_booking > options" exist:
      | booking     | text                | course | description     | importing | bcustom1 | useprice | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingCMP  | Option-exclude      | C1     | NoPrice-exclude | 1         | exclude  | 0        | 6          | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | BookingCMP  | Option-nocustom     | C1     | nocustom        | 1         |          | 0        | 6          | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | BookingCMP  | Option-include      | C1     | NoPrice-include | 1         | include  | 0        | 6          | 1           | 0              | 0              | ## tomorrow ##    | ## +3 days ##   |
      | BookingCMP  | Option-priceexclude | C1     | Price-exclude   | 1         | exclude  | 1        | 6          | 1           | 0              | 0              | ## tomorrow ##    | ## +3 days ##   |
      | BookingCMP  | Option-priceinclude | C1     | Price-include   | 1         | include  | 1        | 6          | 1           | 0              | 0              | ## tomorrow ##    | ## +3 days ##   |
    And the following "mod_booking > answers" exist:
      | booking    | option         | user     |
      | BookingCMP | Option-exclude | student4 |
      | BookingCMP | Option-exclude | student5 |
      | BookingCMP | Option-exclude | student6 |
      | BookingCMP | Option-exclude | student7 |
      | BookingCMP | Option-exclude | student8 |
      | BookingCMP | Option-include | student4 |
      | BookingCMP | Option-include | student5 |
      | BookingCMP | Option-priceexclude | student4 |
      | BookingCMP | Option-priceexclude | student5 |
      | BookingCMP | Option-priceexclude | student6 |
      | BookingCMP | Option-priceexclude | student7 |
      | BookingCMP | Option-priceexclude | student8 |
      | BookingCMP | Option-priceinclude | student4 |
      | BookingCMP | Option-priceinclude | student5 |
    And I change viewport size to "1366x10000"

  ## @javascript
  Scenario: Booking campaigns2: conditions states option customfield does not contain and profile field does contain values
    Given the following "mod_booking > campaigns" exist:
      | name      | type | json                                                                                                                                                                                                                                                    | starttime       | endtime         | pricefactor | limitfactor |
      | campaign3 | 1    | {"bofieldname":"bcustom1","fieldvalue":"exclude","campaignfieldnameoperator":"!~","cpfield":"ucustom1","cpoperator":"~","cpvalue":["student"],"blockoperator":"blockbelow","blockinglabel":"Below50","hascapability":null,"percentageavailableplaces":50} | ## yesterday ## | ## + 1 month ## | 1           | 1           |
    ## Verify "above" blocking campaing2
    When I am on the "BookingCMP" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Below50" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    And I should see "Below50" in the ".allbookingoptionstable_r3 .booknow" "css_element"
    And I should see "Add to cart" in the ".allbookingoptionstable_r4 .booknow" "css_element"
    And I should see "Below50" in the ".allbookingoptionstable_r5 .booknow" "css_element"
    And I log out
    And I am on the "BookingCMP" Activity page logged in as student2
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3 .booknow" "css_element"
    And I should see "Add to cart" in the ".allbookingoptionstable_r4 .booknow" "css_element"
    And I should see "Add to cart" in the ".allbookingoptionstable_r5 .booknow" "css_element"
    And I log out
    And I am on the "BookingCMP" Activity page logged in as student3
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3 .booknow" "css_element"
    And I should see "Add to cart" in the ".allbookingoptionstable_r4 .booknow" "css_element"
    And I should see "Add to cart" in the ".allbookingoptionstable_r5 .booknow" "css_element"
    And I log out

  ## @javascript
  Scenario: Booking campaigns2: conditions states option customfield does contain and profile field does not contain values
    Given the following "mod_booking > campaigns" exist:
      | name      | type | json                                                                                                                                                                                                                                                      | starttime       | endtime         | pricefactor | limitfactor |
      | campaign3 | 1    | {"bofieldname":"bcustom1","fieldvalue":"include","campaignfieldnameoperator":"=","cpfield":"ucustom1","cpoperator":"!~","cpvalue":["student"],"blockoperator":"blockbelow","blockinglabel":"Below50","hascapability":null,"percentageavailableplaces":50} | ## yesterday ## | ## + 1 month ## | 1           | 1           |
    ## Verify "above" blocking campaing2
    When I am on the "BookingCMP" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3 .booknow" "css_element"
    And I should see "Add to cart" in the ".allbookingoptionstable_r4 .booknow" "css_element"
    And I should see "Add to cart" in the ".allbookingoptionstable_r5 .booknow" "css_element"
    And I log out
    And I am on the "BookingCMP" Activity page logged in as student2
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Below50" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3 .booknow" "css_element"
    And I should see "Add to cart" in the ".allbookingoptionstable_r4 .booknow" "css_element"
    And I should see "Below50" in the ".allbookingoptionstable_r5 .booknow" "css_element"
    And I log out
    And I am on the "BookingCMP" Activity page logged in as student3
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Below50" in the ".allbookingoptionstable_r2 .booknow" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3 .booknow" "css_element"
    And I should see "Add to cart" in the ".allbookingoptionstable_r4 .booknow" "css_element"
    And I should see "Below50" in the ".allbookingoptionstable_r5 .booknow" "css_element"
    And I log out
