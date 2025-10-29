#!/usr/bin/env php
<?php
/*
	FusionPBX Backup Manager
	Standalone Backup Processor - Tenant Backup Creator
	
	Copyright (C) 2025 3WTURK - World Wide Web Solutions
	https://3wturk.com
	
	Developed for FusionPBX 5.4.7+
	Usage: php standalone_backup_processor.php <backup_uuid>
*/

// Get backup UUID
$backup_uuid = $argv[1] ?? '';
if (empty($backup_uuid)) {
	exit("Backup UUID required\n");
}

// Get database config dynamically
function get_db_config() {
	// Try multiple config locations
	$config_files = [
		'/etc/fusionpbx/config.conf',
		'/etc/fusionpbx/config.php',
		'/usr/local/fusionpbx/config.php'
	];
	
	foreach ($config_files as $config_file) {
		if (file_exists($config_file)) {
			if (strpos($config_file, '.conf') !== false) {
				$config = parse_ini_file($config_file);
				return [
					'host' => $config['database.0.host'] ?? '127.0.0.1',
					'port' => $config['database.0.port'] ?? '5432',
					'name' => $config['database.0.name'] ?? 'fusionpbx',
					'user' => $config['database.0.username'] ?? 'fusionpbx',
					'pass' => $config['database.0.password'] ?? ''
				];
			} else {
				include($config_file);
				if (isset($db_host)) {
					return [
						'host' => $db_host ?? '127.0.0.1',
						'port' => $db_port ?? '5432',
						'name' => $db_name ?? 'fusionpbx',
						'user' => $db_username ?? 'fusionpbx',
						'pass' => $db_password ?? ''
					];
				}
			}
		}
	}
	
	// Fallback to defaults
	return [
		'host' => '127.0.0.1',
		'port' => '5432',
		'name' => 'fusionpbx',
		'user' => 'fusionpbx',
		'pass' => ''
	];
}

$db_config = get_db_config();

// Connect to database
try {
	$pdo = new PDO(
		"pgsql:host={$db_config['host']};port={$db_config['port']};dbname={$db_config['name']}", 
		$db_config['user'], 
		$db_config['pass']
	);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
	exit("Database connection failed: " . $e->getMessage() . "\n");
}

// Get backup details
$stmt = $pdo->prepare("SELECT * FROM v_backup_manager WHERE backup_manager_uuid = ?");
$stmt->execute([$backup_uuid]);
$backup = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$backup) {
	exit("Backup not found\n");
}

// Get domain details
$stmt = $pdo->prepare("SELECT * FROM v_domains WHERE domain_uuid = ?");
$stmt->execute([$backup['domain_uuid']]);
$domain = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$domain) {
	update_status($pdo, $backup_uuid, 'failed', 0);
	exit("Domain not found\n");
}

echo "Starting backup for domain: " . $domain['domain_name'] . "\n";

// Update status to processing
update_status($pdo, $backup_uuid, 'processing', 5);

// Load backup path from config
$config_file = dirname(__DIR__) . '/backup_config.php';
$backup_path = '/var/backups/fusionpbx'; // Default
if (file_exists($config_file)) {
	require_once $config_file;
	if (defined('BACKUP_PATH')) {
		$backup_path = BACKUP_PATH;
	}
}
echo "Using backup path: $backup_path\n";
$timestamp = date('Y-m-d_H-i-s');
$backup_filename = $domain['domain_name'] . '_' . $backup['backup_type'] . '_' . $timestamp . '.tar.gz';
$backup_file = $backup_path . '/' . $backup_filename;

// Create directory
if (!file_exists($backup_path)) {
	mkdir($backup_path, 0755, true);
}

$temp_dir = '/tmp/fusionpbx_backup_' . $backup_uuid;
if (!file_exists($temp_dir)) {
	mkdir($temp_dir, 0755, true);
}

try {
	$metadata = array();
	
	// Backup database - DOMAIN SPECIFIC ONLY
	if ($backup['backup_type'] == 'full' || $backup['backup_type'] == 'database') {
		update_status($pdo, $backup_uuid, 'processing', 10);
		echo "Backing up database (domain-specific)...\n";
		
		$db_backup_file = $temp_dir . '/database.sql';
		
		// Tables that have domain_uuid column
		$domain_tables = [
			'v_extensions',
			'v_devices',
			'v_dialplans',
			'v_dialplan_details',
			'v_gateways',
			'v_ivr_menus',
			'v_ivr_menu_options',
			'v_ring_groups',
			'v_ring_group_destinations',
			'v_call_center_queues',
			'v_call_center_agents',
			'v_call_center_tiers',
			'v_conference_centers',
			'v_conference_rooms',
			'v_voicemails',
			'v_voicemail_greetings',
			'v_voicemail_messages',
			'v_fax',
			'v_fax_files',
			'v_call_flows',
			'v_time_conditions',
			'v_music_on_hold',
			'v_recordings',
			'v_follow_me',
			'v_follow_me_destinations',
			'v_call_block',
			'v_call_routings',
			'v_destination_conditions',
			'v_destination_actions'
		];
		
		// Create SQL backup file
		$sql_content = "-- FusionPBX Domain Backup\n";
		$sql_content .= "-- Domain: " . $domain['domain_name'] . "\n";
		$sql_content .= "-- Domain UUID: " . $backup['domain_uuid'] . "\n";
		$sql_content .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
		
		foreach ($domain_tables as $table) {
			try {
				// Check if table exists
				$stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = ?");
				$stmt->execute([$table]);
				if ($stmt->fetchColumn() == 0) {
					echo "  Skipping $table (not found)\n";
					continue;
				}
				
				// Get data for this domain ONLY (not global)
				$stmt = $pdo->prepare("SELECT * FROM $table WHERE domain_uuid = ? AND domain_uuid IS NOT NULL");
				$stmt->execute([$backup['domain_uuid']]);
				$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
				
				if (count($rows) > 0) {
					echo "  Backing up $table: " . count($rows) . " rows\n";
					
					$sql_content .= "\n-- Table: $table\n";
					$sql_content .= "-- Rows: " . count($rows) . "\n\n";
					
					foreach ($rows as $row) {
						$columns = array_keys($row);
						$values = array_values($row);
						
						// Get column types to handle properly
						$column_types = [];
						try {
							$type_stmt = $pdo->prepare("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ?");
							$type_stmt->execute([$table]);
							while ($type_row = $type_stmt->fetch(PDO::FETCH_ASSOC)) {
								$column_types[$type_row['column_name']] = $type_row['data_type'];
							}
						} catch (PDOException $e) {
							// Ignore
						}
						
						// Escape values properly
						$escaped_values = [];
						foreach ($columns as $idx => $col) {
							$val = $values[$idx];
							$col_type = $column_types[$col] ?? '';
							
							// NULL values
							if ($val === null || $val === '') {
								$escaped_values[] = 'NULL';
								continue;
							}
							
							// Boolean values
							if ($col_type === 'boolean' || $val === 't' || $val === 'f') {
								if ($val === 't' || $val === 'true' || $val === true) {
									$escaped_values[] = 'true';
								} else if ($val === 'f' || $val === 'false' || $val === false) {
									$escaped_values[] = 'false';
								} else {
									$escaped_values[] = 'NULL';
								}
								continue;
							}
							
							// Regular values
							$escaped_values[] = $pdo->quote($val);
						}
						
						$sql_content .= "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $escaped_values) . ");\n";
					}
				}
			} catch (PDOException $e) {
				echo "  Warning: Could not backup $table: " . $e->getMessage() . "\n";
			}
		}
		
		// Write to file
		file_put_contents($db_backup_file, $sql_content);
		
		if (!file_exists($db_backup_file)) {
			throw new Exception("Database backup failed");
		}
		
		echo "Database backup completed\n";
		
		// Get metadata
		$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM v_extensions WHERE domain_uuid = ?");
		$stmt->execute([$backup['domain_uuid']]);
		$metadata['extensions_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
		
		update_status($pdo, $backup_uuid, 'processing', 30);
	}
	
	// Backup recordings
	if ($backup['backup_type'] == 'full' || $backup['backup_type'] == 'recordings') {
		update_status($pdo, $backup_uuid, 'processing', 40);
		echo "Backing up recordings...\n";
		
		$recordings_dir = '/var/lib/freeswitch/recordings/' . $domain['domain_name'];
		if (file_exists($recordings_dir)) {
			$recordings_backup_dir = $temp_dir . '/recordings';
			mkdir($recordings_backup_dir, 0755, true);
			exec("cp -r $recordings_dir $recordings_backup_dir/ 2>&1");
			$metadata['recordings'] = 'included';
		}
		
		update_status($pdo, $backup_uuid, 'processing', 60);
	}
	
	// Backup voicemail
	if ($backup['backup_type'] == 'full' || $backup['backup_type'] == 'voicemail') {
		update_status($pdo, $backup_uuid, 'processing', 70);
		echo "Backing up voicemail...\n";
		
		$voicemail_dir = '/var/lib/freeswitch/storage/voicemail/default/' . $domain['domain_name'];
		if (file_exists($voicemail_dir)) {
			$voicemail_backup_dir = $temp_dir . '/voicemail';
			mkdir($voicemail_backup_dir, 0755, true);
			exec("cp -r $voicemail_dir $voicemail_backup_dir/ 2>&1");
			$metadata['voicemail'] = 'included';
		}
		
		update_status($pdo, $backup_uuid, 'processing', 80);
	}
	
	// Create metadata file
	$metadata['backup_uuid'] = $backup_uuid;
	$metadata['domain_uuid'] = $backup['domain_uuid'];
	$metadata['domain_name'] = $domain['domain_name'];
	$metadata['backup_type'] = $backup['backup_type'];
	$metadata['backup_date'] = date('Y-m-d H:i:s');
	file_put_contents($temp_dir . '/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT));
	
	// Create tar.gz
	update_status($pdo, $backup_uuid, 'processing', 85);
	echo "Compressing backup...\n";
	
	$cmd = "tar -czf $backup_file -C $temp_dir . 2>&1";
	exec($cmd, $output, $return_var);
	
	if ($return_var !== 0 || !file_exists($backup_file)) {
		throw new Exception("Backup compression failed");
	}
	
	$backup_size = filesize($backup_file);
	
	// Cleanup temp directory
	exec("rm -rf $temp_dir");
	
	// Update backup record
	$stmt = $pdo->prepare("
		UPDATE v_backup_manager 
		SET backup_path = ?, 
		    backup_size = ?, 
		    backup_status = 'completed', 
		    backup_progress = 100,
		    backup_metadata = ?
		WHERE backup_manager_uuid = ?
	");
	$stmt->execute([$backup_file, $backup_size, json_encode($metadata), $backup_uuid]);
	
	echo "Backup completed successfully!\n";
	echo "File: $backup_file\n";
	echo "Size: " . round($backup_size / 1024 / 1024, 2) . " MB\n";
	
} catch (Exception $e) {
	if (file_exists($temp_dir)) {
		exec("rm -rf $temp_dir");
	}
	if (file_exists($backup_file)) {
		unlink($backup_file);
	}
	
	update_status($pdo, $backup_uuid, 'failed', 0);
	exit("Backup failed: " . $e->getMessage() . "\n");
}

function update_status($pdo, $backup_uuid, $status, $progress = null) {
	$sql = "UPDATE v_backup_manager SET backup_status = ?";
	$params = [$status];
	
	if ($progress !== null) {
		$sql .= ", backup_progress = ?";
		$params[] = $progress;
	}
	
	$sql .= " WHERE backup_manager_uuid = ?";
	$params[] = $backup_uuid;
	
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);
}
?>
