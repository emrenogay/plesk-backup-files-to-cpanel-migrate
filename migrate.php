#!/usr/local/bin/php
<?php

$output = ' 2>&1; echo $?';

$die = 0;
foreach(['shell_exec', 'glob'] as $func){
    if(!function_exists($func)){
        echo $func . '() function has been disabled for security reasons. Please allow '.$func.' function by your php.ini file' . PHP_EOL;
        $die = 1;
    }
}

if($die){
    die;
}

file_exists('/root/plesk_backups') or die('"/root/plesk_backups" File not found. Please move your ".tar" Plesk backups to "/root/plesk_backups"' . PHP_EOL);

$tarFiles = glob('/root/plesk_backups/*.tar');
if(!empty($tarFiles)){
    echo count($tarFiles) . ' Plesk backup file was found...' . PHP_EOL;
}else{
    $msg = 'There is no ".tar" Plesk backup files in "/root/plesk_backups"';
    die($msg . "\n");
}

echo shell_exec('whmapi1 set_tweaksetting key=database_prefix value=0');
foreach ($tarFiles as $file){
    echo '========================================================================' . PHP_EOL;
    echo 'Starting extract for ' . $file . PHP_EOL;
    echo '========================================================================' . PHP_EOL;
  
    $explodedName = explode('/', rtrim($file, '/'));

    $fileFullPath = $file;
    $fileName = end($explodedName);
    $fileNameWithoutExtension = str_replace('.tar', null, $fileName);

    echo 'Creating file for ' . $fileNameWithoutExtension . PHP_EOL;
    $createDirectoryCommand = 'mkdir /root/plesk_backups/' . $fileNameWithoutExtension;
    shell_exec($createDirectoryCommand);
    echo 'Directory created for ' . $fileNameWithoutExtension . PHP_EOL;

    echo 'Moving .tar file to /root/plesk_backups/' . $fileNameWithoutExtension . PHP_EOL;
    $moveCommand = 'mv ' . $fileFullPath . ' /root/plesk_backups/' . $fileNameWithoutExtension . '/backup.tar';
    $moveOutput = shell_exec($moveCommand);
    echo 'Move end.' . PHP_EOL;

    $newBackupPath = '/root/plesk_backups/' . $fileNameWithoutExtension . '/backup.tar';

    $tarExecuteCommand = 'tar -xvf ' . $newBackupPath . ' -C /root/plesk_backups/' . $fileNameWithoutExtension;
    shell_exec($tarExecuteCommand . $output);
    shell_exec('rm -rf ' . $newBackupPath);
    echo $newBackupPath . ' Removed.' . PHP_EOL;


    echo '========================================================================' . PHP_EOL;
    echo 'Getting domain and database data from Plesk XML file' . PHP_EOL;
    echo '========================================================================' . PHP_EOL;

    $currentBackupDir = '/root/plesk_backups/' . $fileNameWithoutExtension;
    $xmlFilePath = $currentBackupDir . '/backup_info_*.xml';
    $xmlFiles = glob($xmlFilePath);

    if(!empty($xmlFiles) && is_array($xmlFiles) && !empty($xmlFiles[0])){
        $xmlFile = $xmlFiles[0];
        echo 'XML File found as ' . $xmlFile . PHP_EOL;

        $xmlData = file_get_contents($xmlFile);
        $xml = new SimpleXMLElement($xmlData);

        $domain = (string)$xml->domain['name'];
        $dbName = (string)$xml->domain->databases->database['name'];
        $dbUser = (string)$xml->domain->databases->database->dbuser['name'];
        $dbPass = (string)$xml->domain->databases->database->dbuser->password[0];

        $dbData = [
            'domain' => $domain,
            'dbName' => $dbName,
            'dbUser' => $dbUser,
            'dbPass' => $dbPass
        ];

        if(empty($dbData['domain'])){
            echo 'Please check XML file.';
            echo 'Domain name is empty in XML file. Aborting...';
            exit;
        }

        print_r($dbData);

        echo PHP_EOL . PHP_EOL . PHP_EOL;

        echo '========================================================================' . PHP_EOL;
        echo 'Creating cPanel Account for ' . $dbData['domain'] . PHP_EOL;
        echo '========================================================================' . PHP_EOL;

        $cPanelUserName = str_replace('.', '', $dbData['domain']);
        if(strlen($cPanelUserName) > 7){
            $cPanelUserName = substr($cPanelUserName, 0, 7) . rand(10,99);
        }

        $cPanelAccountStatus = shell_exec("whmapi1 --output=jsonpretty createacct username='{$cPanelUserName}' domain='{$dbData['domain']}'" . $output);
        if(strstr($cPanelAccountStatus, 'Account Creation Ok')){
            echo 'cPanel account created successfuly.' . PHP_EOL;


            $domains = $xml->domain->properties->{'dns-zone'}->{'dnsrec'};
            $isWildcard = 0;
            foreach($domains as $domain){
                if(!empty($domain['src'])){
                    $record = (string)$domain['src'];
                    if(strstr($record, '*.')){
                        $isWildcard = 1;
                    }
                }
            }

            if($isWildcard){
                echo '========================================================================' . PHP_EOL;
                echo 'Wildcard subdomain creating.' . PHP_EOL;
                echo '========================================================================' . PHP_EOL;

                $wildcardCommand = "whmapi1 --output=jsonpretty create_subdomain domain='*.{$dbData['domain']}' .  document_root='public_html/'" . $output;
                $wildcardOutput = shell_exec($wildcardCommand);
                if(strstr($wildcardOutput, '"result" : 1')){
                    echo 'Wildcard subdomain created for ' . $dbData['domain'] . PHP_EOL;
                }

            }

        }
        else{
            echo 'Error; ' . PHP_EOL;
            print_r($cPanelAccountStatus);
        }


        if(!empty($dbName) && !empty($dbUser) && !empty($dbPass)){
            echo '========================================================================' . PHP_EOL;
            echo 'Database and Database user creating.' . PHP_EOL;
            echo '========================================================================' . PHP_EOL;
            $createDbUserOutput = shell_exec("uapi --output=jsonpretty --user={$cPanelUserName} Mysql create_user name='{$dbUser}' password='{$dbPass}'");
            if(strstr($createDbUserOutput, '"status" : 1')){
                echo 'MySQL user created as ' . $dbUser. PHP_EOL;
                echo 'Database creating...' . PHP_EOL;

                $createDatabaseStatus = shell_exec("uapi --output=jsonpretty --user={$cPanelUserName} Mysql create_database name='{$dbName}'");

                if(strstr($createDatabaseStatus, '"status" : 1')){
                    echo 'MySQL database created as ' . $dbName. PHP_EOL;;
                    echo 'Giving ALL Privileges between ' . $dbName . ' and ' . $dbUser;

                    $privilegesStatus = shell_exec("uapi --output=jsonpretty --user={$cPanelUserName} Mysql set_privileges_on_database user='{$dbUser}' database='{$dbName}' privileges='ALL PRIVILEGES'");
                    if(strstr($privilegesStatus, '"status" : 1')){
                        echo 'Privileges ok' . PHP_EOL;
                        echo 'Installing zstd for extract Plesk files.'. PHP_EOL;

                        shell_exec('sudo yum install zstd -y');

                        echo 'Database file importing to ' . $dbName . PHP_EOL;

                        $databaseLocation = $currentBackupDir . '/databases/*/*.tzst';
                        $databaseFolders = glob($databaseLocation);
                        if(is_array($databaseFolders) && !empty($databaseFolders) && isset($databaseFolders[0])){
                            foreach($databaseFolders as $dbFolder){
                                $locationExplode = explode('/', rtrim($dbFolder, '/'));
                                $withExtension = end($locationExplode);
                                $extractLocation = str_replace($withExtension, null, rtrim($dbFolder , '/'));

                                $extractCommand = 'zstd -d -c ' . $dbFolder . ' | tar -xvf - -C ' . $extractLocation . $output;
                                $extractOutput = shell_exec($extractCommand);
                                $fullPathWithoutExtension = str_replace('.tzst', null, $dbFolder);

                                if(file_exists($fullPathWithoutExtension)){

                                    $importCommand = "mysql -u {$dbUser} -p'{$dbPass}' {$dbName} < {$fullPathWithoutExtension}" . $output;
                                    $importOutput = shell_exec($importCommand);
                                    print_r($importOutput);

                                }else{
                                    echo 'An error occured while extract database.' . PHP_EOL;
                                }

                            }
                        }else{
                            echo 'There is no any database file for import. Please check this location: ' . $databaseLocation . PHP_EOL;
                        }
                    }

                }else{
                    echo 'An error occured while creating databse user;' . PHP_EOL;
                    print_r($createDatabaseStatus);
                }

            }
            else{
                echo 'An error occured while creating databse user;' . PHP_EOL;
                print_r($createDbUserOutput);
            }
        }
        else{
            echo PHP_EOL . 'Database will not create because database credentials empty.' . PHP_EOL;
        }


        if(count(glob($currentBackupDir . '/backup_user-data_*.tzst')) > 0){
            echo '========================================================================' . PHP_EOL;
            echo 'Website files moving to public_html' . PHP_EOL;
            echo '========================================================================' . PHP_EOL;
            $siteFilesDir = $currentBackupDir . '/siteFiles';
            shell_exec('mkdir ' . $siteFilesDir . $output);
            shell_exec('mv ' . $currentBackupDir . '/backup_user-data_*.tzst ' . $siteFilesDir);

            $siteExtractCommand = 'zstd -d -c ' . $siteFilesDir . '/backup_user-data_*.tzst | tar -xvf - -C ' . $siteFilesDir . $output;
            $siteExtractOutput = shell_exec($siteExtractCommand);
            print_r($siteExtractOutput);

            $httpDocsPattern = $siteFilesDir . '/httpdocs/{*,.[!.]*,..?*}';
            $httpDocs = glob($httpDocsPattern, GLOB_BRACE);
            foreach($httpDocs as $siteFile){
                $siteMoveOutput = shell_exec('rsync -av --remove-source-files -r ' . $siteFile . ' /home/' . $cPanelUserName . '/public_html' . $output);
            }

            echo '========================================================================' . PHP_EOL;
            echo 'Changing file permissions and owner.' . PHP_EOL;
            echo '========================================================================' . PHP_EOL;
            shell_exec("chown -R {$cPanelUserName}:{$cPanelUserName} /home/{$cPanelUserName}/public_html/*");
            shell_exec("chown -R {$cPanelUserName}:{$cPanelUserName} /home/{$cPanelUserName}/public_html/.*");
            shell_exec("chmod 750 /home/{$cPanelUserName}/public_html");
            shell_exec("chown {$cPanelUserName}:nobody /home/{$cPanelUserName}/public_html");
            echo 'chown and chmod ok.' . PHP_EOL;
        }
        else{
            echo PHP_EOL . 'Files will not move because there is no backup_user-data_*.tzst file' . PHP_EOL;
        }

    }else{
        echo PHP_EOL . 'There is no XML file.' . PHP_EOL;
    }

}

echo shell_exec('whmapi1 set_tweaksetting key=database_prefix value=1');
