@mod @mod_booking @option_images
Feature: Upload booking images for booking options as admin and view it.

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
    And I change viewport size to "1366x10000"

  ## @javascript - JS no need for this test
  Scenario: Booking options: create options with images by customfields via DB and view as admin
    Given the following "activities" exist:
      | activity | course | name     | intro               | bookingmanager | eventtype | bookingimagescustomfield | Default view for booking options  | json                                                                                                                       |
      | booking  | C1     | Booking1 | default image exist | teacher1       | Webinar   | spt1                     | All bookings                      | {"switchtemplates":1,"viewparam":"2","switchtemplatesselection":["0","1","2","3","4"],"unenrolfromgroupofcurrentcourse":1} |
    ## "viewparam":"2" enforces "List view with image on the left"
    And the following "mod_booking > bookingimages" exist:
      | filepath                                  | filename       | booking  |
      | mod/booking/tests/fixtures/fussball.png   | fussball.png   | Booking1 |
      | mod/booking/tests/fixtures/volleyball.png | volleyball.png | Booking1 |
      | mod/booking/tests/fixtures/yoga.png       | yoga.png       | Booking1 |
    And the following "mod_booking > options" exist:
      | booking   | text            | course | description    | importing | spt1     | useprice | maxanswers | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | Booking1  | Option-tenis    | C1     | Price-tenis    | 1         | tenis    | 1        | 1          | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
      | Booking1  | Option-football | C1     | Price-football | 1         | fussball | 1        | 2          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   |
      | Booking1  | Option-yoga     | C1     | Yoga-noprice   | 1         | yoga     | 0        | 3          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   |
      | Booking1  | Option-swim     | C1     | Swim-noprice   | 1         |          | 0        | 4          | 1           | 0              | 0              | ## +3 days ##     | ## +4 days ##   |
    And I am on the "Booking1" Activity page logged in as admin
    ## Validate option with image by customfield and with price
    And I should see "Option-football" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "88.00 EUR" in the ".allbookingoptionstable_r1 .pricecurrency" "css_element"
    ### Confirm that image by customfield exists
    And "//img[contains(@src, '/fussball.png')]" "xpath_element" should exist in the ".allbookingoptionstable_r1" "css_element"
    ## Validate option without image and without price
    And I should see "Option-swim" in the ".allbookingoptionstable_r2" "css_element"
    And ".pricecurrency" "css_element" should not exist in the ".allbookingoptionstable_r2" "css_element"
    And "//img" "xpath_element" should not exist in the ".allbookingoptionstable_r2" "css_element"
    ## Validate option without image and with price
    And I should see "Option-tenis" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "88.00 EUR" in the ".allbookingoptionstable_r3 .pricecurrency" "css_element"
    And "//img" "xpath_element" should not exist in the ".allbookingoptionstable_r3" "css_element"
    ## Validate option with image and without price
    And I should see "Option-yoga" in the ".allbookingoptionstable_r4" "css_element"
    And ".pricecurrency" "css_element" should not exist in the ".allbookingoptionstable_r4" "css_element"
    ### Confirm that image by customfield exists
    And "//img[contains(@src, '/yoga.png')]" "xpath_element" should exist in the ".allbookingoptionstable_r4" "css_element"
