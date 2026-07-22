<?php
$page='';
if(!empty($_REQUEST['p']))
{
	$page = basename($_REQUEST['p']);
}

if(!empty($page) && file_exists($page.".php"))
{
	include($page.".php");
}
else
{
	include("home.php");	
}
?>