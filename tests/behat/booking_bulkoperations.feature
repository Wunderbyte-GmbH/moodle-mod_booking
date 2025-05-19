@mod @mod_booking @booking_bulkoperations
Feature: As admin - apply bulk operations under booking options.

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
    ## Force timezone
    And the following config values are set as admin:
      | timezone      | Europe/Berlin |
      | forcetimezone | Europe/Berlin |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro               | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | BookingCMP | Booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
      | booking  | C1     | BookingXYZ | Booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
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
      | booking     | text       | course | description    | importing | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | useprice | spt1     | institution | titleprefix |
      | BookingCMP  | Option01-t | C1     | Price-tenis    | 1         | 1          | 0              | 0              | 1652320861        | 1652839261      | 1        | tenis    | hall 2      | 0001   |
      | BookingCMP  | Option02-f | C1     | Price-football | 1         | 2          | 0              | 0              | 1683907261        | 1684425661      | 1        | football | place 2     | 123-s  |
      | BookingCMP  | Option03-y | C1     | Yoga-noprice   | 1         | 3          | 0              | 0              | 1652320861        | 1652839261      | 0        | yoga     | OUTSIDE     | 23-s   |
      | BookingCMP  | Option04-c | C1     | Price-chess    | 1         | 1          | 0              | 0              | 1683907261        | 1684425661      | 1        | chess    | hall 1      | 2345-c |
      | BookingCMP  | Option05-r | C1     | Price-rugby    | 1         | 2          | 0              | 0              | 1652320861        | 1652839261      | 1        | rugby    | hall 1      | 1-g    |
      | BookingCMP  | Option06-d | C1     | Darth-noprice  | 1         | 3          | 0              | 0              | 1683907261        | 1684425661      | 0        | darth    | hall 2      | 14-t   |
      | BookingCMP  | Option07-a | C1     | Price-auto     | 1         | 1          | 0              | 0              | 1652320861        | 1652839261      | 1        | auto     | hall 2      | 0002   |
      | BookingCMP  | Option08-m | C1     | Price-moto     | 1         | 2          | 0              | 0              | 1683907261        | 1684425661      | 1        | moto     | hall 2      | 0003   |
      | BookingCMP  | Option09-p | C1     | Polo-noprice   | 1         | 3          | 0              | 0              | 1652320861        | 1652839261      | 0        | polo     | hall 2      | 0004   |
      | BookingXYZ  | Option10-b | C1     | Price-box      | 1         | 1          | 0              | 0              | 1683907261        | 1684425661      | 1        | box      | hall 2      | 0005   |
      | BookingXYZ  | Option11-j | C1     | Price-jump     | 1         | 2          | 0              | 0              | 1652320861        | 1652839261      | 1        | jump     | hall 2      | 0006   |
      | BookingXYZ  | Option12-s | C1     | Ski-noprice    | 1         | 3          | 0              | 0              | 1683907261        | 1684425661      | 0        | ski      | hall 2      | 0007   |
    ## Option01 - 12 May 2022 2:01:01 AM UTC - 18 May 2022 2:01:01 AM UTC
    ## Option02 - 12 May 2023 4:01:01 PM UTC - 18 May 2023 4:01:01 PM UTC
    And the following "activity" exists:
      | activity       | page                               |
      | course         | C1                                 |
      | idnumber       | bulkoptionpage1                    |
      | name           | BookingOptionsBulk                 |
      | intro          | Booking Options Bulk Page          |
      | content        | [bulkoperations customfields=spt1 filter=institution,coursestarttime,spt1 intrangefilter=titleprefix] |
      | contentformat  | 0                                  |
    And I change viewport size to "1366x6000"

  @javascript
  Scenario: Booking bulkoperations: create list and perform its basic management
    Given I am on the "bulkoptionpage1" Activity page logged in as admin
    ## Verify options visibility along with customfields
    And I should see "Option12-s" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r1')]" "xpath_element"
    And I should see "Option10-b" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r3')]" "xpath_element"
    And I should see "box" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r3')]" "xpath_element"
    And I should see "ski" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r1')]" "xpath_element"
    ## Testing filtering
    And I click on "Filter table" "button" in the ".wunderbyte_table_filter_on" "css_element"
    ## Filtering by title
    And I click on "Title" "button"
    And I should see "Option11-j" in the ".wunderbyteTableFilter" "css_element"
    And I set the field "Option11-j" in the ".wunderbyteTableFilter" "css_element" to "checked"
    And I should see "Option11-j" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r1')]" "xpath_element"
    And "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r2')]" "xpath_element" should not exist
    And I set the field "Option11-j" in the ".wunderbyteTableFilter" "css_element" to ""
    And I should see "12 of 12 records found"
    ##And I should see "Option10-b" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r3')]" "xpath_element"
    ## Filtering by timespan
    And I click on "Start time of the course" "button"
    And I set the field "date-coursestarttime" in the "#id_collapse_coursestarttime" "css_element" to "2022-05-11"
    And I set the field "time-coursestarttime" in the "#id_collapse_coursestarttime" "css_element" to "01:00"
    And I set the field "date-" in the "#id_collapse_coursestarttime" "css_element" to "2022-05-19"
    And I set the field "time-" in the "#id_collapse_coursestarttime" "css_element" to "05:00"
    And I set the field "Display records" in the "#id_collapse_coursestarttime" "css_element" to "within"
    And I set the field "Apply Filter" in the "#id_collapse_coursestarttime" "css_element" to "checked"
    And I should see "6 of 12 records found"
    And I should see "Option11-j" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r1')]" "xpath_element"
    And "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r7')]" "xpath_element" should not exist
    And I set the field "Apply Filter" in the "#id_collapse_coursestarttime" "css_element" to ""
    ## Filtering by spt1 customfield
    And I click on "spt1" "button"
    And I should see "auto (1)" in the ".wunderbyteTableFilter" "css_element"
    And  I set the field "auto (1)" in the ".wunderbyteTableFilter" "css_element" to "checked"
    And I should see "Option07-a" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r1')]" "xpath_element"
    And I set the field "auto (1)" in the ".wunderbyteTableFilter" "css_element" to ""
    ## Filtering by Institution
    And I click on "Institution" "button"
    And I set the field "place 2 (1)" in the ".wunderbyteTableFilter" "css_element" to "checked"
    And I should see "Option02-f" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r1')]" "xpath_element"
    And "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r2')]" "xpath_element" should not exist
    And I set the field "place 2 (1)" in the ".wunderbyteTableFilter" "css_element" to ""
    ## Filtering by intrange
    And I click on "Prefix" "button"
    And I set the field "intrange-start-titleprefix" in the "#id_collapse_titleprefix" "css_element" to "1"
    And I set the field "intrange-end-titleprefix" in the "#id_collapse_titleprefix" "css_element" to "3"
    And I set the field "Apply Filter" in the "#id_collapse_titleprefix" "css_element" to "checked"
    And I should see "4 of 12 records found"
    And I should see "Option08-m" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r1')]" "xpath_element"
    And I should see "Option01-t" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r4')]" "xpath_element"
    And "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r5')]" "xpath_element" should not exist
    ##And I click on "Show all records" "text" in the ".wb-records-count-label" "css_element"
    And I set the field "Apply Filter" in the "#id_collapse_titleprefix" "css_element" to ""
    ## Filtering by booking instance
    And I click on "Booking Instance" "button"
    And I set the field "BookingXYZ" in the "#id_collapse_bookingid" "css_element" to "checked"
    And I should see "3 of 12 records found"
    ## Hide filter panel and remove all filters
    And I click on "//aside[contains(@class, 'wunderbyte_table_components')]" "xpath_element"
    And I click on "Show all records" "text" in the ".wb-records-count-label" "css_element"
    And I should see "12 of 12 records found"
    ## Testing searching
    And I set the field with xpath "//input[contains(@name, 'search-') and contains(@name, '_optionbulkoperationstable_')]" to "Option0"
    And I should see "9 of 12 records found"
    And I should see "Option09-p" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r1')]" "xpath_element"
    And I should see "Option01-t" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r9')]" "xpath_element"
    And "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r10')]" "xpath_element" should not exist
    And I set the field with xpath "//input[contains(@name, 'search-') and contains(@name, '_optionbulkoperationstable_')]" to ""
    ## And I wait until the page is ready
    And I wait "1" seconds
    ## Testing sorting
    And I click on "th.id.wb-table-column.desc" "css_element"
    ## And I wait until the page is ready
    And I wait "1" seconds
    And I should see "Option01-t" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r1')]" "xpath_element"
    And I should see "Option12-s" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r12')]" "xpath_element"
    ## Testing pagination
    And "//nav[@aria-label='Page']" "xpath_element" should not exist
    And I set the field with xpath "//select[contains(@name, 'selectrowsperpage-')]" to "Show 10 rows"
    And "//nav[@aria-label='Page']" "xpath_element" should exist
    And I click on "2" "text" in the "ul.pagination" "css_element"
    And I should see "Option11-j" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r1')]" "xpath_element"
    And I should not see "Option10-b"

  @javascript
  Scenario: Booking bulkoperations: processing of booking options
    Given I am on the "bulkoptionpage1" Activity page logged in as admin
    ## Edit a single option
    And I should see "Option12-s" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r1')]" "xpath_element"
    And I click on "Edit booking option" "link" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r1')]" "xpath_element"
    ## And I wait to be redirected
    And I wait "1" seconds
    ## And I should see "BookingCMP" in the ".h2" "css_element"
    And I should see "BookingCMP"
    And I should see "You are editing \"0007 - Option12-s\"."
    And I set the field "Booking option name" to "Option12-ski"
    And I press "Save"
    And I should see "Option12-ski" in the "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r1')]" "xpath_element"
    ## Edit multiple options
    And I set the field with xpath "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r1')]//input[@type='checkbox' and contains(@name, '_optionbulkoperationstable_')]" to "checked"
    And I set the field with xpath "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r3')]//input[@type='checkbox' and contains(@name, '_optionbulkoperationstable_')]" to "checked"
    And I click on "Edit Bookingoptions" "text" in the ".wunderbyteTableClass" "css_element"
    And I set the field "Select field of booking option" to "Teachers"
    And I click on "btn_bookingruletemplates" "button" in the ".modal-body" "css_element"
    And I wait "1" seconds
    And I set the field "Assign teachers:" to "Teacher 1"
    And I click on "Confirm" "button"
    ## Send multiple emails
    And I set the field with xpath "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r1')]//input[@type='checkbox' and contains(@name, '_optionbulkoperationstable_')]" to "checked"
    And I set the field with xpath "//tr[contains(@id, '_optionbulkoperationstable_') and contains(@id, '_r3')]//input[@type='checkbox' and contains(@name, '_optionbulkoperationstable_')]" to "checked"
    And I click on "Send mail to teacher(s)" "text" in the ".wunderbyteTableClass" "css_element"
    And I wait "1" seconds
    And I set the field "Subject" to "Bulkoperations-subj"
    And I set the field "Email body" to "Bulkoperations-message_body"
    And I click on "Send" "button" in the ".modal-footer" "css_element"
    ## Send messages via cron and verify via events log
    And I trigger cron
    And I visit "/report/loglive/index.php"
    And I should see "Custom message A message e-mail with subject \"Bulkoperations-subj\" has been sent to user: \"Teacher 1\" by the user \"Teacher 1\""
    ## Logout is mandatory for admin pages to avoid error
    And I log out
