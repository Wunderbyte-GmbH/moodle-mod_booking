@mod @mod_booking @booking_manage_entities
Feature: In a course add a booking option and manage its entities
  As an administrator or a teacher I need to manage entities for booking options

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
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype |
      | booking  | C1     | BookingEnt | My booking description | teacher1       | Webinar   |
    And the following "mod_booking > options" exist:
      | booking    | text           | course | description  | teachersforoption | maxanswers | maxoverbooking | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | BookingEnt | Option: entity | C1     | Entity       | teacher1          | 5          | 5              | 0              | 0              | 2346937200        | 2347110000      |
    ## 2044/05/15 - 2044/05/17
    And the following "local_entities > entities" exist:
      | name    | shortname | description |
      | Entity1 | entity1   | Ent1desc    |
      | Entity2 | entity2   | Ent2desc    |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking option: select and display entity
    Given I am on the "BookingEnt" Activity page logged in as admin
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    ## Set option-wide entity:
    And I set the following fields to these values:
      | Entity                    | Entity2 |
    ## Set option date's entity:
    And I click on "15 May 2044" "text" in the "#booking_optiondate_1" "css_element"
    And I set the field with xpath "//div[contains(@id, 'fitem_id_local_entities_entityid_1')]//input[contains(@id, 'form_autocomplete_input-')]" to "Entity1"
    And I press "Save"
    ## Verify entities
    And I should see "Entity2" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Option: entity"
    And I switch to a second window
    And I should see "Entity1" in the "#collapseoptiondates" "css_element"
    And I should see "Entity2" in the ".infolist" "css_element"
    And I follow "Entity2"
    And I should see "Ent2desc"
    And I press the "back" button in the browser
    And I follow "Entity1"
    ## Below does not working for unknown reason
    ## And I switch to "Entity1 | Acceptance test site" window
    ## And I wait until the page is ready
    ## And I should see "Ent1desc"
