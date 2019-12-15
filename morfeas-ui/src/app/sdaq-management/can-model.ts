export interface CanBus {
  'logstat_build_date(UTC)': string;
  'logstat_build_date(UNIX)': number;
  'CANBus-interface': string;
  BUS_Utilization: number;
  Detected_SDAQs: number;
  Address_Conflicts: number;
  SDAQs_data: SdaqData[];
}

export interface SdaqData {
  Address: number;
  ISO_code: string;
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
  Min_Value: number;
  Max_Value: number;
  Description: string;
}

export interface Calibrationdate {
  Channel: number;
  Cal_Exp_date: string;
  'Cal_Exp_date(UNIX)': number;
  Amount_of_points: number;
  channelUnit: string;
}
