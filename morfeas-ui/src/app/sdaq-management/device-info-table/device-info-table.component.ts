import { Component, OnInit } from '@angular/core';
import { AllCommunityModules, ColDef, GridOptions } from '@ag-grid-community/all-modules';
import { CanbusService } from '../services/canbus/canbus.service';
import { CanBus, SdaqData } from '../can-model';
import { TableColumn } from '../device-table-sidebar/device-table-sidebar.component';

export interface CanBusFlatData {
  canBus: string;
  isoCode: string;
  sdaqAddress: number;
  sdaqSerial: number;
  sdaqType: string;
  channelName: string; // missing from data
  channelDescription: string; // missing from data
  channelId: number;
  channelUnit: string;
  minValue: number;
  maxvalue: number;
  description: string;
}

@Component({
  selector: 'app-device-info-table',
  templateUrl: './device-info-table.component.html',
  styleUrls: ['./device-info-table.component.scss']
})

export class DeviceInfoTableComponent implements OnInit {
  columnDefs: ColDef[] = [
    { headerName: 'SDAQ Address', field: 'sdaqAddress'},
    { headerName: 'ISO Code', field: 'iso', editable:true }, 
    { headerName: 'SDAQ Serial Number', field: 'sdaqSerial' },
    { headerName: 'SDAQ Channel', field: 'channelId' },
    { headerName: 'Type', field: 'sdaqType' },
    { headerName: 'Unit', field: 'channelUnit' },
    { headerName: 'Min Value', field: 'min' },
    { headerName: 'Max Value', field: 'max' },
    { headerName: 'Description', field: 'description', editable: true },
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
    suppressScrollOnNewData: true,

    debug: true,
    onGridReady: () => {
      setInterval(() => {
        this.getData();
      }, 2000);
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

  flattenRowData(canBusArr: CanBus[]): CanBusFlatData[] {
    //console.log(canBusArr)
    const canBus = canBusArr.filter(x => x != null);
    if (canBus == null || canBus.length === 0) {
      return [];
    }
    return canBus.reduce((canBusArray: CanBusFlatData[], can) => {
      const canBusDataRow = can.SDAQs_data.reduce((flattenArray: CanBusFlatData[], sdaqData: SdaqData) => {
        const dataPoint = {
          canBus: can['CANBus-interface'],
          sdaqAddress: sdaqData.Address,
          sdaqSerial: sdaqData.Serial_number,
          sdaqType: sdaqData.SDAQ_type,
          minValue: sdaqData.Min_Value,
          maxvalue: sdaqData.Max_Value,
          description: sdaqData.Description
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

  getData() {
    this.canbusService.getCanbusData().subscribe((rowData) => {
      this.rowData = this.flattenRowData(rowData);
      this.gridOptions.api.setRowData(this.rowData);
    });
  }

  fetchData(event:any) {
    this.canbusService.saveOpcUaConfigs(this.rowData);
  }
}
