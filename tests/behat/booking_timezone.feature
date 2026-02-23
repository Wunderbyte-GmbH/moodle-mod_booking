@mod @mod_booking @booking_timezone
Feature: Booking options show times in each user's timezone

  Background:
    Given the following config values are set as admin:
      | config   | value |
      | timezone | UTC   |
      | forcetimezone | 99 |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber | timezone        |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       | UTC             |
      | student1 | Student   | 1        | student1@example.com | S1       | Europe/Vienna   |
      | student2 | Student   | 2        | student2@example.com | S2       | Asia/Tehran     |
      | student3 | Student   | 3        | student3@example.com | S3       | America/Chicago |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name        | intro               | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingTZ   | Booking TZ descr    | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking   | text         | course | description | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingTZ | TZ-Option-01 | C1     | TZ Option   | 5          | 0              | 0              | 2373019200        | 2373026400      |
    ## 2045/03/13 12:00 - 2045/03/13 14:00 UTC
    And I change viewport size to "1366x6000"

  @javascript
  Scenario: Booking option dates are rendered in each user's timezone and not from cache
    Given I am on the "BookingTZ" Activity page logged in as student1
    And I should see "13 March 2045, 1:00 PM" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "13 March 2045, 3:00 PM" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    When I am on the "BookingTZ" Activity page logged in as student2
    Then I should see "13 March 2045, 3:30 PM" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "13 March 2045, 5:30 PM" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "13 March 2045, 1:00 PM" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    When I am on the "BookingTZ" Activity page logged in as student3
    Then I should see "13 March 2045, 7:00 AM" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "13 March 2045, 9:00 AM" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "13 March 2045, 1:00 PM" in the ".allbookingoptionstable_r1" "css_element"
