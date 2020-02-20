import { async, ComponentFixture, TestBed } from '@angular/core/testing';

import { DeviceInfoTableComponent, CanBusFlatData } from './device-info-table.component';
import { AgGridModule } from '@ag-grid-community/angular';
import { DeviceTableSidebarComponent } from '../device-table-sidebar/device-table-sidebar.component';
import { HttpClientTestingModule } from '@angular/common/http/testing';
import { CanBusModel } from '../models/can-model';
import { ModalsModule } from 'src/app/modals/modals.module';
import { CanbusDetailsBarComponent } from 'src/app/canbus-details-bar/canbus-details-bar.component';

import { BrowserAnimationsModule } from '@angular/platform-browser/animations';
import { ToastrModule } from 'ngx-toastr';

describe('DeviceInfoTableComponent', () => {
  let component: DeviceInfoTableComponent;
  let fixture: ComponentFixture<DeviceInfoTableComponent>;

  beforeEach(async(() => {
    TestBed.configureTestingModule({
      declarations: [DeviceInfoTableComponent, DeviceTableSidebarComponent, CanbusDetailsBarComponent
      ],
      imports: [
        HttpClientTestingModule,
        ModalsModule,
        AgGridModule.forRoot(),
        BrowserAnimationsModule,
        ToastrModule.forRoot({
          timeOut: 2000,
          positionClass: 'toast-bottom-center',
        })]
    })
      .compileComponents();
  }));

  beforeEach(() => {
    fixture = TestBed.createComponent(DeviceInfoTableComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('should flatten row data array from canbus data', () => {
    const expected: CanBusFlatData[] = [
      {
        canBus: 'can0', sdaqAddress: 1, sdaqSerial: 8, sdaqType: 'Pseudo_SDAQ', channelId: 1, channelUnit: 'Q',
        isoCode: null, minValue: null, maxValue: null, description: null, id: 'can0_1_8_1', isVisible: true,
        avgMeasurement: '0'
      },
      {
        canBus: 'can0', sdaqAddress: 1, sdaqSerial: 9, sdaqType: 'Pseudo_SDAQ', channelId: 1, channelUnit: 'V',
        isoCode: null, minValue: null, maxValue: null, description: null, id: 'can0_1_9_1', isVisible: true,
        avgMeasurement: '0'
      }
    ];
    const result = component.flattenRowData(canData);
    expect(result).toEqual(expected);
  });

  it('should return SDAQ values when no calibration values are present', () => {
    const input: CanBusModel[] =
      [{
        logstat_build_date_UNIX: 1251883,
        logstat_build_date_UTC: '11/25/19 12:01:13',
        'CANBus-interface': 'can0',
        BUS_Utilization: 0,
        Detected_SDAQs: 1,
        BUS_Shunt_Res_temp: 0,
        BUS_amperage: 0,
        BUS_voltage: 0,
        SDAQs_data: [
          {
            Last_seen_UTC: '02/05/20 13:50:12',
            Last_seen_UNIX: 1580910612,
            Address: 1,
            Serial_number: 8,
            SDAQ_type: 'Pseudo_SDAQ',
            Timediff: 28,
            SDAQ_Status: {
              SDAQ_status_val: 1,
              In_sync: false,
              Error: false,
              State: 'Measuring',
              Mode: 'Normal'
            },
            SDAQ_info: {
              firm_rev: 0,
              hw_rev: 0,
              Number_of_channels: 1,
              Sample_rate: 10,
              Max_cal_point: 16
            },
            Calibration_Data: [
              {
                Channel: 1,
                Calibration_date: '2000/00/00',
                Calibration_date_UNIX: 943912800,
                Calibration_period: 0,
                Amount_of_points: 0,
                Unit: '-',
                Is_calibrated: false,
                Unit_code: 0
              },
            ],
            Meas: [
              {
                Channel: 1,
                Channel_Status: {
                  Channel_status_val: 0,
                  Out_of_Range: false,
                  No_Sensor: false
                },
                Unit: '-',
                Meas_avg: 0
              }
            ]
          }]
      }];
    const expected = [
      {
        id: 'can0_1_8_1', channelId: 1, channelUnit: '-', isoCode: null, description: null,
        minValue: null, maxValue: null, canBus: 'can0', sdaqAddress: 1, sdaqSerial: 8, sdaqType: 'Pseudo_SDAQ', isVisible: true,
        avgMeasurement: '0'
      },
    ] as CanBusFlatData[];
    const result = component.flattenRowData(input);
    expect(result).toEqual(expected);
  });

  it('should return empty array when no SDAQ data is available', () => {
    const input = JSON.parse(`
    [{
      "CANBus-interface": "can0",
      "SDAQs_data": []
    }]
    `);
    const result = component.flattenRowData(input);
    expect(result).toEqual([]);
  });

  it('should return empty array when no CanBus data is available', () => {
    const result = component.flattenRowData([null, null]);
    expect(result).toEqual([]);
  });

  it('should show single canbus if other fails to load', () => {
    const can0 = {
      'CANBus-interface': 'can0',
      SDAQs_data: [{
        Address: 1,
        Serial_number: 624642519,
        SDAQ_type: 'SDAQ-TC-16',
      }]
    } as CanBusModel;
    const result = component.flattenRowData([can0, null]);
    const expected = [
      { canBus: 'can0', sdaqAddress: 1, sdaqSerial: 624642519, sdaqType: 'SDAQ-TC-16' },
    ] as CanBusFlatData[];
    expect(result).toEqual(expected);
  });

  const canData: CanBusModel[] =
    [{
      logstat_build_date_UNIX: 1251883,
      logstat_build_date_UTC: '11/25/19 12:01:13',
      'CANBus-interface': 'can0',
      BUS_Utilization: 0,
      Detected_SDAQs: 1,
      BUS_Shunt_Res_temp: 0,
      BUS_amperage: 0,
      BUS_voltage: 0,
      SDAQs_data: [
        {
          Last_seen_UTC: '02/05/20 13:50:12',
          Last_seen_UNIX: 1580910612,
          Address: 1,
          Serial_number: 8,
          SDAQ_type: 'Pseudo_SDAQ',
          Timediff: 28,
          SDAQ_Status: {
            SDAQ_status_val: 1,
            In_sync: false,
            Error: false,
            State: 'Measuring',
            Mode: 'Normal'
          },
          SDAQ_info: {
            firm_rev: 0,
            hw_rev: 0,
            Number_of_channels: 1,
            Sample_rate: 10,
            Max_cal_point: 16
          },
          Calibration_Data: [
            {
              Channel: 1,
              Calibration_date: '2000/00/00',
              Calibration_date_UNIX: 943912800,
              Calibration_period: 0,
              Amount_of_points: 0,
              Unit: 'Q',
              Is_calibrated: false,
              Unit_code: 0
            }
          ],
          Meas: [
            {
              Channel: 1,
              Channel_Status: {
                Channel_status_val: 0,
                Out_of_Range: false,
                No_Sensor: false
              },
              Unit: 'Q',
              Meas_avg: 0
            }
          ]
        },
        {
          Last_seen_UTC: '02/05/20 13:50:12',
          Last_seen_UNIX: 1580910612,
          Address: 1,
          Serial_number: 9,
          SDAQ_type: 'Pseudo_SDAQ',
          Timediff: 28,
          SDAQ_Status: {
            SDAQ_status_val: 1,
            In_sync: false,
            Error: false,
            State: 'Measuring',
            Mode: 'Normal'
          },
          SDAQ_info: {
            firm_rev: 0,
            hw_rev: 0,
            Number_of_channels: 1,
            Sample_rate: 10,
            Max_cal_point: 16
          },
          Calibration_Data: [
            {
              Channel: 1,
              Calibration_date: '2000/00/00',
              Calibration_date_UNIX: 943912800,
              Calibration_period: 0,
              Amount_of_points: 0,
              Unit: 'V',
              Is_calibrated: false,
              Unit_code: 0
            }
          ],
          Meas: [
            {
              Channel: 1,
              Channel_Status: {
                Channel_status_val: 0,
                Out_of_Range: false,
                No_Sensor: false
              },
              Unit: 'V',
              Meas_avg: 0
            }
          ]
        }]
    }];
});
