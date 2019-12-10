import json
from typing import Dict
from flask import Flask, send_from_directory
from flask_cors import CORS
from pathlib import Path

app = Flask(__name__)
CORS(app)
config: Dict[str, str]

@app.route('/ramdisk/<path:path>')
def send_ramdisk_folder(path):
    return send_from_directory(config['ramdisk_path'], path)

def init_config():
    root_path = Path(app.root_path)
    with open(root_path.parent/'config.json') as config_file:
        return json.load(config_file)

if __name__ == '__main__':
    config = init_config()
    app.run(debug=config['debug'])
