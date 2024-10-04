@mod @mod_booking @booking_subboking
Feature: Enabling subboking as admin configuring subboking as a teacher and booking it as a student.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | admin1   | Admin     | 1        | admin1@example.com   | A1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | admin1   | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And I create booking option "Test option 1" in "My booking"
    ## Default - enable subbookings by admin.
    And I log in as "admin"
    And I set the following administration settings values:
      | Activate subbookings | 1 |
    And I change viewport size to "1366x10000"
    And I log out

  @javascript
  Scenario: Add single subbooking option for a booking option as a teacher and verify as students
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I press "Subbookings"
    And I click on "Add a subbooking" "text" in the ".booking-subbookings-container" "css_element"
    ## Only manual fileds setup have to be used - other hand "Expand all" attemted to be invoked.
    And I set the field "Name of the subbooking" to "Partner(s)"
    And I set the field "subbooking_type" to "Additional person booking"
    And I set the field "Describe the additional person booking option" to "You can invite your partner(s):"
    And I press "Save changes"
    And I press "Subbookings"
    And I should see "Partner(s)" in the ".booking-subbookings-list" "css_element"
    And I press "Save"
    And I log out
    ## Verify subbokings working: book as stundet with subbokings
    When I am on the "Course 1" course page logged in as student1
    And I follow "My booking"
    And I wait until the page is ready
    Then I should see "Test option 1" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I wait "1" seconds
    And I should see "Do you want to book Test option 1?" in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I click on "Book now" "text" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I should see "Start" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I follow "Continue"
    And I should see "Partner(s)" in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I press "Partner(s)"
    And I set the field "Add additional person(s)" to "2"
    And I wait "1" seconds
    And I set the following fields to these values:
      | person_firstname_1 | Ann   |
      | person_lastname_1  | Smith |
      | person_age_1       | 20    |
      | person_firstname_2 | Tomm  |
      | person_lastname_2  | Smith |
      | person_age_2       | 30    |
    And I click on "Book now" "text" in the ".subbooking-additionalperson-form" "css_element"
    ## And I should see "Start" in the ".subbooking-additionalperson-form" "css_element"
    And I follow "Continue"
    And I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I should see "Test option 1" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Add subbooking person via DB to a booking option and verify as students
    Given the following "mod_booking > subbookings" exist:
      | name    | type                        | option        | block | json                                                                                                                                     |
      | Partner | subbooking_additionalperson | Test option 1 | 0     | {"name":"Partner(s)","type":"subbooking_additionalperson","data":{"description":"You can invite your partner:","descriptionformat":"1"}} |
    ## Verify subbokings working: book as stundet with subbokings
    When I am on the "Course 1" course page logged in as student1
    And I follow "My booking"
    And I wait until the page is ready
    Then I should see "Test option 1" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I wait "1" seconds
    And I should see "Do you want to book Test option 1?" in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I click on "Book now" "text" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I should see "Start" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I follow "Continue"
    And I should see "Partner(s)" in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I press "Partner(s)"
    And I set the field "Add additional person(s)" to "1"
    And I wait "1" seconds
    And I set the following fields to these values:
      | person_firstname_1 | Ann   |
      | person_lastname_1  | Smith |
      | person_age_1       | 20    |
    And I click on "Book now" "text" in the ".subbooking-additionalperson-form" "css_element"
    And I follow "Continue"
    And I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I should see "Test option 1" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Add subbooking item without price via DB to a booking option and verify as students
    Given the following "mod_booking > subbookings" exist:
      | name | type                      | option        | block | json                                                                                                                                                                                                |
      | item | subbooking_additionalitem | Test option 1 | 0     | {"name":"MyItem","type":"subbooking_additionalitem","data":{"description":"item descr","descriptionformat":"1","useprice":"0","subbookingadditemformlink":"0","subbookingadditemformlinkvalue":""}} |
    ## Verify subbokings working: book as stundet with subbokings
    When I am on the "Course 1" course page logged in as student1
    And I follow "My booking"
    And I wait until the page is ready
    Then I should see "Test option 1" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I wait "1" seconds
    And I should see "Do you want to book Test option 1?" in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I click on "Book now" "text" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I should see "Start" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I follow "Continue"
    And I should see "Supplementary bookings" in the ".modal-dialog.modal-xl .modalHeader" "css_element"
    And I should see "MyItem" in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    And I click on "Book now" "text" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I should see "Start" in the ".modal-dialog.modal-xl .booking-button-area" "css_element"
    And I follow "Continue"
    And I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I should see "Test option 1" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
