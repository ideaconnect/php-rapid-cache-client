Feature: Unordered sets
  As a developer
  I want to manage unordered collections of distinct values
  So that I can track membership efficiently

  Background:
    Given the cache is empty

  # ---------- Positive paths ----------

  Scenario: createSet seeds a set with the given members
    When I create a set "fruit" with values "apple,banana,cherry"
    And I get set "fruit"
    Then the set should contain "apple"
    And the set should contain "banana"
    And the set should contain "cherry"

  Scenario: createSet returns the exact cardinality of the seeded values
    When I create a set "card_seed" with values "a,b,c,d"
    And I get the cardinality of set "card_seed"
    Then the cardinality should be 4

  Scenario: addToSet appends new members
    When I create a set "growing" with values "a,b"
    And I add "c" to set "growing"
    And I get set "growing"
    Then the set should contain "a"
    And the set should contain "b"
    And the set should contain "c"

  Scenario: addToSet deduplicates — re-adding an existing member is a no-op
    When I create a set "dedup" with values "a,b"
    And I add "a" to set "dedup"
    And I get the cardinality of set "dedup"
    Then the cardinality should be 2

  Scenario: removeFromSet removes a single member
    When I create a set "shrink" with values "x,y,z"
    And I remove "y" from set "shrink"
    And I get set "shrink"
    Then the set should contain "x"
    And the set should not contain "y"
    And the set should contain "z"

  Scenario: createSet replaces the entire set
    When I create a set "swap" with values "old1,old2"
    And I create a set "swap" with values "new1,new2"
    And I get set "swap"
    Then the set should contain "new1"
    And the set should contain "new2"
    And the set should not contain "old1"
    And the set should not contain "old2"

  Scenario: addToSet on a previously non-existent set creates it
    When I add "first" to set "auto_created"
    And I get set "auto_created"
    Then the set should contain "first"

  # ---------- Edge cases / negative paths ----------

  Scenario: removeFromSet for a non-member is a silent no-op
    When I create a set "intact" with values "a,b"
    And I remove "never_here" from set "intact"
    And I get the cardinality of set "intact"
    Then the cardinality should be 2

  Scenario: removeFromSet on a missing set is a silent no-op
    When I remove "anything" from set "missing_set"
    And I get the cardinality of set "missing_set"
    Then the cardinality should be 0

  Scenario: createSet with no values effectively deletes the set
    When I create a set "to_empty" with values "a,b"
    And I create a set "to_empty" with values ""
    And I get the cardinality of set "to_empty"
    Then the cardinality should be 0

  Scenario: getCardinality on an unknown set returns zero
    When I get the cardinality of set "ghost_set"
    Then the cardinality should be 0
