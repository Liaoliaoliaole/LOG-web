import os
import threading
from watchdog.observers import Observer
from watchdog.events import PatternMatchingEventHandler
from flask_socketio import SocketIO

def add_watchdog(folder_path, event_handler, recursive=False):
    observer = Observer()
    observer.schedule(event_handler, folder_path, recursive=recursive)
    observer.start()

class EmitFileContentsOnChangeDaemon(threading.Thread):
    """ Emits all file contents when the file is updated to given websocket event"""
    def __init__(self, socketio: SocketIO, path: str, event_name: str):
        threading.Thread.__init__(self)
        self.daemon = True
        self.socketio = socketio
        self.event_name = event_name
        self.folder_path = os.path.dirname(path)
        match_single_file_pattern = '*' + os.path.basename(path)
        print(self.folder_path + ' ' + match_single_file_pattern)
        handler = PatternMatchingEventHandler(
            patterns=[match_single_file_pattern])
        handler.on_modified = self.on_file_update
        self.handler = handler
        self.start()

    def run(self):
        print('starting thread ' + self.name)
        add_watchdog(self.folder_path, self.handler, False)

    def on_file_update(self, event):
        """ Override for the onUpdate handler in watchdog"""
        with open(event.src_path) as file:
            self.socketio.emit(self.event_name, file.read(), namespace='/')
