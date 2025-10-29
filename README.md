# FusionPBX Backup Manager

**Professional Backup & Restore Solution for FusionPBX**

Developed by **[3WTURK](https://3wturk.com)** - World Wide Web Solutions

---

Complete backup and restore system with FTP transfer and scheduling capabilities for FusionPBX 5.4+

## Features

### Core Functionality
- ✅ **Full System Backup**: Complete PostgreSQL database dump
- ✅ **Tenant Backup**: Domain-specific backups with isolation
- ✅ **Full System Restore**: Drop/recreate database with connection termination
- ✅ **Tenant Restore**: Domain data delete and import
- ✅ **FTP Transfer**: Automatic upload to remote FTP server
- ✅ **Scheduled Backups**: Cron-based automation (Hourly/Daily/Weekly/Monthly)
- ✅ **Manual Upload**: On-demand FTP transfer
- ✅ **Auto Cleanup**: Retention policy based cleanup

### Technical Features
- PostgreSQL 17.6+ compatible
- Tenant isolation via domain_uuid
- Recordings & voicemail backup/restore
- Real-time log viewer with modal display
- Dynamic configuration (no hardcoded values)
- Sudo permission management for cron
- Background processing
- Comprehensive error handling

## Installation

### 1. Upload Module
```bash
cd /var/www/fusionpbx/app/
scp -r backup_manager root@your-server:/var/www/fusionpbx/app/
```

### 2. Set Permissions
```bash
chown -R www-data:www-data /var/www/fusionpbx/app/backup_manager
chmod -R 755 /var/www/fusionpbx/app/backup_manager
chmod +x /var/www/fusionpbx/app/backup_manager/resources/*.php
```

### 3. Create Backup Directory
```bash
mkdir -p /var/backups/fusionpbx
chown www-data:www-data /var/backups/fusionpbx
chmod 755 /var/backups/fusionpbx
```

### 4. Configure Settings
Login to FusionPBX web interface:
- Go to Applications > Backup Manager > Settings
- Set backup directory path (default: `/var/backups/fusionpbx`)
- Configure FTP settings (optional)
- Set maximum backups per domain
- Click Save

### 5. Setup Cron Permissions (if using schedules)
```bash
cat > /etc/sudoers.d/fusionpbx-backup << 'EOF'
# FusionPBX Backup Manager - Allow www-data to manage cron jobs
www-data ALL=(ALL) NOPASSWD: /bin/mv /tmp/fusionpbx-backup-cron-* /etc/cron.d/fusionpbx-backup
www-data ALL=(ALL) NOPASSWD: /bin/chown root\:root /etc/cron.d/fusionpbx-backup
www-data ALL=(ALL) NOPASSWD: /bin/chmod 644 /etc/cron.d/fusionpbx-backup
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart cron
www-data ALL=(ALL) NOPASSWD: /bin/rm /etc/cron.d/fusionpbx-backup
EOF
chmod 440 /etc/sudoers.d/fusionpbx-backup
```

### 6. Reload FusionPBX
```bash
cd /var/www/fusionpbx
php /var/www/fusionpbx/core/upgrade/upgrade.php
```

## Usage

### Creating a Manual Backup
1. Navigate to **Applications > Backup Manager**
2. Click **"Create Backup"** button
3. Select backup type:
   - **Full System**: Complete database dump
   - **Tenant**: Domain-specific backup
4. Click **"Create"**

### Restoring a Backup
1. Find backup in list (Full System or Tenant section)
2. Click **"Restore"** button
3. Confirm restoration
4. System will:
   - Terminate active database connections (full system only)
   - Delete existing domain data (tenant only)
   - Import backup data
   - Restore recordings and voicemail

### FTP Transfer Setup
1. Go to **Backup Manager > Settings**
2. Configure FTP settings:
   - **FTP Host**: your-ftp-server.com
   - **FTP Port**: 21 (default)
   - **Username**: your-username
   - **Password**: your-password
   - **Remote Path**: /backups
   - **Passive Mode**: Enabled (recommended)
3. Click **Save**

### Manual FTP Upload
1. Go to **Backup Manager > Backup Schedules**
2. Click **"Upload FTP Now"** button
3. System will:
   - Create new backups for all tenants
   - Package full system backup
   - Upload all files to FTP server
4. Check log file for progress

### Scheduling Automatic Backups
1. Go to **Backup Manager > Backup Schedules**
2. Configure schedule:
   - **Enable Schedule**: Enabled
   - **Frequency**: Daily/Weekly/Monthly
   - **Time**: 02:00 (recommended: off-peak hours)
3. Click **Save**
4. Cron job will be created automatically
5. If cron creation fails, follow the displayed sudo command

### Viewing Logs
1. Go to **Backup Manager > Backup Schedules**
2. Click on **logs/backup.log** link
3. Modal window will show recent log entries
4. Click **"Clear Log"** to empty log file

## File Structure

```
backup_manager/
├── app_config.php                           # Module configuration
├── app_defaults.php                         # Default settings
├── app_menu.php                            # Menu definitions
├── backup_manager.php                      # Main backup list page
├── backup_schedules.php                    # Schedule & FTP upload management
├── backup_settings.php                     # Settings page
├── backup_download.php                     # Download handler
├── backup_list_ajax.php                    # AJAX backup list
├── backup_log_viewer.php                   # Log viewer (modal)
├── backup_restore.php                      # Restore handler
├── backup_config.php                       # Auto-generated config (gitignored)
├── .gitignore                              # Git ignore rules
├── README.md                               # This file
├── logs/                                   # Log directory (auto-created)
│   └── backup.log                          # Backup & FTP logs
└── resources/
    ├── standalone_backup_processor.php     # Tenant backup creator
    ├── standalone_restore_processor_file.php # Tenant restore handler
    ├── schedule_runner.php                 # Scheduled FTP upload
    ├── auto_cleanup.php                    # Cleanup old backups
    └── check_auth.php                      # Permission check
```

## Configuration

### backup_config.php (Auto-generated)
This file is automatically created when you save settings. Contains:
- `BACKUP_PATH`: Backup storage directory
- `BACKUP_MAX_PER_DOMAIN`: Maximum backups to keep
- `FTP_ENABLED`: Enable/disable FTP
- `FTP_HOST`, `FTP_PORT`, `FTP_USERNAME`, `FTP_PASSWORD`: FTP credentials
- `FTP_PATH`: Remote FTP directory
- `FTP_PASSIVE`: Passive mode setting

**Note:** This file is gitignored and should not be committed to version control.

## Backup Contents

### Full System Backup
- Complete PostgreSQL database dump (`.sql.gz`)
- All domains, users, settings, and configurations
- Stored in: `/var/backups/fullsystem.backup.YYYY-MM-DD_HH-MM-SS.sql.gz`

### Tenant Backup (`.tar.gz`)
Contains:
- **database.sql**: Domain-specific tables
  - v_extensions
  - v_devices
  - v_dialplans & v_dialplan_details
  - v_gateways
  - v_ivr_menus & v_ivr_menu_options
  - v_ring_groups & v_ring_group_destinations
  - v_call_center_queues, agents, tiers
  - v_conference_centers & rooms
  - v_voicemails, greetings, messages
  - v_fax & v_fax_files
  - v_call_flows
  - v_time_conditions
  - v_music_on_hold
  - v_recordings
  - v_follow_me & destinations
  - v_call_block
  - v_call_routings
  - v_destination_conditions & actions
- **recordings/**: Domain recordings
- **voicemail/**: Domain voicemail files

## Cron Integration

Scheduled backups are managed via `/etc/cron.d/fusionpbx-backup`

The cron file is automatically generated when you save schedule settings.

Example cron entry (Daily at 02:00):
```
0 2 * * * www-data php /var/www/fusionpbx/app/backup_manager/resources/schedule_runner.php >> /var/www/fusionpbx/app/backup_manager/logs/backup.log 2>&1
```

**Note:** Requires sudo permissions for www-data user (see Installation step 5)

## Security

- Configuration file (`backup_config.php`) is gitignored
- FTP passwords stored in plain text in config (file permissions: 644)
- Backup files stored with www-data:www-data ownership
- Only users with `backup_manager_*` permissions can access
- Sudo permissions limited to specific cron management commands only

## Requirements

- FusionPBX 5.4.7+
- PHP 8.2+
- PostgreSQL 17.6+ (tested with 17.6)
- FreeSWITCH 1.10.12+
- Debian 12+ or Ubuntu 22.04+
- FTP client support (for remote transfers)

## Troubleshooting

### Backup Creation Fails
- Check disk space: `df -h /var/backups`
- Check permissions: `ls -la /var/backups/fusionpbx`
- View module logs: Click on **logs/backup.log** in Backup Schedules page

### Restore Fails with "invalid input syntax for type boolean"
- This was fixed in the latest version
- Create a NEW backup after updating the module
- Old backups may contain empty strings instead of NULL values

### Cron Job Not Created
- Check if sudo permissions are set (Installation step 5)
- Look for warning message in Backup Schedules page
- Run the displayed sudo command manually
- Click Save again after setting permissions

### FTP Upload Fails
- Test FTP connection manually: `ftp your-ftp-server.com`
- Verify credentials in Settings page
- Check FTP server logs
- Enable Passive Mode if behind firewall
- View upload log in Backup Schedules page

### Log File Not Found
- Log file is auto-created on first use
- Location: `/var/www/fusionpbx/app/backup_manager/logs/backup.log`
- Check www-data has write permissions to module directory

## Performance Tips

1. **Backup Timing**: Schedule during off-peak hours (e.g., 02:00)
2. **Retention Policy**: Set max backups per domain (default: 100)
3. **Disk Space**: Monitor `/var/backups` regularly
4. **FTP Transfer**: Use passive mode for better compatibility

## Known Issues

1. **Boolean Field Error**: Fixed in current version - create new backups after update
2. **Global Dialplans**: Not included in tenant backups (by design)
3. **Cron Permissions**: Requires manual sudo setup (one-time)

## Support

**Developed by 3WTURK**
- Website: https://3wturk.com
- Email: support@3wturk.com

For FusionPBX community support:
- Forum: https://fusionpbx.org
- Documentation: https://docs.fusionpbx.com

## License

Mozilla Public License Version 2.0 (MPL 2.0)

## Credits

**Development Team:**
- 3WTURK Development Team
- Tested on FusionPBX 5.4.7
- PostgreSQL 17.6 compatible
- FreeSWITCH 1.10.12 compatible

**Special Thanks:**
- FusionPBX Community
- PostgreSQL Team
- FreeSWITCH Team

## Changelog

### Version 1.0.0 (2025-10-30)
- ✅ Full system backup & restore
- ✅ Tenant-based backup & restore
- ✅ FTP transfer with passive mode
- ✅ Scheduled backups (cron integration)
- ✅ Manual FTP upload
- ✅ Auto cleanup (retention policy)
- ✅ Real-time log viewer (modal)
- ✅ Dynamic configuration
- ✅ Boolean field fix for PostgreSQL
- ✅ Sudo permission management
- ✅ Comprehensive error handling

---

**© 2025 3WTURK - World Wide Web Solutions**
