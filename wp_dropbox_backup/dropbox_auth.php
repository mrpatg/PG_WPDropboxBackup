<?php
session_start();
$form_api_key = $_SESSION['form_api_key'];
$form_api_secret = $_SESSION['form_api_key'];

error_reporting(E_ALL);
require_once("DropboxClient.php");


?>
<html>
<head>
</head>
<body>
<h2>Dropbox App Authorization</h2>
<p>
<form method="POST" action="sample.php">
<input type="text" name="api_key" placeholder="api key"><br>
<input type="text" name="api_secret" placeholder="api secret"><br>
<input type="submit" value="Authorize">
</form>

</p>







</body>
</html>