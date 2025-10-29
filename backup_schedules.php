<?php
/*
	FusionPBX Backup Manager
	Backup Schedules & FTP Upload Management
	
	Copyright (C) 2025 3WTURK - World Wide Web Solutions
	https://3wturk.com
	
	Developed for FusionPBX 5.4.7+
*/

require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

if (permission_exists('backup_manager_schedule')) {
	//access granted
}
else {
	echo "access denied";
	exit;
}

$language = new text;
$text = $language->get();

$config_file = __DIR__ . '/backup_config.php';

// Load config
if (file_exists($config_file)) {
	require_once $config_file;
}

//handle form submission
if (!empty($_POST['action'])) {
	if ($_POST['action'] == 'upload') {
		// Run schedule runner now
		$runner_script = __DIR__ . '/resources/schedule_runner.php';
		$log_file = __DIR__ . '/logs/backup.log';
		
		// Create logs directory if it doesn't exist
		$log_dir = __DIR__ . '/logs';
		if (!is_dir($log_dir)) {
			mkdir($log_dir, 0755, true);
		}
		
		exec('php ' . escapeshellarg($runner_script) . ' >> ' . escapeshellarg($log_file) . ' 2>&1 &');
		$_SESSION['message'] = "<span style='color: green; font-weight: bold;'>✓ FTP upload started. Check log file in Backup Schedules page</span>";
		header("Location: backup_schedules.php");
		exit;
	}
	
	if ($_POST['action'] == 'save') {
		$schedule_enabled = ($_POST['schedule_enabled'] ?? 'false') == 'true';
		$schedule_time = $_POST['schedule_time'] ?? '02:00';
		$schedule_frequency = $_POST['schedule_frequency'] ?? 'daily';
		$schedule_day = intval($_POST['schedule_day'] ?? 0);
	
	// Update cron job
	$cron_file = "/etc/cron.d/fusionpbx-backup";
	
	if ($schedule_enabled) {
		list($hour, $minute) = explode(':', $schedule_time);
		
		$cron_content = "# FusionPBX Backup Manager - Auto-generated\n";
		$cron_content .= "SHELL=/bin/bash\n";
		$cron_content .= "PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin\n\n";
		
		// Use dynamic paths
		$module_path = __DIR__;
		$runner_script = $module_path . '/resources/schedule_runner.php';
		$log_path = $module_path . '/logs/backup.log';
		
		switch ($schedule_frequency) {
			case 'hourly':
				$cron_content .= "{$minute} * * * * www-data /usr/bin/php $runner_script >> $log_path 2>&1\n";
				break;
			case 'daily':
				$cron_content .= "{$minute} {$hour} * * * www-data /usr/bin/php $runner_script >> $log_path 2>&1\n";
				break;
			case 'weekly':
				$cron_content .= "{$minute} {$hour} * * {$schedule_day} www-data /usr/bin/php $runner_script >> $log_path 2>&1\n";
				break;
			case 'monthly':
				$cron_content .= "{$minute} {$hour} {$schedule_day} * * www-data /usr/bin/php $runner_script >> $log_path 2>&1\n";
				break;
		}
		
		// Write cron file using shell command (for permissions)
		$temp_file = '/tmp/fusionpbx-backup-cron-' . time();
		file_put_contents($temp_file, $cron_content);
		exec("sudo mv $temp_file $cron_file 2>&1");
		exec("sudo chown root:root $cron_file 2>&1");
		exec("sudo chmod 644 $cron_file 2>&1");
		exec("sudo systemctl restart cron 2>&1");
		
		$_SESSION['message'] = "Schedule enabled and cron job created";
	} else {
		// Disable - remove cron file
		if (file_exists($cron_file)) {
			unlink($cron_file);
		}
		$_SESSION['message'] = "Schedule disabled and cron job removed";
	}
	
	// Save to config
	$config_content = file_get_contents($config_file);
	
	// Add schedule settings if not exists
	if (strpos($config_content, 'SCHEDULE_ENABLED') === false) {
		$config_content = str_replace("define('BACKUP_PATH'", 
			"// Schedule Settings\ndefine('SCHEDULE_ENABLED', " . ($schedule_enabled ? 'true' : 'false') . ");\ndefine('SCHEDULE_TIME', '$schedule_time');\ndefine('SCHEDULE_FREQUENCY', '$schedule_frequency');\ndefine('SCHEDULE_DAY', $schedule_day);\n\ndefine('BACKUP_PATH'", 
			$config_content);
	} else {
		// Update existing
		$config_content = preg_replace("/define\('SCHEDULE_ENABLED', [^)]+\);/", "define('SCHEDULE_ENABLED', " . ($schedule_enabled ? 'true' : 'false') . ");", $config_content);
		$config_content = preg_replace("/define\('SCHEDULE_TIME', '[^']+'\);/", "define('SCHEDULE_TIME', '$schedule_time');", $config_content);
		$config_content = preg_replace("/define\('SCHEDULE_FREQUENCY', '[^']+'\);/", "define('SCHEDULE_FREQUENCY', '$schedule_frequency');", $config_content);
		$config_content = preg_replace("/define\('SCHEDULE_DAY', \d+\);/", "define('SCHEDULE_DAY', $schedule_day);", $config_content);
	}
	
	file_put_contents($config_file, $config_content);
	
	header("Location: backup_schedules.php");
	exit;
	}
}

// Load current settings
$schedule_enabled = defined('SCHEDULE_ENABLED') ? SCHEDULE_ENABLED : false;
$schedule_time = defined('SCHEDULE_TIME') ? SCHEDULE_TIME : '02:00';
$schedule_frequency = defined('SCHEDULE_FREQUENCY') ? SCHEDULE_FREQUENCY : 'daily';
$schedule_day = defined('SCHEDULE_DAY') ? SCHEDULE_DAY : 0;

$object = new token;
$token = $object->create($_SERVER['PHP_SELF']);

$document['title'] = $text['title-backup_manager'] . ' - Scheduled Transfer';
require_once "resources/header.php";

echo "<div class='action_bar'>\n";
echo "	<div class='heading'><b>Scheduled FTP Transfer</b></div>\n";
echo "	<div class='actions'>\n";
echo button::create(['type'=>'button','label'=>'Upload FTP Now','icon'=>'fa-upload','onclick'=>'upload_now()']);
echo button::create(['type'=>'button','label'=>$text['button-save'],'icon'=>$_SESSION['theme']['button_icon_save'],'onclick'=>'save_settings()']);
echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'fa-arrow-left','link'=>'backup_manager.php']);
echo "	</div>\n";
echo "	<div style='clear: both;'></div>\n";
echo "</div>\n";

// Upload form
echo "<form id='upload_form' method='POST' action='backup_schedules.php' style='display:none;'>\n";
echo "<input type='hidden' name='action' value='upload'>\n";
echo "<input type='hidden' name='" . $token['name'] . "' value='" . $token['hash'] . "'>\n";
echo "</form>\n";

// Save form
echo "<form id='save_form' method='POST' action='backup_schedules.php'>\n";
echo "<input type='hidden' name='action' value='save'>\n";
echo "<input type='hidden' name='" . $token['name'] . "' value='" . $token['hash'] . "'>\n";

echo "<table class='tr_hover' width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
echo "<tr><th colspan='2'>Schedule Settings</th></tr>\n";

echo "<tr>\n";
echo "<td class='vncell' width='30%'>Enable Schedule</td>\n";
echo "<td class='vtable'>\n";
echo "	<select class='formfld' name='schedule_enabled'>\n";
echo "		<option value='false' " . (!$schedule_enabled ? 'selected' : '') . ">Disabled</option>\n";
echo "		<option value='true' " . ($schedule_enabled ? 'selected' : '') . ">Enabled</option>\n";
echo "	</select>\n";
echo "</td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell'>Frequency</td>\n";
echo "<td class='vtable'>\n";
echo "	<select class='formfld' name='schedule_frequency' id='frequency' onchange='update_day_field()'>\n";
$frequencies = ['hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];
foreach ($frequencies as $value => $label) {
	$selected = $schedule_frequency == $value ? 'selected' : '';
	echo "		<option value='$value' $selected>$label</option>\n";
}
echo "	</select>\n";
echo "</td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell'>Time</td>\n";
echo "<td class='vtable'>\n";
echo "	<input class='formfld' type='time' name='schedule_time' value='$schedule_time'>\n";
echo "</td>\n";
echo "</tr>\n";

echo "<tr id='day_field'>\n";
echo "<td class='vncell'>Day</td>\n";
echo "<td class='vtable'>\n";
echo "	<select class='formfld' name='schedule_day' id='day_select'>\n";
echo "	</select>\n";
echo "</td>\n";
echo "</tr>\n";

echo "</table>\n";
echo "<br />\n";

// Show current status
echo "<table class='tr_hover' width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
echo "<tr><th colspan='2'>Current Status</th></tr>\n";

echo "<tr>\n";
echo "<td class='vncell' width='30%'>Status</td>\n";
echo "<td class='vtable'>\n";
if ($schedule_enabled) {
	echo "<span class='badge badge-success'>Enabled</span>\n";
} else {
	echo "<span class='badge badge-secondary'>Disabled</span>\n";
}
echo "</td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell'>FTP Status</td>\n";
echo "<td class='vtable'>\n";
if (defined('FTP_ENABLED') && FTP_ENABLED) {
	echo "<span class='badge badge-success'>FTP Enabled</span> - " . FTP_HOST . ":" . FTP_PORT . "\n";
} else {
	echo "<span class='badge badge-warning'>FTP Disabled</span> - Configure in <a href='backup_settings.php'>Settings</a>\n";
}
echo "</td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell'>Cron File</td>\n";
echo "<td class='vtable'>\n";
if (file_exists('/etc/cron.d/fusionpbx-backup')) {
	echo "<span class='badge badge-success'>Created</span> - /etc/cron.d/fusionpbx-backup<br />\n";
	echo "<pre style='margin-top:10px; padding:10px; background:#f5f5f5; border:1px solid #ddd;'>";
	echo htmlspecialchars(file_get_contents('/etc/cron.d/fusionpbx-backup'));
	echo "</pre>\n";
} else {
	echo "<span class='badge badge-secondary'>Not Created</span><br />\n";
	echo "<span class='vexpl'>Click Save to create cron job</span><br /><br />\n";
	
	// Check if temp files exist (indicates permission issue)
	$temp_files = glob('/tmp/fusionpbx-backup-cron-*');
	if (count($temp_files) > 0) {
		echo "<div style='margin-top:10px; padding:10px; background:#fff3cd; border:1px solid #ffc107; border-radius:4px;'>\n";
		echo "<strong style='color:#856404;'>⚠ Warning: Cron creation failed due to permissions</strong><br />\n";
		echo "<span style='color:#856404;'>The www-data user needs sudo permissions to create cron jobs.</span><br /><br />\n";
		echo "<strong>Run this command as root to fix:</strong><br />\n";
		echo "<pre style='margin-top:5px; padding:8px; background:#fff; border:1px solid #ddd; font-size:12px;'>";
		echo "cat > /etc/sudoers.d/fusionpbx-backup << 'EOF'\n";
		echo "# FusionPBX Backup Manager - Allow www-data to manage cron jobs\n";
		echo "www-data ALL=(ALL) NOPASSWD: /bin/mv /tmp/fusionpbx-backup-cron-* /etc/cron.d/fusionpbx-backup\n";
		echo "www-data ALL=(ALL) NOPASSWD: /bin/chown root\\:root /etc/cron.d/fusionpbx-backup\n";
		echo "www-data ALL=(ALL) NOPASSWD: /bin/chmod 644 /etc/cron.d/fusionpbx-backup\n";
		echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart cron\n";
		echo "www-data ALL=(ALL) NOPASSWD: /bin/rm /etc/cron.d/fusionpbx-backup\n";
		echo "EOF\n";
		echo "chmod 440 /etc/sudoers.d/fusionpbx-backup";
		echo "</pre>\n";
		echo "<span style='color:#856404;'>After running this command, click Save again.</span>\n";
		echo "</div>\n";
	}
}
echo "</td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell'>Log File</td>\n";
echo "<td class='vtable'>\n";
$module_log = __DIR__ . '/logs/backup.log';
if (file_exists($module_log)) {
	$log_size = filesize($module_log);
	$log_size_str = $log_size > 1024 ? round($log_size/1024, 2) . ' KB' : $log_size . ' bytes';
	echo "<a href='#' onclick='view_log(); return false;' style='color:#007bff;'>logs/backup.log</a> <span style='color:#666;'>($log_size_str)</span>\n";
} else {
	echo "<a href='#' onclick='view_log(); return false;' style='color:#007bff;'>logs/backup.log</a> <span style='color:#999;'>(empty)</span>\n";
}
echo " &nbsp; <button type='button' class='btn btn-sm btn-warning' onclick='clear_log()' title='Clear log file'><i class='fa fa-trash'></i> Clear Log</button>\n";
echo "</td>\n";
echo "</tr>\n";

echo "</table>\n";

echo "</form>\n";

?>
<script>
function upload_now() {
	if (confirm('Upload all backups to FTP now?\n\nThis will:\n- Create new backups for all tenants\n- Collect and package backups\n- Upload to FTP server\n\nThis may take a few minutes.')) {
		document.getElementById('upload_form').submit();
	}
}

function save_settings() {
	document.getElementById('save_form').submit();
}

function update_day_field() {
	var freq = document.getElementById('frequency').value;
	var dayField = document.getElementById('day_field');
	var daySelect = document.getElementById('day_select');
	
	daySelect.innerHTML = '';
	
	if (freq == 'weekly') {
		dayField.style.display = '';
		var days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
		for (var i = 0; i < days.length; i++) {
			var opt = document.createElement('option');
			opt.value = i;
			opt.text = days[i];
			if (i == <?php echo $schedule_day; ?>) opt.selected = true;
			daySelect.appendChild(opt);
		}
	} else if (freq == 'monthly') {
		dayField.style.display = '';
		for (var i = 1; i <= 31; i++) {
			var opt = document.createElement('option');
			opt.value = i;
			opt.text = 'Day ' + i;
			if (i == <?php echo $schedule_day; ?>) opt.selected = true;
			daySelect.appendChild(opt);
		}
	} else {
		dayField.style.display = 'none';
	}
}

update_day_field();

function view_log() {
	fetch('backup_log_viewer.php')
		.then(response => response.text())
		.then(data => {
			document.getElementById('log_content').innerHTML = '<pre style="max-height:500px; overflow-y:auto; background:#f5f5f5; padding:15px; border:1px solid #ddd; font-size:12px;">' + data + '</pre>';
			document.getElementById('log_modal').style.display = 'block';
		})
		.catch(error => {
			alert('Error loading log file: ' + error);
		});
}

function clear_log() {
	if (confirm('Are you sure you want to clear the log file?\n\nThis action cannot be undone.')) {
		fetch('backup_log_viewer.php?action=clear', {method: 'POST'})
			.then(response => response.text())
			.then(data => {
				alert('Log file cleared successfully');
				view_log(); // Refresh log view
			})
			.catch(error => {
				alert('Error clearing log file: ' + error);
			});
	}
}

function close_log_modal() {
	document.getElementById('log_modal').style.display = 'none';
}
</script>

<!-- Log Modal -->
<div id="log_modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.4);">
	<div style="background-color:#fefefe; margin:5% auto; padding:20px; border:1px solid #888; width:80%; max-width:900px; border-radius:5px;">
		<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
			<h3 style="margin:0;">Backup Log File</h3>
			<button onclick="close_log_modal()" style="background:none; border:none; font-size:28px; cursor:pointer;">&times;</button>
		</div>
		<div id="log_content">Loading...</div>
		<div style="margin-top:15px; text-align:right;">
			<button type="button" class="btn btn-secondary" onclick="close_log_modal()">Close</button>
		</div>
	</div>
</div>

<?php

require_once "resources/footer.php";
?>
