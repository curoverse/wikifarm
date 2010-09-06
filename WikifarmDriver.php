<?php


//TODO Caching to reduce queries-per-run

# $wdb = new WikifarmDriver ( getenv("WIKIFARM_DB_FILE") );
class WikifarmDriver {
	private $DB, $DBresult;
	protected $_cache;
	public $openid, $q_openid;

	function __construct ($db = null) {
		if (!$db) $db = getenv("WIKIFARM_DB_FILE");
		if (is_object($db)) {
			$this->DB =& $db;
		} elseif (is_file($db)) {
			$this->DB = new SQLite3($db);
		}
		if (!$this->DB) die("Fatal: The wikifarm database was unavailable.\n\n");
		$this->Focus();  // $_SERVER["REMOTE_USER"] by default, the user in focus is the currently signed-in one.
		$this->cacheClear();
	}

	function __destruct () { $this->DB->close(); }  // !!! We should change this if we want to keep the handle later

# basic functions

	function lastResult() { return $DBresult; }

	function query($sql) {
		$result = $this->DB->query($sql);
		if ($result===false) die ( $this->DB->lastErrorMsg() );
		$this->DBresult = array();
	  while ( $row = $result->fetchArray(SQLITE3_ASSOC) ) array_push($this->DBresult, $row);
		return $this->DBresult;
	}
	
	function querySingle($sql) {
		$this->DBresult = $this->DB->querySingle($sql);
		if ($this->DBresult===false) die ( $this->DB->lastErrorMsg() );
		return $this->DBresult;
	}

	function Focus ($openid = null) {
		if (!$openid) $openid = $_SERVER["REMOTE_USER"];
		if ($openid === $this->openid) return;
		$this->openid = $openid;
		$this->q_openid = SQLite3::escapeString ($openid);
		$this->cacheClear();
	}
	
	// sanity functions
	// NOTE: These are not for sanitizing text input, and do not. They merely verify table data.
	// Never mind, it appears now as though they do

	function is_a_group($group) {
		return ($this->querySingle("SELECT 1 FROM usergroups WHERE groupname = '".SQLite3::escapeString($group)."'")) ? true : false;
	}
	function is_a_user($openid) {
		return ($this->querySingle("SELECT 1 FROM users WHERE userid = '".SQLite3::escapeString($openid)."'")) ? true : false;
	}
	function is_a_wiki($wikiname) {
		return $this->querySingle("SELECT 1 FROM wikis WHERE wikiname = '".SQLite3::escapeString($wikiname)."'") ? true : false;
	}
		
	// cache functions

	function cacheClear() { $this->_cache = array( 'on' => true ); }
	function cacheDisable() { $this->_cache['on'] = false; }
	function cacheEnable() { $this->_cache['on'] = true; }	
	
	// specific set functions

	// returns a list of all wikis visible to $this->openid
	function &getVisibleWikis() {
		$wikis =& $this->getAllWikis();
		foreach ($wikis as $k => $w)
			if (!$w["readable"])
				unset ($wikis[$k]);
		return $wikis;
	}

	function _preloadMyRequests() {
		if (array_key_exists ("requested_autologin", $this->_cache))
			return;

		$this->_cache["requested_autologin"] = array();
		$this->_cache["requested_readable"] = array();
		$this->_cache["requested_group"] = array();

		$reqs = $this->query ("SELECT * FROM request WHERE userid='".$this->q_openid."'");
		foreach ($reqs as &$x) {
			if ($x["wikiid"] && $x["mwusername"]) {
				$this->_cache["requested_autologin"][$x["wikiid"]][] = $x["mwusername"];
				$this->_cache["requested_readable"][$x["wikiid"]] = true;
			}
			else if ($x["wikiid"])
				$this->_cache["requested_readable"][$x["wikiid"]] = true;
			else if ($x["groupname"])
				$this->_cache["requested_group"][] = $x["groupname"];
		}
	}

	function getAllWikis() {
		if (array_key_exists ("allwikis", $this->_cache))
			return $this->_cache["allwikis"];

		$wikis =& $this->query(
			"SELECT wikis.id as id,
			wikis.wikiname as wikiname,
			wikis.realname as realname,
			wikis.userid as userid
			FROM wikis ORDER BY wikis.id" );

		$readable = array();
		$x = $this->query ("SELECT * FROM usergroups
			LEFT JOIN wikipermission ON (userid_or_groupname=groupname OR groupname = 'ADMIN' OR userid_or_groupname=userid)
			WHERE userid='".$this->q_openid."' AND userid_or_groupname IS NOT NULL
			GROUP BY wikiid");
		foreach ($x as &$row)
			$readable[$row["wikiid"]] = true;

		$wikigroup = array();
		$x = $this->query ("SELECT * FROM wikipermission LEFT JOIN usergroups ON groupname=userid_or_groupname WHERE groupname IS NOT NULL GROUP BY wikiid, groupname");
		foreach ($x as &$row)
			$wikigroup[$row["wikiid"]][] = $row["groupname"];

		$this->_preloadMyRequests();
		$autologin = array();
		$x = $this->query ("SELECT * FROM autologin WHERE userid='".$this->q_openid."'");
		foreach ($x as &$row)
			$autologin[$row["wikiid"]][] = $row["mwusername"];

		foreach ($wikis as &$row) {
		    $row["wikiid"] = $row["id"];
		    if (array_key_exists ($row["id"], $readable)) {
			$row["readable"] = true;
			$row["requested_readable"] = false;
		    }
		    else if (array_key_exists ($row["id"], $this->_cache["requested_readable"]))
			$row["requested_readable"] = true;
		    else
			$row["requested_readable"] = false;

		    if (array_key_exists ($row["id"], $autologin))
			$row["autologin"] = $autologin[$row["id"]];
		    else
			$row["autologin"] = false;
		    if (array_key_exists ($row["id"], $this->_cache["requested_autologin"]))
			$row["requested_autologin"] = $this->_cache["requested_autologin"][$row["id"]];
		    else
			$row["requested_autologin"] = false;

		    if (array_key_exists ($row["id"], $wikigroup))
			    $row["groups"] = $wikigroup[$row["id"]];
		    else
			    $row["groups"] = array();
		}

		$this->_cache["allwikis"] = $wikis;
		return $wikis;
	}

	# returns true if the focus user owns any wikis
	function hasWikis() {
		$id = $this->q_openid;
		return $this->querySingle(
			"SELECT 1 FROM wikis WHERE wikis.userid='$id' " .
			"UNION SELECT 1 FROM wikipermission WHERE userid_or_groupname='$id'; "
		) ? true : false;
	}

	# returns wikis owned by the focus user
	function getMyWikis() {
		if (array_key_exists ("mywikis", $this->_cache))
			return $this->_cache["mywikis"];

		$wikis =& $this->query(
			"SELECT wikis.id as id,
			wikis.wikiname as wikiname,
			wikis.realname as realname,
			wikis.userid as userid
			FROM wikis 
			WHERE wikis.userid ='".$this->q_openid."'
			ORDER BY wikis.id" );

		$wikigroup = array();
		$x = $this->query ("SELECT * FROM wikipermission LEFT JOIN usergroups ON groupname=userid_or_groupname WHERE groupname IS NOT NULL GROUP BY wikiid, groupname");
		foreach ($x as &$row)
			$wikigroup[$row["wikiid"]][] = $row["groupname"];

		$this->_preloadMyRequests();
		$autologin = array();
		$x = $this->query ("SELECT * FROM autologin WHERE userid='".$this->q_openid."'");
		foreach ($x as &$row)
			$autologin[$row["wikiid"]][] = $row["mwusername"];

		foreach ($wikis as &$row) {
		    $row["wikiid"] = $row["id"];
				$row["readable"] = true;
				$row["requested_readable"] = false;

		    if (array_key_exists ($row["id"], $autologin))
			$row["autologin"] = $autologin[$row["id"]];
		    else
			$row["autologin"] = false;
		    if (array_key_exists ($row["id"], $this->_cache["requested_autologin"]))
			$row["requested_autologin"] = $this->_cache["requested_autologin"][$row["id"]];
		    else
			$row["requested_autologin"] = false;

		    if (array_key_exists ($row["id"], $wikigroup))
			    $row["groups"] = $wikigroup[$row["id"]];
		    else
			    $row["groups"] = array();
		}

		$this->_cache["allwikis"] = $wikis;
		return $wikis;
	}
	
	# returns a list of wikis selected by the focus user as favorites  TODO - invent this table
	function getFavoriteWikis() {
		$id = $this->q_openid;
		return $this->query( "SELECT wikiname FROM favouritewikis WHERE userid='$id' AND favorite=1");
	}

	function setFavoriteWiki($wikiname, $onoff = 1) {
		$id = $this->q_openid;
		$q_wikiname = SQLite3::escapeString ($wikiname);
		if ($onoff)	{
			return $this->DB->exec( "INSERT INTO favoritewikis (userid, wikiname) VALUES ('$id', '$q_wikiname')" );
		}
		return $this->DB->exec( "DELETE FROM favoritewikis WHERE userid='$id' AND wikiname='$q_wikiname'");
	}

	function getRecentWikis() {
		$id = $this->q_openid;
		# return $this->query( "SELECT wikiname, realname FROM wikis WHERE userid='$id' ORDER BY lastaccess LIMIT 5");
		return array("wikiname" => array("recent1","recent2"), "realname" => array('recent array 1', 'recent array 2'));
	}

	// returns true if $this->openid is a wikifarm admin	
	function isAdmin () {
		$id = $this->q_openid;
		return $this->querySingle("SELECT 1 FROM usergroups WHERE usergroups.userid = '$id' AND groupname = 'ADMIN'" );
	}
	
	function getUserGroups() {
		$id = $this->q_openid;
		if (!array_key_exists ("usergroups", $this->_cache)) {
			$this->_cache["usergroups"] = array();
			$x = $this->query( "SELECT groupname FROM usergroups WHERE userid='$id'");
			foreach ($x as $row)
				$this->_cache["usergroups"][] = $row["groupname"];
		}
		return $this->_cache["usergroups"];
	}

	function getRequestedGroups() {
		$this->_preloadMyRequests();
		return $this->_cache["requested_group"];
	}
		
	function getAllGroups() {
		if (!array_key_exists ("allgroups", $this->_cache)) {
			$this->_preloadMyRequests();
			$skipadmin = $this->isAdmin() ? "" : "WHERE groupname <> 'ADMIN'";
			$this->_cache["allgroups"] = $this->query("SELECT groupname FROM usergroups $skipadmin GROUP BY groupname UNION SELECT 'users'");
			foreach ($this->_cache["allgroups"] as &$g) {
				$g["requested"] = false !== array_search ($g["groupname"], $this->_cache["requested_group"]);
				$g["member"] = false !== array_search ($g["groupname"], $this->getUserGroups());
			}
		}
		return $this->_cache["allgroups"];
	}
	

	// Has this user been added to one or more groups, i.e.,
	// sanctioned as a legitimate user?  If not, we have no idea
	// whether she's a spammer, attacker, spy, hater, etc.

	function isActivated($openid = null) {
		return 0 != count ($this->getUserGroups());
	}
	
	function getUserRealname() {
		$id = $this->q_openid;
		return $this->querySingle("SELECT CASE WHEN realname IS NOT NULL THEN realname WHEN email IS NOT NULL THEN '('||email||')' ELSE '(None)' END FROM users WHERE userid='$id';" );
	}
	
	function setUserRealname($name) {
		$name = SQLite3::escapeString ($name); //TODO verifiy this is filtered enough
		$id = $this->q_openid;
		return $this->DB->exec("UPDATE users SET realname='$name' WHERE userid='$id';" );
	}		

	function getUserEmail() {
		$id = $this->q_openid;
		return $this->querySingle("SELECT email FROM users WHERE userid='$id';" );
	}

	function setUserEmail($email) {
		$id = $this->q_openid;
		return $this->DB->exec("UPDATE users SET email='".SQLite3::escapeString(filter_var($email, FILTER_VALIDATE_EMAIL))."' WHERE userid='$id';" );
	}

	function getUserPrefs() {
		return array ("email_requests" => true);
	}

	function setUserPrefs() {
		// TODO
	}

	function getUserByEmail($email) {		
		$email = SQLite3::escapeString (filter_var($email, FILTER_VALIDATE_EMAIL));
		return $this->querySingle("SELECT userid FROM users WHERE email='$email';" );
	}
	
	function getMWUsername() {
		$id = $this->q_openid;
		return $this->querySingle("SELECT mwusername FROM users WHERE userid='$id';" );
	}
		
	function setMWUsername($nickname) {
		if (strlen ($nickname = trim($nickname))) {
			$this->DB->exec ("UPDATE users SET mwusername='".SQLite3::escapeString ($nickname)."' WHERE userid='".SQLite3::escapeString ($this->q_openid)."'");
			return $this->DB->changes() == 1;
		}
	}
	
	function requestGroup($groups) {
		if (!is_array($groups))
			$groups = array($groups);
		$allgroups =& $this->getAllGroups();
		foreach (array_merge ($groups, array ("users")) as $group) {
			$found = false;
			foreach ($allgroups as &$realgroup)
				if ($realgroup["groupname"] == $group) {
					if ($realgroup["member"])
						;
					else if ($realgroup["requested"])
						;
					else {
						if ($this->DB->exec ("INSERT OR IGNORE INTO request (userid, groupname) VALUES ('".$this->q_openid."', '".SQLite3::escapeString($group)."')"))
							$realgroup["requested"] = true;
						else
							error_log ("requestGroup insert failed: ".$this->DB->lastErrorMsg());
					}
					$found = true;
					break;
				}
			if (!$found)
				error_log ("requestGroup nonexistent group: $group");
		}
	}

	// Responding to requests	
	function getAllRequests() {
		if (!array_key_exists ("getAllRequests", $this->_cache)) {
			$reqs = $this->query ("select * from request where wikiid in (select id from wikis where userid='".$this->q_openid."')");
			if (!$this->isAdmin()) {
				$this->_cache['getAllRequests'] = $reqs;
			} else {
				$group_reqs = $this->query ("select * from request where wikiid is null");
				$this->_cache['getAllRequests'] = array_merge ($group_reqs, $reqs);
			}
		}
		return $this->_cache['getAllRequests'];
	}
	
	// Am I allowed to approve or deny this request?  If not
	// allowed, return false.  If allowed, return assoc array with
	// the request details

	function canApproveRequest($requestid) {
		// $requestid itself must be reasonable
		if (!ereg ("^[0-9]+", $requestid)) return false;

		// there must be exactly one request with this id
		$req = $this->query ("select * from request where requestid=$requestid");
		if (count($req) != 1) {
			error_log ("canApproveRequest: count(req id $requestid)=".count($req));
			return false;
		}

		// admin can approve any request
		if ($this->isAdmin()) return $reqs[0];

		// non-admin can only approve a request concerning a wiki she actually owns
		if ($this->querySingle("select 1 from wikis where id=".$req[0]["wikiid"]." and userid='".$this->q_openid."'"))
			return $reqs[0];

		error_log ("canApproveRequest: user ".$this->openid." cannot do ".print_r($reqs[0],true));

		return false;
	}
	
	function approveRequestId($requestid) {
		$req = $this->canApproveRequest ($requestid);
		if (!$req)
			return false;
		if ($req["wikiid"]) {
			if ($req["userid"]) $who = $req["userid"];
			else $who = $req["groupname"];
			$this->DB->exec ("insert or replace into wikipermission (wikiid, userid_or_groupname) values ('".$req["wikiid"]."', '".SQLite3::escapeString($who)."')");
		}
		else if ($req["groupname"])
			$this->DB->exec ("insert or replace into usergroups (userid, groupname) values ('".SQLite3::escapeString($req["userid"])."', '".SQLite3::escapeString($req["groupname"])."')");
		else {
			error_log ("approveRequestId: unknown request type: ".print_r($req,true));
			return false;
		}
		
		$this->DB->exec ("delete from request where requestid=$requestid");
	}
	
	function rejectRequestId($requestid) {
		if ($this->canApproveRequest ($requestid)) {
			$this->DB->exec ("delete from request where requestid=$requestid");
			return $this->DB->changes() == 1;
		}
		else
			return false;
	}

	// Invitations

	function claimInvite($code) {
		$q_openid = $this->q_openid;
		// TODO
	}

	function claimInvitationByPassword($username, $password)
	{
		$userid = $this->openid;
		$q_userid = SQLite3::escapeString ($userid);
		$q_old_username = SQLite3::escapeString ($username);
		$provided_password = ereg_replace ("\n", "", $password);

		$cryptpw = $this->querySingle ("select cryptpw from users where userid='$q_old_username'");
		putenv ("PW=$provided_password");
		putenv ("SALT=$cryptpw");
		$check = `perl -e 'use Apache::Htpasswd; \$h = new Apache::Htpasswd("/dev/null"); print \$h->CryptPasswd (\$ENV{PW}, \$ENV{SALT})'`;
		if (!$userid ||
		    strlen($cryptpw) < 6 ||
		    trim($check) != trim($cryptpw)) {
			$this->_error = array ("code" => "401",
					       "message" => "Authentication failed: username or password incorrect.");
			return false;
		}

		$db->exec ("update wikis set userid='$q_userid' where userid='$q_old_username'");
		$wikis_claimed = $db->changes();

		$db->exec ("update or ignore usergroups set userid='$q_userid' where userid='$q_old_username'");
		$groups_claimed = $db->changes();

		$db->exec ("update or ignore wikipermission set userid_or_groupname='$q_userid' where userid_or_groupname='$q_old_username'");
		$access_claimed = $db->changes();

		$db->exec ("INSERT OR IGNORE INTO users (userid, realname, email)
	    	      SELECT '$q_userid',

		      CASE WHEN realname IS NULL AND userid NOT LIKE '%@%' THEN userid
		      ELSE realname END,

		      CASE WHEN email IS NULL AND userid LIKE '%@%' THEN userid
		      ELSE email END

		      FROM users WHERE userid='$q_old_username'");
		return array ("wikis_claimed" => $wikis_claimed,
			      "groups_claimed" => $groups_claimed,
			      "access_claimed" => $access_claimed);
	}
	
	function createInvitation($group, $wiki, $email) {
		// TODO: make this useful
		return md5(`head -c32 /dev/urandom`);
	}
	
	function inviteUser($wikiid,$invitee_email,$mwusername=false) {
		$userid = $this->getUserByEmail($email);
		if (!$userid) {
			error_log ("inviteUser: no such user: $email");
			$this->_error = "No such user";
			return false;
		}
		$q_userid = SQLite3::escapeString ($userid);
		if (!ereg ('^[0-9]+$', $wikiid)) {
			error_log ("inviteUser: invalid wikiid $wikiid");
			$this->_error = "No such wiki";
			return false;
		}
		if (!$this->isAdmin() &&
		    !$this->querySingle ("select 1 from wikis where id='$wikiid' and userid='".$this->q_openid."'")) {
			error_log ("inviteUser: wikiid $wikiid not owned by ".$this->openid);
			$this->_error = "Permission denied";
			return false;
		}
		if (!$this->DB->exec ("insert or ignore into wikipermission (wikiid, userid_or_groupname) values ($wikiid, '$q_userid')")) {
			error_log ("inviteUser: db error ".$this->DB->lastErrorMsg());
			$this->_error = "Database error";
			return false;
		}
		return true;
	}

	
}  // WikifarmDriver class ends


?>
