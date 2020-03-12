import os
from flask import Blueprint, jsonify, make_response
from flask import current_app
from flask import Flask, request, jsonify

logs = Blueprint('logs', __name__)

@logs.route('/logs', methods=['GET'])
def get_log_list():
    result = dict()
    # TODO: find out whether this can change and whats would be a stable place to find this out
    #       right now its an alias in morfeas_web.conf in apache sites-available but who knows what the user might configure??
    #       could not have an alias or could have a different alias or anything really so where to find out what it is
    #       or whether it can even ever change
    #       doing current_app.config['RAMDISK_PATH'] gives /mnt/ramdisk/ and the client will then try to read api_root/mnt/ramdisk/
    #       which wont work because of the alias
    path = 'ramdisk/Morfeas_Loggers/'
    result['logPath'] = path
    dirs = os.listdir(current_app.config['RAMDISK_PATH'] + 'Morfeas_Loggers/')
    dirs.sort()
    result['logList'] = dirs
    return jsonify(result)