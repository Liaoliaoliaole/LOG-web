import { async, ComponentFixture, TestBed } from '@angular/core/testing';

import { DeviceInfoTableComponent, CanBusFlatData } from './device-info-table.component';
import { CanBus } from '../models/can-model';
import { AgGridModule } from '@ag-grid-community/angular';
import { DeviceTableSidebarComponent } from '../device-table-sidebar/device-table-sidebar.component';
import { HttpClientTestingModule } from '@angular/common/http/testing';

describe('DeviceInfoTableComponent', () => {
  let component: DeviceInfoTableComponent;
  let fixture: ComponentFixture<DeviceInfoTableComponent>;

  beforeEach(async(() => {
    TestBed.configureTestingModule({
      declarations: [ DeviceInfoTableComponent, DeviceTableSidebarComponent ],
      imports: [
        HttpClientTestingModule,
        AgGridModule.forRoot()]
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
      const expected = [
        {canBus: 'can0', sdaqAddress: 1, sdaqSerial: 624642519, sdaqType: 'SDAQ-TC-16', channelId: 1, channelUnit: 'Q'},
        {canBus: 'can0', sdaqAddress: 1, sdaqSerial: 624642519, sdaqType: 'SDAQ-TC-16', channelId: 2, channelUnit: 'V'}
      ] as CanBusFlatData[];
      const result = component.flattenRowData(canData);
      expect(result).toEqual(expected);
  });

  it('should return SDAQ values when no calibration values are present', () => {
    const input = JSON.parse(`
    [{
      "CANBus-interface": "can0",
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
            "Calibration_date": []
        }]
    }]
    `);
    const expected = [
      {canBus: 'can0', sdaqAddress: 1, sdaqSerial: 624642519, sdaqType: 'SDAQ-TC-16'},
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
      }]} as CanBus;
    const result = component.flattenRowData([can0, null]);
    const expected = [
      {canBus: 'can0', sdaqAddress: 1, sdaqSerial: 624642519, sdaqType: 'SDAQ-TC-16'},
    ] as CanBusFlatData[];
    expect(result).toEqual(expected);
  });

  const canData: CanBus[] = JSON.parse(`
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
                        "channelUnit": "V"
                    }
                ]
            }
        ]
    }
]
  `);
});
