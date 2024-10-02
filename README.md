# PHP SQLite Object Cache

PHP Object Caching system with a SQLite backend store and automatic content expiration.
Cach is stored using a SQLite database in a RAM disk in `/dev/shm`.

## Requirements

* PHP 8.0+
* PDO functions in PHP
* SQLite functions in PHP
* JSON functions in PHP
* Ability to write to `/dev/shm`

## Optional components

* igbinary
* msgpack

If either **igbinary** or **msgpack** modules are available we will automatically use them
as the serialization storage method in the database. If neither are available we fall back
to JSON.

## Usage

```PHP
require("/path/to/dir/cache.class.php");

$cache = new \Scottchiefbaker\Cache\Sqlite();

// Store an item
$key     = "cust:123";
$data    = ['name' => 'Jason Doolis', 'age' => 13, 'animal' => 'kitten'];
$expires = time() + 3600;

$ok = $cache->set($key, $data, $expires);

// Fetch an item
$data = $cache->get($key);
```

## Cleanup

Over time objects will be added and deleted to the cache. This *may* create
fragmentation in the database so it might be a good idea to `VACUUM` it every
so often. We have provided a method to do this:

```PHP
// Remove all expired entries and defragment the database
$vacuum = true;
$ok     = $cache->remove_expired_entries($vacuum);
```

## Real World Example
```PHP
function get_slow_data($id) {
	global $cache;

	$ckey = "item:$id";
	$data = $cache->get($ckey);

	if ($data) { return $data; }

	// Not found in cache go get the data the slow way
	$data = my_slow_data_function($id);

	// Store the data in the cache for two hours
	$ok = $cache->set($ckey, $data, time() + 7200);

	return $ret;
}
```
