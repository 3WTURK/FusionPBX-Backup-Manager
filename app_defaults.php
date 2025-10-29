<?php

	if ($domains_processed == 1) {

		//backup storage path
		$y = 0;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "a1b2c3d4-e5f6-7890-abcd-ef1234567890";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "backup_manager";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "storage_path";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "/var/backups/fusionpbx";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Default backup storage path";
		$y++;

		//max concurrent backups
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "b2c3d4e5-f6a7-8901-bcde-f12345678901";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "backup_manager";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "max_concurrent";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "numeric";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "3";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Maximum concurrent backup operations";
		$y++;

		//compression level
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "c3d4e5f6-a7b8-9012-cdef-123456789012";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "backup_manager";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "compression_level";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "numeric";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "6";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Compression level (1-9, higher = better compression but slower)";
		$y++;

		//default retention days
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "d4e5f6a7-b8c9-0123-def1-234567890123";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "backup_manager";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "default_retention";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "numeric";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "30";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Default retention period in days";
		$y++;

		//enable remote transfer
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "e5f6a7b8-c9d0-1234-ef12-345678901234";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "backup_manager";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "remote_transfer_enabled";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "boolean";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "false";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Enable automatic remote transfer after backup";
		$y++;

		//recordings path
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "f6a7b8c9-d0e1-2345-f123-456789012345";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "backup_manager";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "recordings_path";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "/var/lib/freeswitch/recordings";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Base path for recordings";
		$y++;

		//voicemail path
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "a7b8c9d0-e1f2-3456-1234-567890123456";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "backup_manager";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "voicemail_path";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "/var/lib/freeswitch/storage/voicemail/default";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Base path for voicemail";
		$y++;

	}

?>
