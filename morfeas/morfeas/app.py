#!/usr/bin/env python
import json
from typing import Dict
from flask import Flask
from flask_socketio import SocketIO
from watchdogs.single_file import EmitFileContentsOnChangeDaemon


def init_config() -> Dict[str, str]:
    with open('morfeas/config.json') as config:
        return json.load(config)


CONFIG = init_config()
APP = Flask(__name__)
APP.config['SECRET_KEY'] = CONFIG['flask_secret']
SOCKET_IO = SocketIO(APP, cors_allowed_origins="*")
THREADS = None

def init_file_watchers() -> None:
    global THREADS
    if THREADS is None:
        THREADS = [
            SOCKET_IO.start_background_task(
                target=EmitFileContentsOnChangeDaemon, socketio=SOCKET_IO,
                path='../ramdisk/can0.json', event_name='can_data'),
        ]


def on_init() -> None:
    init_file_watchers()


if __name__ == "__main__":
    on_init()
    SOCKET_IO.run(APP, debug=True)
