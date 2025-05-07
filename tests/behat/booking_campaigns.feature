@mod @mod_booking @booking_campaigns
Feature: Create booking campaigns for booking options as admin and booking it as a student.

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
      | booking     | text            | course | description    | importing | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 | useprice | spt1     |
      | BookingCMP  | Option-tenis    | C1     | Price-tenis    | 1         | 1          | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 1        | tenis    |
      | BookingCMP  | Option-football | C1     | Price-football | 1         | 2          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 1        | football |
      | BookingCMP  | Option-yoga     | C1     | Yoga-noprice   | 1         | 3          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | yoga     |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking campaigns: create settings for booking campaign via UI as admin and edit it
    Given I log in as "admin"
    And I visit "/mod/booking/edit_campaigns.php"
    And I click on "Add campaign" "text"
    And I set the field "Campaign type" to "Change price or booking limit"
    And I set the following fields to these values:
      | Custom name for the campaign | campaing1          |
      | endtime[year]                | ## + 1 year ##%Y## |
      | Price factor                 | 0.5                |
      | Booking limit factor         | 2                  |
    ## Mandatory workaround for autocomplete field
    And I set the field "Booking option field" to "Sport1"
    And I wait "1" seconds
    And I set the field "Value" to "tenis"
    And I click on "Save changes" "button"
    And I wait until the page is ready
    And I should see "campaing1"
    And I click on "Edit" "text" in the ".booking-campaigns-list" "css_element"
    And I wait "1" seconds
    And I set the field "Custom name for the campaign" to "campaign1"
    And I click on "Save changes" "button"
    And I wait until the page is ready
    And I should see "campaign1"

  @javascript
  Scenario: Booking campaigns: create settings for blooking booking campaign via UI as admin and edit it
    Given I log in as "admin"
    And I visit "/mod/booking/edit_campaigns.php"
    And I click on "Add campaign" "text"
    And I set the field "Campaign type" to "Block certain booking options"
    And I set the following fields to these values:
      | Custom name for the campaign   | blogcampaing1      |
      | endtime[year]                  | ## + 1 year ##%Y## |
      | blockoperator                  | blockabove         |
      | Percentage of available places | 30                 |
      | Message when blocking          | BlockAbove30       |
    ## Mandatory workaround for autocomplete field
    And I set the field "Booking option field" to "Sport1"
    And I wait "1" seconds
    And I set the field "Value" to "tenis"
    And I click on "Save changes" "button"
    And I wait until the page is ready
    And I should see "blogcampaing1"
    And I click on "Edit" "text" in the ".booking-campaigns-list" "css_element"
    And I wait "1" seconds
    And I set the field "Custom name for the campaign" to "blockingcampaing1"
    And I click on "Save changes" "button"
    And I wait until the page is ready
    And I should see "blockingcampaing1"

  ## @javascript - JS no need for this test
  Scenario: Booking campaigns: create booking campaign via DB and view as teacher
    Given the following "mod_booking > campaigns" exist:
      | name      | type | json                                                                                                                              | starttime   | endtime        | pricefactor | limitfactor | extendlimitforoverbooked |
      | campaign2 | 0    | {"bofieldname":"spt1","fieldvalue":"tenis", "campaignfieldnameoperator" : "=", "cpfield" : "", "cpoperator" : "", "cpvalue" : ""} | ## today ## | ## + 1 year ## | 0.5         | 2           | 0                        |
    When I am on the "BookingCMP" Activity page logged in as teacher1
    Then I should see "Option-football" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "88.00 EUR" in the ".allbookingoptionstable_r1 .pricecurrency" "css_element"
    And I should see "/ 2" in the ".allbookingoptionstable_r1 .col-ap-availableplaces" "css_element"
    And I should see "Option-tenis" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "44.00 EUR" in the ".allbookingoptionstable_r2 .pricecurrency" "css_element"
    And I should see "/ 2" in the ".allbookingoptionstable_r2 .col-ap-availableplaces" "css_element"

  @javascript
  Scenario: Booking campaigns: create bloking booking campaign via DB view and book as students
    Given the following "mod_booking > campaigns" exist:
      | name      | type | json                                                                                                                                                                                                                                        | starttime   | endtime        | pricefactor | limitfactor |
      | campaign3 | 1    | {"bofieldname":"spt1","fieldvalue":"yoga","blockoperator":"blockabove","blockinglabel":"Above30","hascapability":null,"percentageavailableplaces":30, "campaignfieldnameoperator" : "=", "cpfield" : "", "cpoperator" : "", "cpvalue" : ""} | ## today ## | ## + 1 year ## | 1           | 1           |
    ## Verify "above" blocking campaing - student1 can book
    When I am on the "BookingCMP" Activity page logged in as student1
    And I should see "Option-football" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "88.00 EUR" in the ".allbookingoptionstable_r1 .pricecurrency" "css_element"
    And I should see "/ 2" in the ".allbookingoptionstable_r1 .col-ap-availableplaces" "css_element"
    And I should see "Option-tenis" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "88.00 EUR" in the ".allbookingoptionstable_r2 .pricecurrency" "css_element"
    And I should see "/ 1" in the ".allbookingoptionstable_r2 .col-ap-availableplaces" "css_element"
    And I should see "Option-yoga" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3 .booknow" "css_element"
    And I should see "/ 3" in the ".allbookingoptionstable_r3 .col-ap-availableplaces" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r3 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r3" "css_element"
    And I log out
    ## Verify "above" blocking campaing - student2 can NOT book
    And I am on the "BookingCMP" Activity page logged in as student2
    Then I should see "Above30" in the ".allbookingoptionstable_r3 .booknow" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r3 .booknow" "css_element"
    And I log out
    ## Verify "above" blocking campaing - book student2 by admin
    And I am on the "BookingCMP" Activity page logged in as admin
    And I should see "Above30" in the ".allbookingoptionstable_r3 .booknow" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3 .booknow" "css_element"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Student 2 (student2@example.com)" "text"
    And I click on "Add" "button"
    And I follow "<< Back to responses"
    And I should see "Student 1 (student1)" in the "#mod_booking_all_users_sort_new_r0" "css_element"
    And I should see "Student 2 (student2)" in the "#mod_booking_all_users_sort_new_r1" "css_element"
    And I log out
