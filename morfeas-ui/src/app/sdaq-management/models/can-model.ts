export interface CanBusModel {
  logstat_build_date_UTC: string;
  logstat_build_date_UNIX: number;
  BUS_voltage: number;
  BUS_amperage: number;
  BUS_Shunt_Res_temp: number;
  'CANBus-interface': string;
  BUS_Utilization: number;
  Detected_SDAQs: number;
  Address_Conflicts: number;
  SDAQs_data: SdaqData[];
}

export interface SdaqData {
  Address: number;
  Serial_number: number;
  SDAQ_type: string;
  firm_rev: number;
  hw_rev: number;
  Number_of_channels: number;
  Sample_rate: number;
  Max_cal_point: number;
  Calibration_date: Calibrationdate[];
  'Last_seen(UTC)': string;
  'Last_seen(UNIX)': number;
  Timediff: number;
}

export interface Calibrationdate {
  Channel: number;
  Cal_Exp_date: string;
  'Cal_Exp_date(UNIX)': number;
  Amount_of_points: number;
  Unit: string;
  Unit_code: string;
}
