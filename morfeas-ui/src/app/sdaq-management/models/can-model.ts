export interface CanBusModel {
  logstat_build_date_UTC: string;
  logstat_build_date_UNIX: number;
  'CANBus-interface': string;
  BUS_voltage: number;
  BUS_amperage: number;
  BUS_Shunt_Res_temp: number;
  BUS_Utilization: number;
  Detected_SDAQs: number;
  SDAQs_data: SDAQData[];
}

export interface SDAQData {
  Last_seen_UTC: string;
  Last_seen_UNIX: number;
  Address: number;
  Serial_number: number;
  SDAQ_type: string;
  Timediff: number;
  SDAQ_Status: SDAQStatus;
  SDAQ_info: SDAQInfo;
  Calibration_Data: CalibrationData[];
  Meas: Measurements[];
}

export interface Measurements {
  Channel: number;
  Channel_Status: ChannelStatus;
  Unit: string;
  Meas_avg: number;
}

export interface ChannelStatus {
  Channel_status_val: number;
  Out_of_Range: boolean;
  No_Sensor: boolean;
}

export interface CalibrationData {
  Channel: number;
  Calibration_date: string;
  Calibration_date_UNIX: number;
  Calibration_period: number;
  Amount_of_points: number;
  Unit: string;
  Is_calibrated: boolean;
  Unit_code: number;
}

export interface SDAQInfo {
  firm_rev: number;
  hw_rev: number;
  Number_of_channels: number;
  Sample_rate: number;
  Max_cal_point: number;
}

export interface SDAQStatus {
  SDAQ_status_val: number;
  In_sync: boolean;
  Error: boolean;
  State: string;
  Mode: string;
}
