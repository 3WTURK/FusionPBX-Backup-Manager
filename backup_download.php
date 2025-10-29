<?php
/*
	FusionPBX Backup Manager
	Backup Download Handler
	
	Copyright (C) 2025 3WTURK - World Wide Web Solutions
	https://3wturk.com
	
	Developed for FusionPBX 5.4.7+
*/

require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

if (permission_exists('backup_manager_view')) {
	//access granted
}
else {
	echo "access denied";
	exit;
}

//get backup uuid (md5 hash of file path)
$backup_uuid = $_GET['id'] ?? '';

if (empty($backup_uuid)) {
	header("Location: backup_manager.php");
	exit;
}

//load backup path from config
$config_file = __DIR__ . '/backup_config.php';
$backup_path = '/var/backups/fusionpbx'; // Default
if (file_exists($config_file)) {
	require_once $config_file;
	if (defined('BACKUP_PATH')) {
		$backup_path = BACKUP_PATH;
	}
}

//find backup file from filesystem
$files = glob($backup_path . '/*.{tar.gz,sql.gz}', GLOB_BRACE);

$backup_file = null;
foreach ($files as $file) {
	if (md5($file) == $backup_uuid) {
		$backup_file = $file;
		break;
	}
}

if (empty($backup_file) || !file_exists($backup_file)) {
	$_SESSION['message'] = "Backup file not found";
	header("Location: backup_manager.php");
	exit;
}

//send file to browser
$filename = basename($backup_file);
$filesize = filesize($backup_file);

header('Content-Type: application/x-gzip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $filesize);
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');
header('Expires: 0');

//output file
readfile($backup_file);
exit;
?>
