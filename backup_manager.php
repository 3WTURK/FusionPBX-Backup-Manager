<?php
/*
	FusionPBX Backup Manager
	Main Backup List Page
	
	Copyright (C) 2025 3WTURK - World Wide Web Solutions
	https://3wturk.com
	
	Developed for FusionPBX 5.4.7+
	
	License: MPL 1.1
	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Copyright (C) 2025
	All Rights Reserved.
*/

//includes files
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";
require_once "resources/paging.php";

//check permissions
if (permission_exists('backup_manager_view')) {
	//access granted
}
else {
	echo "access denied";
	exit;
}

//add multi-lingual support
$language = new text;
$text = $language->get();

// Helper function to get database config
function get_db_config_for_backup() {
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
			}
		}
	}
	
	return [
		'host' => '127.0.0.1',
		'port' => '5432',
		'name' => 'fusionpbx',
		'user' => 'fusionpbx',
		'pass' => ''
	];
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

//handle restore full system action
if (!empty($_POST['action']) && $_POST['action'] == 'restore_fullsystem' && permission_exists('backup_manager_restore')) {
	$backup_uuid = $_POST['backup_uuid'] ?? '';
	
	if (empty($backup_uuid)) {
		$_SESSION['message'] = "<span style='color: red;'>No backup selected</span>";
		header("Location: backup_manager.php");
		exit;
	}
	
	// Find full system backup file
	$sql_files = glob($backup_path . '/*.sql.gz');
	$backup_file = null;
	
	foreach ($sql_files as $file) {
		if (md5($file) == $backup_uuid) {
			$backup_file = $file;
			break;
		}
	}
	
	if (!$backup_file) {
		$_SESSION['message'] = "<span style='color: red;'>Backup file not found</span>";
		header("Location: backup_manager.php");
		exit;
	}
	
	// Restore full system backup
	$db_config = get_db_config_for_backup();
	$log_file = "/tmp/restore_fullsystem_" . time() . ".log";
	
	// WARNING: This will DROP and RECREATE the entire database!
	$restore_script = "/tmp/restore_fullsystem_" . time() . ".sh";
	$script_content = "#!/bin/bash\n";
	$script_content .= "echo 'Starting full system restore...' > " . escapeshellarg($log_file) . "\n";
	$script_content .= "echo 'Backup file: " . $backup_file . "' >> " . escapeshellarg($log_file) . "\n";
	$script_content .= "echo '' >> " . escapeshellarg($log_file) . "\n";
	$script_content .= "\n";
	$script_content .= "# Terminate all connections to database\n";
	$script_content .= "echo 'Terminating database connections...' >> " . escapeshellarg($log_file) . "\n";
	$script_content .= "PGPASSWORD=" . escapeshellarg($db_config['pass']) . " ";
	$script_content .= "psql -h " . escapeshellarg($db_config['host']) . " ";
	$script_content .= "-p " . escapeshellarg($db_config['port']) . " ";
	$script_content .= "-U " . escapeshellarg($db_config['user']) . " ";
	$script_content .= "-d postgres ";
	$script_content .= "-c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '" . $db_config['name'] . "' AND pid <> pg_backend_pid();\" ";
	$script_content .= ">> " . escapeshellarg($log_file) . " 2>&1\n";
	$script_content .= "\n";
	$script_content .= "# Drop database\n";
	$script_content .= "echo 'Dropping database...' >> " . escapeshellarg($log_file) . "\n";
	$script_content .= "PGPASSWORD=" . escapeshellarg($db_config['pass']) . " ";
	$script_content .= "psql -h " . escapeshellarg($db_config['host']) . " ";
	$script_content .= "-p " . escapeshellarg($db_config['port']) . " ";
	$script_content .= "-U " . escapeshellarg($db_config['user']) . " ";
	$script_content .= "-d postgres ";
	$script_content .= "-c 'DROP DATABASE IF EXISTS " . $db_config['name'] . ";' ";
	$script_content .= ">> " . escapeshellarg($log_file) . " 2>&1\n";
	$script_content .= "\n";
	$script_content .= "# Create database\n";
	$script_content .= "echo 'Creating database...' >> " . escapeshellarg($log_file) . "\n";
	$script_content .= "PGPASSWORD=" . escapeshellarg($db_config['pass']) . " ";
	$script_content .= "psql -h " . escapeshellarg($db_config['host']) . " ";
	$script_content .= "-p " . escapeshellarg($db_config['port']) . " ";
	$script_content .= "-U " . escapeshellarg($db_config['user']) . " ";
	$script_content .= "-d postgres ";
	$script_content .= "-c 'CREATE DATABASE " . $db_config['name'] . ";' ";
	$script_content .= ">> " . escapeshellarg($log_file) . " 2>&1\n";
	$script_content .= "\n";
	$script_content .= "# Restore backup\n";
	$script_content .= "echo 'Restoring backup...' >> " . escapeshellarg($log_file) . "\n";
	$script_content .= "gunzip -c " . escapeshellarg($backup_file) . " | ";
	$script_content .= "PGPASSWORD=" . escapeshellarg($db_config['pass']) . " ";
	$script_content .= "psql -h " . escapeshellarg($db_config['host']) . " ";
	$script_content .= "-p " . escapeshellarg($db_config['port']) . " ";
	$script_content .= "-U " . escapeshellarg($db_config['user']) . " ";
	$script_content .= "-d " . $db_config['name'] . " ";
	$script_content .= ">> " . escapeshellarg($log_file) . " 2>&1\n";
	$script_content .= "\n";
	$script_content .= "echo 'Restore completed!' >> " . escapeshellarg($log_file) . "\n";
	$script_content .= "rm -f " . escapeshellarg($restore_script) . "\n";
	
	file_put_contents($restore_script, $script_content);
	chmod($restore_script, 0755);
	
	exec("bash " . escapeshellarg($restore_script) . " > /dev/null 2>&1 &");
	
	$_SESSION['message'] = "<span style='color: orange; font-weight: bold;'>⚠ Full system restore started. This will DROP and RECREATE the database!<br>Check log: " . $log_file . "</span>";
	header("Location: backup_manager.php");
	exit;
}

//handle restore multiple action
if (!empty($_POST['action']) && $_POST['action'] == 'restore_multiple' && permission_exists('backup_manager_restore')) {
	$backup_uuids = $_POST['backup_uuids'] ?? array();
	
	if (empty($backup_uuids)) {
		$_SESSION['message'] = "<span style='color: red;'>No backups selected</span>";
		header("Location: backup_manager.php");
		exit;
	}
	
	//find backup files from filesystem
	$files = glob($backup_path . '/*.tar.gz');
	
	$restore_count = 0;
	foreach ($backup_uuids as $backup_uuid) {
		// Find file by md5 hash
		foreach ($files as $file) {
			if (md5($file) == $backup_uuid) {
				// Start restore process with file path
				$restore_processor_path = __DIR__ . "/resources/standalone_restore_processor_file.php";
				$log_path = "/tmp/restore_" . basename($file, '.tar.gz') . ".log";
				$cmd = "/usr/bin/php " . escapeshellarg($restore_processor_path) . " " . escapeshellarg($file) . " > " . escapeshellarg($log_path) . " 2>&1 &";
				exec($cmd);
				
				$restore_count++;
				sleep(2); // Delay between restores
				break;
			}
		}
	}
	
	$_SESSION['message'] = $restore_count . " restore(s) started successfully. Check logs: /tmp/restore_*.log";
	header("Location: backup_manager.php");
	exit;
}

//get posted data
if (!empty($_POST['action'])) {
	$action = $_POST['action'];
	$backup_uuid = $_POST['backup_uuid'] ?? '';
	$domain_uuids = $_POST['domain_uuid'] ?? [];
	$backup_type = $_POST['backup_type'] ?? 'full';
	
	// Ensure domain_uuids is array
	if (!is_array($domain_uuids)) {
		$domain_uuids = [$domain_uuids];
	}
	$domain_uuids = array_filter($domain_uuids);
	
	if ($action == 'create' && permission_exists('backup_manager_add')) {
		$full_system = $_POST['full_system'] ?? 'false';
		
		if ($full_system === 'true') {
			// Full System Backup - pg_dump entire database
			$db_config = get_db_config_for_backup();
			$backup_file = $backup_path . '/fullsystem.backup.' . date('Y-m-d_H-i-s') . '.sql.gz';
			
			// Use pg_dump with proper credentials
			$cmd = "PGPASSWORD=" . escapeshellarg($db_config['pass']) . " ";
			$cmd .= "pg_dump ";
			$cmd .= "-h " . escapeshellarg($db_config['host']) . " ";
			$cmd .= "-p " . escapeshellarg($db_config['port']) . " ";
			$cmd .= "-U " . escapeshellarg($db_config['user']) . " ";
			$cmd .= "-d " . escapeshellarg($db_config['name']) . " ";
			$cmd .= "--format=plain --no-owner --no-acl ";
			$cmd .= "| gzip > " . escapeshellarg($backup_file) . " 2>&1";
			
			// Execute in background
			exec($cmd . " &");
			
			// Set permissions
			exec("chown www-data:www-data " . escapeshellarg($backup_file) . " 2>&1 &");
			
			$_SESSION['message'] = "Full system backup started: " . basename($backup_file);
			header("Location: backup_manager.php");
			exit;
		}
		
		//create backup for each selected tenant
		$created_count = 0;
		$processor_path = __DIR__ . "/resources/standalone_backup_processor.php";
		
		foreach ($domain_uuids as $domain_uuid) {
			if (empty($domain_uuid)) continue;
			
			$backup_uuid = uuid();
			
			//insert backup record
			$array['backup_manager'][0]['backup_manager_uuid'] = $backup_uuid;
			$array['backup_manager'][0]['domain_uuid'] = $domain_uuid;
			$array['backup_manager'][0]['backup_type'] = $backup_type;
			$array['backup_manager'][0]['backup_status'] = 'pending';
			$array['backup_manager'][0]['backup_progress'] = 0;
			$array['backup_manager'][0]['backup_date'] = date('Y-m-d H:i:s');
			$array['backup_manager'][0]['insert_date'] = date('Y-m-d H:i:s');
			$array['backup_manager'][0]['insert_user'] = $_SESSION['user']['user_uuid'];
			
			$database = new database;
			$database->app_name = 'backup_manager';
			$database->app_uuid = 'b3d4e7f2-8c9a-4d5e-9f6a-1b2c3d4e5f6a';
			$database->save($array);
			unset($array);
			
			//start background process
			$log_path = "/tmp/backup_" . $backup_uuid . ".log";
			$cmd = "/usr/bin/php " . escapeshellarg($processor_path) . " " . escapeshellarg($backup_uuid) . " > " . escapeshellarg($log_path) . " 2>&1 &";
			exec($cmd);
			
			$created_count++;
		}
		
		$_SESSION['message'] = $created_count . " backup(s) created successfully";
		header("Location: backup_manager.php");
		exit;
	}
	
	if ($action == 'delete' && permission_exists('backup_manager_delete')) {
		//delete backup file (no database, file-based system)
		// backup_uuid is actually md5(filepath), we need to find the file
		$files = glob($backup_path . '/*.tar.gz');
		
		$file_deleted = false;
		foreach ($files as $file) {
			if (md5($file) == $backup_uuid) {
				if (unlink($file)) {
					$file_deleted = true;
					$_SESSION['message'] = "Backup deleted successfully: " . basename($file);
				} else {
					$_SESSION['message'] = "Failed to delete backup file";
				}
				break;
			}
		}
		
		if (!$file_deleted) {
			$_SESSION['message'] = "Backup file not found";
		}
		
		header("Location: backup_manager.php");
		exit;
	}
	
	if ($action == 'delete_fullsystem' && permission_exists('backup_manager_delete')) {
		//delete full system backup file
		$backup_file_path = $_POST['backup_path'] ?? '';
		
		if (!empty($backup_file_path) && file_exists($backup_file_path)) {
			if (unlink($backup_file_path)) {
				$_SESSION['message'] = "Full System backup deleted successfully: " . basename($backup_file_path);
			} else {
				$_SESSION['message'] = "Failed to delete Full System backup file";
			}
		} else {
			$_SESSION['message'] = "Full System backup file not found: " . basename($backup_file_path);
		}
		
		header("Location: backup_manager.php");
		exit;
	}
	
	if ($action == 'restore' && permission_exists('backup_manager_restore')) {
		//restore backup
		header("Location: backup_restore.php?id=" . $backup_uuid);
		exit;
	}
}

//get variables used to control the order
$order_by = $_GET["order_by"] ?? 'backup_date';
$order = $_GET["order"] ?? 'DESC';

//add the search term
$search = strtolower($_GET["search"] ?? '');

//get tenant list for dropdown
$sql = "SELECT domain_uuid, domain_name FROM v_domains ORDER BY domain_name";
$database = new database;
$domains = $database->select($sql, null, 'all');

//get backup list from FILE SYSTEM
$backups = array();
$fullsystem_backups = array();
$tenant_backups = array();
$tenant_backups_final = array();
$grouped_tenant_backups = array();
$domain_counts = array();
$total_tenant_backups = 0;

if (is_dir($backup_path)) {
	$files = array_merge(
		glob($backup_path . '/*.tar.gz') ?: [],
		glob($backup_path . '/*.sql.gz') ?: []
	);
	
	foreach ($files as $file) {
		$filename = basename($file);
		
		// Check for fullsystem.backup backup
		if (preg_match('/^fullsystem\.backup\.(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.sql\.gz$/', $filename, $matches)) {
			$domain_name = 'Full System';
			$backup_type = 'full_system';
			$date_str = $matches[1];
			
			// Convert date format: 2025-10-28_21-04-47 → 2025-10-28 21:04:47
			$backup_date = str_replace('_', ' ', $date_str); // 2025-10-28 21-04-47
			$parts = explode(' ', $backup_date);
			$backup_date = $parts[0] . ' ' . str_replace('-', ':', $parts[1]); // 2025-10-28 21:04:47
			
			$backups[] = array(
				'backup_manager_uuid' => md5($file),
				'domain_uuid' => null,
				'domain_name' => $domain_name,
				'backup_type' => $backup_type,
				'backup_date' => $backup_date,
				'backup_status' => 'completed',
				'backup_progress' => 100,
				'backup_size' => filesize($file),
				'backup_path' => $file,
				'backup_filename' => $filename
			);
			continue;
		}
		
		// Parse filename: domain_type_YYYY-MM-DD_HH-MM-SS.tar.gz
		if (preg_match('/^(.+)_(full|database|recordings|voicemail)_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.tar\.gz$/', $filename, $matches)) {
			$domain_name = $matches[1];
			$backup_type = $matches[2];
			$date_str = $matches[3];
			
			// Convert date format: 2025-10-28_21-04-47 → 2025-10-28 21:04:47
			$backup_date = str_replace('_', ' ', $date_str); // 2025-10-28 21-04-47
			$parts = explode(' ', $backup_date);
			$backup_date = $parts[0] . ' ' . str_replace('-', ':', $parts[1]); // 2025-10-28 21:04:47
			
			// Get file info
			$file_size = filesize($file);
			$file_mtime = filemtime($file);
			
			// Find domain UUID
			$domain_uuid = null;
			foreach ($domains as $d) {
				if ($d['domain_name'] == $domain_name) {
					$domain_uuid = $d['domain_uuid'];
					break;
				}
			}
			
			// Apply search filter
			if (!empty($search)) {
				$search_lower = strtolower($search);
				if (strpos(strtolower($domain_name), $search_lower) === false &&
					strpos(strtolower($backup_type), $search_lower) === false) {
					continue;
				}
			}
			
			$backups[] = array(
				'backup_manager_uuid' => md5($file), // Use file hash as UUID
				'domain_uuid' => $domain_uuid,
				'domain_name' => $domain_name,
				'backup_type' => $backup_type,
				'backup_date' => $backup_date,
				'backup_status' => 'completed',
				'backup_progress' => 100,
				'backup_size' => $file_size,
				'backup_path' => $file,
				'backup_filename' => $filename
			);
		}
	}
	
	// Sort by date descending
	usort($backups, function($a, $b) {
		return strtotime($b['backup_date']) - strtotime($a['backup_date']);
	});
	
	// DEBUG: Log first 5 backups
	if (count($backups) > 0) {
		error_log("=== BACKUP SORT DEBUG ===");
		for ($i = 0; $i < min(5, count($backups)); $i++) {
			error_log(sprintf("%d. %s - %s", $i+1, $backups[$i]['backup_date'], $backups[$i]['domain_name']));
		}
	}
	
	// Separate Full System backups from tenant backups
	foreach ($backups as $backup) {
		if ($backup['backup_type'] == 'full_system') {
			$backup['total_backups'] = 1; // No grouping for full system
			$fullsystem_backups[] = $backup;
		} else {
			$tenant_backups[] = $backup;
		}
	}
	
	// NOTE: $tenant_backups is already sorted DESC from line 341-343
	// Group tenant backups by domain - show LATEST (most recent) per domain
	$tenant_backups_final = array();
	$grouped_tenant_backups = array();
	$domain_counts = array();
	
	foreach ($tenant_backups as $backup) {
		$domain_key = $backup['domain_name'];
		
		if (!isset($domain_counts[$domain_key])) {
			$domain_counts[$domain_key] = 0;
		}
		$domain_counts[$domain_key]++;
		
		// Always take the FIRST one (which is the latest due to DESC sort)
		if (!isset($grouped_tenant_backups[$domain_key])) {
			$backup['total_backups'] = 0; // Will be updated later
			$grouped_tenant_backups[$domain_key] = $backup;
		}
	}
	
	// Update total counts
	foreach ($grouped_tenant_backups as $key => $backup) {
		$grouped_tenant_backups[$key]['total_backups'] = $domain_counts[$key];
	}
	
	// Sort tenant backups by domain name (alphabetically)
	$tenant_backups_final = array_values($grouped_tenant_backups);
	usort($tenant_backups_final, function($a, $b) {
		return strcmp($a['domain_name'], $b['domain_name']);
	});
	
	// Calculate total tenant backups
	$total_tenant_backups = array_sum($domain_counts);
}

$num_rows = count($tenant_backups_final);
$num_fullsystem = count($fullsystem_backups);
$total_tenant_backups = $total_tenant_backups ?? 0;

//create token
$object = new token;
$token = $object->create($_SERVER['PHP_SELF']);

//include header
$document['title'] = $text['title-backup_manager'];
require_once "resources/header.php";

//show the content
echo "<div class='action_bar' id='action_bar'>\n";
echo "	<div class='heading'><b>" . $text['header-backup_manager'] . "</b></div>\n";
echo "	<div class='actions'>\n";
if (permission_exists('backup_manager_add')) {
	echo button::create(['type'=>'button','label'=>$text['button-create_backup'],'icon'=>$_SESSION['theme']['button_icon_add'],'id'=>'btn_create','onclick'=>'show_create_modal()']);
}
if (permission_exists('backup_manager_restore')) {
	echo button::create(['type'=>'button','label'=>'Restore Selected','icon'=>'fa-undo','id'=>'btn_restore','onclick'=>'restore_selected()','style'=>'display:none;']);
}
if (permission_exists('backup_manager_schedule')) {
	echo button::create(['type'=>'button','label'=>$text['button-schedule'],'icon'=>'fa-clock','link'=>'backup_schedules.php']);
}
if (permission_exists('backup_manager_settings')) {
	echo button::create(['type'=>'button','label'=>$text['button-settings'],'icon'=>'fa-cog','link'=>'backup_settings.php']);
}
echo "	</div>\n";
echo "	<div style='clear: both;'></div>\n";
echo "</div>\n";

echo $text['description-backup_manager'] . "<br /><br />\n";

//search bar
echo "<div class='row' style='margin-bottom: 20px;'>\n";
echo "	<div class='col-md-6'>\n";
echo "		<div class='input-group'>\n";
echo "			<input type='text' class='form-control' id='search_input' placeholder='Search backups...' value='" . escape($search) . "' onkeyup='if(event.keyCode==13) search_backups();'>\n";
echo "			<div class='input-group-append'>\n";
echo "				<button class='btn btn-primary' type='button' onclick='search_backups()'><i class='fa fa-search'></i> Search</button>\n";
echo "				<button class='btn btn-secondary' type='button' onclick='clear_search()'><i class='fa fa-times'></i> Clear</button>\n";
echo "			</div>\n";
echo "		</div>\n";
echo "	</div>\n";
echo "	<div class='col-md-6 text-right'>\n";
echo "		<span id='selected_count' style='display:none; margin-right:10px;'><strong>Selected: <span id='count'>0</span></strong></span>\n";
echo "	</div>\n";
echo "</div>\n";

// Show Full System Backups (separate section)
if (is_array($fullsystem_backups) && count($fullsystem_backups) > 0) {
	echo "<h3 style='margin-top:20px;'>Full System Backups</h3>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "\t<th>Backup Type</th>\n";
	echo "\t<th>File Name</th>\n";
	echo "\t<th>Backup Date</th>\n";
	echo "\t<th>Size</th>\n";
	echo "\t<th>Status</th>\n";
	echo "\t<th class='action-button'>Actions</th>\n";
	echo "</tr>\n";
	
	foreach ($fullsystem_backups as $row) {
		echo "<tr class='list-row'>\n";
		echo "\t<td><span class='badge badge-primary'>Full System</span></td>\n";
		echo "\t<td><small>" . escape(basename($row['backup_path'] ?? '')) . "</small></td>\n";
		echo "\t<td>" . escape($row['backup_date']) . "</td>\n";
		echo "\t<td>" . format_bytes($row['backup_size'] ?? 0) . "</td>\n";
		
		$status_class = 'default';
		switch ($row['backup_status']) {
			case 'completed': $status_class = 'success'; break;
			case 'processing': $status_class = 'warning'; break;
			case 'pending': $status_class = 'info'; break;
			case 'failed': $status_class = 'danger'; break;
		}
		echo "	<td><span class='badge badge-" . $status_class . "'>" . escape($row['backup_status']) . "</span></td>\n";
		
		echo "\t<td class='action-button center' style='white-space:nowrap;'>\n";
		if ($row['backup_status'] == 'completed') {
			if (permission_exists('backup_manager_restore')) {
				echo "<button type='button' class='btn btn-default btn-sm' onclick='restore_fullsystem(\"" . $row['backup_manager_uuid'] . "\")' title='Restore'><i class='fa fa-undo'></i></button> ";
			}
			echo "<a href='backup_download.php?id=" . urlencode($row['backup_manager_uuid']) . "' class='btn btn-default btn-sm' title='Download'><i class='fa fa-download'></i></a> ";
		}
		if (permission_exists('backup_manager_delete')) {
			echo "<button type='button' class='btn btn-default btn-sm' onclick='confirm_delete_fullsystem(\"" . $row['backup_manager_uuid'] . "\", \"" . escape($row['backup_path']) . "\")' title='Delete'><i class='fa fa-trash'></i></button>";
		}
		echo "\t</td>\n";
		echo "</tr>\n";
	}
	
	echo "</table>\n";
	echo "<br />\n";
	echo "<div align='center'>" . $num_fullsystem . " full system backup(s)</div>\n";
	echo "<br /><br />\n";
}

//show tenant backups
echo "<h3>Tenant Backups</h3>\n";
echo "<form id='restore_form' method='POST' action='backup_manager.php'>\n";
echo "<input type='hidden' name='action' value='restore_multiple'>\n";
echo "<input type='hidden' name='" . $token['name'] . "' value='" . $token['hash'] . "'>\n";
echo "<table class='list'>\n";
echo "<tr class='list-header'>\n";
if (permission_exists('backup_manager_restore')) {
	echo "	<th class='checkbox'>\n";
	echo "		<input type='checkbox' id='checkbox_all' onclick='toggle_all_checkboxes(this)'>\n";
	echo "	</th>\n";
}
echo "	<th>" . $text['label-tenant'] . "</th>\n";
echo "	<th>" . $text['label-backup_type'] . "</th>\n";
echo "	<th>" . $text['label-backup_date'] . "</th>\n";
echo "	<th>" . $text['label-size'] . "</th>\n";
echo "	<th>" . $text['label-status'] . "</th>\n";
echo "	<th class='action-button'>" . $text['label-actions'] . "</th>\n";
echo "</tr>\n";

if (is_array($tenant_backups_final) && @sizeof($tenant_backups_final) != 0) {
	$x = 0;
	foreach ($tenant_backups_final as $row) {
		$domain_uuid = $row['domain_uuid'];
		$total_backups = $row['total_backups'] ?? 1;
		
		echo "<tr class='list-row'>\n";
		echo "	<td class='text-center'>";
		// Only show checkbox for completed backups
		if ($row['backup_status'] == 'completed') {
			echo "<input type='checkbox' name='backup_uuids[]' value='" . $row['backup_manager_uuid'] . "' class='backup-checkbox' onchange='update_selection()'>";
		}
		echo "</td>\n";
		echo "	<td>";
		// Show expand button if more than 1 backup
		$domain_name_safe = htmlspecialchars($row['domain_name'], ENT_QUOTES);
		if ($total_backups > 1) {
			echo "<a href='javascript:void(0)' onclick='toggleBackups(\"" . $domain_name_safe . "\")' style='margin-right:10px;'>";
			echo "<i class='fa fa-plus-square' id='icon_" . $domain_name_safe . "'></i></a>";
		}
		echo escape($row['domain_name']);
		if ($total_backups > 1) {
			echo " <span class='badge badge-info'>" . $total_backups . " backups</span>";
		}
		echo "</td>\n";
		echo "	<td>" . escape($row['backup_type']) . "</td>\n";
		echo "	<td>" . escape($row['backup_date']) . "</td>\n";
		echo "	<td>" . format_bytes($row['backup_size'] ?? 0) . "</td>\n";
		
		//status badge
		$status_class = 'default';
		switch ($row['backup_status']) {
			case 'completed': $status_class = 'success'; break;
			case 'processing': $status_class = 'warning'; break;
			case 'pending': $status_class = 'info'; break;
			case 'failed': $status_class = 'danger'; break;
		}
		echo "	<td><span class='badge badge-" . $status_class . "'>" . escape($row['backup_status']) . "</span></td>\n";
		
		//actions - icon only buttons
		echo "	<td class='action-button center' style='white-space:nowrap;'>\n";
		if ($row['backup_status'] == 'completed') {
			if (permission_exists('backup_manager_restore')) {
				echo "<button type='button' class='btn btn-default btn-sm' onclick='restore_single(\"" . $row['backup_manager_uuid'] . "\")' title='Restore'><i class='fa fa-undo'></i></button> ";
			}
			if (permission_exists('backup_manager_view')) {
				echo "<a href='backup_download.php?id=" . urlencode($row['backup_manager_uuid']) . "' class='btn btn-default btn-sm' title='Download'><i class='fa fa-download'></i></a> ";
			}
		}
		if (permission_exists('backup_manager_delete')) {
			echo "<button type='button' class='btn btn-default btn-sm' onclick='confirm_delete(\"" . $row['backup_manager_uuid'] . "\")' title='Delete'><i class='fa fa-trash'></i></button>";
		}
		echo "	</td>\n";
		echo "</tr>\n";
		
		// Hidden row for older backups
		if ($total_backups > 1) {
			echo "<tr id='backups_" . $domain_name_safe . "' style='display:none;'>\n";
			echo "	<td colspan='7'>\n";
			echo "		<div style='padding:10px; background:#f5f5f5;'>\n";
			echo "			<div id='backups_content_" . $domain_name_safe . "'>Loading...</div>\n";
			echo "		</div>\n";
			echo "	</td>\n";
			echo "</tr>\n";
		}
		
		$x++;
	}
	unset($backups);
}

echo "</table>\n";
echo "</form>\n";
echo "<br />\n";
echo "<div align='center'>";
echo "<strong>" . $num_rows . "</strong> tenant" . ($num_rows != 1 ? 's' : '') . " | ";
echo "<strong>" . $total_tenant_backups . "</strong> total backup" . ($total_tenant_backups != 1 ? 's' : '');
echo "</div>\n";

//create backup modal
echo "<div id='create_modal' class='modal fade' tabindex='-1' role='dialog'>\n";
echo "	<div class='modal-dialog' role='document'>\n";
echo "		<div class='modal-content'>\n";
echo "			<div class='modal-header'>\n";
echo "				<h5 class='modal-title'>" . $text['button-create_backup'] . "</h5>\n";
echo "				<button type='button' class='close' data-dismiss='modal' aria-label='Close'>\n";
echo "					<span aria-hidden='true'>&times;</span>\n";
echo "				</button>\n";
echo "			</div>\n";
echo "			<form method='POST' action='backup_manager.php'>\n";
echo "			<input type='hidden' name='action' value='create'>\n";
echo "			<input type='hidden' name='" . $token['name'] . "' value='" . $token['hash'] . "'>\n";
echo "			<div class='modal-body'>\n";
echo "				<div class='form-group'>\n";
echo "					<label>Backup Scope</label>\n";
echo "					<select id='backup_scope' class='form-control' onchange='toggle_tenant_selection()'>\n";
echo "						<option value='tenant'>Tenant Backup (Select domains)</option>\n";
echo "						<option value='full_system'>Full System Backup (All domains + database)</option>\n";
echo "					</select>\n";
echo "				</div>\n";
echo "				<div class='form-group' id='tenant_selection'>\n";
echo "					<label>" . $text['label-tenant'] . " <small>(Birden fazla seçebilirsiniz)</small></label>\n";
echo "					<div style='max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 4px;'>\n";
echo "						<div class='form-check'>\n";
echo "							<input class='form-check-input' type='checkbox' id='select_all_tenants' onclick='toggle_all_tenants(this)'>\n";
echo "							<label class='form-check-label' for='select_all_tenants'><strong>Tümünü Seç</strong></label>\n";
echo "						</div>\n";
echo "						<hr style='margin: 5px 0;'>\n";
foreach ($domains as $domain) {
	echo "						<div class='form-check'>\n";
	echo "							<input class='form-check-input tenant-checkbox' type='checkbox' name='domain_uuid[]' value='" . $domain['domain_uuid'] . "' id='tenant_" . $domain['domain_uuid'] . "'>\n";
	echo "							<label class='form-check-label' for='tenant_" . $domain['domain_uuid'] . "'>" . escape($domain['domain_name']) . "</label>\n";
	echo "						</div>\n";
}
echo "					</div>\n";
echo "				</div>\n";
echo "				<input type='hidden' name='full_system' id='full_system_input' value='false'>\n";
echo "				<div class='form-group' id='backup_type_selection'>\n";
echo "					<label>" . $text['label-backup_type'] . "</label>\n";
echo "					<select name='backup_type' class='form-control' required>\n";
echo "						<option value='full'>" . $text['option-backup_full'] . "</option>\n";
echo "						<option value='database'>" . $text['option-backup_database'] . "</option>\n";
echo "						<option value='recordings'>" . $text['option-backup_recordings'] . "</option>\n";
echo "						<option value='voicemail'>" . $text['option-backup_voicemail'] . "</option>\n";
echo "					</select>\n";
echo "					<small class='form-text text-muted'>Tenant backup için geçerlidir</small>\n";
echo "				</div>\n";
echo "			</div>\n";
echo "			<div class='modal-footer'>\n";
echo "				<button type='button' class='btn btn-secondary' data-dismiss='modal'>" . $text['button-cancel'] . "</button>\n";
echo "				<button type='submit' class='btn btn-primary'>" . $text['button-create_backup'] . "</button>\n";
echo "			</div>\n";
echo "			</form>\n";
echo "		</div>\n";
echo "	</div>\n";
echo "</div>\n";

//javascript
?>
<script>
function show_create_modal() {
	$('#create_modal').modal('show');
}

function toggle_all_tenants(checkbox) {
	var checkboxes = document.getElementsByClassName('tenant-checkbox');
	for (var i = 0; i < checkboxes.length; i++) {
		checkboxes[i].checked = checkbox.checked;
	}
}

function toggle_tenant_selection() {
	var scope = document.getElementById('backup_scope').value;
	var tenantDiv = document.getElementById('tenant_selection');
	var backupTypeDiv = document.getElementById('backup_type_selection');
	var fullSystemInput = document.getElementById('full_system_input');
	
	if (scope === 'full_system') {
		tenantDiv.style.display = 'none';
		backupTypeDiv.style.display = 'none';
		fullSystemInput.value = 'true';
	} else {
		tenantDiv.style.display = 'block';
		backupTypeDiv.style.display = 'block';
		fullSystemInput.value = 'false';
	}
}

function confirm_delete(uuid) {
	if (confirm('<?php echo $text['message-confirm_delete']; ?>')) {
		var form = document.createElement('form');
		form.method = 'POST';
		form.action = 'backup_manager.php';
		
		var input1 = document.createElement('input');
		input1.type = 'hidden';
		input1.name = 'action';
		input1.value = 'delete';
		form.appendChild(input1);
		
		var input2 = document.createElement('input');
		input2.type = 'hidden';
		input2.name = 'backup_uuid';
		input2.value = uuid;
		form.appendChild(input2);
		
		var input3 = document.createElement('input');
		input3.type = 'hidden';
		input3.name = '<?php echo $token['name']; ?>';
		input3.value = '<?php echo $token['hash']; ?>';
		form.appendChild(input3);
		
		document.body.appendChild(form);
		form.submit();
	}
}

function confirm_delete_fullsystem(uuid, filepath) {
	if (confirm('Are you sure you want to delete this Full System backup?')) {
		var form = document.createElement('form');
		form.method = 'POST';
		form.action = 'backup_manager.php';
		
		var input1 = document.createElement('input');
		input1.type = 'hidden';
		input1.name = 'action';
		input1.value = 'delete_fullsystem';
		form.appendChild(input1);
		
		var input2 = document.createElement('input');
		input2.type = 'hidden';
		input2.name = 'backup_uuid';
		input2.value = uuid;
		form.appendChild(input2);
		
		var input3 = document.createElement('input');
		input3.type = 'hidden';
		input3.name = 'backup_path';
		input3.value = filepath;
		form.appendChild(input3);
		
		var input4 = document.createElement('input');
		input4.type = 'hidden';
		input4.name = '<?php echo $token['name']; ?>';
		input4.value = '<?php echo $token['hash']; ?>';
		form.appendChild(input4);
		
		document.body.appendChild(form);
		form.submit();
	}
}

function restore_fullsystem(uuid) {
	if (confirm('Are you sure you want to restore this Full System backup? This will restore the entire database. This action cannot be undone!')) {
		var form = document.createElement('form');
		form.method = 'POST';
		form.action = 'backup_manager.php';
		
		var actionInput = document.createElement('input');
		actionInput.type = 'hidden';
		actionInput.name = 'action';
		actionInput.value = 'restore_fullsystem';
		form.appendChild(actionInput);
		
		var uuidInput = document.createElement('input');
		uuidInput.type = 'hidden';
		uuidInput.name = 'backup_uuid';
		uuidInput.value = uuid;
		form.appendChild(uuidInput);
		
		document.body.appendChild(form);
		form.submit();
	}
}

function toggleBackups(domain_name) {
	var row = document.getElementById('backups_' + domain_name);
	var icon = document.getElementById('icon_' + domain_name);
	var content = document.getElementById('backups_content_' + domain_name);
	
	if (row.style.display === 'none') {
		row.style.display = '';
		icon.className = 'fa fa-minus-square';
		
		// Load backups via AJAX if not loaded
		if (content.innerHTML === 'Loading...') {
			loadDomainBackups(domain_name);
		}
	} else {
		row.style.display = 'none';
		icon.className = 'fa fa-plus-square';
	}
}

function loadDomainBackups(domain_name) {
	var xhr = new XMLHttpRequest();
	xhr.open('GET', 'backup_list_ajax.php?domain_name=' + encodeURIComponent(domain_name), true);
	xhr.onload = function() {
		if (xhr.status === 200) {
			document.getElementById('backups_content_' + domain_name).innerHTML = xhr.responseText;
		} else {
			document.getElementById('backups_content_' + domain_name).innerHTML = 'Error loading backups';
		}
	};
	xhr.send();
}

function toggle_all_checkboxes(source) {
	var checkboxes = document.getElementsByClassName('backup-checkbox');
	for (var i = 0; i < checkboxes.length; i++) {
		checkboxes[i].checked = source.checked;
	}
	update_selection();
}

function update_selection() {
	var checkboxes = document.querySelectorAll('.backup-checkbox:checked');
	var count = checkboxes.length;
	
	document.getElementById('count').textContent = count;
	
	if (count > 0) {
		document.getElementById('selected_count').style.display = '';
		document.getElementById('btn_restore').style.display = '';
	} else {
		document.getElementById('selected_count').style.display = 'none';
		document.getElementById('btn_restore').style.display = 'none';
	}
}

function restore_selected() {
	var checkboxes = document.querySelectorAll('.backup-checkbox:checked');
	if (checkboxes.length === 0) {
		alert('Please select at least one backup to restore');
		return;
	}
	
	if (confirm('Are you sure you want to restore ' + checkboxes.length + ' backup(s)? Backup data will be imported/merged with existing data.')) {
		document.getElementById('restore_form').submit();
	}
}

function restore_single(backup_uuid) {
	if (confirm('Are you sure you want to restore this backup? Backup data will be imported/merged with existing data.')) {
		var form = document.createElement('form');
		form.method = 'POST';
		form.action = 'backup_manager.php';
		
		var input1 = document.createElement('input');
		input1.type = 'hidden';
		input1.name = 'action';
		input1.value = 'restore_multiple';
		form.appendChild(input1);
		
		var input2 = document.createElement('input');
		input2.type = 'hidden';
		input2.name = 'backup_uuids[]';
		input2.value = backup_uuid;
		form.appendChild(input2);
		
		var input3 = document.createElement('input');
		input3.type = 'hidden';
		input3.name = '<?php echo $token['name']; ?>';
		input3.value = '<?php echo $token['hash']; ?>';
		form.appendChild(input3);
		
		document.body.appendChild(form);
		form.submit();
	}
}

function search_backups() {
	var search = document.getElementById('search_input').value;
	window.location.href = 'backup_manager.php?search=' + encodeURIComponent(search);
}

function clear_search() {
	window.location.href = 'backup_manager.php';
}

//auto-refresh for processing backups
<?php if (is_array($backups)) { 
	foreach ($backups as $row) {
		if ($row['backup_status'] == 'processing' || $row['backup_status'] == 'pending') {
			echo "setTimeout(function() { location.reload(); }, 5000);\n";
			break;
		}
	}
} ?>
</script>

<?php
//include footer
require_once "resources/footer.php";

//helper function
function format_bytes($bytes, $precision = 2) {
	$units = array('B', 'KB', 'MB', 'GB', 'TB');
	$bytes = max($bytes, 0);
	$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
	$pow = min($pow, count($units) - 1);
	$bytes /= pow(1024, $pow);
	return round($bytes, $precision) . ' ' . $units[$pow];
}
?>
