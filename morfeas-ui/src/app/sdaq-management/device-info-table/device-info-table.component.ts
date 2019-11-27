import { Component } from '@angular/core';
import { AllCommunityModules, ColDef, GridOptions } from '@ag-grid-community/all-modules';
import { CanbusService } from '../services/canbus/canbus.service';
import { CanBus, SdaqData } from '../can-model';

export interface CanBusFlatData {
  canBus: string;
  sdaqAddress: number;
  sdaqSerial: number;
  sdaqType: string;
  channelName: string; // missing from data
  channelDescription: string; // missing from data
  channelId: number;
  channelUnit: string;
}

@Component({
  selector: 'app-device-info-table',
  templateUrl: './device-info-table.component.html',
  styleUrls: ['./device-info-table.component.scss']
})
export class DeviceInfoTableComponent {

  columnDefs: ColDef[] = [
    {colId: '1', headerName: 'CAN Bus', field: 'canBus'},
    {colId: '2', headerName: 'Address', field: 'sdaqAddress', editable: true},
    {colId: '3', headerName: 'Serial', field: 'sdaqSerial'},
    {colId: '4', headerName: 'Type', field: 'sdaqType'},
    {colId: '5', headerName: 'Channel', field: 'channelId' },
    {colId: '6', headerName: 'Unit', field: 'channelUnit'},
  ];

  rowData: CanBusFlatData[];

  gridOptions: GridOptions = {
    defaultColDef: {
      editable: false,
      sortable: true,
      resizable: true,
      filter: true
    },
    columnDefs: this.columnDefs,
    rowData: this.rowData,
    suppressRowTransform: true,

    debug: true,
    onGridReady: () => {
      const rowData = this.canbusService.getCanbusData();
      this.rowData = this.flattenRowData(rowData);
      this.gridOptions.api.setRowData(this.rowData);
    },
  };

  modules = AllCommunityModules;
  constructor(private readonly canbusService: CanbusService) { }

  flattenRowData(canBus: CanBus[]): CanBusFlatData[] {
    return canBus.reduce((canBusArray: CanBusFlatData[], can) => {
      const canBusDataRow = can.SDAQs_data.reduce((flattenArray: CanBusFlatData[], sdaqData: SdaqData) => {
        const dataPoint = {
          canBus: can['CANBus-interface'],
          sdaqAddress: sdaqData.Address,
          sdaqSerial: sdaqData.Serial_number,
          sdaqType: sdaqData.SDAQ_type,
        } as CanBusFlatData;

        if (!sdaqData.Calibration_date || sdaqData.Calibration_date.length === 0) {
          flattenArray.push(dataPoint);
          return flattenArray;
        }

        const dataPoints: CanBusFlatData[] = sdaqData.Calibration_date.map(calibrationData => {
          const result = {
            channelId: calibrationData.Channel,
            channelUnit: calibrationData.channelUnit
          };
          return Object.assign(result, dataPoint); // copy data point values to new object
        });

        return flattenArray.concat(dataPoints);
      }, []);
      return canBusArray.concat(canBusDataRow);
    }, []);
  }

}
