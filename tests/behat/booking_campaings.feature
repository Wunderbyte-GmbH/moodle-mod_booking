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
    And the following "mod_booking > options" exist:
      | booking     | text           | course | description   | optiondatestart[0][day] | optiondatestart[0][month] | optiondatestart[0][year] |optiondatestart[0][hour] |optiondatestart[0][minute] |optiondateend[0][day] |optiondateend[0][month] |optiondateend[0][year] |optiondateend[0][hour] | optiondateend[0][minute] |
      | BookingCMP  | Test option 1  | C1     | Option desc 1 | 15 | March | ## + 1 year ##%Y## | 13 | 00 | 15 | March | ## + 1 year ##%Y## | 16 | 00 |
      | BookingCMP  | Test option 2  | C1     | Option desc 2 | 15 | April | ## + 1 year ##%Y## | 13 | 00 | 15 | April | ## + 1 year ##%Y## | 16 | 00 |

  @javascript
  Scenario: Booking campaings: create booking campain
    Given I log in as "admin"
    And I visit "/admin/category.php?category=modbookingfolder"
    And I wait "5" seconds
    ##And I should see "Add campaing"