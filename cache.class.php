<?php

namespace Scottchiefbaker\Cache;

class Sqlite {
	public $pdo      = null;  // PDO Object
	public $db_file  = "";    // Path to the SQLite file
	public $mode     = "";    // 'json', 'igb', 'msgp'
	public $disabled = false; // Used to disable cache at runtime

	public function __construct($opts = []) {
		$id            = $opts['id']      ?? "0001";
		$this->db_file = $opts['db_file'] ?? "/dev/shm/cache-$id.sqlite";
		$silent        = $opts['silent']  ?? false;
		$missing_db    = !file_exists($this->db_file);
		$dsn           = "sqlite:" . $this->db_file;
		$db_dir        = dirname($this->db_file) . "/";

		if (!is_writable($db_dir)) {
			$this->error_out("directory <code>$db_dir</code> is not writable", 12309);
		}

		$this->pdo  = new \PDO($dsn);
		$this->mode = $opts['mode'] ?? "";

		if ($this->mode) {
			// Do nothing the user specified the mode
		} elseif (function_exists("igbinary_serialize")) {
			$this->mode = "igb";
		} elseif (function_exists("msgpack_pack")) {
			$this->mode = "msgp";
		} elseif (function_exists("json_encode")) {
			$this->mode = "json";
		} else {
			die("No serialization formats available");
		}

		if ($missing_db) {
			$ok = $this->init_db($silent);
		}

		return $this->pdo;
	}

	public function __destruct() { }

	// Create/Erase the database structure
	public function init_db($silent = 0) {
		$sql = "DROP TABLE IF EXISTS cache;";
		$ok  = $this->pdo->exec($sql);

		$sql = "CREATE TABLE cache (
			CreateTime INT,
			ExpireTime INT,
			Key VARCHAR(255) PRIMARY Key UNIQUE,
			Value BLOB
		);";

		// Clean up the SQL so it's easier to read in .dump
		$sql = preg_replace("/\t+/", "\t", $sql);
		$sql = preg_replace("/\t\);/", ");", $sql);

		$ok  = $this->pdo->exec($sql);

		$sql = "CREATE INDEX ExpireTimeIndex ON cache (ExpireTime)";
		$ok  = $this->pdo->exec($sql);

		chmod($this->db_file, 0666);

		if (!$silent) {
			print "<div style=\"background: lightblue; color blue; border: 1px solid darkblue; padding: 6px; border-radius: 4px;\"><b>Info:</b> database initialized</div>";
		}

		return $ok;
	}

	// Read an item from the cache
	public function get($key) {
		if ($this->disabled) { return null; }

		$sql = "SELECT rowid, Value, ExpireTime FROM cache WHERE Key = ?;";

		try {
			$sth = $this->pdo->prepare($sql);
		} catch (PDOException $e) {
			$code = $e->getCode();
			if ($code === "HY000") {
				$this->error_out("Table 'cache' missing. DB corrupt?", 84203);
			} else {
				$this->error_out($e->getMessage(), 12352);
			}

		}

		$ok  = $sth->execute([$key]);
		$ret = $sth->fetch(\PDO::FETCH_NUM);

		$row_id = $ret[0] ?? 0;
		$data   = $ret[1] ?? "";
		$expire = $ret[2] ?? 0;
		$now    = time();

		// If it's expired we remove it from the DB and return null
		if ($row_id && $now > $expire) {
			$this->remove_expired_entries(0);
			$ret = null;
		} else {
			$ret = $this->unpack($data);
		}

		return $ret;
	}

	// Delete an item from the cache
	public function delete_cache_item($key) {
		$sql = "DELETE FROM cache WHERE Key = ?;";

		$sth = $this->pdo->prepare($sql);
		$ok  = $sth->execute([$key]);

		return $ok;
	}

	// Write/Replace a cache entry into the cache
	public function set($key, $value, $expires = 0) {
		if ($this->disabled) { return null; }

		// Default to caching for an hour
		if (empty($expires)) {
			$expires = time() + 3600;
		// $expires less than 100000 means cache for that many seconds
		} elseif ($expires < 100000) {
			$expires = time() + $expires;
		}

		$sql    = "REPLACE INTO cache (Key, Value, ExpireTime, CreateTime) VALUES (:key, :value, :expires, :create);";
		$create = time();

		$enc = $this->pack($value);

		$sth = $this->pdo->prepare($sql);
		$sth->bindParam(":key"    , $key    , \PDO::PARAM_STR);
		$sth->bindParam(":value"  , $enc    , \PDO::PARAM_LOB);
		$sth->bindParam(":expires", $expires, \PDO::PARAM_INT);
		$sth->bindParam(":create" , $create , \PDO::PARAM_INT);
		$ok  = $sth->execute();

		return $ok;
	}

	// Serialize object
	private function pack($str) {
		if ($this->mode === "igb") {
			$ret = igbinary_serialize($str);
		} elseif ($this->mode === "msgp") {
			$ret = msgpack_pack($str);
		} elseif ($this->mode === "json") {
			$ret = json_encode($str);
		} else {
			$ret = false;
		}

		return $ret;
	}

	// Unserialize object
	private function unpack($str) {
		if ($this->mode === "igb") {
			$ret = igbinary_unserialize($str);
		} elseif ($this->mode === "msgp") {
			$ret = msgpack_unpack($str);
		} elseif ($this->mode === "json") {
			$ret = json_decode($str, true);
		} else {
			$ret = false;
		}

		return $ret;
	}

	// Remove any expired entries from the database
	public function remove_expired_entries($vacuum = 1) {
		$sql = "DELETE FROM cache WHERE ExpireTime < ?;";

		$now = time();
		$sth = $this->pdo->prepare($sql);
		$ok  = $sth->execute([$now]);

		if ($vacuum) {
			$this->vacuum();
		}

		return $ok;
	}

	// Run VACUUM on the SQLite DB to free up disk space
	public function vacuum() {
		$sql = "VACUUM";
		$sth = $this->pdo->prepare($sql);
		$ok  = $sth->execute();
	}

	// Spit out an error message
	public function error_out($msg, int $err_num) {
		$style = "
			.s_error {
				font-family  : sans;
				color        : #842029;
				padding      : 0.8em;
				border-radius: 4px;
				margin-bottom: 8px;
				background   : #f8d7da;
				border       : 1px solid #f5c2c7;
				max-width    : 70%;
				margin       : auto;
				min-width    : 370px;
			}

			.s_error_head {
				margin-top : 0;
				color      : white;
				text-shadow: 0px 0px 7px gray;
			}
			.s_error_num { margin-top: 1em; }
			.s_error_file {
				margin-top : 2em;
				padding-top: 0.5em;
				font-size  : .8em;
				border-top : 1px solid gray;
			}

			.s_error code {
				padding         : .2rem .4rem;
				font-size       : 1.1em;
				border-radius   : .2rem;
				background-color: #dad5d5;
				color           : #1a1a1a;
				border          : 1px solid #c2c2c2;
			}
		";

		$d    = debug_backtrace();
		$file = $d[0]['file'] ?? "";
		$line = $d[0]['line'] ?? 0;

		$title = "Error #$err_num";

		$body  = "<div class=\"s_error\">\n";
		$body .= "<h1 class=\"s_error_head\">Fatal Error #$err_num</h1>";
		$body .= "<div class=\"s_error_desc\"><b>Description:</b> $msg</div>";

		if ($file && $line) {
			$body .= "<div class=\"s_error_file\">Source: <code>$file</code> #$line</div>";
		}

		$body .= "</div>\n";

		$out = "<!doctype html>
		<html lang=\"en\">
			<head>
				<meta charset=\"utf-8\">
				<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">
				<title>$title</title>
				<style>$style</style>
			</head>
			<body>
				$body
			</body>
		</html>";

		print $out;

		exit;
	}
}

// vim: tabstop=4 shiftwidth=4 noexpandtab autoindent softtabstop=4
