@mod @mod_booking @booking_campaings
Feature: Create booking campaings for booking options as a teacher and booking it as a student.

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
      | booking     | text           | course | description   | coursestarttime | courseendtime | optiondatestart[0] | optiondateend[0] | optiondatestart[1] | optiondateend[1] | useprice | customfield_spt1 |
      | BookingCMP  | Test option 1  | C1     | Option desc 1 | ## tomorrow ##  | ## +4 days ## | ## tomorrow ##     | ## +2 days ##    | ## +3 days ##      | ## +4 days ##    | 1        | tenis            |
      | BookingCMP  | Test option 2  | C1     | Option desc 2 | ## tomorrow ##  | ## +5 days ## | ## +2 days ##      | ## +3 days ##    | ## +4 days ##      | ## +4 days ##    | 1        | football         |

  @javascript
  Scenario: Booking campaings: create booking campain
    Given I log in as "admin"
    And I visit "/admin/category.php?category=modbookingfolder"
    And I wait "5" seconds
    ##And I should see "Add campaing"
