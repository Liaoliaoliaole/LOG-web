import json
import xml.dom.minidom
import xmltodict
from dicttoxml import dicttoxml
from typing import Dict
from flask import Flask, send_from_directory, request, jsonify
from typing import Dict
from pathlib import Path
from xml.parsers.expat import ExpatError
from devices.devices import devices

app = Flask(__name__)
app.config.from_json("../config.json", silent=False)
app.register_blueprint(devices)

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

    if (prettyxml):
        with open(
            app.config['RAMDISK_PATH'] + app.config['OPC_UA_CONFIG_FILE'], 
            'w+'
        ) as xml_file:
            xml_file.write(prettyxml)
        return jsonify(success=True), 200

    return jsonify(success=False), 500

@app.route('/get_iso_codes_by_unit', methods=['GET'])
def get_iso_codes_by_unit():
    if (request.args.get('unit')):
        unit = request.args.get('unit')
        
        all = read_xml_file(app.config['ISO_STANDARD_FILE'])
        result = []

        for iso_code, props in all['root']['points'].items():
            if  (unit == props['unit']):
                item = dict()
                item['iso_code'] = iso_code
                item['attributes'] = props
                result.append(item)
    
    return jsonify(result)

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

        if ('isoCode' in row and row['isoCode'] is not None):
            sensor['ISO_CHANNEL'] = row['isoCode']

            # TODO: to be dynamic, hard-coded for now - 10.01.2020
            sensor['INTERFACE_TYPE'] = 'SDAQ'
            
            try:
                if (row['sdaqSerial'] is not None and
                    row['channelId'] is not None and
                    row['description'] is not None and
                    row['minValue'] is not None and
                    row['maxValue'] is not None):
                    sensor['ANCHOR'] = '{}.CH{}'.format(
                        str(row['sdaqSerial']), 
                        str(row['channelId'])
                    )
                    sensor['DESCRIPTION'] = row['description']
                    sensor['MIN'] = row['minValue']
                    sensor['MAX'] = row['maxValue']

                    result.append(dict(sensor))
            except KeyError:
                print('One or more fields are empty/None')


    return result

def read_xml_file(file_name):
    try: 
        f = open(app.config['RAMDISK_PATH'] + file_name)
        with f as xml_file:
            try:
                data = xmltodict.parse(xml_file.read())
            except xmltodict.expat.ExpatError:
                return

        return data
    except FileNotFoundError:
        print("File doesn't exist.")

if __name__ == '__main__':
    app.run(debug=app.config['DEBUG'])
