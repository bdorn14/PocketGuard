<?php

/*
 __PocketMine Plugin__
name=PocketGuard
description=PocketGuard guards your chest against thieves.
version=1.0
author=MinecrafterJPN
class=PocketGuard
apiversion=9
*/

define("NOT_LOCKED", -1);
define("NORMAL_LOCK", 0);
define("PASSCODE_LOCK", 1);
define("PUBLIC_LOCK", 2);

class PocketGuard implements Plugin
{
	private $api, $db, $queue = array(), $shareQueue = array();

	public function __construct(ServerAPI $api, $server = false)
	{
		$this->api = $api;
	}

	public function init()
	{
		$this->loadDB();
		$this->api->addHandler("player.block.touch", array($this, "eventHandler"));
		$this->api->console->register("pg", "A set of commands PocketGuard offers", array($this, "commandHandler"));
	}

	public function eventHandler($data, $event)
	{
		if ($data['target']->getID() === CHEST) {
			$username = $data['player']->username;
			$owner = $this->getOwner($data['target']->x, $data['target']->y, $data['target']->z);
			$attribute = $this->getAttribute($data['target']->x, $data['target']->y, $data['target']->z);
			if (isset($this->queue[$username])) {
				$task = $this->queue[$username];
				switch ($task) {
					case "lock":
						if ($owner === NOT_LOCKED) {
							$this->lock($username, $data['target']->x, $data['target']->y, $data['target']->z, NORMAL_LOCK);
						} else {
							$this->api->chat->sendTo(false, "[PocketGuard] That chest has already been guarded by other player.", $username);
						}
						break;
					case "unlock":
						if ($owner === $username) {
							$this->unlock($data['target']->x, $data['target']->y, $data['target']->z, $username);
						}
						elseif ($owner === NOT_LOCKED) {
							$this->api->chat->sendTo(false, "[PocketGuard] That chest is not guarded.", $username);
						} else {
							$this->api->chat->sendTo(false, "[PocketGuard] That chest has been guarded by other player.", $username);
						}
						break;
					case "public":
						if ($owner === NOT_LOCKED) {
							$this->lock($username, $data['target']->x, $data['target']->y, $data['target']->z, PUBLIC_LOCK);
						} else {
							$this->api->chat->sendTo(false, "[PocketGuard] That chest has already been guarded by other player.", $username);
						}
						break;
					case "passlock":
						//passlock
						break;
					case "passunlock":
						//passunlock
						break;
					case "info":
						if ($owner !== NOT_LOCKED) {
							$this->info($data['target']->x, $data['target']->y, $data['target']->z, $username);
						} else {
							$this->api->chat->sendTo(false, "[PocketGuard] That chest is not guarded.", $username);
						}
						break;
				}
				unset($this->queue[$username]);
				return false;
			} elseif (isset($this->shareQueue[$username])) {
				$target = $this->queue[$username];
			} elseif ($owner !== $username and ($attribute !== PUBLIC_LOCK and $attribute !== NOT_LOCKED)) {
				$this->api->chat->sendTo(false, "[PocketGuard] That chest has been guarded.", $username);
				return false;
			} else {
				$this->api->chat->sendTo(false, "[PocketGuard] OK.", $username);
				return false;
			}
		}
	}

	public function CommandHandler($cmd, $args, $issuer, $alias)
	{
		$subCmd = $args[0];
		$output = "";
		if ($issuer === 'console') {
			$output .= "[PocketGuard] Must be run on the world.";
		} else {
			switch ($subCmd) {
				case "lock":
				case "unlock":
				case "public":
				case "passlock":
				case "passunlock":
				case "info":
					if (isset($this->queue[$issuer->username])
					or isset($this->shareQueue[$issuer->username])) {
						$output .= "[PocketGuards] You still have the task to do!";
					} else {
						$this->queue[$issuer->username] = $subCmd;
						$output .= "[PocketGuards][CMD:" . $subCmd . "] Touch the target chest!";
					}
					break;
				case "share":
					if (isset($this->queue[$issuer->username])
							or isset($this->shareQueue[$issuer->username])) {
						$output .= "[PocketGuards] You still have the task to do!";
					}
					//$target = $args[1];
					//$this->shareQueue[$issuer->username] = $target;
					break;
				default:
					$output .= "[PocketGuards] Such command dose not exist!";
					break;
			}
		}
		return $output;
	}

	private function loadDB()
	{
		$this->db = new SQLite3($this->api->plugin->configPath($this) . "PocketGuard.sqlite3");
		$this->db->query(
				"CREATE TABLE IF NOT EXISTS chests(
					id INTEGER PRIMARY KEY AUTOINCREMENT,
					owner TEXT NOT NULL,
					x INTEGER NOT NULL,
					y INTEGER NOT NULL,
					z INTEGER NOT NULL,
					attribute INTEGER NOT NULL,
					passcode TEXT
				)"
		);
	}
	
	private function getAttribute($x, $y, $z)
	{
		$result = $this->db->querySingle("SELECT attribute FROM chests WHERE x = $x AND y = $y AND z = $z", true);
		if(empty($result) or $result === false) return NOT_LOCKED;
		else return $result['attribute'];
	}

	private function getOwner($x, $y, $z)
	{
		$result = $this->db->querySingle("SELECT owner FROM chests WHERE x = $x AND y = $y AND z = $z", true);
		if(empty($result) or $result === false) return NOT_LOCKED;
		else return $result['owner'];
	}

	private function lock($owner, $x, $y, $z, $attribute, $passcode = null)
	{
		$stmt = $this->db->prepare("INSERT INTO chests (owner, x, y, z, attribute, passcode) VALUES (:owner, :x, :y, :z, :attribute, :passcode)");
		$stmt->bindValue(":owner", $owner);
		$stmt->bindValue(":x", $x);
		$stmt->bindValue(":y", $y);
		$stmt->bindValue(":z", $z);
		$stmt->bindValue(":attribute", $attribute);
		$stmt->bindValue(":passcode", $passcode);
		$stmt->execute();
		$stmt->clear();
		$stmt->close();
		$this->api->chat->sendTo(false, "[PocketGuard] Completed to lock.", $owner);
	}

	private function unlock($x, $y, $z, $username)
	{
		$this->db->query("DELETE FROM chests WHERE x = $x AND y = $y AND z = $z");
		$this->api->chat->sendTo(false, "[PocketGuard] Completed to unlock.", $username);
	}

	private function info($x, $y, $z, $username)
	{
		$stmt = $this->db->prepare("SELECT owner, attribute FROM chests WHERE x = :x AND y = :y AND z = :z");
		$stmt->bindValue(":x", $x);
		$stmt->bindValue(":y", $y);
		$stmt->bindValue(":z", $z);
		$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
		while ($res = $result) {
			console($res['owner']);
		}
		
		$owner = $result['owner'];
		if ($result['attribute'] === PASSCODE_LOCK) {
			$this->api->chat->sendTo(false, "[PocketGuard] Owner:$owner Passcode:Off", $username);
		} else {
			$this->api->chat->sendTo(false, "[PocketGuard] Owner:$owner Passcode:On", $username);
		}
	}

	public function __destruct()
	{
		$this->db->close();
	}
}