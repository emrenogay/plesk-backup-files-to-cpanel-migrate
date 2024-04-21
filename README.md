# Plesk Backup Files to cPanel Migration
It's a command line PHP script. You have to be root or same privileges for run.

#How to use it?

Enter your linux server command line with root credentials and apply on the codes below. You have to move your ".tar" backup files to /root/plesk_backups. After that run the following codes.

    cd /root && wget https://raw.githubusercontent.com/emrenogay/plesk-backup-files-to-cpanel-migrate/main/migrate.php
    chmod +x migrate.sh
    ./migrate.sh

    
