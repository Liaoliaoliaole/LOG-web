import { Component, OnInit, OnDestroy } from '@angular/core';
import {
  AllCommunityModules,
  ColDef,
  GridOptions,
  ValueCache,
  RowNodeTransaction
} from '@ag-grid-community/all-modules';
import { CanbusService } from '../services/canbus/canbus.service';
import { CanBusModel, SdaqData } from '../models/can-model';
import { TableColumn } from '../device-table-sidebar/device-table-sidebar.component';
import { OpcUaConfigModel } from '../models/opcua-config-model';
import { ModalService } from 'src/app/modals/services/modal.service';
import { SensorLinkModalComponent } from 'src/app/modals/components/sensor-link-modal/sensor-link-modal.component';
import { IsoStandard } from '../models/iso-standard-model';

export interface CanBusFlatData {
  id: string;
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
    { headerName: 'SDAQ Address', field: 'sdaqAddress' },
    { headerName: 'ISO Code', field: 'isoCode', sort: 'asc' },
    { headerName: 'SDAQ Serial Number', field: 'sdaqSerial' },
    { headerName: 'SDAQ Channel', field: 'channelId' },
    { headerName: 'Type', field: 'sdaqType' },
    { headerName: 'Unit', field: 'channelUnit' },
    { headerName: 'Min Value', field: 'minValue', editable: true },
    { headerName: 'Max Value', field: 'maxValue', editable: true },
    { headerName: 'Description', field: 'description', editable: true }
  ];

  rowData: CanBusFlatData[] = [];
  tableColumns: TableColumn[] = [];
  opcUaMap = new Map<string, OpcUaConfigModel>();
  opcUaConfigData: OpcUaConfigModel[];
  canBusPoller: any;
  canBusDetails: CanBusModel[];
  pause = false;
  clientChanges = new Map<string, CanBusFlatData>();
  configuredIsoCodes: string[] = [];

  gridOptions: GridOptions = {
    defaultColDef: {
      editable: false,
      sortable: true,
      resizable: true,
      filter: true,
      enableCellChangeFlash: true
    },
    onCellClicked: this.onCellClicked.bind(this),
    onCellEditingStarted: this.onCellEdittingStarted.bind(this),
    onCellEditingStopped: this.onCellEdittingStopped.bind(this),
    columnDefs: this.columnDefs,
    rowData: this.rowData,
    suppressRowTransform: true,
    suppressScrollOnNewData: true,
    batchUpdateWaitMillis: 50,

    onGridReady: async () => {
      // Visualize data on the table
      await this.getOpcUaConfigData();

      this.getLogStatData();
      this.canBusPoller = setInterval(() => {
        if (!this.pause) {
          this.getLogStatData();
        }
      }, 5000);
    }
  };

  modules = AllCommunityModules;
  constructor(
    private readonly canbusService: CanbusService,
    private readonly modalService: ModalService
  ) { }

  onCellEdittingStarted(e: any): void {
    this.togglePause(); // enalbe pause mode
  }

  onCellEdittingStopped(e: any): void {
    this.saveClientChanges(e.data); // save client temporary changes

    this.togglePause(); // disable pause mode
  }

  onCellClicked(e: any): void {
    if (e.colDef.field === 'isoCode') {
      // open popup dialog when ISO cell is clicked
      this.togglePause(); // enable pause mode when dialog is to be open

      if (!e.data.isoCode) {
        // Confirm
        this.modalService
          .confirm({
            component: SensorLinkModalComponent,
            data: {
              unit: e.data.channelUnit,
              configuredIsoCodes: this.configuredIsoCodes
            }
          })
          .then((data: IsoStandard) => {
            const selectedRow = this.rowData.find(
              x =>
                x.sdaqSerial === e.data.sdaqSerial &&
                x.channelId === e.data.channelId
            );

            // apply selected ISO code's data to the table
            selectedRow.isoCode = data.iso_code;
            selectedRow.description = data.attributes.description;
            selectedRow.minValue = +data.attributes.min;
            selectedRow.maxValue = +data.attributes.max;

            this.saveClientChanges(e.data);
            this.getLogStatData();

            this.configuredIsoCodes.push(data.iso_code);
            this.togglePause();
          })
          .catch(() => {
            // Cancel
            this.togglePause();
            console.log('cancelled');
          });
      } else {
        // TODO
        this.modalService.open({ title: 'title', message: 'message' });
      }
    }
  }

  ngOnInit(): void {
    this.tableColumns = this.columnDefs.map(
      x => new TableColumn(x.field, x.headerName)
    );
  }

  ngOnDestroy() {
    clearInterval(this.canBusPoller);
    this.canBusPoller = null;
  }

  toggleColumnVisibility(id: string) {
    const column = this.gridOptions.columnApi.getColumn(id);
    this.gridOptions.columnApi.setColumnVisible(column, !column.isVisible());
  }

  togglePause() {
    this.pause = !this.pause;
  }

  saveClientChanges(data) {
    const modifiedAnchor = this.canbusService.generateAnchor(
      data.sdaqSerial,
      data.channelId
    );

    this.clientChanges.set(modifiedAnchor, data);
  }

  applyTemporaryClientChanges() {
    this.rowData.forEach((row, index, arr) => {
      // TODO: rework this logic as there's no need to loop through the entire row data
      const anchor = this.canbusService.generateAnchor(
        row.sdaqSerial,
        row.channelId
      );

      if (this.clientChanges.get(anchor)) {
        arr[index] = this.clientChanges.get(anchor);
      }
    });
  }

  flattenRowData(canBusArr: CanBusModel[]): CanBusFlatData[] {
    const canBus = canBusArr.filter(x => x != null);
    if (canBus == null || canBus.length === 0) {
      return [];
    }

    return canBus.reduce((canBusArray: CanBusFlatData[], can) => {
      const canBusDataRow = can.SDAQs_data.reduce(
        (flattenArray: CanBusFlatData[], sdaqData: SdaqData) => {
          const dataPoint = {
            id:
              can['CANBus-interface'] +
              '_' +
              sdaqData.Address +
              '_' +
              sdaqData.Serial_number,
            canBus: can['CANBus-interface'],
            sdaqAddress: sdaqData.Address,
            sdaqSerial: sdaqData.Serial_number,
            sdaqType: sdaqData.SDAQ_type
          } as CanBusFlatData;

          if (
            !sdaqData.Calibration_date ||
            sdaqData.Calibration_date.length === 0
          ) {
            flattenArray.push(dataPoint);
            return flattenArray;
          }

          const dataPoints: CanBusFlatData[] = sdaqData.Calibration_date.map(
            calibrationData => {
              const anchor = this.canbusService.generateAnchor(
                sdaqData.Serial_number,
                calibrationData.Channel
              );
              let opcUaConf: OpcUaConfigModel;

              if (this.opcUaMap.has(anchor)) {
                // get value of OPC UA config based on key as anchor
                opcUaConf = this.opcUaMap.get(anchor);
              }

              const result = {
                channelId: calibrationData.Channel,
                channelUnit: calibrationData.Unit,
                isoCode: opcUaConf ? opcUaConf.ISO_CHANNEL : null, // map value of OPC UA config to table data
                description: opcUaConf ? opcUaConf.DESCRIPTION : null, // map value of OPC UA config to table data
                minValue: opcUaConf ? opcUaConf.MIN : null, // map value of OPC UA config to table data
                maxValue: opcUaConf ? opcUaConf.MAX : null // map value of OPC UA config to table data
              };
              return Object.assign(result, dataPoint); // copy data point values to new object
            }
          );

          return flattenArray.concat(dataPoints);
        },
        []
      );
      return canBusArray.concat(canBusDataRow);
    }, []);
  }

  getLogStatData() {
    this.canbusService.getLogStatData().subscribe(rowData => {
      const details = [];

      rowData.forEach(row => {
        const canInterface = row['CANBus-interface'];
        details.push({
          logstat_build_date_UTC: row.logstat_build_date_UTC,
          'CANBus-interface': canInterface,
          BUS_voltage: row.BUS_voltage,
          BUS_amperage: row.BUS_amperage,
          BUS_Shunt_Res_temp: row.BUS_Shunt_Res_temp,
          BUS_Utilization: row.BUS_Utilization,
          Detected_SDAQs: row.Detected_SDAQs,
        });
      });

      this.canBusDetails = details;
      const newData = this.flattenRowData(rowData);
      this.applyTemporaryClientChanges();

      // this.gridOptions.api.setRowData(this.rowData);

      if (this.rowData && this.rowData.length > 0) {
        newData.forEach(row => {
          setTimeout(() => {
            this.gridOptions.api.batchUpdateRowData(
              { update: [row] },
              (result: RowNodeTransaction) => {
                // console.log("batchupdated");
              }
            );
          }, 0);
        });
      } else {
        this.rowData = newData;
      }
    });
  }

  getRowNodeId = (data: any) => data.id;

  async getOpcUaConfigData() {
    const opcUaConfigs = await this.canbusService.getOpcUaConfigs().toPromise();
    console.log(opcUaConfigs);
    if (opcUaConfigs && opcUaConfigs.length > 0) {
      opcUaConfigs.map(sensor => {
        if (sensor) {
          this.opcUaMap.set(sensor.ANCHOR, sensor);

          if (sensor.ISO_CHANNEL) {
            this.configuredIsoCodes.push(sensor.ISO_CHANNEL);
          }
        }
      });
    }
  }

  saveOpcUaConfigs(event: any) {
    this.canbusService.saveOpcUaConfigs(this.rowData).subscribe(resp => {
      this.getOpcUaConfigData();
    });
  }
}
