from os import listdir
from os.path import isfile, join
import json
from flask import Blueprint, jsonify, make_response
from flask import current_app
from devices.sdaq_parser import SdaqLogParser
from devices.models.device import ConnectedDevicesDTO
from typing import List
import jsonpickle

devices = Blueprint('devices', __name__)

@devices.route('/devices', methods=['GET'])
def get_logstat_content():
    path = current_app.config["CONFIG_PATH"]
    content = ConnectedDevicesDTO()
    logstatFiles = [f for f in listdir(path) if isfile(join(path, f))]
    for logstatFile in logstatFiles:
        with open(join(path, logstatFile), 'r') as logstatJsonFile:
            if logstatFile.startswith("logstat_can"):
                logstatData = json.load(logstatJsonFile)
                parser = SdaqLogParser(logstatData)
                content.devices += parser.parse_devices()
                content.connections.append(parser.parse_connections())
    r = make_response(jsonpickle.encode(content, unpicklable=False))
    r.mimetype = 'application/json'
    return r