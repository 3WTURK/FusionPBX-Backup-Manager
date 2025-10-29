<?php
/*
	FusionPBX Backup Manager
	AJAX Backup List Handler
	
	Copyright (C) 2025 3WTURK - World Wide Web Solutions
	https://3wturk.com
	
	Developed for FusionPBX 5.4.7+
*/

/*
	AJAX endpoint to load all backups for a domain
*/

require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

if (permission_exists('backup_manager_view')) {
	//access granted
}
else {
	exit("access denied");
}

$domain_name = $_GET['domain_name'] ?? '';

if (empty($domain_name)) {
	exit("Invalid domain");
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

//get all backups for this domain from FILE SYSTEM
$backups = array();

if (is_dir($backup_path)) {
	$files = glob($backup_path . '/*.tar.gz');
	
	foreach ($files as $file) {
		$filename = basename($file);
		
		// Parse filename
		if (preg_match('/^(.+)_(full|database|recordings|voicemail)_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.tar\.gz$/', $filename, $matches)) {
			$file_domain_name = $matches[1];
			
			// Only this domain
			if ($file_domain_name != $domain_name) {
				continue;
			}
			
			$backup_type = $matches[2];
			$date_str = $matches[3];
			
			// Convert date format: 2025-10-28_21-04-47 → 2025-10-28 21:04:47
			$backup_date = str_replace('_', ' ', $date_str); // 2025-10-28 21-04-47
			$parts = explode(' ', $backup_date);
			$backup_date = $parts[0] . ' ' . str_replace('-', ':', $parts[1]); // 2025-10-28 21:04:47
			
			$backups[] = array(
				'backup_manager_uuid' => md5($file),
				'domain_name' => $file_domain_name,
				'backup_type' => $backup_type,
				'backup_date' => $backup_date,
				'backup_status' => 'completed',
				'backup_size' => filesize($file),
				'backup_path' => $file
			);
		}
	}
	
	// Sort by date descending
	usort($backups, function($a, $b) {
		return strtotime($b['backup_date']) - strtotime($a['backup_date']);
	});
}

//helper function
function format_bytes($bytes, $precision = 2) {
	$units = array('B', 'KB', 'MB', 'GB', 'TB');
	$bytes = max($bytes, 0);
	$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
	$pow = min($pow, count($units) - 1);
	$bytes /= pow(1024, $pow);
	return round($bytes, $precision) . ' ' . $units[$pow];
}

if (is_array($backups) && count($backups) > 0) {
	echo "<table class='list' style='margin:0;'>\n";
	echo "<thead>\n";
	echo "<tr>\n";
	if (permission_exists('backup_manager_restore')) {
		echo "	<th class='checkbox'></th>\n";
	}
	echo "	<th>Date</th>\n";
	echo "	<th>Type</th>\n";
	echo "	<th>Size</th>\n";
	echo "	<th>Status</th>\n";
	echo "	<th>Actions</th>\n";
	echo "</tr>\n";
	echo "</thead>\n";
	echo "<tbody>\n";
	
	foreach ($backups as $backup) {
		$status_class = 'default';
		switch ($backup['backup_status']) {
			case 'completed': $status_class = 'success'; break;
			case 'processing': $status_class = 'warning'; break;
			case 'pending': $status_class = 'info'; break;
			case 'failed': $status_class = 'danger'; break;
		}
		
		echo "<tr>\n";
		echo "	<td class='text-center'>";
		if ($backup['backup_status'] == 'completed') {
			echo "<input type='checkbox' name='backup_uuids[]' value='" . $backup['backup_manager_uuid'] . "' class='backup-checkbox' onchange='parent.update_selection()' form='restore_form'>";
		}
		echo "</td>\n";
		echo "	<td>" . escape($backup['backup_date']) . "</td>\n";
		echo "	<td>" . escape($backup['backup_type']) . "</td>\n";
		echo "	<td>" . format_bytes($backup['backup_size'] ?? 0) . "</td>\n";
		echo "	<td><span class='badge badge-" . $status_class . "'>" . escape($backup['backup_status']) . "</span></td>\n";
		echo "	<td>\n";
		
		if ($backup['backup_status'] == 'completed') {
			if (permission_exists('backup_manager_restore')) {
				echo "<a href='javascript:void(0)' onclick='parent.restore_single(\"" . $backup['backup_manager_uuid'] . "\")' class='btn btn-default btn-sm' title='Restore'><i class='fa fa-undo'></i></a> ";
			}
			echo "<a href='backup_download.php?id=" . urlencode($backup['backup_manager_uuid']) . "' class='btn btn-default btn-sm' title='Download'><i class='fa fa-download'></i></a> ";
		}
		if (permission_exists('backup_manager_delete')) {
			echo "<a href='javascript:void(0)' onclick='parent.confirm_delete(\"" . $backup['backup_manager_uuid'] . "\")' class='btn btn-default btn-sm' title='Delete'><i class='fa fa-trash'></i></a>";
		}
		
		echo "	</td>\n";
		echo "</tr>\n";
	}
	
	echo "</tbody>\n";
	echo "</table>\n";
} else {
	echo "<p>No backups found for this domain.</p>\n";
}
?>
