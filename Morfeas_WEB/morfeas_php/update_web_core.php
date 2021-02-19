<?php
require("../Morfeas_env.php");
$requestType = $_SERVER['REQUEST_METHOD'];
$postdata = file_get_contents("php://input");
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
    if ($request->update == 'web') {
        $output = shell_exec('
        cd /var/www/html/morfeas_web/
        git pull http://' . $username . ':' . $password . '@' . $localServerAddressWeb . ' ' . $morfeasWebBranchName . ' 2>&1
        git pull http://' . $username . ':' . $password . '@' . $localServerAddressPeclDbus . ' master --allow-unrelated-histories 2>&1');
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
        $makeOutput = shell_exec('cd pecl-dbus 2>&1
        phpize 2>&1
        ./configure 2>&1
        make -j$(nproc) 2>&1
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
        $pullCommand = '
        cd /opt/Morfeas_project/Morfeas_core
        git reset --hard HEAD
        git pull http://' . $username . ':' . $password . '@' . $localServerAddressCore . ' master --allow-unrelated-histories 2>&1
        cd /opt/Morfeas_project/Morfeas_core/src/cJSON
        git reset --hard HEAD
        git pull http://' . $username . ':' . $password . '@' . $localServerAddress_cJSON . ' master --allow-unrelated-histories 2>&1
        cd /opt/Morfeas_project/Morfeas_core/src/open62541
        git reset --hard HEAD
        git pull http://' . $username . ':' . $password . '@' . $localServerAddress_open62541 . ' master --allow-unrelated-histories 2>&1
        cd /opt/Morfeas_project/Morfeas_core/src/sdaq-worker
        git reset --hard HEAD
        git pull http://' . $username . ':' . $password . '@' . $localServerAddress_sdaq_worker . ' master --allow-unrelated-histories 2>&1';
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
        cd /opt/Morfeas_project/Morfeas_core
        cd src/cJSON/build 2>&1
        echo "installing cJSON."
        make clean 2>&1
        make -j$(nproc) 2>&1
        sudo make install 2>&1
        cd ../../..
        cd src/open62541/build 2>&1
        echo "installing open62541."
        make clean 2>&1
        make -j$(nproc) 2>&1
        sudo make install 2>&1
        cd ../../..
        cd src/sdaq-worker 2>&1
        echo "installing sdaq worker."
        make clean 2>&1
        make tree 2>&1
        make -j$(nproc) 2>&1
        sudo make install 2>&1
        cd ../..
        echo "installing core."
        make clean 2>&1
        make tree 2>&1
        make -j$(nproc) 2>&1
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
    } else {
        echo '{"message":"Not supported"}';
    }
}
