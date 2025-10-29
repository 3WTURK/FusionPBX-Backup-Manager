<?php
/*
	Remote Transfer - Transfer backups to remote storage
*/

require_once dirname(__DIR__, 3) . "/resources/require.php";

$backup_uuid = $argv[1] ?? '';

if (empty($backup_uuid)) {
	exit("Backup UUID required\n");
}

//get backup details
$sql = "SELECT * FROM v_backup_manager WHERE backup_manager_uuid = :backup_uuid";
$parameters['backup_uuid'] = $backup_uuid;
$database = new database;
$backup = $database->select($sql, $parameters, 'row');

if (empty($backup) || !file_exists($backup['backup_path'])) {
	exit("Backup not found\n");
}

//load settings from config file
$config_file = dirname(__DIR__) . '/backup_config.php';
if (file_exists($config_file)) {
	require_once $config_file;
}

$settings = array(
	'ftp_enabled' => defined('FTP_ENABLED') ? (FTP_ENABLED ? 'true' : 'false') : 'false',
	'ftp_host' => defined('FTP_HOST') ? FTP_HOST : '',
	'ftp_port' => defined('FTP_PORT') ? FTP_PORT : 21,
	'ftp_username' => defined('FTP_USERNAME') ? FTP_USERNAME : '',
	'ftp_password' => defined('FTP_PASSWORD') ? FTP_PASSWORD : '',
	'ftp_path' => defined('FTP_PATH') ? FTP_PATH : '/',
	'ftp_passive' => defined('FTP_PASSIVE') ? (FTP_PASSIVE ? 'true' : 'false') : 'true'
);

$success = false;
$errors = array();

//try FTP transfer
if (($settings['ftp_enabled'] ?? 'false') == 'true') {
	echo "Attempting FTP transfer...\n";
	
	try {
		$result = transfer_ftp($backup['backup_path'], $settings);
		if ($result) {
			$success = true;
			echo "FTP transfer completed successfully\n";
		}
	} catch (Exception $e) {
		$errors[] = "FTP: " . $e->getMessage();
		echo "FTP transfer failed: " . $e->getMessage() . "\n";
	}
}

//try SFTP transfer
if (($settings['sftp_enabled'] ?? 'false') == 'true') {
	echo "Attempting SFTP transfer...\n";
	
	try {
		$result = transfer_sftp($backup['backup_path'], $settings);
		if ($result) {
			$success = true;
			echo "SFTP transfer completed successfully\n";
		}
	} catch (Exception $e) {
		$errors[] = "SFTP: " . $e->getMessage();
		echo "SFTP transfer failed: " . $e->getMessage() . "\n";
	}
}

//try S3 transfer
if (($settings['s3_enabled'] ?? 'false') == 'true') {
	echo "Attempting S3 transfer...\n";
	
	try {
		$result = transfer_s3($backup['backup_path'], $settings);
		if ($result) {
			$success = true;
			echo "S3 transfer completed successfully\n";
		}
	} catch (Exception $e) {
		$errors[] = "S3: " . $e->getMessage();
		echo "S3 transfer failed: " . $e->getMessage() . "\n";
	}
}

//try Rsync transfer
if (($settings['rsync_enabled'] ?? 'false') == 'true') {
	echo "Attempting Rsync transfer...\n";
	
	try {
		$result = transfer_rsync($backup['backup_path'], $settings);
		if ($result) {
			$success = true;
			echo "Rsync transfer completed successfully\n";
		}
	} catch (Exception $e) {
		$errors[] = "Rsync: " . $e->getMessage();
		echo "Rsync transfer failed: " . $e->getMessage() . "\n";
	}
}

if (!$success) {
	echo "All remote transfers failed:\n";
	foreach ($errors as $error) {
		echo "  - " . $error . "\n";
	}
	exit(1);
}

//transfer functions
function transfer_ftp($file_path, $settings) {
	$host = $settings['ftp_host'] ?? '';
	$port = $settings['ftp_port'] ?? 21;
	$username = $settings['ftp_username'] ?? '';
	$password = $settings['ftp_password'] ?? '';
	$remote_path = $settings['ftp_path'] ?? '/';
	$passive = ($settings['ftp_passive'] ?? 'true') == 'true';
	
	if (empty($host) || empty($username)) {
		throw new Exception("FTP credentials not configured");
	}
	
	$conn = ftp_connect($host, $port, 30);
	if (!$conn) {
		throw new Exception("Could not connect to FTP server");
	}
	
	if (!ftp_login($conn, $username, $password)) {
		ftp_close($conn);
		throw new Exception("FTP login failed");
	}
	
	ftp_pasv($conn, $passive);
	
	//create remote directory if needed
	$dirs = explode('/', trim($remote_path, '/'));
	$current_dir = '';
	foreach ($dirs as $dir) {
		$current_dir .= '/' . $dir;
		@ftp_mkdir($conn, $current_dir);
	}
	
	$remote_file = rtrim($remote_path, '/') . '/' . basename($file_path);
	
	if (!ftp_put($conn, $remote_file, $file_path, FTP_BINARY)) {
		ftp_close($conn);
		throw new Exception("FTP upload failed");
	}
	
	ftp_close($conn);
	return true;
}

function transfer_sftp($file_path, $settings) {
	$host = $settings['sftp_host'] ?? '';
	$port = $settings['sftp_port'] ?? 22;
	$username = $settings['sftp_username'] ?? '';
	$password = $settings['sftp_password'] ?? '';
	$key_path = $settings['sftp_key_path'] ?? '';
	$remote_path = $settings['sftp_path'] ?? '/';
	
	if (empty($host) || empty($username)) {
		throw new Exception("SFTP credentials not configured");
	}
	
	$remote_file = rtrim($remote_path, '/') . '/' . basename($file_path);
	
	//use scp command
	if (!empty($key_path)) {
		$cmd = "scp -P {$port} -i " . escapeshellarg($key_path) . " " . 
			   escapeshellarg($file_path) . " " . 
			   escapeshellarg($username . "@" . $host . ":" . $remote_file);
	} else {
		$cmd = "sshpass -p " . escapeshellarg($password) . " scp -P {$port} " . 
			   escapeshellarg($file_path) . " " . 
			   escapeshellarg($username . "@" . $host . ":" . $remote_file);
	}
	
	exec($cmd, $output, $return_var);
	
	if ($return_var !== 0) {
		throw new Exception("SFTP transfer failed");
	}
	
	return true;
}

function transfer_s3($file_path, $settings) {
	$bucket = $settings['s3_bucket'] ?? '';
	$region = $settings['s3_region'] ?? 'us-east-1';
	$access_key = $settings['s3_access_key'] ?? '';
	$secret_key = $settings['s3_secret_key'] ?? '';
	$s3_path = $settings['s3_path'] ?? '';
	
	if (empty($bucket) || empty($access_key) || empty($secret_key)) {
		throw new Exception("S3 credentials not configured");
	}
	
	//check if AWS CLI is available
	exec("which aws", $output, $return_var);
	if ($return_var !== 0) {
		throw new Exception("AWS CLI not installed");
	}
	
	$s3_key = trim($s3_path, '/') . '/' . basename($file_path);
	
	//set AWS credentials as environment variables
	$env = "AWS_ACCESS_KEY_ID=" . escapeshellarg($access_key) . " ";
	$env .= "AWS_SECRET_ACCESS_KEY=" . escapeshellarg($secret_key) . " ";
	$env .= "AWS_DEFAULT_REGION=" . escapeshellarg($region) . " ";
	
	$cmd = $env . "aws s3 cp " . escapeshellarg($file_path) . " s3://" . $bucket . "/" . $s3_key;
	
	exec($cmd, $output, $return_var);
	
	if ($return_var !== 0) {
		throw new Exception("S3 upload failed");
	}
	
	return true;
}

function transfer_rsync($file_path, $settings) {
	$host = $settings['rsync_host'] ?? '';
	$user = $settings['rsync_user'] ?? '';
	$remote_path = $settings['rsync_path'] ?? '/';
	$options = $settings['rsync_options'] ?? '-avz';
	
	if (empty($host)) {
		throw new Exception("Rsync host not configured");
	}
	
	$destination = $user . "@" . $host . ":" . rtrim($remote_path, '/') . '/';
	
	$cmd = "rsync " . $options . " " . escapeshellarg($file_path) . " " . escapeshellarg($destination);
	
	exec($cmd, $output, $return_var);
	
	if ($return_var !== 0) {
		throw new Exception("Rsync transfer failed");
	}
	
	return true;
}
?>
