@mod @mod_booking
Feature: Booking works correctly
  To see if booking works correctly - I need to be able to add a group of users to a booking option and it should stop me from adding too many users to a limited audience booking option

  Background:

    Given the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |

    And the following "users" exist:
      | username | firstname | lastname | email |
      | student1 | Test | Testko1 | student1@example.com |
      | student2 | Test | Testko2 | student2@example.com |
      | student3 | Test | Testko3 | student3@example.com |
      | student4 | Test | Testko4 | student4@example.com |

    And the following "course enrolments" exist:
      | user | course | role |
      | student1 | C1 | student |
      | student2 | C1 | student |
      | student3 | C1 | student |
      | student4 | C1 | student |

    And the following "groups" exist:
      | name | course | idnumber |
      | G1   | C1     | GI1      |

    And the following "group members" exist:
      | user     | group |
      | student1 | GI1   |
      | student2 | GI1   |

    And I log in as "admin"

    And I am on "Course 1" course homepage with editing mode on
    
    #Create booking activity
    And I click on "Add an activity or resource" "button"
    And I click on "Add a new Booking" "link"
    And I set the field "name" to "Prijava na izpit"
    And I set the field "eventtype" to "izpit"
    And I click on "Save and return to course" "button"

  @javascript
  Scenario: Adding group of users
    #Add booking option
    When I am on "Course 1" course homepage with editing mode on
    And I click on "Prijava na izpit" "link"
    And I click on "Actions menu" "link"
    And I click on "Add a new booking option" "link"
    And I set the field "text" to "Prva izvedba dogodka"
    And I click on "Save and display" "button"
    
    And I click on "Actions menu" "link"
    And I click on "Book users from group" "link"
    And I click on "Save changes" "button"
    Then I should see "Test Testko1 (student1)"
    And I should see "Test Testko2 (student2)"
    And I should not see "Test Testko3"
    And I should not see "Test Testko4"



  @javascript
  Scenario: Limited number of particpants
    #Add booking option
    When I am on "Course 1" course homepage with editing mode on
    And I click on "Prijava na izpit" "link"
    And I click on "Actions menu" "link"
    And I click on "Add a new booking option" "link"
    And I set the field "text" to "Prva izvedba dogodka"
    And I set the field "limitanswers" to "1"
    And I set the field "maxanswers" to "2"
    And I set the field "maxoverbooking" to "0"
    And I click on "Save and display" "button"
    
    And I click on "Actions menu" "link"
    And I click on "Book other users" "link"
    And I set the field "addselect[]" to "1,2"
    And I click on "subscribe" "button"
    And I should see "All 2 selected users have successfully been assigned to this booking option."
    And I set the field "addselect[]" to "3"
    And I click on "subscribe" "button"
    Then I should see "The following users could not be booked due to reaching the max number of bookings per user or lack of available places for the booking option: Test Testko3"

  @javascript
  Scenario: Limited number of particpants with group add - partially full
    #Add booking option
    When I am on "Course 1" course homepage with editing mode on
    And I click on "Prijava na izpit" "link"
    And I click on "Actions menu" "link"
    And I click on "Add a new booking option" "link"
    And I set the field "text" to "Prva izvedba dogodka"
    And I set the field "limitanswers" to "1"
    And I set the field "maxanswers" to "2"
    And I set the field "maxoverbooking" to "0"
    And I click on "Save and display" "button"
    
    And I click on "Actions menu" "link"
    And I click on "Book other users" "link"
    And I set the field "addselect[]" to "3"
    And I click on "subscribe" "button"
    And I should see "All 1 selected users have successfully been assigned to this booking option."
    
    And I am on "Course 1" course homepage with editing mode on
    And I click on "Prijava na izpit" "link"
    And I click on "Active bookings" "link"
    And I click on "Settings" "icon"
    And I click on "Book users from group" "link"
    And I click on "Save changes" "button"
    Then I should see "Test Testko3 (student3)"
    And I should see "Test Testko1 (student1)"
    And I should not see "Test Testko2"
    And I should not see "Test Testko4"

    @javascript
  Scenario: Limited number of particpants with group add - full
    #Add booking option
    When I am on "Course 1" course homepage with editing mode on
    And I click on "Prijava na izpit" "link"
    And I click on "Actions menu" "link"
    And I click on "Add a new booking option" "link"
    And I set the field "text" to "Prva izvedba dogodka"
    And I set the field "limitanswers" to "1"
    And I set the field "maxanswers" to "2"
    And I set the field "maxoverbooking" to "0"
    And I click on "Save and display" "button"
    
    And I click on "Actions menu" "link"
    And I click on "Book other users" "link"
    And I set the field "addselect[]" to "3,4"
    And I click on "subscribe" "button"
    And I should see "All 2 selected users have successfully been assigned to this booking option."
    
    And I am on "Course 1" course homepage with editing mode on
    And I click on "Prijava na izpit" "link"
    And I click on "Active bookings" "link"
    And I click on "Settings" "icon"
    And I click on "Book users from group" "link"
    And I click on "Save changes" "button"
    Then I should see "Test Testko3 (student3)"
    And I should see "Test Testko4 (student4)"
    And I should not see "Test Testko1"
    And I should not see "Test Testko2"