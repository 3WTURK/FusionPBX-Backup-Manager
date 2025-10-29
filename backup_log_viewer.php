<?php
/*
	FusionPBX Backup Manager
	Backup Log Viewer
	
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

// Use logs directory in module folder
$log_dir = dirname(__FILE__) . '/logs';
if (!is_dir($log_dir)) {
	mkdir($log_dir, 0755, true);
}
$log_file = $log_dir . '/backup.log';

// Handle clear action
if (isset($_GET['action']) && $_GET['action'] == 'clear' && $_SERVER['REQUEST_METHOD'] == 'POST') {
	if (permission_exists('backup_manager_delete')) {
		file_put_contents($log_file, '');
		echo "Log file cleared";
	} else {
		echo "Permission denied";
	}
	exit;
}

// Display log content
if (!file_exists($log_file)) {
	// Create log file if it doesn't exist
	$initial_content = "=== FusionPBX Backup Manager Log ===\n";
	$initial_content .= "Log created: " . date('Y-m-d H:i:s') . "\n\n";
	file_put_contents($log_file, $initial_content);
	chmod($log_file, 0664);
	echo "Log file created. No entries yet.";
} else {
	$content = file_get_contents($log_file);
	if (empty($content)) {
		echo "Log file is empty. No backup operations have been logged yet.";
	} else {
		// Show last 500 lines
		$lines = explode("\n", $content);
		$lines = array_slice($lines, -500);
		echo htmlspecialchars(implode("\n", $lines));
	}
}
?>
