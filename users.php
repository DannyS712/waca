<?php
/************************************************
** English Wikipedia Account Request Interface **
** All code is released into the public domain **
**             Developers:                     **
**  SQL ( http://en.wikipedia.org/User:SQL )   **
**  Cobi ( http://en.wikipedia.org/User:Cobi ) **
**                                             **
************************************************/
echo "<html>
<head>
<title>Account Creation Manager User Report</title>
</head>
<body>\n";
function showfooter() {
	echo "</body></html>\n";
}
function sanitize($what) {
        $what = mysql_real_escape_string($what);
        return($what);
}

require_once('../../database.inc');
mysql_connect("sql",$toolserver_username,$toolserver_password);
@mysql_select_db("u_sql") or print mysql_error();
$query = "SELECT * FROM acc_user ORDER BY user_level";
$result = mysql_query($query);
if(!$result) Die("ERROR: No result returned.");
if ($_GET[viewuser] != "") {
	echo "<h2>Detail report for user: </h2>\n";
	showfooter();
	die();
}
echo "<h2>User List</h2>\n<ul>\n";
while ($row = mysql_fetch_assoc($result)) {
	if($row[user_level] != $lastlevel && $row[user_level] != "Suspended" && $row[user_level] != "Declined") { echo "<h3>$row[user_level]</h3>\n"; }
	if($row[user_level] == "Suspended") { $row[user_name] = ""; }
	if($row[user_level] == "Declined") { $row[user_name] = ""; }
	if($row[user_name] != "") {
		echo "<li><a href=\"users.php?viewuser=$row[user_id]\">$row[user_name]</a></li>\n";
	}
	$lastlevel = $row[user_level];
}
echo "<ul>\n";
showfooter();
?>
