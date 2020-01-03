import { Component, OnInit, OnDestroy } from '@angular/core';
import { AllCommunityModules, ColDef, GridOptions, ValueCache } from '@ag-grid-community/all-modules';
import { CanbusService } from '../services/canbus/canbus.service';
import { CanBusModel, SdaqData } from '../models/can-model';
import { TableColumn } from '../device-table-sidebar/device-table-sidebar.component';
import { OpcUaConfigModel } from '../models/opcua-config-model';
import { ModalService } from 'src/app/modals/services/modal.service';

export interface CanBusFlatData {
  canBus: string;
  isoCode: string;
  sdaqAddress: number;
  sdaqSerial: number;
  sdaqType: string;
  channelId: number;
  channelUnit: string;
  minValue: number;
  maxValue: number;
  description: string;
}

@Component({
  selector: 'app-device-info-table',
  templateUrl: './device-info-table.component.html',
  styleUrls: ['./device-info-table.component.scss']
})

export class DeviceInfoTableComponent implements OnInit, OnDestroy {
  columnDefs: ColDef[] = [
    { headerName: 'SDAQ Address', field: 'sdaqAddress'},
    { headerName: 'ISO Code', field: 'iso', sort:'asc'}, 
    { headerName: 'SDAQ Serial Number', field: 'sdaqSerial' },
    { headerName: 'SDAQ Channel', field: 'channelId' },
    { headerName: 'Type', field: 'sdaqType' },
    { headerName: 'Unit', field: 'channelUnit' },
    { headerName: 'Min Value', field: 'minValue', editable: true },
    { headerName: 'Max Value', field: 'maxValue', editable: true },
    { headerName: 'Description', field: 'description', editable: true },
  ];

  rowData: CanBusFlatData[] = [];
  tableColumns: TableColumn[] = [];
  opcUaMap = new Map<string, OpcUaConfigModel>();
  opcUaConfigData: OpcUaConfigModel[];
  canBusPoller: any;
  pause = false;

  gridOptions: GridOptions = {
    defaultColDef: {
      editable: false,
      sortable: true,
      resizable: true,
      filter: true
    },
    onCellEditingStarted: this.onCellEdittingStarted.bind(this),
    onCellEditingStopped: this.onCellEdittingStopped.bind(this),
    columnDefs: this.columnDefs,
    rowData: this.rowData,
    suppressRowTransform: true,
    suppressScrollOnNewData: true,

    debug: true,
    onGridReady: async () => {
      // Visualize data on the table
      await this.getOpcUaConfigData();

      this.getLogStatData();
      this.canBusPoller = setInterval(() => {
        if (!this.pause) {
          this.getLogStatData();
        }
      }, 2000);

    },
  };

  modules = AllCommunityModules;
  constructor(private readonly canbusService: CanbusService,
              private readonly modalService: ModalService) { }

  onCellEdittingStarted(e:any): void {
    this.togglePause(e); // enalbe pause mode
  }

  onCellEdittingStopped(e:any): void {
    this.saveTemporaryClientChanges(e); // save client temporary changes
    this.togglePause(e); // disable pause mode
  }

  ngOnInit(): void {
    this.tableColumns = this.columnDefs.map(x => new TableColumn(x.field, x.headerName));
  }

  ngOnDestroy() {
    clearInterval(this.canBusPoller);
    this.canBusPoller = null;
  }

  toggleColumnVisibility(id: string) {
    const column = this.gridOptions.columnApi.getColumn(id);
    this.gridOptions.columnApi.setColumnVisible(column, !column.isVisible());
  }

  togglePause(e) {
    this.pause = !this.pause;
  }

  saveTemporaryClientChanges(e) {
    let clientChange:CanBusFlatData = e.data;

    this.rowData.forEach(row => {
      if (row.sdaqSerial === clientChange.sdaqSerial && row.channelId === clientChange.channelId) {
        row = clientChange;
      }
    });
    
    this.gridOptions.api.setRowData(this.rowData);
  }

  flattenRowData(canBusArr: CanBusModel[]): CanBusFlatData[] {
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
          sdaqType: sdaqData.SDAQ_type
        } as CanBusFlatData;

        if (!sdaqData.Calibration_date || sdaqData.Calibration_date.length === 0) {
          flattenArray.push(dataPoint);
          return flattenArray;
        }

        const dataPoints: CanBusFlatData[] = sdaqData.Calibration_date.map(calibrationData => {
          let anchor = this.canbusService.generateAnchor(sdaqData.Serial_number, calibrationData.Channel)
          let opcUaConf:OpcUaConfigModel;
          if (this.opcUaMap.has(anchor)) { // get value of OPC UA config based on key as anchor
            opcUaConf = this.opcUaMap.get(anchor);
          }
    
          let result = {
            channelId: calibrationData.Channel,
            channelUnit: calibrationData.channelUnit,
            description: opcUaConf.description, // map value of OPC UA config to table data
            minValue: opcUaConf.minValue, // map value of OPC UA config to table data
            maxValue: opcUaConf.maxValue, // map value of OPC UA config to table data
          };
          return Object.assign(result, dataPoint); // copy data point values to new object
        });

        return flattenArray.concat(dataPoints);
      }, []);
      return canBusArray.concat(canBusDataRow);
    }, []);
  }

  getLogStatData() {
    this.canbusService.getLogStatData().subscribe((rowData) => {
      this.rowData = this.flattenRowData(rowData);
      this.gridOptions.api.setRowData(this.rowData);
    });
  }

  async getOpcUaConfigData() {
    const opcUaConfigs = await this.canbusService.getOpcUaConfigs().toPromise();
      opcUaConfigs.map(sensor => {
        if (sensor) {
          this.opcUaMap.set(sensor.anchor, sensor);
        }
      });
  }

  saveOpcUaConfigs(event:any) {
    this.canbusService.saveOpcUaConfigs(this.rowData).subscribe(resp => {
      this.getOpcUaConfigData();
    });
  }

  // TODO: Remove
  showModal() {
    this.modalService.confirm({title: 'title', message: 'message'})
    .then(() => {
      console.log('confirmed');
    }).catch(() => console.log('cancelled'));
  }

  showModal2() {
    this.modalService.open({title: 'title', message: 'message'});
  }
}
