from typing import Optional, List

class Connection(object):
    logstat_build_date_UTC: str
    BUS_voltage: float
    BUS_amperage: float
    BUS_Shunt_Res_temp: float
    BUS_Utilization: float
    Detected_SDAQs: int

# TODO: Can be made more generic
class Device(object):
    id: str
    canBus: str
    sdaqAddress: int
    sdaqSerial: int
    sdaqType: str
    channelId: int
    channelUnit: str

class ConnectedDevicesDTO(object):
    connections: List[Connection]
    devices: List[Device]

    def __init__(self):
        self.connections = []
        self.devices = []
