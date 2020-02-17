import { Component, OnInit, OnDestroy } from '@angular/core';
import {
  AllCommunityModules,
  ColDef,
  GridOptions,
  RowNodeTransaction
} from '@ag-grid-community/all-modules';
import { CanbusService } from '../services/canbus/canbus.service';
import { CanBusModel, SDAQData } from '../models/can-model';
import { TableColumn } from '../device-table-sidebar/device-table-sidebar.component';
import { OpcUaConfigModel } from '../models/opcua-config-model';
import { ModalService } from 'src/app/modals/services/modal.service';
import { SensorLinkModalComponent } from 'src/app/modals/components/sensor-link-modal/sensor-link-modal.component';
import { SensorLinkModalInitiateModel, SensorLinkModalSubmitModel, SensorLinkModalSubmitAction } from '../models/sensor-link-modal-model';
import { ToastrService } from 'ngx-toastr';

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
  avgMeasurement: number;
  isVisible: boolean;
  unavailable?: boolean;
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
    { headerName: 'Description', field: 'description', editable: true },
    { headerName: 'Avg Measurement', field: 'avgMeasurement' },
  ];

  rowData: CanBusFlatData[] = [];
  tableColumns: TableColumn[] = [];
  opcUaMap = new Map<string, OpcUaConfigModel>();
  opcUaConfigData: OpcUaConfigModel[];
  canBusPoller: any;
  canBusDetails: CanBusModel[];
  pause = false;
  configuredIsoCodes: string[] = [];

  showLinked = true;
  showUnlinked = true;

  showCan1 = true;
  showCan2 = true;

  showUnsaved = false;

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
    suppressRowTransform: true,
    suppressScrollOnNewData: true,
    batchUpdateWaitMillis: 50,

    isExternalFilterPresent: () => true,
    doesExternalFilterPass: (node) => node.data.isVisible,

    onGridReady: async () => {
      // Visualize data on the table

      this.getLogStatData(true);

      this.canBusPoller = setInterval(() => {
        if (!this.pause) {
          this.getLogStatData(false);
        }
      }, 800);
    }
  };

  modules = AllCommunityModules;
  constructor(
    private readonly canbusService: CanbusService,
    private readonly modalService: ModalService,
    private readonly toastr: ToastrService
  ) { }

  onCellEdittingStarted(e: any): void {
    this.togglePause(); // enalbe pause mode
  }

  onCellEdittingStopped(e: any): void {
    this.togglePause(); // disable pause mode
  }

  onCellClicked(e: any): void {
    if (e.colDef.field === 'isoCode') {
      // open popup dialog when ISO cell is clicked
      this.togglePause(); // enable pause mode when dialog is to be open

      const initiate: SensorLinkModalInitiateModel = {
        unit: e.data.channelUnit,
        configuredIsoCodes: this.configuredIsoCodes,
        unlinked: !e.data.isoCode,
        existingIsoStandard: e.data.isoCode ? {
          iso_code: e.data.isoCode,
          attributes: {
            min: e.data.minValue,
            max: e.data.maxValue,
            description: e.data.description,
            unit: e.data.unit
          }
        } : null
      };

      this.modalService
        .confirm({
          component: SensorLinkModalComponent,
          data: initiate
        })
        .then((data: SensorLinkModalSubmitModel) => {

          const selectedRow = this.rowData.find(
            x =>
              x.id === e.data.id
          );

          switch (data.action) {

            case SensorLinkModalSubmitAction.Add:

              const anchor = this.canbusService.generateAnchor(selectedRow.sdaqSerial, selectedRow.channelId);

              this.opcUaMap.set(anchor, {
                ISO_CHANNEL: data.isoStandard.iso_code,
                INTERFACE_TYPE: selectedRow.sdaqType,
                ANCHOR: anchor,
                DESCRIPTION: data.isoStandard.attributes.description,
                MIN: +data.isoStandard.attributes.min,
                MAX: +data.isoStandard.attributes.max,
              });

              this.configuredIsoCodes.push(data.isoStandard.iso_code);
              this.togglePause();

              break;

            case SensorLinkModalSubmitAction.Update:

              this.configuredIsoCodes.splice(
                this.configuredIsoCodes.indexOf(e.data.isoCode),
                1,
              );

              const updateAnchor = this.canbusService.generateAnchor(selectedRow.sdaqSerial, selectedRow.channelId);

              this.opcUaMap.set(updateAnchor, {
                ISO_CHANNEL: data.isoStandard.iso_code,
                INTERFACE_TYPE: selectedRow.sdaqType,
                ANCHOR: updateAnchor,
                DESCRIPTION: data.isoStandard.attributes.description,
                MIN: +data.isoStandard.attributes.min,
                MAX: +data.isoStandard.attributes.max,
              });

              this.configuredIsoCodes.push(data.isoStandard.iso_code);
              this.togglePause();

              break;

            case SensorLinkModalSubmitAction.Remove:

              const removeAnchor = this.canbusService.generateAnchor(selectedRow.sdaqSerial, selectedRow.channelId);

              this.opcUaMap.delete(removeAnchor);

              this.configuredIsoCodes.splice(
                this.configuredIsoCodes.indexOf(e.data.isoCode),
                1,
              );
              this.togglePause();

              break;
          }

          this.showUnsaved = true;

        })
        .catch((err: any) => {
          this.togglePause();
          console.log(err);
        });
    }
  }

  addRow(row: CanBusFlatData) {
    this.rowData.push(row);
    setTimeout(() => {
      this.gridOptions.api.batchUpdateRowData(
        { add: [row], },
        (result: RowNodeTransaction) => {
        }
      );
    }, 0);
  }

  updateRow(row: CanBusFlatData) {
    this.rowData[this.rowData.findIndex(element => element.id === row.id)] = row;
    setTimeout(() => {
      this.gridOptions.api.batchUpdateRowData(
        { update: [row], },
        (result: RowNodeTransaction) => {
        }
      );
    }, 0);
  }

  removeRow(row: CanBusFlatData) {
    this.rowData.splice(
      this.rowData.indexOf(row),
      1,
    );
    setTimeout(() => {
      this.gridOptions.api.batchUpdateRowData(
        { remove: [row], },
        (result: RowNodeTransaction) => {
        }
      );
    }, 0);
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

  flattenRowData(canBusArr: CanBusModel[]): CanBusFlatData[] {
    const canBus = canBusArr.filter(x => x != null);
    if (canBus == null || canBus.length === 0) {
      return [];
    }

    return canBus.reduce((canBusArray: CanBusFlatData[], can) => {
      const canBusDataRow = can.SDAQs_data.reduce(
        (flattenArray: CanBusFlatData[], sdaqData: SDAQData) => {
          const dataPoint = {
            canBus: can['CANBus-interface'],
            sdaqAddress: sdaqData.Address,
            sdaqSerial: sdaqData.Serial_number,
            sdaqType: sdaqData.SDAQ_type
          } as CanBusFlatData;

          if (
            !sdaqData.Calibration_Data ||
            sdaqData.Calibration_Data.length === 0
          ) {
            flattenArray.push(dataPoint);
            return flattenArray;
          }

          const measPoints: any = sdaqData.Meas.map(
            measurementData => {
              return {
                channel: measurementData.Channel,
                avgMeasurement: measurementData.Meas_avg
              };
            }
          );

          const dataPoints: CanBusFlatData[] = sdaqData.Calibration_Data.map(
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

              const isoCode = opcUaConf ? opcUaConf.ISO_CHANNEL : null;
              const channelId = calibrationData.Channel;
              const measPoint = measPoints.find(meas => meas.channel === channelId);
              let isVisible = isoCode ? this.showLinked : this.showUnlinked;

              // TODO: once more filters are wanted maybe figure something better here and refactor the whole thing
              if (isVisible) {
                if (can['CANBus-interface'] === 'can0') {
                  isVisible = this.showCan1;
                } else if (can['CANBus-interface'] === 'can1') {
                  isVisible = this.showCan2;
                }
              }

              const result = {
                id:
                  can['CANBus-interface'] +
                  '_' +
                  sdaqData.Address +
                  '_' +
                  sdaqData.Serial_number + '_' + calibrationData.Channel,
                channelId,
                channelUnit: calibrationData.Unit,
                isoCode,
                description: opcUaConf ? opcUaConf.DESCRIPTION : null,
                minValue: opcUaConf ? opcUaConf.MIN : null,
                maxValue: opcUaConf ? opcUaConf.MAX : null,
                avgMeasurement: measPoint ? measPoint.avgMeasurement : null,
                isVisible
              };
              return Object.assign(result, dataPoint);
            }
          );

          return flattenArray.concat(dataPoints);
        },
        []
      );
      return canBusArray.concat(canBusDataRow);
    }, []);
  }

  getLogStatData(initial: boolean) {
    this.canbusService.getLogStatData().subscribe(rowData => {
      this.applyRowData(rowData);

      if (initial) {
        this.getOpcUaConfigData();
      }
    },
      error => {
        console.log(error);
      });
  }

  applyRowData(rowData: CanBusModel[]) {
    const details = [];

    rowData.forEach(row => {
      const canInterface = row['CANBus-interface'];
      details.push({
        logstat_build_date_UNIX: row.logstat_build_date_UNIX,
        'CANBus-interface': canInterface.replace(/\d+$/, (n) => { const num = parseInt(n, 10) + 1; return num.toString(); }),
        BUS_voltage: row.BUS_voltage,
        BUS_amperage: row.BUS_amperage,
        BUS_Shunt_Res_temp: row.BUS_Shunt_Res_temp,
        BUS_Utilization: row.BUS_Utilization,
        Detected_SDAQs: row.Detected_SDAQs,
      });
    });

    this.canBusDetails = details;
    const newData = this.flattenRowData(rowData);

    if (this.rowData && this.rowData.length > 0) {

      this.rowData.forEach(row => {

        const anchor = this.canbusService.generateAnchor(row.sdaqSerial, row.channelId);

        if (!newData.find(newRow => newRow.id === row.id) && !this.opcUaMap.has(anchor)) {
          this.removeRow(row);
        }
      });

      newData.forEach(row => {

        const anchor = this.canbusService.generateAnchor(row.sdaqSerial, row.channelId);

        if (this.rowData.find(newRow => newRow.id === row.id)) {
          this.updateRow(row);
        } else if (!this.opcUaMap.has(anchor)) {
          this.addRow(row);
        } else {

          const unavailableRow = this.rowData.find(uRow => uRow.channelId === row.channelId && uRow.sdaqSerial === row.sdaqSerial);

          this.removeRow(unavailableRow);
          this.addRow(row);
        }
      });
    } else {
      this.rowData = newData;
      this.gridOptions.api.setRowData(this.rowData);
    }
  }

  getRowNodeId = (data: any) => data.id;

  async getOpcUaConfigData() {
    const opcUaConfigs = await this.canbusService.getOpcUaConfigs().toPromise();
    if (opcUaConfigs && opcUaConfigs.length > 0) {
      opcUaConfigs.forEach(sensor => {
        if (sensor) {
          this.opcUaMap.set(sensor.ANCHOR, sensor);

          const serial = +sensor.ANCHOR.split('.')[0];
          const channel = +sensor.ANCHOR.split('.')[1].replace('CH', '');

          const selectedRow = this.rowData.find(row => row.channelId === channel && row.sdaqSerial === serial);
          let newRow: CanBusFlatData;

          if (!selectedRow) {
            newRow = {
              id: 'unavailable_' + serial + '_' + channel,
              canBus: null,
              isoCode: sensor.ISO_CHANNEL,
              sdaqAddress: null,
              sdaqSerial: serial,
              sdaqType: sensor.INTERFACE_TYPE,
              channelId: channel,
              channelUnit: null,
              minValue: +sensor.MIN,
              maxValue: +sensor.MAX,
              description: sensor.DESCRIPTION,
              avgMeasurement: null,
              unavailable: true,
              isVisible: true
            };

            this.rowData.push(newRow);
            setTimeout(() => {
              this.gridOptions.api.batchUpdateRowData(
                { add: [newRow], },
                (result: RowNodeTransaction) => {
                }
              );
            }, 0);
          }

          if (sensor.ISO_CHANNEL) {
            this.configuredIsoCodes.push(sensor.ISO_CHANNEL);
          }
        }
      });
    }
  }

  saveOpcUaConfigs(event: any) {
    this.canbusService.saveOpcUaConfigs(this.rowData).subscribe(resp => {
      this.toastr.success('OPC UA Configuration saving successful');
      this.showUnsaved = false;
    }, error => {
      this.toastr.error(error, 'OPC UA Configuration saving failure');
    });
  }

  toggleLinkedFilter(value: boolean) {
    this.showLinked = value;
  }

  toggleUnlinkedFilter(value: boolean) {
    this.showUnlinked = value;
  }

  toggleCan1Filter(value: boolean) {
    this.showCan1 = value;
  }

  toggleCan2Filter(value: boolean) {
    this.showCan2 = value;
  }
}
