import json
import xmltodict
from typing import Dict
from flask import Flask, send_from_directory, jsonify
from flask_cors import CORS
from pathlib import Path

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
