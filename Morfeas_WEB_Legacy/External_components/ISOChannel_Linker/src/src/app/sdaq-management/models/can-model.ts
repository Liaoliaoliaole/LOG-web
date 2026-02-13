export interface Logstat {
  logstat_build_date_UNIX: number;
  if_name: string;
  sensors: Sensor[];
  connections: Connection[];
}

export class Sensor {
  deviceId: string;
  deviceUserIdentifier: string;
  sensorUserId: string;
  anchor: string;
  type: string;
  avgMeasurement: string;
  // tslint:disable-next-line: variable-name
  Is_meas_valid: boolean;
  unit?: string;
  calibrationPeriod?: number;
  calibrationDate?: Date;
}

export class Connection {
  name: string;
  value: string;
  unit?: string;
}
