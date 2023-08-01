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
      | booking     | text            | course | description    | limitanswers | maxanswers | coursestarttime | courseendtime | optiondatestart[0] | optiondateend[0] | optiondatestart[1] | optiondateend[1] | useprice | customfield_spt1 |
      | BookingCMP  | Option-tenis    | C1     | Deskr-tenis    | 1            | 2          | ## tomorrow ##  | ## +4 days ## | ## tomorrow ##     | ## +2 days ##    | ## +3 days ##      | ## +4 days ##    | 1        | tenis            |
      | BookingCMP  | Option-football | C1     | Deskr-football | 1            | 2          | ## tomorrow ##  | ## +5 days ## | ## +2 days ##      | ## +3 days ##    | ## +4 days ##      | ## +4 days ##    | 1        | football         |

  @javascript
  Scenario: Booking campaigns: create settings for booking campaign
    Given I log in as "admin"
    And I visit "/mod/booking/edit_campaigns.php"
    And I click on "Add campaign" "text"
    And I set the following fields to these values:
      | Custom name for the campaign | campaing1          |
      | endtime[year]                | ## + 1 year ##%Y## |
      | Price factor                 | 0.5                |
      | Booking limit factor         | 2                  |
    ## Mandatory workaround for autocomplete field
    And I set the field "Field" to "Sport1"
    And I wait "1" seconds
    And I set the field "Value" to "tenis"
    And I click on "Save changes" "button"
    And I should see "campaing1"
    And I log out
    When I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "BookingCMP"
    Then I should see "Option-football" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "88.00 EUR" in the ".allbookingoptionstable_r1 .pricecurrency" "css_element"
    And I should see "/ 2" in the ".allbookingoptionstable_r1 .col-ap-availableplaces" "css_element"
    And I should see "Option-tenis" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "44.00 EUR" in the ".allbookingoptionstable_r2 .pricecurrency" "css_element"
    And I should see "/ 4" in the ".allbookingoptionstable_r2 .col-ap-availableplaces" "css_element"
    And I log out
