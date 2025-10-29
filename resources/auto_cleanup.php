<?php
/*
	FusionPBX Backup Manager
	Auto Cleanup - Retention Policy Management
	
	Copyright (C) 2025 3WTURK - World Wide Web Solutions
	https://3wturk.com
	
	Developed for FusionPBX 5.4.7+
	Deletes old backups based on max_backups_per_domain setting
*/

require_once dirname(__DIR__, 3) . "/resources/require.php";

echo "=== AUTO CLEANUP ===\n";

//load settings from config file
$config_file = dirname(__DIR__) . '/backup_config.php';
if (file_exists($config_file)) {
	require_once $config_file;
}

$max_backups = defined('BACKUP_MAX_PER_DOMAIN') ? BACKUP_MAX_PER_DOMAIN : 10;
$backup_path = defined('BACKUP_PATH') ? BACKUP_PATH : '/var/backups/fusionpbx';

echo "Max backups per domain: $max_backups\n";
echo "Backup path: $backup_path\n\n";

//get all backup files
$files = array_merge(
	glob($backup_path . '/*.tar.gz') ?: [],
	glob($backup_path . '/*.sql.gz') ?: []
);

//group by domain
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

//cleanup each domain
$total_deleted = 0;

foreach ($backups_by_domain as $domain => $backups) {
	$count = count($backups);
	
	if ($count <= $max_backups) {
		echo "$domain: $count backups (OK)\n";
		continue;
	}
	
	// Sort by timestamp DESC (newest first)
	usort($backups, function($a, $b) {
		return $b['timestamp'] - $a['timestamp'];
	});
	
	// Delete oldest backups
	$to_delete = $count - $max_backups;
	echo "$domain: $count backups, deleting $to_delete oldest...\n";
	
	for ($i = $max_backups; $i < $count; $i++) {
		$file = $backups[$i]['file'];
		$date = $backups[$i]['date'];
		
		if (unlink($file)) {
			echo "  ✓ Deleted: " . basename($file) . " ($date)\n";
			$total_deleted++;
		} else {
			echo "  ✗ Failed to delete: " . basename($file) . "\n";
		}
	}
}

echo "\n=== CLEANUP COMPLETE ===\n";
echo "Total deleted: $total_deleted file(s)\n";
?>
