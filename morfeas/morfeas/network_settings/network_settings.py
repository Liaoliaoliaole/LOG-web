import subprocess
import socket
import struct
import json
import re
from flask import Blueprint, jsonify, make_response
from flask import current_app
from flask import Flask, request, jsonify

network_settings = Blueprint('network', __name__)

@network_settings.route('/settings/network/interfaces', methods=['POST'])
def save_network_settings():
    
    interface_data = request.get_json()

    ipAddressRegexp = r"^(([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.){3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])$"
    subnetMaskRegexp = r"^(((255\.){3}(255|254|252|248|240|224|192|128|0+))|((255\.){2}(255|254|252|248|240|224|192|128|0+)\.0)|((255\.)(255|254|252|248|240|224|192|128|0+)(\.0+){2})|((255|254|252|248|240|224|192|128|0+)(\.0+){3}))$"

    for interface in interface_data:
        if not re.match(ipAddressRegexp, interface['ipAddress']):
            return jsonify("Invalid IP Address format"), 500
        if not re.match(subnetMaskRegexp, interface['subnetMask']) and interface['subnetMask'] != "":
            return jsonify("Invalid Subnet mask format"), 500
        if not re.match(ipAddressRegexp, interface['defaultGateway'])  and interface['defaultGateway'] != "":
            return jsonify("Invalid Default Gateway format"), 500

    newlines = []

    try:
        with open('/etc/network/interfaces', 'r') as interface_file:
            lines = interface_file.readlines()
            config_writing = False
            config_started = False
            lines.append("EOF")
            for line in lines:
                if(line.strip() == "#Configure from web start" or (line == "EOF" and config_started == False)):
                    newlines.append("#Configure from web start\n")
                    config_writing = True
                    for interface in interface_data:
                        newlines.append("auto " + interface['interface'])
                        newlines.append("\niface " + interface['interface'] + " inet static")
                        newlines.append("\n	address " + interface['ipAddress'])
                        if(interface['subnetMask'] != ""):
                            newlines.append("\n	netmask " + interface['subnetMask'])
                        if(interface['defaultGateway'] != ""):
                            newlines.append("\n	gateway " + interface['defaultGateway'])
                        newlines.append("\n")
                    if(line == "EOF" and config_started == False):
                        newlines.append("#Configure from web end\n")
                    config_started = True
                if(line.strip() == "#Configure from web end"):
                    config_writing = False
                if(config_writing == False and line != "EOF"):
                    newlines.append(line)
            interface_file.close()
    except Exception as e:
        return jsonify(str(e)), 500

    try:
        with open('/etc/network/interfaces', 'w') as interface_file:
            interface_file.writelines(newlines)
            interface_file.close()
    except Exception as e:
        return jsonify(str(e)), 500

    try:
        restart_command = 'sudo systemctl restart networking'
        pipe = subprocess.Popen(restart_command, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        output = pipe.communicate()
        returncode = pipe.returncode
        stdout = output[0].decode("utf-8", errors="ignore").strip()
        stderr = output[1].decode("utf-8", errors="ignore").strip()
        if(stderr != ""):
            return jsonify(stderr), 500
    except Exception as e:
        return jsonify(str(e)), 500

    # the program will not be able to send this json success payload if its actually successful in restarting the networking system as the internet connection cuts off
    # this means that the web ui should just pray it actually worked if there are no immediate errors being thrown by the previous returns
    return jsonify(success=True), 200

# https://stackoverflow.com/a/23352055
def cidr_to_mask(prefix):
    return socket.inet_ntoa(struct.pack(">I", (0xffffffff << (32 - prefix)) & 0xffffffff))

@network_settings.route('/settings/network/interfaces', methods=['GET'])
def get_network_settings():

    # https://unix.stackexchange.com/a/383532 modified a bit
    interface_command = 'ip -o -4 addr show | awk \'{print $2": "$4}\' | grep -v "lo"'
    # https://stackoverflow.com/a/1226395 NOTE: might want to find a better way for this because might have more than one default gateway (or none at all???)
    default_gateway_command = 'ip route | awk \'/default/ { print $3 }\''

    pipe = subprocess.Popen(interface_command, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    output = pipe.communicate()

    returncode = pipe.returncode
    stdout = output[0].decode("utf-8", errors="ignore").strip()
    stderr = output[1].decode("utf-8", errors="ignore").strip()

    pipe2 = subprocess.Popen(default_gateway_command, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    output2 = pipe2.communicate()

    stdout2 = output2[0].decode("utf-8", errors="ignore").strip()
    stderr2 = output2[1].decode("utf-8", errors="ignore").strip()

    result = dict()
    result['retcode'] = returncode
    result['interfaces'] = []

    if(stderr == "" and stderr2 == ""):
        interface_strings = stdout.split('\n')
        for index, value in enumerate(interface_strings):
            interface_split = value.split(':')
            interface = dict()
            interface['interface'] = interface_split[0]
            interface['ipAddress'] = interface_split[1].split('/')[0].strip()
            interface['subnetMask'] = cidr_to_mask(int(interface_split[1].split('/')[1]))
            interface['defaultGateway'] = stdout2
            result['interfaces'].append(interface)

    result['error'] = stderr

    if(stderr2 != ""):
        result['error'] += "\n" + stderr2

    resultJSON = json.dumps(result)

    if(result['error'] == ""):
        return resultJSON, 200
    else:
        return resultJSON, 500
