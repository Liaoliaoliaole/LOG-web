import json
from typing import Dict
from flask import Flask, send_from_directory, request, jsonify
from flask_cors import CORS
from pathlib import Path

app = Flask(__name__)
CORS(app)
config: Dict[str, str]

@app.route('/ramdisk/<path:path>')
def send_ramdisk_folder(path):
    return send_from_directory(config['ramdisk_path'], path)

@app.route('/api/save_config', methods=['POST'])
def save_opc_ua_config():
    json_data = request.json

    if ('unit' in json_data):
        print(json_data['config'])

    return jsonify()

def init_config():
    root_path = Path(app.root_path)
    with open(root_path.parent/'config.json') as config_file:
        return json.load(config_file)

if __name__ == '__main__':
    config = init_config()
    app.run(debug=config['debug'])
