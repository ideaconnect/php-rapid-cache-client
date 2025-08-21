Feature: Redis Cache Service Comprehensive Tests
  As a developer
  I want to use a cache service with hash-based tagging
  So that I can store and retrieve data efficiently

  Background:
    Given the cache is empty

  Scenario: Setting and retrieving a single element without TTL
    Given I set a cache item with key "test_key" and value "test_value"
    When I retrieve the cache item with key "test_key"
    Then the retrieved value should be "test_value"

  Scenario: Setting and retrieving a single element with TTL while valid
    Given I set a cache item with key "ttl_key" and value "ttl_value" with TTL 5 seconds
    When I retrieve the cache item with key "ttl_key"
    Then the retrieved value should be "ttl_value"

  Scenario: Setting a single element with TTL and retrieval after expiry
    Given I set a cache item with key "expire_key" and value "expire_value" with TTL 1 seconds
    When I wait 2 seconds
    And I retrieve the cache item with key "expire_key"
    Then the retrieved value should be null

  Scenario: Adding two elements and deletion of one
    Given I set a cache item with key "key1" and value "value1"
    And I set a cache item with key "key2" and value "value2"
    When I delete the cache item with key "key1"
    And I retrieve the cache item with key "key1"
    Then the retrieved value should be null
    When I retrieve the cache item with key "key2"
    Then the retrieved value should be "value2"

  Scenario: Using queues - enqueuing and popping elements
    When I enqueue "first" to queue "test_queue"
    And I enqueue "second" to queue "test_queue"
    And I enqueue "third" to queue "test_queue"
    And I pop from queue "test_queue"
    Then the retrieved value should be "first"
    When I pop from queue "test_queue"
    Then the retrieved value should be "second"

  Scenario: Testing queue length
    When I enqueue "item1" to queue "length_queue"
    And I enqueue "item2" to queue "length_queue"
    And I get the length of queue "length_queue"
    Then the queue length should be 2
    When I pop from queue "length_queue"
    And I get the length of queue "length_queue"
    Then the queue length should be 1

  Scenario: Testing increasing and decreasing of a value
    When I increase key "counter" by 10
    And I increase key "counter" by 5
    And I retrieve the cache item with key "counter"
    Then the retrieved value should be "15"
    When I decrease key "counter" by 3
    And I retrieve the cache item with key "counter"
    Then the retrieved value should be "12"

  Scenario: Creation of a set, adding to and removal from set
    When I create a set "test_set" with values "apple,banana,cherry"
    And I get set "test_set"
    Then the set should contain "apple"
    And the set should contain "banana"
    And the set should contain "cherry"
    When I add "date" to set "test_set"
    And I get set "test_set"
    Then the set should contain "date"
    When I remove "banana" from set "test_set"
    And I get set "test_set"
    Then the set should not contain "banana"
    And the set should contain "apple"

  Scenario: Testing cardinality of sets
    When I create a set "card_set" with values "one,two,three"
    And I get the cardinality of set "card_set"
    Then the cardinality should be 3
    When I add "four" to set "card_set"
    And I get the cardinality of set "card_set"
    Then the cardinality should be 4

  Scenario: Adding tagged elements and retrieval by tag
    Given I set a cache item with key "item1" and value "value1" with tag "category_a"
    And I set a cache item with key "item2" and value "value2" with tag "category_a"
    When I retrieve items by tag "category_a"
    Then the retrieved values should contain "item1" with value "value1"
    And the retrieved values should contain "item2" with value "value2"
    And the retrieved values should have 2 items

  Scenario: Adding elements with two different tags and retrieval by one tag
    Given I set a cache item with key "shared1" and value "sharedvalue1" with tag "tag_x"
    And I set a cache item with key "shared2" and value "sharedvalue2" with tag "tag_x"
    And I set a cache item with key "unique1" and value "uniquevalue1" with tag "tag_y"
    And I set a cache item with key "unique2" and value "uniquevalue2" with tag "tag_y"
    When I retrieve items by tag "tag_x"
    Then the retrieved values should contain "shared1" with value "sharedvalue1"
    And the retrieved values should contain "shared2" with value "sharedvalue2"
    And the retrieved values should not contain "unique1"
    And the retrieved values should not contain "unique2"
    And the retrieved values should have 2 items

  Scenario: Adding elements with two tags and removal by one tag
    Given I set a cache item with key "multi1" and value "multivalue1" with tag "remove_tag"
    And I set a cache item with key "multi2" and value "multivalue2" with tag "remove_tag"
    And I set a cache item with key "keep1" and value "keepvalue1" with tag "keep_tag"
    And I set a cache item with key "keep2" and value "keepvalue2" with tag "keep_tag"
    When I remove items by tag "remove_tag"
    And I retrieve items by tag "remove_tag"
    Then the retrieved values should be empty
    When I retrieve items by tag "keep_tag"
    Then the retrieved values should contain "keep1" with value "keepvalue1"
    And the retrieved values should contain "keep2" with value "keepvalue2"
    And the retrieved values should have 2 items
