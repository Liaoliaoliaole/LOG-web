#!/usr/bin/env python
from flask import Flask
from flask_socketio import SocketIO

app = Flask(__name__)
socketio = SocketIO(app)

@app.route('/')
def index():
    return "Morfeas"

if __name__ == "__main__":
    socketio.run(app, debug=True)

