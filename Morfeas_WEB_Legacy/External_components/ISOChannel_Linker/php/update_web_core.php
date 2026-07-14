<?php
/*
File: update_web_core.php PHP Script. External part for the Morfeas_project.
Copyright (C) 12019-12021  skycode

	This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as
    published by the Free Software Foundation, either version 3 of the
    License, or any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

$instructionsForUse = "
To make the update buttons work, you need to do these following tasks:
Here is the list of projects you need to have on your local git server:
cJSON
morfeas_core
morfeas_web
open62541
pecl-dbus
sdaq-worker
Alternatively, you can use the update online -choice, however, a working internet connection is required in this case.

1. Set up a local git server with user and password access. We used Bonobo Git Server, and highly recommend it. Here is a link to a good video tutorial on installing the server: https://youtu.be/S3Dm2Z-hc1o.
2. After installing the git server, in the server settings, \"allow push to create repositories\". This will let you add the git projects to the server.
3. Create a user for the git server. Then add the username and password to the config file in \"/home/morfeas/configuration/Update_config.xml\". This will let the update script to use this account for pulling the files from the server.
4. When the computer has access to the internet, clone the relevant projects from Gitlab to an arbitrary folder. Then add the local server as remote and push the projects into the local server. You can then keep these folders up to date and push the changes when necessary to the local server.
If you are not yet familiar with this process, here is a simple guide for cJSON:

cd cJSON\
git checkout master
# then you can check the remotes with \"git remote -v\"
# origin  https://github.com/DaveGamble/cJSON.git (fetch)
# origin  https://github.com/DaveGamble/cJSON.git (push)
git remote add local_server http://192.168.1.109/Bonobo.Git.Server/cJSON_local.git
# do note that the ip needs to always match the local server's ip in order to work.
# to update, just push to the local server's master:
git push local_server master

5. Add the addresses of the local server to the \"/home/morfeas/configuration/Update_config.xml\".
<LOCAL_SERVER_ADDRESS_WEB>192.168.1.109/Bonobo.Git.Server/morfeas_web_local.git</LOCAL_SERVER_ADDRESS_WEB> # note that there is no \"http://\" at the beginning. this is omitted as the update script pulls from addresses like: http://username:password@address
Also, you can specify which branch you will update.
6. Then you need to adjust the permissions. As the php running the update script (user called www-data) does not have enough permissions to access certain areas of the device, you need to let these areas to be accessible for www-data. In our testing, the web and core folders had restrictions for www-data. This can be countered by giving access to modify these areas. You need let the files that give errors be writable.
7. You need to add \"your\" name to the git config in the \".git\" folders in the web and core projects. Just add
[user]
	name = morfeas
	email = morfeas@LOG
 
8. You also need to add sudo permissions to the \"make install\" command. Add this line to sudoers:
 
cd /etc/sudoers.d/
And use \"sudo visudo Morfeas_web_allow\" to add this line:
                                /usr/bin/make install,\

This will allow the update script to properly make the installations.

The update buttons have some degree of error detection, but it is still recommended to check the shell output during the first updates. The browser will inform if it finds errors during the updating process and write the shell output to the browser console. On Chrome browser, the console can be accessed by holding control, shift and pressing \"i\", and then opening the console tab.
 
To keep the local server up to date, you only need to have the git projects up to date on a computer with access to your local server, and just pushing the updated projects to the local server remote. Then the update script can automatically pull and install the required updates.
";

/* 
Example of Update_config.xml:

<?xml version="1.0" encoding="UTF-8"?>
<CONFIG>
  <!--<CONFIGS_DIR>/home/pi/Morfeas_proj/Morfeas_core/configuration</CONFIGS_DIR>-->
    <LOCAL_SERVER_INFO>
        <LOCAL_SERVER_ADDRESS_WEB>192.168.1.101/Bonobo.Git.Server/morfeas_web_local.git</LOCAL_SERVER_ADDRESS_WEB>
        <LOCAL_SERVER_ADDRESS_CORE>192.168.1.101/Bonobo.Git.Server/morfeas_core_local.git</LOCAL_SERVER_ADDRESS_CORE>
        <LOCAL_SERVER_ADDRESS_CJSON>192.168.1.101/Bonobo.Git.Server/cJSON_local.git</LOCAL_SERVER_ADDRESS_CJSON>
        <LOCAL_SERVER_ADDRESS_OPEN62541>192.168.1.101/Bonobo.Git.Server/open62541_local.git</LOCAL_SERVER_ADDRESS_OPEN62541>
        <LOCAL_SERVER_ADDRESS_SDAQ_WORKER>192.168.1.101/Bonobo.Git.Server/sdaq-worker_local.git</LOCAL_SERVER_ADDRESS_SDAQ_WORKER>
        <LOCAL_SERVER_ADDRESS_PECL_DBUS>192.168.1.101/Bonobo.Git.Server/pecl-dbus_local.git</LOCAL_SERVER_ADDRESS_PECL_DBUS>
        <MORFEAS_WEB_BRANCH_NAME>skycode</MORFEAS_WEB_BRANCH_NAME>
    </LOCAL_SERVER_INFO>
    <USER_INFO>
        <USERNAME>testuser</USERNAME>
        <PASSWORD>123</PASSWORD>
    </USER_INFO>
</CONFIG>
*/

require("../../../Morfeas_env.php");
require("../../../morfeas_php/Supplementary.php");
$requestType = $_SERVER['REQUEST_METHOD'];
$postdata = file_get_contents("php://input");
//$postdata = decompress($postdata);
$request = json_decode($postdata);
if ($requestType == "POST") {
    $localServerConfigs = json_decode(json_encode(simplexml_load_file($opc_ua_config_dir.'Update_config.xml')));
    $username = $localServerConfigs->USER_INFO->USERNAME;
    $password = $localServerConfigs->USER_INFO->PASSWORD;
    $localServerAddressWeb = $localServerConfigs
    ->LOCAL_SERVER_INFO
    ->LOCAL_SERVER_ADDRESS_WEB;
    $localServerAddressCore = $localServerConfigs
    ->LOCAL_SERVER_INFO
    ->LOCAL_SERVER_ADDRESS_CORE;
    $localServerAddress_cJSON = $localServerConfigs
    ->LOCAL_SERVER_INFO
    ->LOCAL_SERVER_ADDRESS_CJSON;
    $localServerAddress_open62541 = $localServerConfigs
    ->LOCAL_SERVER_INFO
    ->LOCAL_SERVER_ADDRESS_OPEN62541;
    $localServerAddress_sdaq_worker = $localServerConfigs
    ->LOCAL_SERVER_INFO
    ->LOCAL_SERVER_ADDRESS_SDAQ_WORKER;
    $localServerAddressPeclDbus = $localServerConfigs
    ->LOCAL_SERVER_INFO
    ->LOCAL_SERVER_ADDRESS_PECL_DBUS;
    $morfeasWebBranchName = $localServerConfigs
    ->LOCAL_SERVER_INFO
    ->MORFEAS_WEB_BRANCH_NAME;
    if (isset($request->update) && is_null($localServerConfigs)) {
        $response = [
            'errors' => true,
            'message' => 'Update buttons not configured. Instructions are logged to your console.',
            'shell_output' => $instructionsForUse
        ];
        echo json_encode($response);
        return;
    }
    if ($request->update == 'web') {
        if ($request->online == true) {
            $output = shell_exec('
            cd /var/www/html/morfeas_web/ &&
            git pull origin master 2>&1 &&
            cd pecl-dbus/ &&
            git pull origin master --allow-unrelated-histories 2>&1');
        } else {
            $output = shell_exec('
            cd /var/www/html/morfeas_web/ &&
            git pull http://' . $username . ':' . $password . '@' . $localServerAddressWeb . ' ' . $morfeasWebBranchName . ' 2>&1 &&
            cd pecl-dbus/ &&
            git pull http://' . $username . ':' . $password . '@' . $localServerAddressPeclDbus . ' master --allow-unrelated-histories 2>&1');
        }
        if (strpos($output, 'fatal:') !== false) {
            $errorReport = ['shell_output' => $output, 'errors' => true, 'message' => "Fatal errors encountered during git retrieval. Please review the shell output.\n\nThe shell output is logged to your browser's console."];
            echo json_encode($errorReport);
            return;
        }
        if (strpos($output, 'error:') !== false) {
            $errorReport = ['shell_output' => $output, 'errors' => true, 'message' => "Errors encountered during git retrieval. Please review the shell output.\n\nThe shell output is logged to your browser's console."];
            echo json_encode($errorReport);
            return;
        }
        $upToDate = false;
        if ((strpos($output, 'Updating') !== false) == false) {
            $upToDate = true;
        }
        $makeOutput = shell_exec('
        cd /var/www/html/morfeas_web/pecl-dbus 2>&1 &&
        phpize 2>&1 &&
        ./configure 2>&1 &&
        make -j$(nproc) 2>&1 &&
        sudo make install 2>&1
        ');
        $fullOutput = $output . "\n\n" . $makeOutput;
        if (strpos($output, 'Error 1') !== false) {
            $errorReport = ['shell_output' => $fullOutput, 'errors' => true, 'message' => "Fatal errors encountered during installation process. Please review the shell output.\n\nThe shell output is logged to your browser's console."];
            echo json_encode($errorReport);
            return;
        }
        $message = 'Update completed.';
        if ($upToDate) {
            $message = "Update completed.\n\nEverything was already up to date, but installations were still made."; 
        }
        $response = [
            'message' => $message,
            'shell_output' => $fullOutput,
            'errors' => false
        ];
        echo json_encode($response);
    } else if ($request->update == 'core') {
        if ($request->online == true) {
            return;
            $pullCommand = '
            cd /opt/Morfeas_project/Morfeas_core &&
            git reset --hard HEAD &&
            git pull origin master --allow-unrelated-histories 2>&1 &&
            cd /opt/Morfeas_project/Morfeas_core/src/cJSON &&
            git reset --hard HEAD &&
            git pull origin master --allow-unrelated-histories 2>&1 &&
            cd /opt/Morfeas_project/Morfeas_core/src/open62541 &&
            git reset --hard HEAD &&
            git pull origin master --allow-unrelated-histories 2>&1 &&
            cd /opt/Morfeas_project/Morfeas_core/src/sdaq-worker &&
            git reset --hard HEAD &&
            git pull origin master --allow-unrelated-histories 2>&1';
        } else {
            $pullCommand = '
            cd /opt/Morfeas_project/Morfeas_core &&
            git reset --hard HEAD &&
            git pull http://' . $username . ':' . $password . '@' . $localServerAddressCore . ' master --allow-unrelated-histories 2>&1 &&
            cd /opt/Morfeas_project/Morfeas_core/src/cJSON &&
            git reset --hard HEAD &&
            git pull http://' . $username . ':' . $password . '@' . $localServerAddress_cJSON . ' master --allow-unrelated-histories 2>&1 &&
            cd /opt/Morfeas_project/Morfeas_core/src/open62541 &&
            git reset --hard HEAD &&
            git pull http://' . $username . ':' . $password . '@' . $localServerAddress_open62541 . ' master --allow-unrelated-histories 2>&1 &&
            cd /opt/Morfeas_project/Morfeas_core/src/sdaq-worker &&
            git reset --hard HEAD &&
            git pull http://' . $username . ':' . $password . '@' . $localServerAddress_sdaq_worker . ' master --allow-unrelated-histories 2>&1';
        }
        $updateOutput = shell_exec($pullCommand);
        if (strpos($updateOutput, 'fatal:') !== false) {
            $errorReport = ['shell_output' => $updateOutput, 'errors' => true, 'message' => "Fatal errors encountered during git retrieval. Please review the shell output.\n\nThe shell output is logged to your browser's console."];
            echo json_encode($errorReport);
            return;
        }
        if (strpos($updateOutput, 'error:') !== false) {
            $errorReport = ['shell_output' => $updateOutput, 'errors' => true, 'message' => "Errors encountered during git retrieval. Please review the shell output.\n\nThe shell output is logged to your browser's console."];
            echo json_encode($errorReport);
            return;
        }
        $upToDate = false;
        if ((strpos($updateOutput, 'Updating') !== false) == false) {
            $upToDate = true;
        }
        $updateCommand = '
        cd /opt/Morfeas_project/Morfeas_core &&
        cd src/cJSON/build 2>&1 &&
        echo "installing cJSON." &&
        make clean 2>&1 &&
        make -j$(nproc) 2>&1 &&
        sudo make install 2>&1 &&
        cd ../../.. &&
        cd src/open62541/build 2>&1 &&
        echo "installing open62541." &&
        make clean 2>&1 &&
        make -j$(nproc) 2>&1 &&
        sudo make install 2>&1 &&
        cd ../../.. &&
        cd src/sdaq-worker 2>&1 &&
        echo "installing sdaq worker." &&
        make clean 2>&1 &&
        make tree 2>&1 &&
        make -j$(nproc) 2>&1 &&
        sudo make install 2>&1 &&
        cd ../.. &&
        echo "installing core." &&
        make clean 2>&1 &&
        make tree 2>&1 &&
        make -j$(nproc) 2>&1 &&
        sudo make install 2>&1
        ';
        $output = shell_exec($updateCommand);
        $fullOutput = $updateOutput . "\n" . $output;
        if (strpos($output, 'Error 1') !== false) {
            $errorReport = ['shell_output' => $fullOutput, 'errors' => true, 'message' => "Fatal errors encountered during installation process. Please review the shell output.\n\nThe shell output is logged to your browser's console."];
            echo json_encode($errorReport);
            return;
        }
        $systemRestart = shell_exec(
            'sudo systemctl restart Morfeas_system.service 2>&1'
        );
        $message = 'Update completed. Restarting Morfeas system.';
        if ($upToDate) {
            $message = "Update completed. Restarting Morfeas system.\n\nEverything was already up to date, but installations were still made.";
        }
        $response = [
            'errors' => false,
            'message' => $message,
            'shell_output' => $fullOutput
        ];
        echo json_encode($response);
    } else if(!isset($request->update)) {
        echo '{"message":"Parameters required."}';
    } else {
        echo '{"message":"Not supported."}';
    }
} else {
    echo '{"message":"Only POST method is supported."}';
}