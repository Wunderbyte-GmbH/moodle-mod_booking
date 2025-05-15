@mod @mod_booking @booking_maxoptionforcategory_dynamicdropdown
Feature: As admin - configure max option for category with dynamic dropdown customfield and validate it as student.

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
      | name  | component   | area    | itemid |
      | UserN | mod_booking | booking | 0      |
    And the following "custom fields" exist:
      | name     | category | type          | shortname   | configdata                                          |
      | DynamicU | UserN    | dynamicformat | dynamicuser | {"required":"0","uniquevalues":"0","dynamicsql":"SELECT username as id, username as data FROM {user}","autocomplete":"0","defaultvalue":"1","multiselect":"1"} |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 88           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 77           | 0        | 2                 |
      | 3        | discount2  | Disc2 | 66           | 0        | 3                 |
    And the following "activities" exist:
    ## Base64 of customfield value must be used as key in json:
      | activity | course | name     | intro               | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail | json                                                                                                                        |
      | booking  | C1     | Booking0 | Booking description | teacher1       | Webinar   | All bookings                     | Yes                      | {"maxoptionsfromcategory":"{\"dGVhY2hlcjE=\":{\"count\":2,\"localizedstring\":\"teacher1\"}}","maxoptionsfrominstance":"0"} |
      | booking  | C1     | Booking1 | Booking description | teacher1       | Webinar   | All bookings                     | Yes                      | {"maxoptionsfromcategory":"{\"dGVhY2hlcjE=\":{\"count\":3,\"localizedstring\":\"teacher1\"}}","maxoptionsfrominstance":"0"} |
    And the following "mod_booking > options" exist:
      | booking   | text       | course | description        | importing | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 | useprice | dynamicuser |
      | Booking0  | Option01-t | C1     | teacher1 (limited) | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | teacher1,student2    |
      | Booking0  | Option02-f | C1     | student2           | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | student2 |
      | Booking0  | Option03-y | C1     | student1 (limited) | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | student1    |
      | Booking0  | Option04-c | C1     | student2           | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | student2    |
      | Booking0  | Option05-r | C1     | student2           | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | student2    |
      | Booking0  | Option06-d | C1     | student2           | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | student2    |
      | Booking0  | Option07-t | C1     | teacher1 (limited) | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | teacher1    |
      | Booking0  | Option08-t | C1     | teacher1 (limited) | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 1        | teacher1    |
      | Booking0  | Option09-y | C1     | student1 (limited) | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | student1    |
      | Booking0  | Option10-c | C1     | student2           | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | student2    |
      | Booking0  | Option11-y | C1     | student1 (limited) | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 1        | student1    |
      | Booking0  | Option12-c | C1     | student2           | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 1        | student2    |
      | Booking1  | Option11-t | C1     | teacher1 (limited) | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | teacher1    |
      | Booking1  | Option12-f | C1     | student2           | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | student2    |
      | Booking1  | Option13-y | C1     | student1 (limited) | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | student1    |
      | Booking1  | Option14-c | C1     | student2           | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | student2    |
      | Booking1  | Option15-t | C1     | teacher1 (limited) | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | teacher1    |
      | Booking1  | Option16-t | C1     | teacher1 (limited) | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | teacher1    |
    And the following config values are set as admin:
      | config                      | value         | plugin  |
      | maxoptionsfromcategory      | 1             | booking |
      | maxoptionsfromcategoryfield | dynamicuser   | booking |
    And I change viewport size to "1366x16000"

  @javascript
  Scenario: Booking: configure max option for category via dynamic dropdown customfield with multiselect and validate it as student
    Given I am on the "Booking0" "booking activity editing" page logged in as admin
    And I expand all fieldsets
    And I set the following fields to these values:
      | maxoptionsfromcategorycount                          | 2                 |
      | id_maxoptionsfromcategoryvalue                       | teacher1,student1 |
      | Limitation applies only to bookings of this instance | 1                 |
    And I press "Save and display"
    ## Might be necessary in order to get latest sanitized customfield values
    ## And I am on the "Booking1" "booking activity editing" page
    ## And I press "Save and display"
    And I log out
    ## Verify max booking options for 1st instance as a student
    When I am on the "Booking0" Activity page logged in as student1
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r7 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r7" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r7" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r7" "css_element"
    Then I should see "You have reached the maximum of 2 bookings of type \"teacher1\" (in category \"dynamicuser\")" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "You have reached the maximum of 2 bookings of type \"teacher1\" (in category \"dynamicuser\")" in the ".allbookingoptionstable_r7" "css_element"
    And I should see "You have reached the maximum of 2 bookings of type \"teacher1\" (in category \"dynamicuser\")" in the ".allbookingoptionstable_r8" "css_element"
    ## Verify max booking options for 2nd instance as a student
    And I am on the "Booking1" Activity page
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "You have reached the maximum of 3 bookings of type \"teacher1\" (in category \"dynamicuser\")" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "You have reached the maximum of 3 bookings of type \"teacher1\" (in category \"dynamicuser\")" in the ".allbookingoptionstable_r5" "css_element"
    And I should see "You have reached the maximum of 3 bookings of type \"teacher1\" (in category \"dynamicuser\")" in the ".allbookingoptionstable_r6" "css_element"
