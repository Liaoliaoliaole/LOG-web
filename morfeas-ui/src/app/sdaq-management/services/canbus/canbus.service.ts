import { Injectable } from '@angular/core';
import { CanBus } from '../../can-model';

@Injectable({
  providedIn: 'root'
})
export class CanbusService {

  constructor() { }

  getCanbusData(): CanBus[] {
    return JSON.parse(`
    [
      {
          "logstat_build_date(UTC)": "11/25/19 12:01:13",
          "logstat_build_date(UNIX)": 1574683273,
          "CANBus-interface": "can0",
          "BUS_Utilization": 0,
          "Detected_SDAQs": 4,
          "Address_Conflicts": 0,
          "SDAQs_data": [
              {
                  "Address": 1,
                  "Serial_number": 624642519,
                  "SDAQ_type": "SDAQ-TC-16",
                  "firm_rev": 2,
                  "hw_rev": 1,
                  "Number_of_channels": 16,
                  "Sample_rate": 10,
                  "Max_cal_point": 0,
                  "Calibration_date": [
                      {
                          "Channel": 1,
                          "Cal_Exp_date": "1970/01",
                          "Cal_Exp_date(UNIX)": 0,
                          "Amount_of_points": 0,
                          "channelUnit": "Q"
                      },
                      {
                          "Channel": 2,
                          "Cal_Exp_date": "1970/01",
                          "Cal_Exp_date(UNIX)": 0,
                          "Amount_of_points": 0,
                          "channelUnit": "Q"
                      },
                      {
                          "Channel": 3,
                          "Cal_Exp_date": "1970/01",
                          "Cal_Exp_date(UNIX)": 0,
                          "Amount_of_points": 0,
                          "channelUnit": "Q"
                      },
                      {
                          "Channel": 4,
                          "Cal_Exp_date": "1970/01",
                          "Cal_Exp_date(UNIX)": 0,
                          "Amount_of_points": 0,
                          "channelUnit": "Q"
                      },
                      {
                          "Channel": 5,
                          "Cal_Exp_date": "1970/01",
                          "Cal_Exp_date(UNIX)": 0,
                          "Amount_of_points": 0,
                          "channelUnit": "Q"
                      }
                  ]
              },
              {
                  "Address": 2,
                  "Serial_number": 626190454,
                  "SDAQ_type": "SDAQ-TC-1",
                  "firm_rev": 3,
                  "hw_rev": 1,
                  "Number_of_channels": 1,
                  "Sample_rate": 10,
                  "Max_cal_point": 0,
                  "Calibration_date": [
                      {
                          "Channel": 1,
                          "Cal_Exp_date": "1970/01",
                          "Cal_Exp_date(UNIX)": 0,
                          "Amount_of_points": 0,
                          "channelUnit": "Q"
                      },
                      {
                        "Channel": 2,
                        "Cal_Exp_date": "1970/01",
                        "Cal_Exp_date(UNIX)": 0,
                        "Amount_of_points": 0,
                        "channelUnit": "Q"
                    },
                    {
                        "Channel": 3,
                        "Cal_Exp_date": "1970/01",
                        "Cal_Exp_date(UNIX)": 0,
                        "Amount_of_points": 0,
                        "channelUnit": "Q"
                    }
                  ],
                  "Last_seen(UTC)": "11/25/19 12:01:11",
                  "Last_seen(UNIX)": 1574683271,
                  "Timediff": 0
              }
          ]
      }
  ]
    `);
  }
}
