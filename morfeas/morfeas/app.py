import json
import os
import xml.dom.minidom
import xmltodict
import datetime as dt
from dicttoxml import dicttoxml
from typing import Dict
from flask import Flask, send_from_directory, request, jsonify
from flask_gzip import Gzip
from typing import Dict
from pathlib import Path
from xml.parsers.expat import ExpatError
from devices.sensors import sensors
from network_settings.network_settings import network_settings
from logs.logs import logs
from config.config import config
from common.common import common
from lxml import etree

app = Flask(__name__)
Gzip(app)

app.config.from_json("../config.json", silent=False)
app.register_blueprint(sensors)
app.register_blueprint(network_settings)
app.register_blueprint(logs)
app.register_blueprint(config)
app.register_blueprint(common)

@app.route('/get_opc_ua_configs', methods=['GET'])
def get_opc_ua_configs():
    opc_ua_configs = read_xml_file(app.config['OPC_UA_CONFIG_FILE'])
    
    if (opc_ua_configs):
        if (opc_ua_configs[app.config['OPC_UA_XML_ROOT_ELEM']] is not None):
            sensors = opc_ua_configs[app.config['OPC_UA_XML_ROOT_ELEM']][app.config['OPC_UA_XML_CHANNEL_ELEM']]
            
            # Create a list if the config file contains only one configured sensor
            if (type(sensors) is not list or len(sensors) == 1):
                sensors = [sensors]
            
            return jsonify(sensors)

    return jsonify()

# curl -X POST localhost:5000/api/save_config -d '{"unit":"valueaaaa"}' 
# -H 'Content-Type: application/json'
@app.route('/save_opc_ua_configs', methods=['POST'])
def save_opc_ua_configs():
    canbus_data = request.get_json()
    
    if (canbus_data == None or len(canbus_data) == 0):
        return jsonify(success=False), 400

    formatted_canbus_data = format_canbus_data(canbus_data)
    
    prettyxml = parse_xml(formatted_canbus_data)

    dtd = etree.DTD(os.path.join(app.config['CONFIG_PATH'], 'Morfeas.dtd'))
    etree_xml = etree.XML(prettyxml)

    if(dtd.validate(etree_xml) == False):
        return jsonify('OPC UA file validation against Morfeas.dtd failed'), 500

    if (prettyxml):
        with open(os.path.join(app.config['CONFIG_PATH'], app.config['OPC_UA_CONFIG_FILE']), 'w+', encoding="utf-8") as xml_file:
            xml_file.write(prettyxml)
        return jsonify(success=True), 200

    return jsonify(success=False), 500

@app.route('/get_iso_codes_by_unit', methods=['GET'])
def get_iso_codes_by_unit():
    all = read_xml_file(app.config['ISO_STANDARD_FILE'])
    result = []

    for iso_code, props in all['root']['points'].items():
        result.append({'iso_code': iso_code, 'attributes': props})

    unit = request.args.get('unit')
    if not unit:
        return jsonify(result)
    
    filteredResult = list(filter(lambda x: x.get('attributes').get('unit') is unit, result))
    return jsonify(filteredResult)

def parse_xml(data):
    sensor_item = lambda x: app.config['OPC_UA_XML_CHANNEL_ELEM']
    xml_data = dicttoxml(
        data, 
        custom_root=app.config['OPC_UA_XML_ROOT_ELEM'], 
        attr_type=False, 
        item_func=sensor_item
    ).decode('utf-8')
    
    position_to_add_doctype = xml_data.find('>')

    if (position_to_add_doctype != -1):
        xml_data = \
            xml_data[:position_to_add_doctype+1] + \
            app.config['OPC_UA_XML_DOCTYPE'] + \
            xml_data[position_to_add_doctype+1:]
    
    try: 
        dom = xml.dom.minidom.parseString(xml_data)
    except ExpatError:
        return
    return dom.toprettyxml() # format xml convention

def format_canbus_data(canbus_data):
    result = []

    for row in canbus_data:
        sensor = {}

        # TODO: Check what is required for valid OPC UA conf and validate.
        # Show error in client if the input is not valid
        if ('isoCode' in row and row['isoCode'] is not None):
            sensor['ISO_CHANNEL'] = row['isoCode']
            sensor['INTERFACE_TYPE'] = row['type']
            sensor['ANCHOR'] = row['anchor']
            sensor['DESCRIPTION'] = row['description']
            sensor['MIN'] = row['minValue']
            sensor['MAX'] = row['maxValue']
            sensor['UNIT'] = row['channelUnit']
            if(sensor['INTERFACE_TYPE'] != 'SDAQ' and row['calibrationDate'] is not None):
                sensor['CAL_DATE'] = dt.datetime.utcfromtimestamp(row['calibrationDate']).strftime("%Y/%m/%d")
                sensor['CAL_PERIOD'] = row['calibrationPeriod']
            result.append(dict(sensor))
    return result

def read_xml_file(file_name):
    try: 
        f = open(os.path.join(app.config['CONFIG_PATH'], file_name))
        with f as xml_file:
            try:
                data = xmltodict.parse(xml_file.read(), xml_attribs=False)
            except xmltodict.expat.ExpatError:
                return

        return data
    except FileNotFoundError:
        print("File doesn't exist.")

if __name__ == '__main__':
    app.run(debug=app.config['DEBUG'])
