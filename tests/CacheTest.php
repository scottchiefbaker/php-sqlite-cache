<?php

namespace Scottchiefbaker\Cache\Tests;

use PHPUnit\Framework\TestCase;
use Scottchiefbaker\Cache\Sqlite;

class CacheTest extends TestCase
{
	private Sqlite $cache;
	private string $dbFile;

	protected function setUp(): void
	{
		$this->dbFile = sys_get_temp_dir() . '/sqlite_cache_test_' . uniqid() . '.sqlite';
		$this->cache  = new Sqlite([
			'db_file' => $this->dbFile,
			'silent'  => true,
			'mode'    => 'json',
		]);
	}

	protected function tearDown(): void
	{
		if (file_exists($this->dbFile)) {
			unlink($this->dbFile);
		}
	}

	public function testConstructorCreatesDbFile(): void
	{
		$this->assertFileExists($this->dbFile);
	}

	public function testConstructorSetsJsonMode(): void
	{
		$this->assertSame('json', $this->cache->mode);
	}

	public function testConstructorSetsPdoConnection(): void
	{
		$this->assertInstanceOf(\PDO::class, $this->cache->pdo);
	}

	public function testSetAndGetString(): void
	{
		$this->cache->set('name', 'Alice');
		$this->assertSame('Alice', $this->cache->get('name'));
	}

	public function testSetAndGetInteger(): void
	{
		$this->cache->set('count', 42);
		$this->assertSame(42, $this->cache->get('count'));
	}

	public function testSetAndGetArray(): void
	{
		$data = ['foo' => 'bar', 'baz' => 123];
		$this->cache->set('data', $data);
		$this->assertSame($data, $this->cache->get('data'));
	}

	public function testSetAndGetNestedArray(): void
	{
		$data = ['level1' => ['level2' => ['level3' => [1, 2, 3]]]];
		$this->cache->set('nested', $data);
		$this->assertSame($data, $this->cache->get('nested'));
	}

	public function testGetMissingKeyReturnsNull(): void
	{
		$this->assertNull($this->cache->get('nonexistent'));
	}

	public function testSetOverwritesExistingKey(): void
	{
		$this->cache->set('key', 'first');
		$this->cache->set('key', 'second');
		$this->assertSame('second', $this->cache->get('key'));
	}

	public function testDeleteRemovesEntry(): void
	{
		$this->cache->set('key', 'value');
		$this->cache->delete('key');
		$this->assertNull($this->cache->get('key'));
	}

	public function testDeleteReturnsTrue(): void
	{
		$this->cache->set('key', 'value');
		$this->assertTrue($this->cache->delete('key'));
	}

	public function testSetReturnsTrue(): void
	{
		$this->assertTrue($this->cache->set('key', 'value'));
	}

	public function testCachedItemCount(): void
	{
		$this->assertSame(0, $this->cache->cached_item_count());

		$this->cache->set('a', 1);
		$this->cache->set('b', 2);
		$this->cache->set('c', 3);

		$this->assertSame(3, $this->cache->cached_item_count());
	}

	public function testCachedItemKeys(): void
	{
		$this->cache->set('x', 10);
		$this->cache->set('y', 20);

		$keys = $this->cache->cached_item_keys();
		$this->assertContains('x', $keys);
		$this->assertContains('y', $keys);
		$this->assertCount(2, $keys);
	}

	public function testExpiredEntryReturnsNull(): void
	{
		$this->cache->set('key', 'value', 1);
		sleep(2);
		$this->assertNull($this->cache->get('key'));
	}

	public function testRemoveExpiredEntries(): void
	{
		$this->cache->set('short', 'gone soon', 1);
		$this->cache->set('long', 'stays', 3600);

		sleep(2);

		$this->cache->remove_expired_entries();
		$this->assertSame(1, $this->cache->cached_item_count());
		$this->assertNull($this->cache->get('short'));
		$this->assertSame('stays', $this->cache->get('long'));
	}

	public function testEmptyCache(): void
	{
		$this->cache->set('a', 1);
		$this->cache->set('b', 2);

		$count = $this->cache->empty_cache();
		$this->assertSame(2, $count);
		$this->assertSame(0, $this->cache->cached_item_count());
	}

	public function testDisabledCacheReturnsNull(): void
	{
		$this->cache->set('key', 'value');

		$this->cache->disabled = true;

		$this->assertNull($this->cache->set('key2', 'value2'));
		$this->assertNull($this->cache->get('key'));
		$this->assertNull($this->cache->delete('key'));
		$this->assertNull($this->cache->cached_item_count());
		$this->assertNull($this->cache->cached_item_keys());
		$this->assertNull($this->cache->remove_expired_entries());
		$this->assertNull($this->cache->empty_cache());
	}

	public function testVacuumRuns(): void
	{
		$this->cache->set('key', 'value');
		$this->cache->vacuum();
		$this->assertSame('value', $this->cache->get('key'));
	}

	public function testSetDefaultExpiry(): void
	{
		$this->cache->set('key', 'value');
		$this->assertSame('value', $this->cache->get('key'));
	}

	public function testRelativeExpiry(): void
	{
		$this->cache->set('key', 'value', 3600);
		$this->assertSame('value', $this->cache->get('key'));
	}

	public function testAbsoluteExpiry(): void
	{
		$future = time() + 3600;
		$this->cache->set('key', 'value', $future);
		$this->assertSame('value', $this->cache->get('key'));
	}

	public function testAbsoluteExpiryInPast(): void
	{
		$past = time() - 10;
		$this->cache->set('key', 'value', $past);
		$this->assertNull($this->cache->get('key'));
	}

	public function testMultipleOperationsSequence(): void
	{
		$this->cache->set('a', 1);
		$this->cache->set('b', 2);
		$this->cache->set('c', 3);

		$this->assertSame(3, $this->cache->cached_item_count());

		$this->cache->delete('b');
		$this->assertSame(2, $this->cache->cached_item_count());

		$keys = $this->cache->cached_item_keys();
		$this->assertContains('a', $keys);
		$this->assertNotContains('b', $keys);
		$this->assertContains('c', $keys);

		$this->assertSame(1, $this->cache->get('a'));
		$this->assertNull($this->cache->get('b'));
		$this->assertSame(3, $this->cache->get('c'));
	}

	public function testGetOnExpiredKeyTriggersCleanup(): void
	{
		$this->cache->set('expired', 'data', 1);
		$this->cache->set('valid', 'data', 3600);

		sleep(2);

		$this->cache->get('expired');

		$this->assertSame(1, $this->cache->cached_item_count());
		$this->assertNull($this->cache->get('expired'));
	}

	public function testBooleanValue(): void
	{
		$this->cache->set('flag', true);
		$this->assertTrue($this->cache->get('flag'));

		$this->cache->set('flag', false);
		$this->assertFalse($this->cache->get('flag'));
	}

	public function testNullValue(): void
	{
		$this->cache->set('empty', null);
		$this->assertNull($this->cache->get('empty'));
	}

	public function testLargeString(): void
	{
		$large = str_repeat('a', 100000);
		$this->cache->set('big', $large);
		$this->assertSame($large, $this->cache->get('big'));
	}

	public function testSpecialCharactersInKey(): void
	{
		$this->cache->set('key/with:special chars', 'value');
		$this->assertSame('value', $this->cache->get('key/with:special chars'));
	}

	public function testInitDbResetsTable(): void
	{
		$this->cache->set('key', 'value');
		$this->assertSame(1, $this->cache->cached_item_count());

		$this->cache->init_db(true);

		$this->assertSame(0, $this->cache->cached_item_count());
	}

	public function testVersionProperty(): void
	{
		$this->assertSame(0.6, $this->cache->version);
	}

	private function createCacheWithMode(string $mode): Sqlite
	{
		$dbFile = sys_get_temp_dir() . '/sqlite_cache_test_' . uniqid() . '.sqlite';
		return new Sqlite([
			'db_file' => $dbFile,
			'silent'  => true,
			'mode'    => $mode,
		]);
	}

	////////////////////////////////////////////////////////////////////////////////
	// igbinary tests
	////////////////////////////////////////////////////////////////////////////////

	#[\PHPUnit\Framework\Attributes\RequiresPhpExtension('igbinary')]
	public function testSetAndGetStringWithIgbinary(): void
	{
		$cache = $this->createCacheWithMode('igb');
		$cache->set('name', 'Alice');
		$this->assertSame('Alice', $cache->get('name'));
	}

	#[\PHPUnit\Framework\Attributes\RequiresPhpExtension('igbinary')]
	public function testSetAndGetArrayWithIgbinary(): void
	{
		$cache = $this->createCacheWithMode('igb');
		$data = ['foo' => 'bar', 'baz' => 123];
		$cache->set('data', $data);
		$this->assertSame($data, $cache->get('data'));
	}

	#[\PHPUnit\Framework\Attributes\RequiresPhpExtension('igbinary')]
	public function testSetAndGetNestedArrayWithIgbinary(): void
	{
		$cache = $this->createCacheWithMode('igb');
		$data = ['level1' => ['level2' => ['level3' => [1, 2, 3]]]];
		$cache->set('nested', $data);
		$this->assertSame($data, $cache->get('nested'));
	}

	////////////////////////////////////////////////////////////////////////////////
	// msgpack tests
	////////////////////////////////////////////////////////////////////////////////

	#[\PHPUnit\Framework\Attributes\RequiresPhpExtension('msgpack')]
	public function testSetAndGetStringWithMsgpack(): void
	{
		$cache = $this->createCacheWithMode('msgp');
		$cache->set('name', 'Alice');
		$this->assertSame('Alice', $cache->get('name'));
	}

	#[\PHPUnit\Framework\Attributes\RequiresPhpExtension('msgpack')]
	public function testSetAndGetArrayWithMsgpack(): void
	{
		$cache = $this->createCacheWithMode('msgp');
		$data = ['foo' => 'bar', 'baz' => 123];
		$cache->set('data', $data);
		$this->assertSame($data, $cache->get('data'));
	}

	#[\PHPUnit\Framework\Attributes\RequiresPhpExtension('msgpack')]
	public function testSetAndGetNestedArrayWithMsgpack(): void
	{
		$cache = $this->createCacheWithMode('msgp');
		$data = ['level1' => ['level2' => ['level3' => [1, 2, 3]]]];
		$cache->set('nested', $data);
		$this->assertSame($data, $cache->get('nested'));
	}
}
