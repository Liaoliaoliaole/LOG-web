import subprocess
import functools
from flask import Blueprint

common = Blueprint('common', __name__)

def execute_command(command):

    returnvalue = dict()

    try:
        try_command = command
        pipe = subprocess.Popen(try_command, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        output = pipe.communicate()

        returncode = pipe.returncode
        stdout = output[0].decode("utf-8", errors="ignore").strip()
        stderr = output[1].decode("utf-8", errors="ignore").strip()
        
        returnvalue['stdout'] = stdout
        returnvalue['stderr'] = stderr

        return returnvalue
    except Exception as e:
        returnvalue['stderr'] = str(e)
        return returnvalue

# Get the dictionary values safely, returns null if not found
def deep_get(dictionary, *keys):
    return functools.reduce(lambda d, key: d.get(key, None) if isinstance(d, dict) else None, keys, dictionary)