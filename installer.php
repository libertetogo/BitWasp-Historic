<?php

$step = $_GET['step'];

if($step == '1'){
$mysqlhost = $_POST['mysqlhost'];
$mysqluser = $_POST['mysqluser'];
$mysqlpass = $_POST['mysqlpass'];
$sitename = $_POST['sitename'];
$sitebase = $_POST['sitebase'];
$dbfail = false;
$connection = mysql_connect($mysqlhost,$mysqluser,$mysqlpass);
if(!$connection){
$dbfail = true;
echo "Failure connecting to mysql server; check host, username, password?<br />";
} else {
echo "MySQL database connected<br />";
}
echo "<br />";

$db = mysql_select_db($mysqldb, $connection);
if(!$db){
$dbfail = true;
echo "Unable to select your database, check it currently exists?";
} else {
echo "Database exists!<br />";
}
echo "<br />";

// Die if DB failed.
if($dbfail == true){
die("Click back on your browser, and enter your mysql configuration again.");
}

require_once("schema.sql");
$anyfail = false;
foreach($query as $q){
	$sql = mysql_query($q,$connection);
	if(!$sql)
		$anyfail = true;
}
if($anyfail == true){
	echo "There was an error somewhere... ";
}

echo "Doing well to get here!";

} else {
//if(!isset($step)){
echo "<form action='installer.php?step=1' method='post'>
Enter MySQL database info:<Br />
Host:<input type='text' name='mysqlhost' value='localhost'/><br />
User:<input type='text' name='mysqluser' value='' /><br />
Pass:<input type='text' name='mysqlpass' value='' /><Br />
DB:<input type='text' name='mysqldb' value='' /><br /><br />

Enter some basic info about your site:<br />
Name:<input type='text' name='sitename' value='' /><br />
Base URL: <input type='text' name='sitebase' value='' /><br />

<input type='submit' value='Submit'>
</form>";

}
