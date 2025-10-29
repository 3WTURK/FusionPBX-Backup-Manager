<?php
/*
	FusionPBX Backup Manager
	Schedule Runner - Automated Backup & FTP Upload
	
	Copyright (C) 2025 3WTURK - World Wide Web Solutions
	https://3wturk.com
	
	Developed for FusionPBX 5.4.7+
	Usage: php schedule_runner.php (called by cron)
*/

require_once dirname(__DIR__, 3) . "/resources/require.php";

echo "=== SCHEDULED BACKUP & TRANSFER ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

// Load config
$config_file = dirname(__DIR__) . '/backup_config.php';
if (file_exists($config_file)) {
	require_once $config_file;
}

// Check if FTP is enabled
if (!defined('FTP_ENABLED') || !FTP_ENABLED) {
	echo "FTP is not enabled. Exiting.\n";
	exit(0);
}

// STEP 1: Create new backups
echo "=== STEP 1: CREATING BACKUPS ===\n\n";

// Get all domains
$database = new database;
$sql = "SELECT domain_uuid, domain_name FROM v_domains WHERE domain_enabled = 'true' ORDER BY domain_name";
$domains = $database->select($sql, null, 'all');

if (is_array($domains) && count($domains) > 0) {
	echo "Found " . count($domains) . " active domain(s)\n\n";
	
	foreach ($domains as $domain) {
		echo "Creating backup for: " . $domain['domain_name'] . "\n";
		
		// Create backup entry
		$backup_uuid = uuid();
		$array['backup_manager'][0]['backup_manager_uuid'] = $backup_uuid;
		$array['backup_manager'][0]['domain_uuid'] = $domain['domain_uuid'];
		$array['backup_manager'][0]['backup_name'] = $domain['domain_name'] . '_scheduled_' . date('Y-m-d_H-i-s');
		$array['backup_manager'][0]['backup_type'] = 'full';
		$array['backup_manager'][0]['backup_status'] = 'pending';
		$array['backup_manager'][0]['backup_progress'] = 0;
		$array['backup_manager'][0]['insert_date'] = date('Y-m-d H:i:s');
		$array['backup_manager'][0]['insert_user'] = 'system';
		
		$database->app_name = 'backup_manager';
		$database->app_uuid = 'b3d4e7f2-8c9a-4d5e-9f6a-1b2c3d4e5f6a';
		$database->save($array);
		unset($array);
		
		// Run backup processor
		$processor = dirname(__DIR__) . '/resources/standalone_backup_processor.php';
		exec("php " . escapeshellarg($processor) . " " . escapeshellarg($backup_uuid) . " > /dev/null 2>&1 &");
		
		echo "  ✓ Backup queued: $backup_uuid\n";
	}
	
	echo "\nWaiting for backups to complete (30 seconds)...\n";
	sleep(30);
	echo "✓ Backup creation phase completed\n\n";
} else {
	echo "No active domains found\n\n";
}

// Create full system backup
echo "Creating full system backup...\n";
$backup_uuid = uuid();
$array['backup_manager'][0]['backup_manager_uuid'] = $backup_uuid;
$array['backup_manager'][0]['domain_uuid'] = null;
$array['backup_manager'][0]['backup_name'] = 'fullsystem_scheduled_' . date('Y-m-d_H-i-s');
$array['backup_manager'][0]['backup_type'] = 'fullsystem';
$array['backup_manager'][0]['backup_status'] = 'pending';
$array['backup_manager'][0]['backup_progress'] = 0;
$array['backup_manager'][0]['insert_date'] = date('Y-m-d H:i:s');
$array['backup_manager'][0]['insert_user'] = 'system';

$database->app_name = 'backup_manager';
$database->app_uuid = 'b3d4e7f2-8c9a-4d5e-9f6a-1b2c3d4e5f6a';
$database->save($array);
unset($array);

$processor = dirname(__DIR__) . '/resources/standalone_backup_processor.php';
exec("php " . escapeshellarg($processor) . " " . escapeshellarg($backup_uuid) . " > /dev/null 2>&1 &");

echo "  ✓ Full system backup queued: $backup_uuid\n";
echo "\nWaiting for full system backup (60 seconds)...\n";
sleep(60);
echo "✓ Full system backup completed\n\n";

// STEP 2: Collect and transfer backups
echo "=== STEP 2: COLLECTING & TRANSFERRING ===\n\n";

// Get backup path from config (already loaded at top)
$backup_path = defined('BACKUP_PATH') ? BACKUP_PATH : '/var/backups/fusionpbx';
echo "Backup path: $backup_path\n";

$temp_dir = '/tmp/backup_transfer_' . time();
mkdir($temp_dir, 0755, true);

echo "Temp directory: $temp_dir\n\n";

// Get all backup files
$tar_files = glob($backup_path . '/*.tar.gz') ?: [];
$sql_files = glob($backup_path . '/*.sql.gz') ?: [];
$files = array_merge($tar_files, $sql_files);

echo "Found " . count($tar_files) . " .tar.gz files\n";
echo "Found " . count($sql_files) . " .sql.gz files\n";
echo "Total files: " . count($files) . "\n\n";

// Group by domain
$backups_by_domain = array();

foreach ($files as $file) {
	$filename = basename($file);
	
	// Full system backups
	if (preg_match('/^fullsystem\.backup\.(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.sql\.gz$/', $filename, $matches)) {
		$domain_name = 'fullsystem';
		$date_str = $matches[1];
	}
	// Tenant backups
	elseif (preg_match('/^(.+)_(full|database|recordings|voicemail)_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.tar\.gz$/', $filename, $matches)) {
		$domain_name = $matches[1];
		$date_str = $matches[3];
	}
	else {
		continue;
	}
	
	// Convert date format
	$backup_date = str_replace('_', ' ', $date_str);
	$parts = explode(' ', $backup_date);
	$backup_date = $parts[0] . ' ' . str_replace('-', ':', $parts[1]);
	
	$backups_by_domain[$domain_name][] = array(
		'file' => $file,
		'date' => $backup_date,
		'timestamp' => strtotime($backup_date)
	);
}

echo "Found " . count($backups_by_domain) . " domains with backups\n\n";

// Process each domain
$transfer_files = array();

foreach ($backups_by_domain as $domain => $backups) {
	// Sort by timestamp DESC (newest first)
	usort($backups, function($a, $b) {
		return $b['timestamp'] - $a['timestamp'];
	});
	
	// Get domain short name (before first dot)
	$domain_short = explode('.', $domain)[0];
	
	// Create tar.gz with all backups for this domain
	$output_file = $temp_dir . '/' . $domain_short . '.tar.gz';
	
	echo "Processing $domain ($domain_short)...\n";
	echo "  Found " . count($backups) . " backup(s)\n";
	
	// Create tar command with all backup files
	$tar_files = array();
	foreach ($backups as $backup) {
		$tar_files[] = escapeshellarg($backup['file']);
		echo "  - " . basename($backup['file']) . " (" . $backup['date'] . ")\n";
	}
	
	$tar_cmd = "tar -czf " . escapeshellarg($output_file) . " -C " . escapeshellarg($backup_path) . " " . implode(' ', array_map('basename', array_column($backups, 'file')));
	exec($tar_cmd, $output, $return_var);
	
	if ($return_var === 0 && file_exists($output_file)) {
		$size = filesize($output_file);
		echo "  ✓ Created: " . basename($output_file) . " (" . format_bytes($size) . ")\n";
		$transfer_files[] = $output_file;
	} else {
		echo "  ✗ Failed to create archive\n";
	}
	
	echo "\n";
}

// Transfer to FTP
if (count($transfer_files) > 0) {
	echo "=== FTP TRANSFER ===\n";
	echo "Connecting to " . FTP_HOST . ":" . FTP_PORT . "...\n";
	
	$ftp = ftp_connect(FTP_HOST, FTP_PORT, 30);
	if (!$ftp) {
		echo "✗ Failed to connect to FTP server\n";
		cleanup_temp($temp_dir);
		exit(1);
	}
	
	if (!ftp_login($ftp, FTP_USERNAME, FTP_PASSWORD)) {
		echo "✗ FTP login failed\n";
		ftp_close($ftp);
		cleanup_temp($temp_dir);
		exit(1);
	}
	
	echo "✓ Connected and logged in\n";
	
	ftp_pasv($ftp, FTP_PASSIVE);
	
	// Create remote directory if needed
	$remote_path = FTP_PATH;
	$dirs = explode('/', trim($remote_path, '/'));
	$current_dir = '';
	foreach ($dirs as $dir) {
		$current_dir .= '/' . $dir;
		@ftp_mkdir($ftp, $current_dir);
	}
	
	echo "Remote path: $remote_path\n\n";
	
	// Upload each file
	$success_count = 0;
	foreach ($transfer_files as $local_file) {
		$remote_file = rtrim($remote_path, '/') . '/' . basename($local_file);
		echo "Uploading " . basename($local_file) . "...\n";
		
		if (ftp_put($ftp, $remote_file, $local_file, FTP_BINARY)) {
			echo "  ✓ Uploaded successfully\n";
			$success_count++;
		} else {
			echo "  ✗ Upload failed\n";
		}
	}
	
	ftp_close($ftp);
	
	echo "\n=== TRANSFER COMPLETE ===\n";
	echo "Uploaded: $success_count / " . count($transfer_files) . " files\n";
} else {
	echo "No files to transfer\n";
}

// Cleanup
cleanup_temp($temp_dir);

echo "\nFinished: " . date('Y-m-d H:i:s') . "\n";

// Helper functions
function format_bytes($bytes, $precision = 2) {
	$units = array('B', 'KB', 'MB', 'GB', 'TB');
	$bytes = max($bytes, 0);
	$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
	$pow = min($pow, count($units) - 1);
	$bytes /= pow(1024, $pow);
	return round($bytes, $precision) . ' ' . $units[$pow];
}

function cleanup_temp($dir) {
	echo "\nCleaning up temp directory...\n";
	exec("rm -rf " . escapeshellarg($dir));
	echo "✓ Cleanup complete\n";
}
?>
