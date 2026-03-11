@mod @mod_booking @booking_timezone
Feature: Booking options show times in each user's timezone

  Background:
    Given the following config values are set as admin:
      | config   | value |
      | timezone | UTC   |
      | forcetimezone | 99 |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber | timezone         |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       | UTC              |
      | teacher2 | Teacher   | 2        | teacher2@example.com | T2       | America/New_York |
      | student1 | Student   | 1        | student1@example.com | S1       | Europe/Vienna    |
      | student2 | Student   | 2        | student2@example.com | S2       | Asia/Tehran      |
      | student3 | Student   | 3        | student3@example.com | S3       | America/Chicago  |
      | student4 | Student   | 4        | student4@example.com | S0       | UTC              |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher2 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student4 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name        | intro               | bookingmanager | eventtype | Default view for booking options | optionsfields                                                                                                      |
      | booking  | C1     | BookingTZ   | Booking TZ descr    | teacher1       | Webinar   | All bookings                     | description,statusdescription,teacher,bookingopeningtime,bookingclosingtime,showdates,dayofweektime,location,institution,minanswers |
    And the following "mod_booking > options" exist:
      | booking   | text         | course | description | teachersforoption | maxanswers | availability | restrictanswerperiodopening | bookingopeningtime | restrictanswerperiodclosing | bookingclosingtime | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingTZ | TZ-Option-01 | C1     | TZ Option   | teacher2,teacher1 | 5          | 1            | 1                           | 2373012000         | 1                           | 2373033600         | 0              | 0              | 2373019200        | 2373026400      |
    ## 2045/03/13 10:00 - 2045/03/13 16:00 UTC (booking 01 opening/closing)
    ## 2045/03/13 12:00 - 2045/03/13 14:00 UTC (option 01 dates)
    And I change viewport size to "1366x6000"

  @javascript
  Scenario: Booking option dates are rendered in each user's timezone and not from cache
    Given I am on the "BookingTZ" Activity page logged in as student1
    And I should see "13 March 2045, 1:00 PM (CET)" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "3:00 PM (CET)" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Bookable from: 13 March 2045, 11:00 AM (CET)" in the ".allbookingoptionstable_r1 .bookingopeningtime" "css_element"
    And I should see "Bookable until: 13 March 2045, 5:00 PM (CET)" in the ".allbookingoptionstable_r1 .bookingclosingtime" "css_element"
    And I log out
    When I am on the "BookingTZ" Activity page logged in as student2
    Then I should see "13 March 2045, 3:30 PM (Tehran)" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "5:30 PM (Tehran)" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Bookable from: 13 March 2045, 1:30 PM (Tehran)" in the ".allbookingoptionstable_r1 .bookingopeningtime" "css_element"
    And I should see "Bookable until: 13 March 2045, 7:30 PM (Tehran)" in the ".allbookingoptionstable_r1 .bookingclosingtime" "css_element"
    And I should not see "13 March 2045, 1:00 PM (CET)" in the ".allbookingoptionstable_r1" "css_element"
    When I am on the "BookingTZ" Activity page logged in as student4
    And I should see "13 March 2045, 12:00 PM" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "2:00 PM" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Bookable from: 13 March 2045, 10:00 AM" in the ".allbookingoptionstable_r1 .bookingopeningtime" "css_element"
    And I should see "Bookable until: 13 March 2045, 4:00 PM" in the ".allbookingoptionstable_r1 .bookingclosingtime" "css_element"
    And I should not see "(" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see ")" in the ".allbookingoptionstable_r1" "css_element"
    And I log out

  @javascript
  Scenario: Booking option dates are rendered in Chicago and New_York timezone and not from cache
  ## Very slow test - over 4 min locally.
    Given the following config values are set as admin:
      | config        | value           |
      | timezone      | America/Chicago |
    And I clean booking cache
    And the following "mod_booking > options" exist:
      | booking   | text         | course | description | teachersforoption | maxanswers | availability | restrictanswerperiodopening | bookingopeningtime | restrictanswerperiodclosing | bookingclosingtime | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingTZ | TZ-Option-02 | C1     | TZ Option   | teacher2,teacher1 | 5          | 1            | 1                           | 2370873600         | 1                           | 2370960000         | 0              | 0              | 2370960000        | 2370963600      |
    ## 2045/02/16 11:00 - 2045/02/17 11:00 America/New_York (booking 02 opening/closing)
    ## 2045/02/17 11:00 - 2045/02/17 12:00 America/New_York (option 02 dates)
    When I am on the "BookingTZ" Activity page logged in as teacher2
    Then I should see "17 February 2045, 11:00 AM (EST)" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "12:00 PM (EST)" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I follow "Dates"
    And I should see "17 February 2045, 11:00 AM (EST)" in the "#booking_optiondate_1" "css_element"
    And I should see "12:00 PM (EST)" in the "#booking_optiondate_1" "css_element"
    And I click on "17 February 2045" "text" in the "#booking_optiondate_1" "css_element"
    And the field "coursestarttime_1[hour]" matches value "11"
    And the field "coursestarttime_1[minute]" matches value "00"
    And the field "courseendtime_1[hour]" matches value "12"
    And the field "courseendtime_1[minute]" matches value "00"
    ## BELOW IS EXTREMELY SLOW!
    ##And the following fields match these values:
    ##  | coursestarttime_1[day]                | 17                            |
    ##  | coursestarttime_1[month]              | February                      |
    ##  | coursestarttime_1[hour]               | 11                            |
    ##  | coursestarttime_1[minute]             | 00                            |
    ##  | courseendtime_1[day]                  | 17                            |
    ##  | courseendtime_1[month]                | February                      |
    ##  | courseendtime_1[hour]                 | 12                            |
    ##  | courseendtime_1[minute]               | 00                            |

  @javascript
  Scenario: Booking option dates are rendered using forced timezone when configured
    Given the following config values are set as admin:
      | config        | value         |
      | timezone      | UTC           |
      | forcetimezone | Europe/Vienna |
    And I clean booking cache
    When I am on the "BookingTZ" Activity page logged in as student2
    Then I should see "13 March 2045, 1:00 PM (CET)" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "3:00 PM (CET)" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Bookable from: 13 March 2045, 11:00 AM (CET)" in the ".allbookingoptionstable_r1 .bookingopeningtime" "css_element"
    And I should see "Bookable until: 13 March 2045, 5:00 PM (CET)" in the ".allbookingoptionstable_r1 .bookingclosingtime" "css_element"
    And I should not see "(Tehran)" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    When I am on the "BookingTZ" Activity page logged in as student3
    Then I should see "13 March 2045, 1:00 PM (CET)" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "3:00 PM (CET)" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Bookable from: 13 March 2045, 11:00 AM (CET)" in the ".allbookingoptionstable_r1 .bookingopeningtime" "css_element"
    And I should see "Bookable until: 13 March 2045, 5:00 PM (CET)" in the ".allbookingoptionstable_r1 .bookingclosingtime" "css_element"
    And I should not see "(CDT)" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    When I am on the "BookingTZ" Activity page logged in as student4
    And I should see "13 March 2045, 1:00 PM" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "3:00 PM" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Bookable from: 13 March 2045, 11:00 AM (CET)" in the ".allbookingoptionstable_r1 .bookingopeningtime" "css_element"
    And I should see "Bookable until: 13 March 2045, 5:00 PM (CET)" in the ".allbookingoptionstable_r1 .bookingclosingtime" "css_element"
