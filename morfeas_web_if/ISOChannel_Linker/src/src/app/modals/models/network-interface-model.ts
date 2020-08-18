export interface NetworkInterfaceResponse {
    retcode: number;
    interfaces: NetworkInterface[];
    error: string;
}

export interface NetworkInterface {
    interface: string;
    ipAddress: string;
    subnetMask: string;
    defaultGateway: string;
}
