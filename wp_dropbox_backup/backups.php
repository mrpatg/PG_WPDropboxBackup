<?php
session_start();
ini_set("memory_limit","512M");

date_default_timezone_set('America/New_York');

//grab dropbox api keys from post or session
$form_api_key = "";
$form_api_secret = "";

// Define MySQL information, and path to backups folder
$dbhost   = "";
$dbuser   = "";
$dbpass    = "";
$dbname   = "";

// Define file names for your backed up files
$dumpsql = $dbname . "_" . date("Y-m-d_H-i-s") . ".sql";
$dumptar = "Full_Backup_" . date("Y-m-d_H-i-s") . ".tar";

// Establish type of backup (full or incremental)
$backup_type = null;
$backup_type = $_REQUEST['backup'];

// establish blank tmp files to load backup data into
$tempsql = tempnam("/tmp","tmp");
$temptar = tempnam("/tmp","tmp");

// Perform MySQL dump, write to file and create md5 hash
passthru("/usr/bin/mysqldump --opt --host=$dbhost --user=$dbuser --password=$dbpass $dbname > ".$tempsql);
// Establish a md5 hash and filesize for our sql file to validate later on
$dumpsql_hash = md5_file($tempsql);
$dumpsql_size = filesize($tempsql);


// Perform FULL backup of files contained within the specified directories amd create md5 hash
if($backup_type == "full"){
	passthru("tar -cvf ".$temptar." ");
	$dumptar_hash = md5_file($temptar);
	$dumptar_size = filesize($temptar);
}
// Perform INCREMENTAL backup of files contained within the specified directories (only ones that have changed since last date) and create md5 hash
if($backup_type == "inc"){
	$dumptar = "Incremental_Backup_" . date("Y-m-d_H-i-s") . ".tar";
	passthru("tar -cf ".$temptar." portfolio js");
	$dumptar_hash = md5_file($temptar);
	$dumptar_size = filesize($temptar);
}

// Determine how backup will be offsite_methodted
$offsite_method = $_REQUEST['offsite'];
//$offsite_method = null;

// $i is used as a counter to verify two file matches have occured. It appears later on
$i=null;

$temptar_handle = fopen($temptar, 'r+');
$tempsql_handle = fopen($tempsql, 'r+');

if($offsite_method == "ftp"){
	$ftppath = trim($_REQUEST['ftppath']);
	$ftpport = trim($_REQUEST['ftpport']);
	$ftpserver = trim($_REQUEST['ftpserver']);
	$ftpuser = trim($_REQUEST['ftpuser']);
	$ftppass = trim($_REQUEST['ftppass']);


	// FTP Information
	$conn_id = ftp_connect($ftpserver, $ftpport);
	$login_result = ftp_login($conn_id, $ftpuser, $ftppass);
	$i=0;
	// Upload TAR file first, and confirm MD5 check with local and remote file
	ftp_chdir($conn_id, $ftppath);
	if (ftp_fput($conn_id, $dumptar, $temptar_handle, FTP_BINARY)) {
		//$dumptar_remote_hash = md5_file($backup_path."remote/".$dumptar);
			// If file checksums match, delete local file
			$dumptar_remote_size = ftp_size($conn_id, $dumptar);
			if($dumptar_size == $dumptar_remote_size){
				$i++;
				unlink($temptar);
			}
		

	} else {
		// error
	}
	// Upload MySQL file, and confirm MD5 check with local and remote file
	if (ftp_fput($conn_id, $dumpsql, $tempsql_handle, FTP_BINARY)) {
		//$dumpsql_remote_hash = md5_file($backup_path."remote/".$dumpsql);
			// If file checksums match, delete local file
			$dumpsql_remote_size = ftp_size($conn_id, $dumpsql);
			if($dumpsql_size == $dumpsql_remote_size){
				$i++;
				unlink($tempsql);
			}
	} else {
		// error
	}

	// Close the connection and the file handlers
	ftp_close($conn_id);


}else if($offsite_method == "dropbox"){



	require_once("DropboxClient.php");

	$dropbox = new DropboxClient(array(
		'app_key' => $form_api_key, 
		'app_secret' => $form_api_secret,
		'app_full_access' => false,
	),'en');
	
	// first try to load existing access token
	$access_token = load_token("access");
	if(!empty($access_token)) {
		$dropbox->SetAccessToken($access_token);
		//print_r($access_token);
	}
	elseif(!empty($_REQUEST['auth_callback'])) // are we coming from dropbox's auth page?
	{
		// then load our previosly created request token
		$request_token = load_token($_REQUEST['oauth_token']);
		if(empty($request_token)) die('Request token not found!');
		
		// get & store access token, the request token is not needed anymore
		$access_token = $dropbox->GetAccessToken($request_token);	
		store_token($access_token, "access");
		delete_token($_REQUEST['oauth_token']);
	}

	// checks if access token is required
	if(!$dropbox->IsAuthorized())
	{
		// redirect user to dropbox auth page
		$return_url = "http://".$_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"]."&auth_callback=1";
		$auth_url = $dropbox->BuildAuthorizeUrl($return_url);
		$request_token = $dropbox->GetRequestToken();
		store_token($request_token, $request_token['t']);
		//die("Authentication required. <a href='$auth_url'>Click here.</a>");
		// If authentication is required, redirect page straight to dropbox to authorize
		header('Location: '.$auth_url);
	}

	// Upload file, verify remote size with local size, if it works, remove local file and increase match counter ($i)
	$dropbox->UploadFile($tempsql, $dumpsql);
	$dumpsql_dropbox_metadata = $dropbox->GetMetadata($dumpsql);
	if($dumpsql_size == $dumpsql_dropbox_metadata->bytes){ $i++; }
	$dropbox->UploadFile($temptar, $dumptar);
	$dumptar_dropbox_metadata = $dropbox->GetMetadata($dumptar);
	if($dumptar_size == $dumptar_dropbox_metadata->bytes){ $i++; }
	






}


// Determine if we have a 2 count for the file matching
if($i == "2"){ $remote_match = "YES"; }else{ $remote_match = "NO"; }

// If an email is provided, kick out a message with report on file size and hashes
$email=null;
$email = $_REQUEST['email'];
if($email){
	$message = "SQL Backup: $dumpsql ($dumpsql_size bytes)\r\n
	SQL MD5 Checksum: $dumpsql_hash\r\n
	Files Backup: $dumptar ($dumptar_size bytes)\r\n
	Files MD5 Checksum: $dumptar_hash\r\n \r\n
	Remote Files Match: $remote_match";

	$message = wordwrap($message, 100, "\r\n");
	mail($email, 'Backup', $message);


}



// Functions associated with the dropbox api token handling
function store_token($token, $name)
{
	if(!file_put_contents("tokens/$name.token", serialize($token)))
		die('<br />Could not store token! <b>Make sure that the directory `tokens` exists and is writable!</b>');
}

function load_token($name)
{
	if(!file_exists("tokens/$name.token")) return null;
	return @unserialize(@file_get_contents("tokens/$name.token"));
}

function delete_token($name)
{
	@unlink("tokens/$name.token");
}
?>
