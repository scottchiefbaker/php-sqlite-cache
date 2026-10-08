<?php

// Standalone unit tests for php-sqlite-cache
// Run with: php unit_tests/tests.php
// No external dependencies (no PHPUnit, no Composer) are required.

error_reporting(E_ALL);

require __DIR__ . '/../cache.class.php';

use Scottchiefbaker\Cache\Sqlite;

////////////////////////////////////////////////////////////////////////////////
// Test harness
////////////////////////////////////////////////////////////////////////////////

class AssertionFailed extends \Exception { }

function fail_test(string $msg): void {
	throw new AssertionFailed($msg);
}

function export($v): string {
	return var_export($v, true);
}

function assert_same($expected, $actual, string $msg = ""): void {
	if ($expected !== $actual) {
		$m = $msg ? "$msg: " : "";
		fail_test($m . "expected " . export($expected) . ", got " . export($actual));
	}
}

function assert_true($actual, string $msg = ""): void {
	assert_same(true, $actual, $msg);
}

function assert_false($actual, string $msg = ""): void {
	assert_same(false, $actual, $msg);
}

function assert_null($actual, string $msg = ""): void {
	assert_same(null, $actual, $msg);
}

function assert_contains($needle, array $haystack, string $msg = ""): void {
	if (!in_array($needle, $haystack, true)) {
		$m = $msg ? "$msg: " : "";
		fail_test($m . "expected array to contain " . export($needle));
	}
}

function assert_not_contains($needle, array $haystack, string $msg = ""): void {
	if (in_array($needle, $haystack, true)) {
		$m = $msg ? "$msg: " : "";
		fail_test($m . "expected array not to contain " . export($needle));
	}
}

function assert_file_exists(string $file, string $msg = ""): void {
	if (!file_exists($file)) {
		$m = $msg ? "$msg: " : "";
		fail_test($m . "file does not exist: $file");
	}
}

function assert_between(int $min, int $max, int $actual, string $msg = ""): void {
	if ($actual < $min || $actual > $max) {
		$m = $msg ? "$msg: " : "";
		fail_test($m . "expected $actual to be between $min and $max");
	}
}

// Registered tests: name, callable, required extension (or null)
$TESTS = [];

function test(string $name, callable $fn, ?string $ext = null): void {
	global $TESTS;
	$TESTS[] = ['name' => $name, 'fn' => $fn, 'ext' => $ext];
}

// Temp DB files created during the run, removed at the end
$TEMP_FILES = [];

function new_cache(string $mode = "json", array $extra = []): Sqlite {
	global $TEMP_FILES;

	$file = sys_get_temp_dir() . "/sqlite_cache_test_" . bin2hex(random_bytes(8)) . ".sqlite";
	$TEMP_FILES[] = $file;

	$opts = array_merge([
		'db_file' => $file,
		'silent'  => true,
		'mode'    => $mode,
	], $extra);

	return new Sqlite($opts);
}

// Count rows in the table, including expired ones (cached_item_count() ignores expired rows)
function raw_row_count(Sqlite $cache): int {
	$sth = $cache->pdo->query("SELECT count(*) FROM cache");
	return (int)$sth->fetchColumn();
}

function raw_expire_time(Sqlite $cache, string $key): int {
	$sth = $cache->pdo->prepare("SELECT ExpireTime FROM cache WHERE Key = ?");
	$sth->execute([$key]);
	return (int)$sth->fetchColumn();
}

function raw_value(Sqlite $cache, string $key): string {
	$sth = $cache->pdo->prepare("SELECT Value FROM cache WHERE Key = ?");
	$sth->execute([$key]);
	return (string)$sth->fetchColumn();
}

////////////////////////////////////////////////////////////////////////////////
// Constructor / setup
////////////////////////////////////////////////////////////////////////////////

test("constructor creates the DB file", function () {
	$cache = new_cache();
	assert_file_exists($cache->db_file);
});

test("constructor creates the cache table", function () {
	$cache = new_cache();
	$sth   = $cache->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='cache'");
	assert_same('cache', $sth->fetchColumn());
});

test("constructor sets the requested mode", function () {
	$cache = new_cache("json");
	assert_same("json", $cache->mode);
});

test("constructor exposes a PDO connection", function () {
	$cache = new_cache();
	assert_true($cache->pdo instanceof \PDO);
});

test("constructor auto-detects mode when none is given", function () {
	$file  = sys_get_temp_dir() . "/sqlite_cache_test_" . bin2hex(random_bytes(8)) . ".sqlite";
	global $TEMP_FILES;
	$TEMP_FILES[] = $file;

	$cache = new Sqlite(['db_file' => $file, 'silent' => true]);

	if (function_exists("igbinary_serialize")) {
		$expected = "igb";
	} elseif (function_exists("msgpack_pack")) {
		$expected = "msgp";
	} else {
		$expected = "json";
	}

	assert_same($expected, $cache->mode);
});

test("version is a positive number", function () {
	$cache = new_cache();
	assert_true(is_float($cache->version) && $cache->version > 0, "version was " . export($cache->version));
});

test("json mode stores JSON text", function () {
	$cache = new_cache("json");
	$cache->set("key", ["a" => 1]);
	assert_same('{"a":1}', raw_value($cache, "key"));
});

////////////////////////////////////////////////////////////////////////////////
// Set / get round trips
////////////////////////////////////////////////////////////////////////////////

test("set and get a string", function () {
	$cache = new_cache();
	$cache->set("name", "Alice");
	assert_same("Alice", $cache->get("name"));
});

test("set and get an integer", function () {
	$cache = new_cache();
	$cache->set("count", 42);
	assert_same(42, $cache->get("count"));
});

test("set and get an array", function () {
	$cache = new_cache();
	$data  = ["foo" => "bar", "baz" => 123];
	$cache->set("data", $data);
	assert_same($data, $cache->get("data"));
});

test("set and get a nested array", function () {
	$cache = new_cache();
	$data  = ["level1" => ["level2" => ["level3" => [1, 2, 3]]]];
	$cache->set("nested", $data);
	assert_same($data, $cache->get("nested"));
});

test("set and get booleans", function () {
	$cache = new_cache();
	$cache->set("yes", true);
	$cache->set("no", false);
	assert_same(true, $cache->get("yes"));
	assert_same(false, $cache->get("no"));
});

test("set and get null", function () {
	$cache = new_cache();
	$cache->set("empty", null);
	assert_null($cache->get("empty"));
});

test("set and get a large string", function () {
	$cache = new_cache();
	$large = str_repeat("a", 100000);
	$cache->set("big", $large);
	assert_same($large, $cache->get("big"));
});

test("keys with special characters work", function () {
	$cache = new_cache();
	$key   = "key/with:special chars";
	$cache->set($key, "value");
	assert_same("value", $cache->get($key));
});

test("get on a missing key returns null", function () {
	$cache = new_cache();
	assert_null($cache->get("nonexistent"));
});

test("set returns true on success", function () {
	$cache = new_cache();
	assert_true($cache->set("key", "value"));
});

test("set overwrites an existing key", function () {
	$cache = new_cache();
	$cache->set("key", "first");
	$cache->set("key", "second");
	assert_same("second", $cache->get("key"));
	assert_same(1, $cache->cached_item_count());
});

////////////////////////////////////////////////////////////////////////////////
// Delete / count / keys
////////////////////////////////////////////////////////////////////////////////

test("delete removes an entry", function () {
	$cache = new_cache();
	$cache->set("key", "value");
	$cache->delete("key");
	assert_null($cache->get("key"));
});

test("delete returns true", function () {
	$cache = new_cache();
	$cache->set("key", "value");
	assert_true($cache->delete("key"));
});

test("cached_item_count counts active items", function () {
	$cache = new_cache();
	assert_same(0, $cache->cached_item_count());

	$cache->set("a", 1);
	$cache->set("b", 2);
	$cache->set("c", 3);

	assert_same(3, $cache->cached_item_count());
});

test("cached_item_keys returns all active keys", function () {
	$cache = new_cache();
	$cache->set("x", 10);
	$cache->set("y", 20);

	$keys = $cache->cached_item_keys();
	assert_contains("x", $keys);
	assert_contains("y", $keys);
	assert_same(2, count($keys));
});

test("a sequence of set/delete/get stays consistent", function () {
	$cache = new_cache();
	$cache->set("a", 1);
	$cache->set("b", 2);
	$cache->set("c", 3);
	assert_same(3, $cache->cached_item_count());

	$cache->delete("b");
	assert_same(2, $cache->cached_item_count());

	$keys = $cache->cached_item_keys();
	assert_contains("a", $keys);
	assert_not_contains("b", $keys);
	assert_contains("c", $keys);

	assert_same(1, $cache->get("a"));
	assert_null($cache->get("b"));
	assert_same(3, $cache->get("c"));
});

////////////////////////////////////////////////////////////////////////////////
// Expiry
////////////////////////////////////////////////////////////////////////////////

test("default expiry is one hour", function () {
	$cache = new_cache();
	$now   = time();
	$cache->set("key", "value");

	assert_between($now + 3595, $now + 3605, raw_expire_time($cache, "key"), "default expiry");
});

test("relative expiry sets ExpireTime from now", function () {
	$cache = new_cache();
	$now   = time();
	$cache->set("key", "value", 3600);

	assert_between($now + 3595, $now + 3605, raw_expire_time($cache, "key"));
	assert_same("value", $cache->get("key"));
});

test("absolute future expiry keeps the value", function () {
	$cache = new_cache();
	$cache->set("key", "value", time() + 3600);
	assert_same("value", $cache->get("key"));
});

test("absolute past expiry makes the value unavailable", function () {
	$cache = new_cache();
	$cache->set("key", "value", time() - 10);
	assert_null($cache->get("key"));
});

test("relative expiry: value is gone after it elapses", function () {
	// The only test that actually sleeps; it needs real wall-clock time to pass
	$cache = new_cache();
	$cache->set("key", "value", 1);
	sleep(2);
	assert_null($cache->get("key"));
});

test("get on an expired key removes the row", function () {
	$cache = new_cache();
	$cache->set("expired", "data", time() - 10);
	$cache->set("valid", "data", 3600);
	assert_same(2, raw_row_count($cache));

	assert_null($cache->get("expired"));

	assert_same(1, raw_row_count($cache));
	assert_same(1, $cache->cached_item_count());
});

test("remove_expired_entries removes only expired rows", function () {
	$cache = new_cache();
	$cache->set("short", "gone soon", time() - 10);
	$cache->set("long", "stays", 3600);

	$cache->remove_expired_entries();

	assert_same(1, raw_row_count($cache));
	assert_null($cache->get("short"));
	assert_same("stays", $cache->get("long"));
});

test("remove_expired_entries accepts vacuum=0", function () {
	$cache = new_cache();
	$cache->set("short", "gone", time() - 10);
	assert_true($cache->remove_expired_entries(0));
	assert_same(0, raw_row_count($cache));
});

////////////////////////////////////////////////////////////////////////////////
// Maintenance
////////////////////////////////////////////////////////////////////////////////

test("empty_cache returns the number of rows removed", function () {
	$cache = new_cache();
	$cache->set("a", 1);
	$cache->set("b", 2);

	assert_same(2, $cache->empty_cache());
	assert_same(0, $cache->cached_item_count());
});

test("vacuum runs without losing data", function () {
	$cache = new_cache();
	$cache->set("key", "value");
	$cache->vacuum();
	assert_same("value", $cache->get("key"));
});

test("init_db drops all existing entries", function () {
	$cache = new_cache();
	$cache->set("key", "value");
	assert_same(1, $cache->cached_item_count());

	$cache->init_db(true);

	assert_same(0, $cache->cached_item_count());
});

////////////////////////////////////////////////////////////////////////////////
// Disabled mode
////////////////////////////////////////////////////////////////////////////////

test("disabled cache returns null from every method", function () {
	$cache = new_cache();
	$cache->set("key", "value");

	$cache->disabled = true;

	assert_null($cache->set("key2", "value2"));
	assert_null($cache->get("key"));
	assert_null($cache->delete("key"));
	assert_null($cache->cached_item_count());
	assert_null($cache->cached_item_keys());
	assert_null($cache->remove_expired_entries());
	assert_null($cache->empty_cache());
	assert_null($cache->vacuum());
	assert_null($cache->init_db(true));
});

test("disabled cache leaves stored data untouched", function () {
	$cache = new_cache();
	$cache->set("key", "value");

	$cache->disabled = true;
	$cache->set("key", "other");
	$cache->disabled = false;

	assert_same("value", $cache->get("key"));
});

////////////////////////////////////////////////////////////////////////////////
// igbinary and msgpack serializers (skipped when the extension is missing)
////////////////////////////////////////////////////////////////////////////////

test("igbinary: round trip string, array and nested array", function () {
	$cache  = new_cache("igb");
	$nested = ["level1" => ["level2" => ["level3" => [1, 2, 3]]]];

	$cache->set("name", "Alice");
	$cache->set("data", ["foo" => "bar", "baz" => 123]);
	$cache->set("nested", $nested);

	assert_same("Alice", $cache->get("name"));
	assert_same(["foo" => "bar", "baz" => 123], $cache->get("data"));
	assert_same($nested, $cache->get("nested"));
}, "igbinary");

test("msgpack: round trip string, array and nested array", function () {
	$cache  = new_cache("msgp");
	$nested = ["level1" => ["level2" => ["level3" => [1, 2, 3]]]];

	$cache->set("name", "Alice");
	$cache->set("data", ["foo" => "bar", "baz" => 123]);
	$cache->set("nested", $nested);

	assert_same("Alice", $cache->get("name"));
	assert_same(["foo" => "bar", "baz" => 123], $cache->get("data"));
	assert_same($nested, $cache->get("nested"));
}, "msgpack");

////////////////////////////////////////////////////////////////////////////////
// Runner
////////////////////////////////////////////////////////////////////////////////

// Colorize output when writing to a terminal (disable with NO_COLOR or --no-color)
$use_color = function_exists('posix_isatty') ? posix_isatty(STDOUT) : stream_isatty(STDOUT);
if (getenv('NO_COLOR') !== false || in_array('--no-color', $argv, true)) {
	$use_color = false;
}

function paint(string $text, string $code, bool $on): string {
	return $on ? "\033[{$code}m{$text}\033[0m" : $text;
}

$passed  = 0;
$failed  = 0;
$skipped = 0;

foreach ($TESTS as $t) {
	if ($t['ext'] !== null && !extension_loaded($t['ext'])) {
		echo paint("SKIP", "33", $use_color) . "  {$t['name']} (ext {$t['ext']} not loaded)\n";
		$skipped++;
		continue;
	}

	try {
		($t['fn'])();
		echo paint("PASS", "32", $use_color) . "  {$t['name']}\n";
		$passed++;
	} catch (\Throwable $e) {
		echo paint("FAIL", "31;1", $use_color) . "  {$t['name']}\n";
		echo "      " . paint($e->getMessage(), "31", $use_color) . "\n";
		$failed++;
	}
}

foreach ($TEMP_FILES as $file) {
	foreach (["", "-journal", "-wal", "-shm"] as $suffix) {
		if (file_exists($file . $suffix)) {
			@unlink($file . $suffix);
		}
	}
}

echo "\n";
$summary = "Passed: $passed  Failed: $failed  Skipped: $skipped";
if ($failed > 0) {
	echo paint($summary, "31;1", $use_color) . "\n";
} else {
	echo paint($summary, "32;1", $use_color) . "\n";
}

exit($failed > 0 ? 1 : 0);
