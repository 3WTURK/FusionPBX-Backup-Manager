#!/usr/bin/env php
<?php
/*
	FusionPBX Backup Manager
	Standalone Restore Processor - Tenant Backup Restore
	
	Copyright (C) 2025 3WTURK - World Wide Web Solutions
	https://3wturk.com
	
	Developed for FusionPBX 5.4.7+
	Usage: php standalone_restore_processor_file.php /path/to/backup.tar.gz
*/

// Get backup file path
$backup_file = $argv[1] ?? '';
if (empty($backup_file) || !file_exists($backup_file)) {
	exit("Backup file not found: $backup_file\n");
}

echo "Starting restore from: $backup_file\n";

// Extract backup
$temp_dir = '/tmp/restore_' . time();
mkdir($temp_dir, 0755, true);

echo "Extracting backup to: $temp_dir\n";
$cmd = "tar -xzf " . escapeshellarg($backup_file) . " -C " . escapeshellarg($temp_dir);
exec($cmd, $output, $return_var);

if ($return_var !== 0) {
	echo "Failed to extract backup\n";
	exec("rm -rf " . escapeshellarg($temp_dir));
	exit(1);
}

echo "Backup extracted successfully\n";

// Check for database.sql file
$db_file = $temp_dir . '/database.sql';
if (file_exists($db_file)) {
	echo "Restoring database...\n";
	
	// Get database config
	require_once dirname(__DIR__, 3) . "/resources/require.php";
	
	// Extract domain_uuid from backup file
	$sql_content = file_get_contents($db_file);
	if (preg_match('/-- Domain UUID: (.+)/', $sql_content, $matches)) {
		$domain_uuid = trim($matches[1]);
		echo "Domain UUID: $domain_uuid\n";
		
		// Delete existing data for this domain
		echo "Deleting existing data for domain...\n";
		
		$tables_to_clean = [
			'v_extensions', 'v_devices', 'v_dialplans', 'v_dialplan_details',
			'v_gateways', 'v_ivr_menus', 'v_ivr_menu_options', 'v_ring_groups',
			'v_ring_group_destinations', 'v_call_center_queues', 'v_call_center_agents',
			'v_call_center_tiers', 'v_conference_centers', 'v_conference_rooms',
			'v_voicemails', 'v_voicemail_greetings', 'v_voicemail_messages',
			'v_fax', 'v_fax_files', 'v_call_flows', 'v_time_conditions',
			'v_music_on_hold', 'v_recordings', 'v_follow_me', 'v_follow_me_destinations',
			'v_call_block', 'v_call_routings', 'v_destination_conditions', 'v_destination_actions'
		];
		
		try {
			$pdo = new PDO(
				"pgsql:host=$db_host;port=$db_port;dbname=$db_name",
				$db_username,
				$db_password
			);
			$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			
			foreach ($tables_to_clean as $table) {
				try {
					// Delete only this domain's records (not global)
					$stmt = $pdo->prepare("DELETE FROM $table WHERE domain_uuid = ? AND domain_uuid IS NOT NULL");
					$stmt->execute([$domain_uuid]);
					$count = $stmt->rowCount();
					if ($count > 0) {
						echo "  Deleted $count rows from $table\n";
					}
				} catch (PDOException $e) {
					// Table might not exist or no domain_uuid column
				}
			}
		} catch (PDOException $e) {
			echo "Warning: Could not clean existing data: " . $e->getMessage() . "\n";
		}
	}
	
	// Import database
	$cmd = "PGPASSWORD=" . escapeshellarg($db_password) . " ";
	$cmd .= "psql -h " . escapeshellarg($db_host) . " ";
	$cmd .= "-p " . escapeshellarg($db_port) . " ";
	$cmd .= "-U " . escapeshellarg($db_username) . " ";
	$cmd .= "-d " . escapeshellarg($db_name) . " ";
	$cmd .= "< " . escapeshellarg($db_file) . " 2>&1";
	
	exec($cmd, $output, $return_var);
	
	// Count errors
	$error_count = 0;
	$success_count = 0;
	foreach ($output as $line) {
		if (stripos($line, 'ERROR') !== false) {
			$error_count++;
		}
		if (stripos($line, 'INSERT') !== false) {
			$success_count++;
		}
	}
	
	echo "SQL execution completed\n";
	echo "Successful INSERTs: $success_count\n";
	echo "Errors: $error_count\n";
	
	if ($error_count > 0) {
		echo "\nFirst 10 errors:\n";
		$shown = 0;
		foreach ($output as $line) {
			if (stripos($line, 'ERROR') !== false) {
				echo "  $line\n";
				$shown++;
				if ($shown >= 10) break;
			}
		}
	}
	
	if ($success_count > 0) {
		echo "Database restored with $success_count records\n";
	} else {
		echo "WARNING: No records were inserted!\n";
	}
}

// Restore recordings
$recordings_dir = $temp_dir . '/recordings';
if (is_dir($recordings_dir)) {
	echo "Restoring recordings...\n";
	$cmd = "cp -r " . escapeshellarg($recordings_dir) . "/* /var/lib/freeswitch/recordings/ 2>&1";
	exec($cmd);
	echo "Recordings restored\n";
}

// Restore voicemail
$voicemail_dir = $temp_dir . '/voicemail';
if (is_dir($voicemail_dir)) {
	echo "Restoring voicemail...\n";
	$cmd = "cp -r " . escapeshellarg($voicemail_dir) . "/* /var/lib/freeswitch/storage/voicemail/ 2>&1";
	exec($cmd);
	echo "Voicemail restored\n";
}

// Cleanup
echo "Cleaning up...\n";
exec("rm -rf " . escapeshellarg($temp_dir));

echo "Restore completed!\n";
?>
