<?php
/*
	FusionPBX Backup Manager
	Backup Restore Handler
	
	Copyright (C) 2025 3WTURK - World Wide Web Solutions
	https://3wturk.com
	
	Developed for FusionPBX 5.4.7+
*/

require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

if (permission_exists('backup_manager_restore')) {
	//access granted
}
else {
	echo "access denied";
	exit;
}

$language = new text;
$text = $language->get();

//get backup details
$backup_uuid = $_GET['id'] ?? '';
$backup_type = $_GET['type'] ?? '';

if (empty($backup_uuid)) {
	header("Location: backup_manager.php");
	exit;
}

// Check if this is a full system backup (file-based, not in database)
if ($backup_type == 'fullsystem') {
	// Find full system backup file
	$config_file = __DIR__ . '/backup_config.php';
	$backup_path = '/var/backups/fusionpbx';
	if (file_exists($config_file)) {
		require_once $config_file;
		if (defined('BACKUP_PATH')) {
			$backup_path = BACKUP_PATH;
		}
	}
	
	$files = glob($backup_path . '/*.sql.gz');
	$backup_file = null;
	foreach ($files as $file) {
		if (md5($file) == $backup_uuid) {
			$backup_file = $file;
			break;
		}
	}
	
	if (!$backup_file) {
		$_SESSION['message'] = "Full system backup file not found";
		header("Location: backup_manager.php");
		exit;
	}
	
	// Create fake backup array for full system
	$backup = array(
		'backup_manager_uuid' => $backup_uuid,
		'domain_uuid' => null,
		'domain_name' => 'Full System',
		'backup_type' => 'fullsystem',
		'backup_path' => $backup_file,
		'backup_size' => filesize($backup_file),
		'backup_status' => 'completed',
		'backup_date' => date('Y-m-d H:i:s', filemtime($backup_file))
	);
} else {
	// Regular tenant backup from database
	$sql = "SELECT bm.*, d.domain_name FROM v_backup_manager bm ";
	$sql .= "LEFT JOIN v_domains d ON bm.domain_uuid = d.domain_uuid ";
	$sql .= "WHERE bm.backup_manager_uuid = :backup_uuid";
	$parameters['backup_uuid'] = $backup_uuid;
	$database = new database;
	$backup = $database->select($sql, $parameters, 'row');

	if (empty($backup)) {
		$_SESSION['message'] = "Backup not found";
		header("Location: backup_manager.php");
		exit;
	}
}

//handle restore request
if (!empty($_POST['action']) && $_POST['action'] == 'restore') {
	//create safety backup before restore
	$safety_backup_uuid = uuid();
	
	$array['backup_manager'][0]['backup_manager_uuid'] = $safety_backup_uuid;
	$array['backup_manager'][0]['domain_uuid'] = $backup['domain_uuid'];
	$array['backup_manager'][0]['backup_type'] = 'full';
	$array['backup_manager'][0]['backup_status'] = 'pending';
	$array['backup_manager'][0]['backup_progress'] = 0;
	$array['backup_manager'][0]['backup_date'] = date('Y-m-d H:i:s');
	$array['backup_manager'][0]['insert_date'] = date('Y-m-d H:i:s');
	$array['backup_manager'][0]['insert_user'] = $_SESSION['user']['user_uuid'];
	
	$database->app_name = 'backup_manager';
	$database->app_uuid = 'b3d4e7f2-8c9a-4d5e-9f6a-1b2c3d4e5f6a';
	$database->save($array);
	unset($array);
	
	//start safety backup
	$cmd = "php " . __DIR__ . "/resources/backup_processor.php " . $safety_backup_uuid . " > /dev/null 2>&1";
	exec($cmd);
	
	//wait for safety backup to complete (with timeout)
	$timeout = 300; //5 minutes
	$start = time();
	while (time() - $start < $timeout) {
		$sql = "SELECT backup_status FROM v_backup_manager WHERE backup_manager_uuid = :backup_uuid";
		$params['backup_uuid'] = $safety_backup_uuid;
		$status = $database->select($sql, $params, 'row');
		
		if ($status['backup_status'] == 'completed') {
			break;
		}
		
		if ($status['backup_status'] == 'failed') {
			$_SESSION['message'] = "Safety backup failed. Restore aborted.";
			header("Location: backup_manager.php");
			exit;
		}
		
		sleep(2);
	}
	
	//start restore process
	$cmd = "php " . __DIR__ . "/resources/restore_processor.php " . $backup_uuid . " > /dev/null 2>&1 &";
	exec($cmd);
	
	$_SESSION['message'] = "Restore process started. Safety backup created.";
	header("Location: backup_manager.php");
	exit;
}

//extract metadata
$metadata = array();
if (!empty($backup['backup_metadata'])) {
	$metadata = json_decode($backup['backup_metadata'], true);
}

//extract metadata from tar.gz if not in database
if (empty($metadata) && file_exists($backup['backup_path'])) {
	$temp_dir = sys_get_temp_dir() . '/fusionpbx_restore_preview_' . $backup_uuid;
	if (!file_exists($temp_dir)) {
		mkdir($temp_dir, 0755, true);
	}
	
	//extract only metadata.json
	$cmd = "tar -xzf " . escapeshellarg($backup['backup_path']) . " -C " . escapeshellarg($temp_dir) . " metadata.json 2>/dev/null";
	exec($cmd);
	
	if (file_exists($temp_dir . '/metadata.json')) {
		$metadata = json_decode(file_get_contents($temp_dir . '/metadata.json'), true);
	}
	
	//cleanup
	exec("rm -rf " . escapeshellarg($temp_dir));
}

$object = new token;
$token = $object->create($_SERVER['PHP_SELF']);

$document['title'] = $text['title-backup_manager'] . ' - Restore';
require_once "resources/header.php";

echo "<div class='action_bar'>\n";
echo "	<div class='heading'><b>Restore Backup</b></div>\n";
echo "	<div class='actions'>\n";
echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'fa-arrow-left','link'=>'backup_manager.php']);
echo "	</div>\n";
echo "	<div style='clear: both;'></div>\n";
echo "</div>\n";

echo "<div class='alert alert-warning'>\n";
echo "	<strong>Warning:</strong> " . $text['message-confirm_restore'] . "\n";
echo "</div>\n";

//backup information
echo "<table class='tr_hover' width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
echo "<tr><th colspan='2'>Backup Information</th></tr>\n";

echo "<tr>\n";
echo "<td class='vncell' width='30%'>Tenant</td>\n";
echo "<td class='vtable'>" . escape($backup['domain_name']) . "</td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell'>Backup Type</td>\n";
echo "<td class='vtable'>" . escape($backup['backup_type']) . "</td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell'>Backup Date</td>\n";
echo "<td class='vtable'>" . escape($backup['backup_date']) . "</td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell'>Backup Size</td>\n";
echo "<td class='vtable'>" . format_bytes($backup['backup_size']) . "</td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell'>Status</td>\n";
echo "<td class='vtable'>" . escape($backup['backup_status']) . "</td>\n";
echo "</tr>\n";

if (!empty($metadata['fusionpbx_version'])) {
	echo "<tr>\n";
	echo "<td class='vncell'>FusionPBX Version</td>\n";
	echo "<td class='vtable'>" . escape($metadata['fusionpbx_version']) . "</td>\n";
	echo "</tr>\n";
}

echo "</table>\n";
echo "<br />\n";

//metadata details
if (!empty($metadata)) {
	echo "<table class='tr_hover' width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
	echo "<tr><th colspan='2'>Backup Contents</th></tr>\n";
	
	if (!empty($metadata['database'])) {
		echo "<tr><td colspan='2' class='vncell'><strong>Database</strong></td></tr>\n";
		
		if (isset($metadata['database']['extensions_count'])) {
			echo "<tr>\n";
			echo "<td class='vncell' width='30%'>Extensions</td>\n";
			echo "<td class='vtable'>" . $metadata['database']['extensions_count'] . "</td>\n";
			echo "</tr>\n";
		}
		
		if (isset($metadata['database']['devices_count'])) {
			echo "<tr>\n";
			echo "<td class='vncell'>Devices</td>\n";
			echo "<td class='vtable'>" . $metadata['database']['devices_count'] . "</td>\n";
			echo "</tr>\n";
		}
		
		if (isset($metadata['database']['dialplans_count'])) {
			echo "<tr>\n";
			echo "<td class='vncell'>Dialplans</td>\n";
			echo "<td class='vtable'>" . $metadata['database']['dialplans_count'] . "</td>\n";
			echo "</tr>\n";
		}
		
		if (isset($metadata['database']['ivr_menus_count'])) {
			echo "<tr>\n";
			echo "<td class='vncell'>IVR Menus</td>\n";
			echo "<td class='vtable'>" . $metadata['database']['ivr_menus_count'] . "</td>\n";
			echo "</tr>\n";
		}
	}
	
	if (!empty($metadata['recordings'])) {
		echo "<tr><td colspan='2' class='vncell'><strong>Recordings</strong></td></tr>\n";
		
		if (isset($metadata['recordings']['file_count'])) {
			echo "<tr>\n";
			echo "<td class='vncell'>Files</td>\n";
			echo "<td class='vtable'>" . $metadata['recordings']['file_count'] . "</td>\n";
			echo "</tr>\n";
		}
		
		if (isset($metadata['recordings']['total_size'])) {
			echo "<tr>\n";
			echo "<td class='vncell'>Total Size</td>\n";
			echo "<td class='vtable'>" . format_bytes($metadata['recordings']['total_size']) . "</td>\n";
			echo "</tr>\n";
		}
	}
	
	if (!empty($metadata['voicemail'])) {
		echo "<tr><td colspan='2' class='vncell'><strong>Voicemail</strong></td></tr>\n";
		
		if (isset($metadata['voicemail']['file_count'])) {
			echo "<tr>\n";
			echo "<td class='vncell'>Files</td>\n";
			echo "<td class='vtable'>" . $metadata['voicemail']['file_count'] . "</td>\n";
			echo "</tr>\n";
		}
		
		if (isset($metadata['voicemail']['total_size'])) {
			echo "<tr>\n";
			echo "<td class='vncell'>Total Size</td>\n";
			echo "<td class='vtable'>" . format_bytes($metadata['voicemail']['total_size']) . "</td>\n";
			echo "</tr>\n";
		}
	}
	
	echo "</table>\n";
	echo "<br />\n";
}

//restore button
if ($backup['backup_status'] == 'completed') {
	echo "<form method='POST' action='backup_restore.php?id=" . $backup_uuid . "' onsubmit='return confirm(\"" . $text['message-confirm_restore'] . "\")'>\n";
	echo "<input type='hidden' name='action' value='restore'>\n";
	echo "<input type='hidden' name='" . $token['name'] . "' value='" . $token['hash'] . "'>\n";
	echo "<div style='text-align: center;'>\n";
	echo "	<button type='submit' class='btn btn-danger btn-lg'>\n";
	echo "		<i class='fa fa-undo'></i> " . $text['button-restore'] . "\n";
	echo "	</button>\n";
	echo "</div>\n";
	echo "</form>\n";
} else {
	echo "<div class='alert alert-danger'>\n";
	echo "	This backup cannot be restored. Status: " . escape($backup['backup_status']) . "\n";
	echo "</div>\n";
}

require_once "resources/footer.php";

function format_bytes($bytes, $precision = 2) {
	$units = array('B', 'KB', 'MB', 'GB', 'TB');
	$bytes = max($bytes, 0);
	$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
	$pow = min($pow, count($units) - 1);
	$bytes /= pow(1024, $pow);
	return round($bytes, $precision) . ' ' . $units[$pow];
}
?>
