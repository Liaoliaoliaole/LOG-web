from typing import List, Set, Dict, Tuple, Optional
from functools import reduce
from devices.models.device import Device, Connection

class SdaqLogParser:

    def __init__(self, canBus):
        self.canBus = canBus

    def parse_devices(self) -> List[Device]:
        result = reduce(lambda arr, data: self.sdaq_aggregate(arr, data, self.canBus), self.canBus['SDAQs_data'], [])
        return result

    def sdaq_aggregate(self, devices: List[Device], sdaqData, can = None) -> List[Device]:
        device = Device()
        device.id = can['CANBus-interface'] + '_' + str(sdaqData['Address']) + '_' + str(sdaqData['Serial_number'])
        device.canBus = can['CANBus-interface']
        device.sdaqAddress = sdaqData['Address']
        device.sdaqSerial = sdaqData['Serial_number']
        device.sdaqType = sdaqData['SDAQ_type']

        if sdaqData['Calibration_Data']:
            device.channelId = sdaqData['Calibration_Data'][0]['Channel']
            device.channelUnit = sdaqData['Calibration_Data'][0]['Unit']
        
        devices.append(device)
        return devices

    def parse_connections(self):
        connection = Connection()
        connection.logstat_build_date_UTC = self.canBus['logstat_build_date_UTC']
        connection.BUS_voltage = self.canBus['BUS_voltage']
        connection.BUS_amperage = self.canBus['BUS_amperage']
        connection.BUS_Shunt_Res_temp = self.canBus['BUS_Shunt_Res_temp']
        connection.BUS_Utilization = self.canBus['BUS_Utilization']
        connection.Detected_SDAQs = self.canBus['Detected_SDAQs']
        return connection