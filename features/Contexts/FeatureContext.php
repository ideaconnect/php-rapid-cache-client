<?php

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;
use IDCT\Cache\RapidCacheClient;
use PHPUnit\Framework\Assert;

class FeatureContext implements Context
{
    private RapidCacheClient $cacheService;
    private mixed $retrievedValue = null;
    /** @var array<int|string, mixed> */
    private array $retrievedValues = [];
    private int $retrievedCount = 0;
    private int $retrievedLength = 0;
    private ?\Throwable $caughtException = null;

    public function __construct()
    {
        $host = is_string($_ENV['REDIS_HOST'] ?? null) ? $_ENV['REDIS_HOST'] : 'localhost';
        $port = (int) ($_ENV['REDIS_PORT'] ?? 6380);

        $this->cacheService = new RapidCacheClient($host, $port);
        $this->cacheService->clear();
    }

    /**
     * @BeforeScenario
     */
    public function clearCache(): void
    {
        $this->cacheService->clear();
        $this->retrievedValue = null;
        $this->retrievedValues = [];
        $this->retrievedCount = 0;
        $this->retrievedLength = 0;
        $this->caughtException = null;
    }

    // ---------- Background ----------

    /**
     * @Given the cache is empty
     */
    public function theCacheIsEmpty(): void
    {
        $this->cacheService->clear();
    }

    // ---------- Basic key/value ----------

    /**
     * @Given I set a cache item with key :key and value :value
     */
    public function iSetACacheItemWithKeyAndValue(string $key, string $value): void
    {
        $this->cacheService->set($key, $value);
    }

    /**
     * @Given I set a cache item with key :key and value :value with TTL :ttl seconds
     */
    public function iSetACacheItemWithKeyAndValueWithTtl(string $key, string $value, string $ttl): void
    {
        $this->cacheService->set($key, $value, (int) $ttl);
    }

    /**
     * @When I retrieve the cache item with key :key
     */
    public function iRetrieveTheCacheItemWithKey(string $key): void
    {
        $this->retrievedValue = $this->cacheService->get($key);
    }

    /**
     * @When I retrieve the cache item with key :key with default :default
     */
    public function iRetrieveWithDefault(string $key, string $default): void
    {
        $this->retrievedValue = $this->cacheService->get($key, $default);
    }

    /**
     * @When I delete the cache item with key :key
     */
    public function iDeleteTheCacheItemWithKey(string $key): void
    {
        $this->cacheService->delete($key);
    }

    /**
     * @When I clear the cache
     */
    public function iClearTheCache(): void
    {
        $this->cacheService->clear();
    }

    /**
     * @When I check if the cache contains key :key
     */
    public function iCheckIfTheCacheContainsKey(string $key): void
    {
        $this->retrievedValue = $this->cacheService->has($key);
    }

    /**
     * @Then the cache should contain key :key
     */
    public function theCacheShouldContainKey(string $key): void
    {
        Assert::assertTrue($this->cacheService->has($key));
    }

    /**
     * @Then the cache should not contain key :key
     */
    public function theCacheShouldNotContainKey(string $key): void
    {
        Assert::assertFalse($this->cacheService->has($key));
    }

    // ---------- Tagging ----------

    /**
     * @Given I set a cache item with key :key and value :value with tag :tag
     */
    public function iSetACacheItemWithKeyAndValueWithTag(string $key, string $value, string $tag): void
    {
        $this->cacheService->setTagged($key, $value, $tag);
    }

    /**
     * @Given I set a cache item with key :key and value :value with tag :tag with TTL :ttl seconds
     */
    public function iSetACacheItemWithKeyAndValueWithTagWithTtl(string $key, string $value, string $tag, string $ttl): void
    {
        $this->cacheService->setTagged($key, $value, $tag, (int) $ttl);
    }

    /**
     * @When I retrieve items by tag :tag
     */
    public function iRetrieveItemsByTag(string $tag): void
    {
        $this->retrievedValues = [];
        foreach ($this->cacheService->getTagged($tag) as $key => $value) {
            $this->retrievedValues[$key] = $value;
        }
    }

    /**
     * @When I tag key :key with tag :tag
     */
    public function iTagKeyWithTag(string $key, string $tag): void
    {
        $this->cacheService->tag($key, $tag);
    }

    /**
     * @When I untag key :key from tag :tag
     */
    public function iUntagKeyFromTag(string $key, string $tag): void
    {
        $this->cacheService->untag($key, $tag);
    }

    /**
     * @When I remove items by tag :tag
     */
    public function iRemoveItemsByTag(string $tag): void
    {
        $this->cacheService->clearByTag($tag);
    }

    /**
     * @When I get the cardinality of tag :tag
     */
    public function iGetTheCardinalityOfTag(string $tag): void
    {
        $this->retrievedCount = $this->cacheService->getTagCardinality($tag);
    }

    // ---------- Bulk operations ----------

    /**
     * @When I set multiple cache items:
     */
    public function iSetMultipleCacheItems(TableNode $table): void
    {
        $items = [];
        foreach ($table as $row) {
            $items[$row['key']] = $row['value'];
        }
        $this->cacheService->setMultiple($items);
    }

    /**
     * @When I set multiple cache items with TTL :ttl seconds:
     */
    public function iSetMultipleCacheItemsWithTtl(string $ttl, TableNode $table): void
    {
        $items = [];
        foreach ($table as $row) {
            $items[$row['key']] = $row['value'];
        }
        $this->cacheService->setMultiple($items, (int) $ttl);
    }

    /**
     * @When I retrieve multiple cache items with keys :csv
     */
    public function iRetrieveMultipleCacheItems(string $csv): void
    {
        $keys = array_map('trim', explode(',', $csv));
        $result = $this->cacheService->getMultiple($keys);
        $this->retrievedValues = [];
        foreach ($result as $k => $v) {
            $this->retrievedValues[$k] = $v;
        }
    }

    /**
     * @When I retrieve multiple cache items with keys :csv and default :default
     */
    public function iRetrieveMultipleCacheItemsWithDefault(string $csv, string $default): void
    {
        $keys = array_map('trim', explode(',', $csv));
        $result = $this->cacheService->getMultiple($keys, $default);
        $this->retrievedValues = [];
        foreach ($result as $k => $v) {
            $this->retrievedValues[$k] = $v;
        }
    }

    /**
     * @When I delete multiple cache items with keys :csv
     */
    public function iDeleteMultipleCacheItems(string $csv): void
    {
        $keys = array_map('trim', explode(',', $csv));
        $this->cacheService->deleteMultiple($keys);
    }

    // ---------- Queues ----------

    /**
     * @When I enqueue :value to queue :queue
     */
    public function iEnqueueToQueue(string $value, string $queue): void
    {
        $this->cacheService->enqueue($queue, $value);
    }

    /**
     * @When I pop from queue :queue
     */
    public function iPopFromQueue(string $queue): void
    {
        $this->retrievedValue = $this->cacheService->pop($queue);
    }

    /**
     * @When I pop :count items from queue :queue
     */
    public function iPopItemsFromQueue(string $count, string $queue): void
    {
        $result = $this->cacheService->pop($queue, (int) $count);
        if (is_array($result)) {
            $this->retrievedValues = $result;
        } elseif ($result === null) {
            $this->retrievedValues = [];
        } else {
            $this->retrievedValues = [$result];
        }
    }

    /**
     * @When I peek from queue :queue
     */
    public function iPeekFromQueue(string $queue): void
    {
        $this->retrievedValue = $this->cacheService->peek($queue);
    }

    /**
     * @When I peek :count items from queue :queue
     */
    public function iPeekItemsFromQueue(string $count, string $queue): void
    {
        $result = $this->cacheService->peek($queue, (int) $count);
        if (is_array($result)) {
            $this->retrievedValues = $result;
        } elseif ($result === null) {
            $this->retrievedValues = [];
        } else {
            $this->retrievedValues = [$result];
        }
    }

    /**
     * @When I get the contents of queue :queue
     */
    public function iGetTheContentsOfQueue(string $queue): void
    {
        $this->retrievedValues = $this->cacheService->getQueue($queue);
    }

    /**
     * @When I get the length of queue :queue
     */
    public function iGetTheLengthOfQueue(string $queue): void
    {
        $this->retrievedLength = $this->cacheService->getQueueLength($queue);
    }

    // ---------- Counters ----------

    /**
     * @When I increase key :key by :value
     */
    public function iIncreaseKeyBy(string $key, string $value): void
    {
        $this->cacheService->increase($key, (int) $value);
    }

    /**
     * @When I decrease key :key by :value
     */
    public function iDecreaseKeyBy(string $key, string $value): void
    {
        $this->cacheService->decrease($key, (int) $value);
    }

    // ---------- Sets ----------

    /**
     * @When I create a set :set with values :values
     */
    public function iCreateASetWithValues(string $set, string $values): void
    {
        $valueArray = $values === '' ? [] : array_map('trim', explode(',', $values));
        $this->cacheService->createSet($set, $valueArray);
    }

    /**
     * @When I add :value to set :set
     */
    public function iAddToSet(string $value, string $set): void
    {
        $this->cacheService->addToSet($set, $value);
    }

    /**
     * @When I remove :value from set :set
     */
    public function iRemoveFromSet(string $value, string $set): void
    {
        $this->cacheService->removeFromSet($set, $value);
    }

    /**
     * @When I get set :set
     */
    public function iGetSet(string $set): void
    {
        $this->retrievedValues = $this->cacheService->getSet($set) ?? [];
    }

    /**
     * @When I get the cardinality of set :set
     */
    public function iGetTheCardinalityOfSet(string $set): void
    {
        $this->retrievedCount = $this->cacheService->getCardinality($set);
    }

    // ---------- Negative-path "attempt" steps ----------

    /**
     * @When I attempt to set a cache item with key :key and value :value
     */
    public function iAttemptToSetACacheItem(string $key, string $value): void
    {
        $this->runWithCapture(fn() => $this->cacheService->set($key, $value));
    }

    /**
     * @When I attempt to retrieve the cache item with key :key
     */
    public function iAttemptToRetrieveTheCacheItem(string $key): void
    {
        $this->runWithCapture(function () use ($key) {
            $this->retrievedValue = $this->cacheService->get($key);
        });
    }

    /**
     * @When I attempt to delete the cache item with key :key
     */
    public function iAttemptToDeleteTheCacheItem(string $key): void
    {
        $this->runWithCapture(fn() => $this->cacheService->delete($key));
    }

    /**
     * @When I attempt to enqueue a null value to queue :queue
     */
    public function iAttemptToEnqueueNull(string $queue): void
    {
        $this->runWithCapture(fn() => $this->cacheService->enqueue($queue, null));
    }

    /**
     * @When I attempt to enqueue :value to queue :queue
     */
    public function iAttemptToEnqueue(string $value, string $queue): void
    {
        $this->runWithCapture(fn() => $this->cacheService->enqueue($queue, $value));
    }

    /**
     * @When I attempt to pop :count items from queue :queue
     */
    public function iAttemptToPopItems(string $count, string $queue): void
    {
        $this->runWithCapture(fn() => $this->cacheService->pop($queue, (int) $count));
    }

    /**
     * @When I attempt to peek :count items from queue :queue
     */
    public function iAttemptToPeekItems(string $count, string $queue): void
    {
        $this->runWithCapture(fn() => $this->cacheService->peek($queue, (int) $count));
    }

    /**
     * @When I attempt to tag key :key with tag :tag
     */
    public function iAttemptToTagKey(string $key, string $tag): void
    {
        $this->runWithCapture(fn() => $this->cacheService->tag($key, $tag));
    }

    /**
     * @When I attempt to retrieve items by tag :tag
     */
    public function iAttemptToRetrieveItemsByTag(string $tag): void
    {
        $this->runWithCapture(function () use ($tag) {
            foreach ($this->cacheService->getTagged($tag) as $_) {
                // drain the generator so the validation runs
            }
        });
    }

    private function runWithCapture(callable $op): void
    {
        try {
            $op();
        } catch (\Throwable $e) {
            $this->caughtException = $e;
        }
    }

    // ---------- Then steps ----------

    /**
     * @Then the retrieved value should be :value
     */
    public function theRetrievedValueShouldBe(string $value): void
    {
        if ($value === 'null') {
            Assert::assertNull($this->retrievedValue);
        } elseif ($value === 'true') {
            Assert::assertTrue($this->retrievedValue);
        } elseif ($value === 'false') {
            Assert::assertFalse($this->retrievedValue);
        } else {
            Assert::assertEquals($value, $this->retrievedValue);
        }
    }

    /**
     * @Then the retrieved values should contain :key with value :value
     */
    public function theRetrievedValuesShouldContainWithValue(string $key, string $value): void
    {
        Assert::assertArrayHasKey($key, $this->retrievedValues);
        Assert::assertEquals($value, $this->retrievedValues[$key]);
    }

    /**
     * @Then the retrieved values should not contain :key
     */
    public function theRetrievedValuesShouldNotContain(string $key): void
    {
        Assert::assertArrayNotHasKey($key, $this->retrievedValues);
    }

    /**
     * @Then the retrieved values should be empty
     */
    public function theRetrievedValuesShouldBeEmpty(): void
    {
        Assert::assertSame([], $this->retrievedValues);
    }

    /**
     * @Then the retrieved values should equal :csv
     */
    public function theRetrievedValuesShouldEqual(string $csv): void
    {
        $expected = array_map('trim', explode(',', $csv));
        Assert::assertEquals($expected, $this->retrievedValues);
    }

    /**
     * @Then the retrieved values should have :count items
     */
    public function theRetrievedValuesShouldHaveItems(string $count): void
    {
        Assert::assertCount((int) $count, $this->retrievedValues);
    }

    /**
     * @Then the queue length should be :length
     */
    public function theQueueLengthShouldBe(string $length): void
    {
        Assert::assertSame((int) $length, $this->retrievedLength);
    }

    /**
     * @Then the cardinality should be :count
     */
    public function theCardinalityShouldBe(string $count): void
    {
        Assert::assertSame((int) $count, $this->retrievedCount);
    }

    /**
     * @Then the set should contain :value
     */
    public function theSetShouldContain(string $value): void
    {
        Assert::assertContains($value, $this->retrievedValues);
    }

    /**
     * @Then the set should not contain :value
     */
    public function theSetShouldNotContain(string $value): void
    {
        Assert::assertNotContains($value, $this->retrievedValues);
    }

    /**
     * @Then the operation should fail with an :class
     */
    public function theOperationShouldFailWith(string $class): void
    {
        Assert::assertNotNull(
            $this->caughtException,
            'Expected an exception but none was thrown.'
        );

        // Accept short class names (resolved against the package's exception namespace)
        // and fully-qualified names interchangeably.
        $candidates = [$class, 'IDCT\\Cache\\Exception\\' . $class, '\\' . $class];
        foreach ($candidates as $candidate) {
            if (class_exists($candidate) && $this->caughtException instanceof $candidate) {
                return;
            }
        }

        Assert::fail(sprintf(
            'Expected exception of type %s, got %s with message: %s',
            $class,
            get_class($this->caughtException),
            $this->caughtException->getMessage()
        ));
    }

    /**
     * @Then the error message should contain :substring
     */
    public function theErrorMessageShouldContain(string $substring): void
    {
        Assert::assertNotNull(
            $this->caughtException,
            'Expected an exception but none was thrown.'
        );
        Assert::assertStringContainsString($substring, $this->caughtException->getMessage());
    }

    /**
     * @Then no exception should be thrown
     */
    public function noExceptionShouldBeThrown(): void
    {
        if ($this->caughtException !== null) {
            Assert::fail(sprintf(
                'Expected no exception but got %s: %s',
                get_class($this->caughtException),
                $this->caughtException->getMessage()
            ));
        }
    }

    /**
     * @When I wait :seconds seconds
     */
    public function iWaitSeconds(string $seconds): void
    {
        sleep((int) $seconds);
    }
}
