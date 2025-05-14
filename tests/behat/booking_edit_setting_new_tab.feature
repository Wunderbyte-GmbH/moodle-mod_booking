@mod @mod_booking @booking_edit_setting_new_tab @mod_booking_edit_setting_new_tab
Feature: Edit booking's "what's new" tab setting as admin and view new tab as students

  Background:
    Given the following config values are set as admin:
    ## Set default test objectives.
      | config          | value         | plugin  |
      | tabwhatsnew     | 1             | booking |
      | tabwhatsnewdays | 50            | booking |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | manager1 | Manager   | 1        | manager1@example.com | M1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | manager1 | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name      | intro        | bookingmanager | eventtype | Default view for booking options | showviews                                                                                                    |
      | booking  | C1     | MyBooking | BookingDescr | manager1       | Webinar   | All bookings                     | showall,mybooking,myoptions,showactive,myinstitution,showvisible,showinvisible,showfieldofstudy,showwhatsnew |
    And the following "mod_booking > options" exist:
      | booking   | text      | course | description | timemadevisible | timecreated    | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | teachersforoption |
      | MyBooking | Option-40 | C1     | Option -40  | ## -40 days ##  | ## -40 days ## | 0              | 0              | ## tomorrow ##    | ## +40 days ##  | teacher1          |
      | MyBooking | Option-20 | C1     | Option -20  | ## -20 days ##  | ## -20 days ## | 0              | 0              | ## tomorrow ##    | ## +20 days ##  | teacher1          |
      | MyBooking | Option-10 | C1     | Option -10  | ## -10 days ##  | ## -10 days ## | 0              | 0              | ## tomorrow ##    | ## +10 days ##  | teacher1          |
      | MyBooking | Option+1  | C1     | Option -40  |                 |                | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | teacher1          |
    And I change viewport size to "1366x4000"

  @javascript
  Scenario: Booking settings - configure whatsnew tab as admin and view it as student
    Given I am on the "MyBooking" Activity page logged in as student1
    And I should see "What's new?" in the "#nav-tabs-booking-options-view" "css_element"
    When I click on "What's new?" "button"
    Then I should see "Option-40" in the ".wunderbyteTableClass.whatsnewtable" "css_element"
    And I should see "Option-20" in the ".wunderbyteTableClass.whatsnewtable" "css_element"
    And I should see "Option-10" in the ".wunderbyteTableClass.whatsnewtable" "css_element"
    And I should not see "Option+1" in the ".wunderbyteTableClass.whatsnewtable" "css_element"
    And I log out
    ## Update tabwhatsnewdays and validate results
    And the following config values are set as admin:
      | config              | value         | plugin  |
      | tabwhatsnewdays     | 15            | booking |
    And I clean booking cache
    And I am on the "MyBooking" Activity page logged in as student1
    And I click on "What's new?" "button"
    And I should see "Option-10" in the ".wunderbyteTableClass.whatsnewtable" "css_element"
    And I should not see "Option-40" in the ".wunderbyteTableClass.whatsnewtable" "css_element"
    And I should not see "Option-20" in the ".wunderbyteTableClass.whatsnewtable" "css_element"
    And I should not see "Option+1" in the ".wunderbyteTableClass.whatsnewtable" "css_element"
