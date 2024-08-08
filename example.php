<?php

require("cache.class.php");

$opts = [];

// If you want to use non-default settings you can set them in $opts
//$opts = [
//    'db_file' => '/tmp/foo.sqlite', // Store the DB in a specific location
//    'silent'  => true,              // Disable the info/warning when the DB is initialized
//];

$cache = new \Scottchiefbaker\Cache\Sqlite($opts);

///////////////////////////////////////////////////////////////////////////////

$ckey = "files:php";
$data = $cache->get($ckey);

if ($data) {
	print "<p>Object with key '$ckey' found in cache</p>";
	print "<pre>";
	print_r($data);
	print "</pre>\n";
} else {
	print "ckey '$ckey' not found";
}

print "<br />";

///////////////////////////////////////////////////////////////////////////////

$files  = glob("*.php");
$data   = [];
foreach ($files as $file) {
	$data[$file] = hash_file('sha256', $file);
}

$expire = time() + 300;
$ok     = $cache->set($ckey, $data, $expire);

if ($ok) {
	print "Wrote object into cache with key '$ckey'";
} else {
	print "Error writing object into cache";
}

// Remove an item from the cache
// $cache->delete_cache_item($ckey);

// Optionally remove expired entries. This is called automatically whenever
// an expired entry is accessed via get().
// $vacuum = 1;
// $cache->remove_expired_items($vacuum);

// vim: tabstop=4 shiftwidth=4 noexpandtab autoindent softtabstop=4
