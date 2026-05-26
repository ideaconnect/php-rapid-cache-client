Feature: Bulk operations
  As a developer
  I want to store, fetch, and delete many entries in one call
  So that I avoid N round-trips on hot paths

  Background:
    Given the cache is empty

  # ---------- setMultiple ----------

  Scenario: setMultiple stores every entry
    When I set multiple cache items:
      | key   | value     |
      | bulk1 | val1      |
      | bulk2 | val2      |
      | bulk3 | val3      |
    Then the cache should contain key "bulk1"
    And the cache should contain key "bulk2"
    And the cache should contain key "bulk3"

  Scenario: setMultiple applies a shared TTL
    When I set multiple cache items with TTL 1 seconds:
      | key | value |
      | t1  | a     |
      | t2  | b     |
    And I wait 2 seconds
    Then the cache should not contain key "t1"
    And the cache should not contain key "t2"

  # ---------- getMultiple ----------

  Scenario: getMultiple returns every requested value, in order
    Given I set a cache item with key "g1" and value "alpha"
    And I set a cache item with key "g2" and value "beta"
    And I set a cache item with key "g3" and value "gamma"
    When I retrieve multiple cache items with keys "g1,g2,g3"
    Then the retrieved values should contain "g1" with value "alpha"
    And the retrieved values should contain "g2" with value "beta"
    And the retrieved values should contain "g3" with value "gamma"
    And the retrieved values should have 3 items

  Scenario: getMultiple returns null for missing keys
    Given I set a cache item with key "present" and value "here"
    When I retrieve multiple cache items with keys "present,absent"
    Then the retrieved values should contain "present" with value "here"
    And the retrieved values should have 2 items

  Scenario: getMultiple substitutes the provided default for missing keys
    Given I set a cache item with key "hit" and value "yes"
    When I retrieve multiple cache items with keys "hit,miss" and default "FALLBACK"
    Then the retrieved values should contain "hit" with value "yes"
    And the retrieved values should contain "miss" with value "FALLBACK"

  Scenario: getMultiple on entirely-missing keys returns the default for all
    When I retrieve multiple cache items with keys "x,y,z" and default "n/a"
    Then the retrieved values should contain "x" with value "n/a"
    And the retrieved values should contain "y" with value "n/a"
    And the retrieved values should contain "z" with value "n/a"
    And the retrieved values should have 3 items

  # ---------- deleteMultiple ----------

  Scenario: deleteMultiple removes every listed key
    Given I set a cache item with key "d1" and value "x"
    And I set a cache item with key "d2" and value "y"
    And I set a cache item with key "d3" and value "z"
    When I delete multiple cache items with keys "d1,d2,d3"
    Then the cache should not contain key "d1"
    And the cache should not contain key "d2"
    And the cache should not contain key "d3"

  Scenario: deleteMultiple ignores non-existent keys
    Given I set a cache item with key "real" and value "x"
    When I delete multiple cache items with keys "real,never_existed"
    Then the cache should not contain key "real"
    And the cache should not contain key "never_existed"

  Scenario: deleteMultiple leaves untouched keys alone
    Given I set a cache item with key "keep" and value "safe"
    And I set a cache item with key "drop" and value "gone"
    When I delete multiple cache items with keys "drop"
    Then the cache should contain key "keep"
    And the cache should not contain key "drop"
