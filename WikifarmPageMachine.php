<?php	

require_once ('WikifarmDriver.php');

class WikifarmPageMachine extends WikifarmDriver {
	public $tabNames, $js_tabNames;

	function __construct($db = null) {
		WikifarmDriver::__construct($db);
	}

	function page_debug() {
		$output = "<b>current sqlite schema: </b><br><pre>";
		$result = $this->query( "SELECT sql FROM sqlite_master" );
		foreach ($result as $row) { 
			$output .= htmlspecialchars($row['sql']) . "\n\n";
		}
		$output .= "</pre>";
		return $output;
	}

	// all about the tabs	

	function tabGet($tab) {
		if (!method_exists ($this, "page_$tab"))
			return "__FUNCTION__: Invalid page request";
		return call_user_func (array ($this, "page_$tab"));
	}

	// activating invites based on user/password or an invite code, requesting access or additional access
	function page_getaccess() {
		$openid = $this->openid;
		$requestcount = 0;
		$username = null;
		if ($this->isActivated()) {
			$username = $this->getUserRealname();
			$wikinick = $this->getMWUsername();
		}
		//hack
		$grouplist = array( 'group' => array('group1','group2','group3','group4','group5'),
			'pending_since' => array( time()-1000, time()-4000, "june 23, 2010", null, null),
			'is_a_member' => array (false, false, false, false, true) );		
		
		$output = <<<BLOCK
<table width=100%><tr><td>
Already have an invite code or a pre-OpenID username and password?<br><br>
<blockquote>
<form action="index.php" method="post">
Username: <input type=text name=username size=16>
<br />Password: <input type=password name=password size=16>

<blockquote>Or</blockquote>

Invite Code: <input type=text name=invite size=16>
<br /><input type=submit value="Get Access">
</form>

</blockquote>
After you do this, your wiki and group memberships will be
attached to the OpenID you are currently logged in as ($openid).

</td><td class=vertbreak>|</td><td>

Request access to stuff (approval required, we will let you know)
<blockquote>
<form action="index.php" method="post">
BLOCK;
		if ($username) {
			$output .= "You are signed in as: <b>$username</b><br>";
		} else {
			$output .= "Your Name: <input type=text name=realname size=16> Email Adress: <input type=text name=email size=16>";
		}
		$output .= "Groups you wish to request membership to:<br>
<table><tr><td>group name</td><td>membership status</td></tr>";
		foreach ($grouplist['group'] as $i => $group) {
			$requestcount++;
			$output .= "\n<tr><td>$group</td><td>";
			if ($grouplist['pending_since'][$i]) {
				$output .= "Request pending since " . PMRelativeTime($grouplist['pending_since'][$i]);
			} elseif ($grouplist['is_a_member'][$i]) {
				$output .= "You are a member";
			} else {
				$output .= "<input type=\"checkbox\" name=\"request$requestcount\" value=\"$group\" /> Request membership";
			}
			$output .= "</td></tr>";
		}
		$output .= "</table><input type=submit value=\"Send Request\"></form>\n</blockquote>";
		return $output;
	}

	function page_myaccount() {
		return $this->uglydumpling (array ("userid" => $this->openid,
						   "email" => $this->getUserEmail(),
						   "realname" => $this->getUserRealname(),
						   "mwusername" => $this->getMWUsername(),
						   "prefs" => $this->getUserPrefs()));
	}


	function page_wikis() {
		if (!$this->isActivated()) {			
			error_log ("page_wikis: requested by unactivated user");
			return page_wikis_unactivated();
		}
		$wikiArray = $this->getAllWikis();
		$output = "<script type=\"text/javascript\">\n\t$(function() {\n".
				// "\t$(function() {\n\t\t$(\"#$element\").selectable();\n\t});\n" .
				// "\t$(function() {\n\t\t$('#$element').accordion({ header: 'h3' });\n\t});\n" .			
				"\t\t$(\"a\", \"#controls\").button();\n".
				
			"\t});\n</script>\n<style type=\"text/css\">\n" .
				// "#$element { width: 80%; }\n".
				"#allwikis td.wikiid { width: 25px; text-align: right; padding-right: 10px; }\n".

			"</style>\n";		
		$output .= "<h2>All Wikis</h2>\n<table id=\"allwikis\" class=\"ui-widget\" >\n".
			"<tr class=\"ui-widget-header\"><td class=\"wikiid ui-corner-tl\">#</td><td>Wiki</td><td class=\"controls ui-corner-tr\">Your Username</td></tr>\n";
		
		foreach ($wikiArray as $row) {
			extract ($row);
			if ($realname == '') $realname = $wikiname;	
			$output .= "\t<tr class=\"ui-widget-content\">".
				"<td class=\"wikiid\">$wikiid</td>".
				"<td>".($readable ? "<a href=\"/$wikiid/\">$realname</a>" : $realname)."</td>".
				"<td class=\"controls\"><a href=\"#\">Request Access</a></td>".
				"</tr>\n";
		}
		$output .= "</table>\n";		
		$output .= $this->uglydumpling ($this->getAllWikis());
		return $output;
	}

	function page_mywikis() {
		if (!$this->isActivated()) {
			error_log ("__FUNCTION__: requested by unactivated user");
			return false;
		}
		$wikiArray = $this->getMyWikis();
		$element = "mywikistabs";
		$output = "<script language=\"JavaScript\">\n\t$(function() {\n\t\t$(\"#$element\").tabs();\n\t});\n</script>" .
			"\n\t" .
//TODO
			"<h2>My Wikis</h2>\n" .
			"<div id=\"$element\">\n\t<ul>\n";
		$content = "";
		foreach ($wikiArray as $row) {
			extract ($row);
			$visible_to = implode(", ", $groups);
			$output .= "\t\t<li><a href=\"#tab_$wikiname\">#$wikiid: $realname</a></li>\n";
			$content .= "
	<div id=\"tab_$wikiname\">
		<p>Wiki #$wikiid: $realname ($wikiname)<br>
		Login now as: ... <br>
		This wiki is visible to these groups: $visible_to<br>
		to do...</p>
	</div>";
		}
		$output .= "\t\t<li><a href=\"#newwikitab\"><span class=\"ui-icon ui-icon-arrowreturnthick-1-s\" style=\"float: left; margin-right: .3em;\"></span>Create a New Wiki</a></li>\n\t</ul>$content\n" .
			"\t<div id=\"newwikitab\">\n\t\t<p> create wiki stuff... </p>\n\t</div>\n</div>\n";		
		$output .= $this->uglydumpling ($this->getMyWikis());
		return $output;
	}
	
	// some default landing page
	function page_wikis_unactivated() {
		return "page_wikis_unactivated - [join group links]";
	}

	function page_groups() {
		$output = "<h3>Groups</h3>";
	
		return ($output . $this->uglydumpling ($this->getAllGroups()) );
	}


	function page_tools() {
		return <<<BLOCK
<h2>Tools</h2><br>
<ul>
<li><a href="table.php">Excel -> Wiki Table converter</a></li>
</ul>
BLOCK;
	}

	function page_requests() {
		$requests = $this->getAllRequests();
		$num = count($requests);
		$output = "<h2>Requests</h2>What's this page do?";
		if ($num > 0) $output .= $this->textHighlight("You have <strong>$num</strong> pending request". ($num == 1 ? "." : "s.") );
		$output .= "<table class=\"ui-state-default ui-corner-all\" style=\"padding: 0 .7em;\">\n";
		foreach ($requests as $req) {
			extract ($req);
			$output .= "\t<tr><td><span class=\"ui-icon ui-icon-flag\" style=\"float: left; margin-right: .3em;\"></span>" . 
				(!$this->isAdmin() ? "<td>#$requestid</td>" : "") .
				"<td>wiki: $wikiid, mwusername: $mwusername, groupname: $groupname </td>
				</tr>\n";
		}
		$output .= "</table>\n";
		
		return $output . $this->uglydumpling ($this->getAllRequests());
	}
	
	function page_createwiki() {
		return "to do";
		
	}

	function uglydumpling ($x) {
		return "<pre>".htmlspecialchars(print_r($x,true))."</pre>";
	}
	
	// $obj->textHighlight("<strong>Hey!</strong> Sample ui-state-highlight style.");
	function textHighlight($text) {
		return "<div class=\"ui-widget\">
			<div class=\"ui-state-highlight ui-corner-all\" style=\"margin-top: 20px; padding: 0 .7em;\"> 
				<p><span class=\"ui-icon ui-icon-info\" style=\"float: left; margin-right: .3em;\"></span>
				$text</p>
			</div>";
	}
	// $obj->textError("<strong>Alert:</strong> Sample ui-state-error style.");
	function textError($text) {
		return "<div class=\"ui-widget\">
			<div class=\"ui-state-error ui-corner-all\" style=\"padding: 0 .7em;\"> 
				<p><span class=\"ui-icon ui-icon-alert\" style=\"float: left; margin-right: .3em;\"></span> 
				$text</p>
			</div>
		</div>";
	}


	
}  // class ends




// misc functions

function PMRelativeTime($date) {
	if ($date+0 == 0) $date = strtotime($date);
	$diff = time() - $date;
	if ($diff<60) {
		$r = "$diff second";
	} else {
		$diff = round($diff/60);
		if ($diff<60) {
			$r = "$diff minute";
		} else {
			$diff = round($diff/60);
			if ($diff<24) {
				$r = "$diff hour";
			} else {
				$diff = round($diff/24);
				if ($diff<7) {
					$r = "$diff day";
				} else {
					$diff = round($diff/7);
					if ($diff<4) {
						$r = "$diff week";
					} else {
						return date("F j, Y", $date);
					}
				}
			}
		}
	}
	return $r . ($diff !=1 ? 's' : '') . " ago";
}

?>