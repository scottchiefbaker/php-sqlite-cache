# PHP SQLite Object Cache

PHP Object Caching system with a SQLite backend store and automatic content expiration.
Cache is stored using a SQLite database and persists on disk.

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

$opts = ["db_file" => "/var/tmp/mycache.sqlite"];
$cache = new \Scottchiefbaker\Cache\Sqlite($opts);

// Store an item
$key     = "cust:123";
$data    = ['name' => 'Jason Doolis', 'age' => 13, 'animal' => 'kitten'];
$expires = time() + 3600;

$ok = $cache->set($key, $data, $expires);

// Fetch an item
$data = $cache->get($key);
```

## Methods

### get($key)
Returns stored object.

### set($key, $object, $expire_time)
Store an object in cache. Returns status of storage.

### delete($key)
Remove an item from cache. Returns status of deletion.

### cached_item_count()
Return the number of active items in the cache.

### cached_item_keys()
Return array of all the active items in the cache.

### remove_expired_entries($vacuum_db)
Remove all expired items from cache. If `$vacuum_db` is set, the DB will be vacuumed after removal.

### empty_cache()
Remove **all** items from the cache.

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
