import json
import xml.dom.minidom
import xmltodict
from dicttoxml import dicttoxml
from typing import Dict
from flask import Flask, send_from_directory, request, jsonify
from typing import Dict
from flask_cors import CORS
from pathlib import Path
from xml.parsers.expat import ExpatError

from global_enums import OPC_UA_REQUIRED_FIELDS

app = Flask(__name__)
CORS(app)
config: Dict[str, str]

@app.route('/ramdisk/<path:path>')
def send_ramdisk_folder(path):
    return send_from_directory(config['ramdisk_path'], path)

@app.route('/api/get_opcua_configs', methods=['GET'])
def get_opcua_configs():
    opc_ua_configs = read_xml_file('opc_ua.xml')

    if (opc_ua_configs):
        return jsonify(opc_ua_configs)

    return jsonify()

# curl -X POST localhost:5000/api/save_config -d '{"unit":"valueaaaa"}' -H 'Content-Type: application/json'
@app.route('/api/save_opc_ua_configs', methods=['POST'])
def save_opc_ua_configs():
    canbus_data = request.get_json()

    if (canbus_data == None or len(canbus_data) == 0):
        return jsonify(success=False), 400

    formatted_canbus_data = format_canbus_data(canbus_data)

    prettyxml = parse_xml(formatted_canbus_data)

    if (prettyxml):
        with open(config['ramdisk_path'] + config['opc_ua_config_file'], 'w+') as xml_file:
            xml_file.write(prettyxml)
        return jsonify(success=True), 200

    return jsonify(success=False), 500

def parse_xml(data):
    sensor_item = lambda x: 'sensor'
    xml_data = dicttoxml(data, custom_root='root', attr_type=False, item_func=sensor_item)
    try: 
        dom = xml.dom.minidom.parseString(xml_data)
    except ExpatError:
        return
    return dom.toprettyxml() # format xml convention

def format_canbus_data(canbus_data):
    result = []
    sensor = {}

    for row in canbus_data:
        if (OPC_UA_REQUIRED_FIELDS.ANCHOR and 'sdaqSerial' in row and 'channelId' in row):
            sensor['anchor'] = str(row['sdaqSerial']) + '.' + str(row['channelId'])

        if (OPC_UA_REQUIRED_FIELDS.ISO_CODE and 'isoCode' in row):
            sensor['ISO_code'] = row['isoCode']

        if (OPC_UA_REQUIRED_FIELDS.MIN_VALUE and 'minValue' in row):
            sensor['min_value'] = row['minValue']

        if (OPC_UA_REQUIRED_FIELDS.MAX_VALUE and 'maxValue' in row):
            sensor['max_value'] = row['maxValue']

        if (OPC_UA_REQUIRED_FIELDS.DESCRIPTION and 'description' in row):
            sensor['description'] = row['description']

        result.append(dict(sensor))

    return result

def read_xml_file(file_name):
    try: 
        f = open(config['ramdisk_path'] + file_name)
        with f as xml_file:
            try:
                data = xmltodict.parse(xml_file.read())
            except xmltodict.expat.ExpatError:
                return

        return data
    except FileNotFoundError:
        print("File doesn't exist.")

def init_config():
    root_path = Path(app.root_path)
    with open(root_path.parent/'config.json') as config_file:
        return json.load(config_file)

if __name__ == '__main__':
    config = init_config()
    app.run(debug=config['debug'])
