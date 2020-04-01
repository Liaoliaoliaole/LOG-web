import os
import subprocess
import xml.dom.minidom
from dicttoxml import dicttoxml
from flask import Blueprint, jsonify, make_response
from flask import current_app
from flask import Flask, request, jsonify
from common.common import execute_command

config = Blueprint('config', __name__)

def component_to_xml(data, name):
    return dicttoxml(data, root=False, ids=False, attr_type=False, item_func=lambda x: name).decode('utf-8')

def append_xml_to_position(position, xml, append):
    return xml[:position+1] + append + xml[position+1:]

@config.route('/morfeas/config', methods=['POST'])
def save_morfeas_config():
    data = request.get_json()

    from app import read_xml_file
    morfeas_config = read_xml_file(current_app.config['MORFEAS_CONFIG_FILE'])

    if(morfeas_config is None):
        return jsonify(success=False), 500

    morfeas_config['CONFIG']['COMPONENTS']['OPC_UA_SERVER']['APP_NAME'] = data['app_name']

    # https://stackoverflow.com/a/43491315
    try:
        del morfeas_config['CONFIG']['COMPONENTS']['SDAQ_HANDLER']
    except KeyError:
        pass
    try:
        del morfeas_config['CONFIG']['COMPONENTS']['MDAQ_HANDLER']
    except KeyError:
        pass
    try:
        del morfeas_config['CONFIG']['COMPONENTS']['IOBOX_HANDLER']
    except KeyError:
        pass
    try:
        del morfeas_config['CONFIG']['COMPONENTS']['MTI_HANDLER']
    except KeyError:
        pass

    # TODO: simplify these at somepoint, eg dont specifically call sdaq mdaq etc just get all the components from the components and use them
    sdaq_xml = component_to_xml(data['components']['sdaq_handlers'], 'SDAQ_HANDLER')
    mdaq_xml = component_to_xml(data['components']['mdaq_handlers'], 'MDAQ_HANDLER')
    iobox_xml = component_to_xml(data['components']['iobox_handlers'], 'IOBOX_HANDLER')
    mti_xml = component_to_xml(data['components']['mti_handlers'], 'MTI_HANDLER')

    xml_data = dicttoxml(
        morfeas_config,
        root=False,
        ids=False,
        attr_type=False,
    ).decode('utf-8')

    xml_data = '<!DOCTYPE CONFIG SYSTEM "Morfeas.dtd">' + xml_data

    position_to_append_components = xml_data.find('</OPC_UA_SERVER>') + len('</OPC_UA_SERVER>') - 1
    xml_data = append_xml_to_position(position_to_append_components, xml_data, mti_xml)
    xml_data = append_xml_to_position(position_to_append_components, xml_data, iobox_xml)
    xml_data = append_xml_to_position(position_to_append_components, xml_data, mdaq_xml)
    xml_data = append_xml_to_position(position_to_append_components, xml_data, sdaq_xml)

    try:
        dom = xml.dom.minidom.parseString(xml_data)
        dom = dom.toprettyxml(encoding="UTF-8")
        with open(current_app.config['CONFIG_PATH'] + current_app.config['MORFEAS_CONFIG_FILE'], 'wb+') as f:
            f.write(dom)
            f.close()
    except Exception as e:
        return jsonify(str(e)), 500

    std = execute_command('sudo systemctl restart Morfeas_system.service')

    if(std['stderr'] != ""):
        return jsonify(std['stderr']), 500

    return jsonify(success=True), 200

@config.route('/morfeas/config', methods=['GET'])
def get_morfeas_config():
    result = dict()

    from app import read_xml_file
    morfeas_config = read_xml_file(current_app.config['MORFEAS_CONFIG_FILE'])

    if(morfeas_config is None):
        return jsonify(success=False), 500

    # as stated in the spec there should be only one app name
    # so lets hope there is only one app name
    result['app_name'] = morfeas_config['CONFIG']['COMPONENTS']['OPC_UA_SERVER']['APP_NAME']

    components = dict()

    components['sdaq_handlers'] = morfeas_config['CONFIG']['COMPONENTS']['SDAQ_HANDLER']
    components['mdaq_handlers'] = morfeas_config['CONFIG']['COMPONENTS']['MDAQ_HANDLER']
    components['iobox_handlers'] = morfeas_config['CONFIG']['COMPONENTS']['IOBOX_HANDLER']
    components['mti_handlers'] = morfeas_config['CONFIG']['COMPONENTS']['MTI_HANDLER']

    result['components'] = components

    std = execute_command('ip -o -0 addr show | grep -v link/ether | awk \'{print $2}\' | grep -v lo')

    if(std['stderr'] != ""):
        return jsonify(std['stderr']), 500

    stdout = std['stdout'].replace(':','')
    result['canbus_if_options'] = stdout.split('\n')

    return jsonify(result)
