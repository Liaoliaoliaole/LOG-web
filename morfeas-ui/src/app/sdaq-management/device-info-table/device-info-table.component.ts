import { Component, OnInit } from '@angular/core';
import { AllCommunityModules, ColDef, GridOptions } from '@ag-grid-community/all-modules';
import { CanbusService } from '../services/canbus/canbus.service';
import { CanBus, SdaqData } from '../can-model';
import { TableColumn } from '../device-table-sidebar/device-table-sidebar.component';

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
export class DeviceInfoTableComponent implements OnInit {

  columnDefs: ColDef[] = [
    {headerName: 'CAN Bus', field: 'canBus'},
    {headerName: 'Address', field: 'sdaqAddress', editable: true},
    {headerName: 'Serial', field: 'sdaqSerial'},
    {headerName: 'Type', field: 'sdaqType'},
    {headerName: 'Channel', field: 'channelId' },
    {headerName: 'Unit', field: 'channelUnit'},
  ];

  rowData: CanBusFlatData[];
  tableColumns: TableColumn[] = [];

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

  ngOnInit(): void {
    this.tableColumns = this.columnDefs.map(x => new TableColumn(x.field, x.headerName));
  }

  toggleColumnVisibility(id: string) {
    const column = this.gridOptions.columnApi.getColumn(id);
    this.gridOptions.columnApi.setColumnVisible(column, !column.isVisible());
  }

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
