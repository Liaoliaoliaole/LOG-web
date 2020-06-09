from os import listdir
from os.path import isfile, join
import json
from flask import Blueprint, jsonify, make_response
from flask import current_app
from typing import List

sensors = Blueprint('sensors', __name__)

@sensors.route('/sensors', methods=['GET'])
def get_logstat_content():
    path = current_app.config["RAMDISK_PATH"]
    content = dict()
    content['logstats_names'] = []
    content['logstat_contents'] = []
    logstatFiles = [f for f in listdir(path) if isfile(join(path, f))]
    for logstatFile in logstatFiles:
        with open(join(path, logstatFile), 'r', encoding="utf-8") as logstatJsonFile:
            if "logstat" in logstatFile:
                content['logstats_names'].append(logstatFile)
                content['logstat_contents'].append(json.load(logstatJsonFile))
    r = make_response(json.dumps(content))
    r.mimetype = 'application/json'
    return r