Feature: Basic key/value operations
  As a developer
  I want to store, retrieve, and delete individual cache entries
  So that I can use the cache as a simple key/value store

  Background:
    Given the cache is empty

  # ---------- Positive paths ----------

  Scenario: Storing and retrieving a string value
    Given I set a cache item with key "name" and value "alice"
    When I retrieve the cache item with key "name"
    Then the retrieved value should be "alice"

  Scenario: Overwriting an existing key
    Given I set a cache item with key "color" and value "red"
    And I set a cache item with key "color" and value "blue"
    When I retrieve the cache item with key "color"
    Then the retrieved value should be "blue"

  Scenario: Deleting a key removes it
    Given I set a cache item with key "temp" and value "doomed"
    When I delete the cache item with key "temp"
    And I retrieve the cache item with key "temp"
    Then the retrieved value should be null

  Scenario: Deleting one key leaves siblings intact
    Given I set a cache item with key "a" and value "alpha"
    And I set a cache item with key "b" and value "beta"
    When I delete the cache item with key "a"
    And I retrieve the cache item with key "a"
    Then the retrieved value should be null
    When I retrieve the cache item with key "b"
    Then the retrieved value should be "beta"

  Scenario: has() returns true for a stored key
    Given I set a cache item with key "present" and value "yes"
    Then the cache should contain key "present"

  Scenario: has() returns false after delete
    Given I set a cache item with key "doomed" and value "yes"
    When I delete the cache item with key "doomed"
    Then the cache should not contain key "doomed"

  Scenario: clear() wipes everything
    Given I set a cache item with key "k1" and value "v1"
    And I set a cache item with key "k2" and value "v2"
    When I clear the cache
    Then the cache should not contain key "k1"
    And the cache should not contain key "k2"

  # ---------- Missing-key handling ----------

  Scenario: Retrieving a missing key returns null
    When I retrieve the cache item with key "ghost"
    Then the retrieved value should be null

  Scenario: Retrieving a missing key with a default returns the default
    When I retrieve the cache item with key "ghost" with default "fallback"
    Then the retrieved value should be "fallback"

  Scenario: has() returns false for a missing key
    Then the cache should not contain key "ghost"

  Scenario: Deleting a missing key is a silent no-op
    When I delete the cache item with key "never_existed"
    Then the cache should not contain key "never_existed"

  Scenario: clear() on an already-empty cache succeeds
    When I clear the cache
    Then the cache should not contain key "anything"
