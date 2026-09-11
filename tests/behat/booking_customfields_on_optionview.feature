@mod @mod_booking @booking_customfields_on_optionview
Feature: As admin - configure customfield filter for booking instance and view it on the optionview page.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S1       |
      | student3 | Student   | 3        | student3@example.com | S1       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And I clean booking cache
    And the following "custom field categories" exist:
      | name        | component   | area    | itemid |
      | SportArt    | mod_booking | booking | 0      |
      | OtherFields | mod_booking | booking | 1      |
    And the following "custom fields" exist:
      | name      | category    | type          | shortname   | configdata                                                                                                                                               |
      | Sport1    | SportArt    | text          | spt1        | {"required":"0","uniquevalues":"0","defaultvalue":"defsport","displaysize":50,"maxlength":1333,"ispassword":"0","link":"","locked":"0","visibility":"2"} |
      | dtime     | OtherFields | date          | dtime       | {"required":"0","uniquevalues":"0","includetime":"1","mindate":0,"maxdate":0,"locked":"0","visibility":"2"}                                              |
      | dtext     | OtherFields | textarea      | dtext       | {"required":"0","uniquevalues":"0","locked":"0","visibility":"2","defaultvalue":"<p><strong>text<\/strong> <em>area <a href=\"http:\/\/google.com\">test<\/a><\/em><\/p>","defaultvalueformat":"1"} |
      | ddownmenu | OtherFields | select        | ddownmenu   | {"required":"0","uniquevalues":"0","locked":"0","visibility":"2","defaultvalue":"1","options":"Option1\nOption2\nOption3"}                               |
      | dnumber   | OtherFields | number        | dnumber     | {"required":"0","uniquevalues":"0","locked":"0","visibility":"2","defaultvalue":"5","rangefrom":"0","rangeto":"10"}                                      |
      | DynamicU  | OtherFields | dynamicformat | dynamicuser | {"required":"0","uniquevalues":"0","dynamicsql":"SELECT username as id, username as data FROM {user}","autocomplete":"0","defaultvalue":"1","multiselect":"1"} |
    And the following config values are set as admin:
       | config                 | value                                            | plugin  |
       | optionviewcustomfields | 0,spt1,dtime,dtext,ddownmenu,dnumber,dynamicuser | booking |
    And the following "activities" exist:
      | activity | course | name     | intro               | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | Booking0 | Booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And the following "mod_booking > options" exist:
      | booking   | text       | course | description            | importing | maxanswers | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 | useprice | spt1  | dtime      | ddownmenu | dynamicuser       |
      | Booking0  | Option01-t | C1     | tenis-{dnumber}-{spt1} | 1         | 3          | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0        | tenis | 2346937200 | 1         | student1,student3 |
      | Booking0  | Option02-f | C1     | yoga                   | 1         | 3          | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   | 0        | yoga  | 2347110000 | 0         | student2,student3 |
    ## 2044/05/15 and 2044/05/17
    And the following "custom field categories" exist:
      | name        | component   | area    | itemid |
      | OtherFields | core_course | course  | 2      |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking: configure multiple customfield values and view it on the optionview page
    Given I am on the "Booking0" Activity page logged in as admin
    ## Verify customfields placeholder in description
    And I should see "tenis-5-tenis" in the ".allbookingoptionstable_r1" "css_element"
    ## Verify customfields values on optionview page
    And I click on "Option01-t" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I switch to a second window
    And I should see "tenis" in the ".optionview-customfield-spt1" "css_element"
    And I should see "15 May 2044" in the ".optionview-customfield-dtime" "css_element"
    And I should see "textarea test" in the ".optionview-customfield-dtext .text_to_html" "css_element"
    And I should see "Option1" in the ".optionview-customfield-ddownmenu" "css_element"
    And I should see "5" in the ".optionview-customfield-dnumber" "css_element"
    And I should see "student1, student3" in the ".optionview-customfield-dynamicuser" "css_element"
    ## Verify customfields placeholder in description again
    And I should see "tenis-5-tenis" in the ".mod-booking-row .content" "css_element"
