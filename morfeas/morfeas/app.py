import json
from dicttoxml import dicttoxml
from typing import Dict
from flask import Flask, send_from_directory, request, jsonify
from flask_cors import CORS
from pathlib import Path
from xml.parsers.expat import ExpatError
import xml.dom.minidom

app = Flask(__name__)
CORS(app)
config: Dict[str, str]

@app.route('/ramdisk/<path:path>')
def send_ramdisk_folder(path):
    return send_from_directory(config['ramdisk_path'], path)

# curl -X POST localhost:5000/api/save_config -d '{"unit":"valueaaaa"}' -H 'Content-Type: application/json'
@app.route('/api/save_opc_ua_configs', methods=['POST'])
def save_opc_ua_configs():
    canbus_data = request.get_json()

    for sensor in canbus_data:
        sensor['anchor'] = str(sensor['sdaqSerial']) + '.' + str(sensor['channelId'])
        del sensor['sdaqSerial']
        del sensor['channelId']
        del sensor['canBus']
        del sensor['sdaqType']

    prettyxml = parse_xml(canbus_data)

    if (prettyxml):
        with open(config['ramdisk_path'] + 'opc_ua_config.xml', 'w+') as xml_file:
            xml_file.write(prettyxml)
        return app.response_class(
            status=200,
            response=json.dumps({'result':'configs saved'}),
            mimetype='application/json'
        )

    return app.response_class(
        status=500,
        response=json.dumps({'result':'data malformed'}),
        mimetype='application/json'
    )

def parse_xml(data):
    sensor_item = lambda x: 'sensor'
    xml_data = dicttoxml(data, custom_root='root', attr_type=False, item_func=sensor_item)
    try: 
        dom = xml.dom.minidom.parseString(xml_data)
    except ExpatError:
        return
    return dom.toprettyxml() # format xml convention

def init_config():
    root_path = Path(app.root_path)
    with open(root_path.parent/'config.json') as config_file:
        return json.load(config_file)

if __name__ == '__main__':
    config = init_config()
    app.run(debug=config['debug'])
