import os
import subprocess
import xml.dom.minidom
import zipfile
import base64
from io import BytesIO
from dicttoxml import dicttoxml
from flask import Blueprint, jsonify, Response
from flask import current_app
from flask import Flask, request, jsonify
from common.common import execute_command
from lxml import etree

config = Blueprint('config', __name__)

def component_to_xml(data, name):
    return dicttoxml(data, root=False, ids=False, attr_type=False, item_func=lambda x: name).decode('utf-8')

def append_xml_to_position(position, xml, append):
    return xml[:position+1] + append + xml[position+1:]

@config.route('/morfeas/config/download', methods=['GET'])
def download_morfeas_config():
    o = BytesIO()
    zf = zipfile.ZipFile(o, mode='w')
    zf.write(os.path.join(current_app.config['CONFIG_PATH'], current_app.config['MORFEAS_CONFIG_FILE']), current_app.config['MORFEAS_CONFIG_FILE'])
    zf.write(os.path.join(current_app.config['CONFIG_PATH'], current_app.config['OPC_UA_CONFIG_FILE']), current_app.config['OPC_UA_CONFIG_FILE'])
    zf.write(os.path.join(current_app.config['CONFIG_PATH'], current_app.config['ISO_STANDARD_FILE']), current_app.config['ISO_STANDARD_FILE'])
    zf.close()
    o.seek(0)
    response = Response(o.getvalue())
    o.close()
    response.headers['Content-Type'] = 'application/octet-stream'
    response.headers['Content-Disposition'] = "attachment; filename=\"config.zip\""
    return response

@config.route('/morfeas/config/filenames', methods=['GET'])
def get_filenames():
    response = dict()
    response['morfeas_file'] = current_app.config['MORFEAS_CONFIG_FILE']
    response['opc_file'] = current_app.config['OPC_UA_CONFIG_FILE']
    response['iso_file'] = current_app.config['ISO_STANDARD_FILE']
    return jsonify(response)

def overwrite_xml(dest, xml):
    et = etree.ElementTree(xml)
    with open(dest, 'wb+') as f:
        et.write(f, encoding="utf-8", xml_declaration=True, pretty_print=True)

def decode_to_etree(xml):
    xml_string = base64.b64decode(xml)
    return etree.XML(xml_string)

def process_upload(xml_data, name):
    dtd = etree.DTD(os.path.join(current_app.config['CONFIG_PATH'], 'Morfeas.dtd'))

    xml_file = decode_to_etree(xml_data)

    if(dtd.validate(xml_file) == False):
        return False

    overwrite_xml(os.path.join(current_app.config['CONFIG_PATH'], name), xml_file)

    return True

def process_upload_no_validation(xml_data, name):

    xml_file = decode_to_etree(xml_data)

    overwrite_xml(os.path.join(current_app.config['CONFIG_PATH'], name), xml_file)

    return True

@config.route('/morfeas/config/upload', methods=['POST'])
def upload_morfeas_config():
    data = request.get_json()

    try:
        # keyerror means it wasnt uploaded so dont even try to overwrite anything
        try:
            if(data[current_app.config['MORFEAS_CONFIG_FILE']] is not None):
                if(process_upload(data[current_app.config['MORFEAS_CONFIG_FILE']], current_app.config['MORFEAS_CONFIG_FILE']) == False):
                    return jsonify(current_app.config['MORFEAS_CONFIG_FILE'] + ' file validation against Morfeas.dtd failed'), 500
        except KeyError:
            pass
        try:
            if(data[current_app.config['OPC_UA_CONFIG_FILE']] is not None):
                if(process_upload(data[current_app.config['OPC_UA_CONFIG_FILE']], current_app.config['OPC_UA_CONFIG_FILE']) == False):
                    return jsonify(current_app.config['OPC_UA_CONFIG_FILE'] + ' file validation against Morfeas.dtd failed'), 500
        except KeyError:
            pass
        try:
            if(data[current_app.config['ISO_STANDARD_FILE']] is not None):
                if(process_upload_no_validation(data[current_app.config['ISO_STANDARD_FILE']], current_app.config['ISO_STANDARD_FILE']) == False):
                    return jsonify(current_app.config['ISO_STANDARD_FILE'] + ' file validation against Morfeas.dtd failed'), 500
        except KeyError:
            pass
    except Exception as e:
        return jsonify(str(e)), 500

    std = execute_command('sudo systemctl restart Morfeas_system.service')

    if(std['stderr'] != ""):
        return jsonify(std['stderr']), 500

    return jsonify(success=True), 200

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
