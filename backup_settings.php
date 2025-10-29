<?php
/*
	FusionPBX Backup Manager
	Settings Page - Backup Path & FTP Configuration
	
	Copyright (C) 2025 3WTURK - World Wide Web Solutions
	https://3wturk.com
	
	Developed for FusionPBX 5.4.7+
*/

require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

if (permission_exists('backup_manager_settings')) {
	//access granted
}
else {
	echo "access denied";
	exit;
}

$language = new text;
$text = $language->get();

$config_file = __DIR__ . '/backup_config.php';

//handle form submission
if (!empty($_POST['action']) && $_POST['action'] == 'save') {
	$backup_path = $_POST['backup_path'] ?? '/var/backups/fusionpbx';
	$max_backups = intval($_POST['max_backups_per_domain'] ?? 10);
	$ftp_enabled = ($_POST['ftp_enabled'] ?? 'false') == 'true' ? 'true' : 'false';
	$ftp_host = $_POST['ftp_host'] ?? '';
	$ftp_port = intval($_POST['ftp_port'] ?? 21);
	$ftp_username = $_POST['ftp_username'] ?? '';
	$ftp_password = $_POST['ftp_password'] ?? '';
	$ftp_path = $_POST['ftp_path'] ?? '/backups';
	$ftp_passive = ($_POST['ftp_passive'] ?? 'true') == 'true' ? 'true' : 'false';
	
	// Create backup directory if it doesn't exist
	if (!is_dir($backup_path)) {
		if (!@mkdir($backup_path, 0755, true)) {
			$_SESSION['message'] = "<span style='color: red; font-weight: bold;'>Failed to create backup directory: $backup_path<br>Please create it manually or check permissions.</span>";
			header("Location: backup_settings.php");
			exit;
		}
	}
	
	// Generate config file content
	$config_content = "<?php\n";
	$config_content .= "/*\n";
	$config_content .= "\tBackup Manager Configuration\n";
	$config_content .= "\tSimple config file - no database needed\n";
	$config_content .= "*/\n\n";
	$config_content .= "// General Settings\n";
	$config_content .= "define('BACKUP_MAX_PER_DOMAIN', $max_backups); // Maximum backups to keep per domain\n\n";
	$config_content .= "// FTP Settings\n";
	$config_content .= "define('FTP_ENABLED', $ftp_enabled);\n";
	$config_content .= "define('FTP_HOST', '" . addslashes($ftp_host) . "');\n";
	$config_content .= "define('FTP_PORT', $ftp_port);\n";
	$config_content .= "define('FTP_USERNAME', '" . addslashes($ftp_username) . "');\n";
	$config_content .= "define('FTP_PASSWORD', '" . addslashes($ftp_password) . "');\n";
	$config_content .= "define('FTP_PATH', '" . addslashes($ftp_path) . "');\n";
	$config_content .= "define('FTP_PASSIVE', $ftp_passive);\n\n";
	$config_content .= "// Paths\n";
	$config_content .= "define('BACKUP_PATH', '" . addslashes($backup_path) . "');\n\n";
	$config_content .= "?>\n";
	
	// Debug log
	error_log("Backup Settings Save - Config file: $config_file");
	error_log("Backup Settings Save - Backup path: $backup_path");
	error_log("Backup Settings Save - Max backups: $max_backups");
	
	// Write config file
	$result = file_put_contents($config_file, $config_content);
	error_log("Backup Settings Save - Write result: " . ($result !== false ? "SUCCESS ($result bytes)" : "FAILED"));
	
	if ($result !== false) {
		$_SESSION['message'] = "<span style='color: green; font-weight: bold;'>✓ Settings saved successfully!</span>";
	} else {
		$error = error_get_last();
		$_SESSION['message'] = "<span style='color: red; font-weight: bold;'>✗ Failed to save configuration file: " . ($error['message'] ?? 'Unknown error') . "<br>Path: $config_file</span>";
	}
	
	header("Location: backup_settings.php");
	exit;
}

//load existing settings from config file
$settings = array();
if (file_exists($config_file)) {
	require_once $config_file;
	$settings['backup_path'] = defined('BACKUP_PATH') ? BACKUP_PATH : '/var/backups/fusionpbx';
	$settings['max_backups_per_domain'] = defined('BACKUP_MAX_PER_DOMAIN') ? BACKUP_MAX_PER_DOMAIN : 10;
	$settings['ftp_enabled'] = defined('FTP_ENABLED') ? (FTP_ENABLED ? 'true' : 'false') : 'false';
	$settings['ftp_host'] = defined('FTP_HOST') ? FTP_HOST : '';
	$settings['ftp_port'] = defined('FTP_PORT') ? FTP_PORT : 21;
	$settings['ftp_username'] = defined('FTP_USERNAME') ? FTP_USERNAME : '';
	$settings['ftp_password'] = defined('FTP_PASSWORD') ? FTP_PASSWORD : '';
	$settings['ftp_path'] = defined('FTP_PATH') ? FTP_PATH : '/backups';
	$settings['ftp_passive'] = defined('FTP_PASSIVE') ? (FTP_PASSIVE ? 'true' : 'false') : 'true';
} else {
	// Default settings if config file doesn't exist
	$settings['backup_path'] = '/var/backups/fusionpbx';
	$settings['max_backups_per_domain'] = 10;
	$settings['ftp_enabled'] = 'false';
	$settings['ftp_host'] = '';
	$settings['ftp_port'] = 21;
	$settings['ftp_username'] = '';
	$settings['ftp_password'] = '';
	$settings['ftp_path'] = '/backups';
	$settings['ftp_passive'] = 'true';
}

$object = new token;
$token = $object->create($_SERVER['PHP_SELF']);

$document['title'] = $text['title-backup_manager'] . ' - ' . $text['button-settings'];
require_once "resources/header.php";

echo "<form method='POST' action='backup_settings.php'>\n";
echo "<input type='hidden' name='action' value='save'>\n";
echo "<input type='hidden' name='" . $token['name'] . "' value='" . $token['hash'] . "'>\n";

echo "<div class='action_bar'>\n";
echo "	<div class='heading'><b>Backup Settings</b></div>\n";
echo "	<div class='actions'>\n";
echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$_SESSION['theme']['button_icon_save']]);
echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'fa-arrow-left','link'=>'backup_manager.php']);
echo "	</div>\n";
echo "	<div style='clear: both;'></div>\n";
echo "</div>\n";

//General Settings
echo "<table class='tr_hover' width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
echo "<tr><th colspan='2'>General Settings</th></tr>\n";

echo "<tr>\n";
echo "<td class='vncell' width='30%'>Backup Directory Path</td>\n";
echo "<td class='vtable'>\n";
echo "	<input class='formfld' type='text' name='backup_path' value='" . htmlspecialchars($settings['backup_path'] ?? '/var/backups/fusionpbx') . "' style='width: 400px;'>\n";
echo "</td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell' width='30%'>Max Backups Per Domain</td>\n";
echo "<td class='vtable'>\n";
echo "	<input class='formfld' type='number' name='max_backups_per_domain' value='" . ($settings['max_backups_per_domain'] ?? '10') . "' min='1' max='100'>\n";
echo "</td>\n";
echo "</tr>\n";

echo "</table>\n";
echo "<br />\n";

//FTP Settings
echo "<table class='tr_hover' width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
echo "<tr><th colspan='2'>FTP Remote Transfer</th></tr>\n";

echo "<tr>\n";
echo "<td class='vncell' width='30%'>Enable FTP Transfer</td>\n";
echo "<td class='vtable'>\n";
echo "	<select class='formfld' name='ftp_enabled'>\n";
echo "		<option value='false' " . (($settings['ftp_enabled'] ?? 'false') == 'false' ? 'selected' : '') . ">Disabled</option>\n";
echo "		<option value='true' " . (($settings['ftp_enabled'] ?? 'false') == 'true' ? 'selected' : '') . ">Enabled</option>\n";
echo "	</select>\n";
echo "</td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell'>FTP Host</td>\n";
echo "<td class='vtable'><input class='formfld' type='text' name='ftp_host' value='" . htmlspecialchars($settings['ftp_host'] ?? '') . "'></td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell'>FTP Port</td>\n";
echo "<td class='vtable'><input class='formfld' type='text' name='ftp_port' value='" . ($settings['ftp_port'] ?? '21') . "'></td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell'>FTP Username</td>\n";
echo "<td class='vtable'><input class='formfld' type='text' name='ftp_username' value='" . htmlspecialchars($settings['ftp_username'] ?? '') . "'></td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell'>FTP Password</td>\n";
echo "<td class='vtable'><input class='formfld' type='password' name='ftp_password' value='" . htmlspecialchars($settings['ftp_password'] ?? '') . "'></td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell'>FTP Path</td>\n";
echo "<td class='vtable'><input class='formfld' type='text' name='ftp_path' value='" . htmlspecialchars($settings['ftp_path'] ?? '/backups') . "'></td>\n";
echo "</tr>\n";

echo "<tr>\n";
echo "<td class='vncell'>Passive Mode</td>\n";
echo "<td class='vtable'>\n";
echo "	<select class='formfld' name='ftp_passive'>\n";
echo "		<option value='true' " . (($settings['ftp_passive'] ?? 'true') == 'true' ? 'selected' : '') . ">Yes</option>\n";
echo "		<option value='false' " . (($settings['ftp_passive'] ?? 'true') == 'false' ? 'selected' : '') . ">No</option>\n";
echo "	</select>\n";
echo "</td>\n";
echo "</tr>\n";

echo "</table>\n";

echo "</form>\n";

require_once "resources/footer.php";
?>
