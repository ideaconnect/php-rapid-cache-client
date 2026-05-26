Feature: FIFO queues
  As a developer
  I want to use cache keys as producer/consumer queues
  So that I can pass work items between processes

  Background:
    Given the cache is empty

  # ---------- enqueue / pop FIFO ordering ----------

  Scenario: pop returns items in enqueue order
    When I enqueue "first" to queue "jobs"
    And I enqueue "second" to queue "jobs"
    And I enqueue "third" to queue "jobs"
    And I pop from queue "jobs"
    Then the retrieved value should be "first"
    When I pop from queue "jobs"
    Then the retrieved value should be "second"
    When I pop from queue "jobs"
    Then the retrieved value should be "third"

  Scenario: pop drains the queue and subsequent pops return null
    When I enqueue "only_one" to queue "small"
    And I pop from queue "small"
    Then the retrieved value should be "only_one"
    When I pop from queue "small"
    Then the retrieved value should be null

  Scenario: Bulk pop returns up to N head items
    When I enqueue "a" to queue "bulk_q"
    And I enqueue "b" to queue "bulk_q"
    And I enqueue "c" to queue "bulk_q"
    And I pop 2 items from queue "bulk_q"
    Then the retrieved values should equal "a,b"
    When I get the length of queue "bulk_q"
    Then the queue length should be 1

  Scenario: Bulk pop with N larger than available returns whatever is there
    When I enqueue "x" to queue "short_q"
    And I enqueue "y" to queue "short_q"
    And I pop 10 items from queue "short_q"
    Then the retrieved values should equal "x,y"
    When I get the length of queue "short_q"
    Then the queue length should be 0

  # ---------- peek ----------

  Scenario: peek returns the head without removing it
    When I enqueue "look_at_me" to queue "peeked"
    And I enqueue "but_not_me" to queue "peeked"
    And I peek from queue "peeked"
    Then the retrieved value should be "look_at_me"
    When I get the length of queue "peeked"
    Then the queue length should be 2

  Scenario: peek N items returns the head window without removing
    When I enqueue "1" to queue "peek_window"
    And I enqueue "2" to queue "peek_window"
    And I enqueue "3" to queue "peek_window"
    And I peek 2 items from queue "peek_window"
    Then the retrieved values should equal "1,2"
    When I get the length of queue "peek_window"
    Then the queue length should be 3

  Scenario: peek on an empty queue returns null
    When I peek from queue "empty_q"
    Then the retrieved value should be null

  # ---------- getQueue ----------

  Scenario: getQueue returns the full contents head-first
    When I enqueue "h1" to queue "full_q"
    And I enqueue "h2" to queue "full_q"
    And I enqueue "h3" to queue "full_q"
    And I get the contents of queue "full_q"
    Then the retrieved values should equal "h1,h2,h3"

  Scenario: getQueue on an empty queue returns an empty list
    When I get the contents of queue "empty"
    Then the retrieved values should be empty

  # ---------- getQueueLength ----------

  Scenario: Length reflects enqueues and pops
    When I enqueue "a" to queue "len_q"
    And I enqueue "b" to queue "len_q"
    And I get the length of queue "len_q"
    Then the queue length should be 2
    When I pop from queue "len_q"
    And I get the length of queue "len_q"
    Then the queue length should be 1
    When I pop from queue "len_q"
    And I get the length of queue "len_q"
    Then the queue length should be 0

  Scenario: Length of an unknown queue is zero
    When I get the length of queue "never_pushed"
    Then the queue length should be 0

  # ---------- Negative paths ----------

  Scenario: Enqueuing null is rejected
    When I attempt to enqueue a null value to queue "any"
    Then the operation should fail with an "InvalidArgumentException"
    And the error message should contain "null"

  Scenario: pop with range below 1 is rejected
    When I enqueue "x" to queue "guarded"
    And I attempt to pop 0 items from queue "guarded"
    Then the operation should fail with an "InvalidArgumentException"
    And the error message should contain "Range"

  Scenario: peek with range below 1 is rejected
    When I enqueue "x" to queue "guarded2"
    And I attempt to peek 0 items from queue "guarded2"
    Then the operation should fail with an "InvalidArgumentException"
    And the error message should contain "Range"
