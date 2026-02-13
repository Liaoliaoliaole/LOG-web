import { Component, OnInit, OnDestroy, HostListener } from '@angular/core';
import {
  AllCommunityModules,
  ColDef,
  GridOptions,
  RowNodeTransaction,
  CellEvent
} from '@ag-grid-community/all-modules';
import { CanbusService } from '../services/canbus/canbus.service';
import { UpdateService } from './services/update.service';
import { Logstat } from '../models/can-model';
import { TableColumn, FilterEvent } from '../device-table-sidebar/device-table-sidebar.component';
import { OpcUaConfigModel } from '../models/opcua-config-model';
import { ModalService } from 'src/app/modals/services/modal.service';
import { SensorLinkModalComponent } from 'src/app/modals/components/sensor-link-modal/sensor-link-modal.component';
import { SensorLinkModalInitiateModel, SensorLinkModalSubmitModel, SensorLinkModalSubmitAction } from '../models/sensor-link-modal-model';
import { ToastrService } from 'ngx-toastr';
import { DatePipe } from '@angular/common';
import { ModalOptions } from 'src/app/modals/modal-options';
declare const morfeas_logstat_commonizer: any;

export interface CanBusFlatData {
  id: string;
  deviceId: string;
  deviceUserId: string;
  sensorUserId: string;
  anchor: string;
  type: string;
  avgMeasurement: string;
  isoCode: string;
  description: string;
  channelUnit: string;
  minValue: number;
  maxValue: number;
  calibrationDate: Date;
  calibrationPeriod: number;
  isVisible: boolean;
  status: RowStatus;
  isMeasValid: boolean;
  unavailable?: boolean;
  sensorUnit: string; // "Hardcoded" unit for sensor, cannot be changed
}

export enum RowStatus {
  UnlinkedISOCode = 'grey',
  UnlinkedSensor = 'blue',
  UnlinkedSensorError = 'orange',
  LinkedSensorNotPresent = 'yellow',
  LinkedSensorError = 'red',
  LinkedSensor = 'green'
}

@Component({
  selector: 'app-device-info-table',
  templateUrl: './device-info-table.component.html',
  styleUrls: ['./device-info-table.component.scss']
})
export class DeviceInfoTableComponent implements OnInit, OnDestroy {

  secondsInMonth = 2592000;

  columnDefs: ColDef[] = [
    {
      headerName: 'Status', field: 'status', maxWidth: 40, width: 40, minWidth: 40, cellRenderer: (params: any) => {
        params.eGridCell.style.backgroundColor = params.value;
        return ``;
      }, suppressCellFlash: true
    },
    { headerName: 'Device', field: 'deviceUserId' },
    { headerName: 'ISO Code', field: 'isoCode', sort: 'asc' },
    { headerName: 'Description', field: 'description', editable: true },
    { headerName: 'Connection', field: 'deviceId' },
    { headerName: 'Sensor', field: 'sensorUserId' },
    { headerName: 'Type', field: 'type', width: 100 },
    { headerName: 'Unit', field: 'channelUnit', width: 100 },
    { headerName: 'Min Value', field: 'minValue', editable: true, width: 125 },
    { headerName: 'Max Value', field: 'maxValue', editable: true, width: 125 },
    { headerName: 'Avg Measurement', field: 'avgMeasurement', width: 170 },
    {
      headerName: 'Calibration Date', field: 'calibrationDate', cellRenderer: (params: CellEvent) => {

        let value = '-';

        if (params.value instanceof Date && params.value.toString() === 'Invalid Date') {
          return `<span>${value}</span>`;
        }

        if (params.value && !(params.value instanceof Date)) {
          value = this.datePipe.transform(params.value * 1000, 'dd-MM-yyyy HH:mm:ss');
        } else if (params.value instanceof Date) {
          value = this.datePipe.transform(params.value, 'dd-MM-yyyy HH:mm:ss');
        }

        const secondsBeforeCalibrationDateExpires = params.data.calibrationPeriod * this.secondsInMonth;
        const calibrationdateSeconds = Date.now() / 1000 - params.value;

        if (calibrationdateSeconds < secondsBeforeCalibrationDateExpires || value === '-') {
          return `<span>${value}</span>`;
        } else {
          return `<span style="color:red">${value}</span>`;
        }
      }, suppressCellFlash: true
    }
  ];

  rowData: CanBusFlatData[] = [];
  tableColumns: TableColumn[] = [];
  opcUaMap = new Map<string, OpcUaConfigModel>();
  opcUaConfigData: OpcUaConfigModel[];
  canBusPoller: any;
  logstats: Logstat[];
  pause = false;
  configuredIsoCodes: string[] = [];
  connectionNames: string[] = [];
  ifNames: string[] = [];

  showLinked = true;
  showUnlinked = true;

  filters: FilterEvent[] = [];
  showUnsaved = false;
  showSidebar = false;
  showAllComponents = false;

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
    onSortChanged: this.onSortChanged.bind(this),
    rowBuffer: 50,

    isExternalFilterPresent: () => true,
    doesExternalFilterPass: (node) => {
      const row: CanBusFlatData = node.data;
      return this.filters.every(filterFunc => filterFunc(row));
    },

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
    private readonly updateService: UpdateService,
    private readonly toastr: ToastrService,
    private readonly datePipe: DatePipe
  ) { }

  @HostListener('window:beforeunload', ['$event'])
  onBeforeUnload($event) {
    if (this.showUnsaved) {
      $event.returnValue = true;
    }
  }

  onSortChanged(e: any): void {
    const model = this.gridOptions.api.getSortModel();

    if (model && model.length === 1 && model[0].colId === 'sensorUserId') {
      this.gridOptions.api.setSortModel([{
        colId: 'deviceUserId',
        sort: model[0].sort
      }, {
        colId: 'sensorUserId',
        sort: model[0].sort
      }]);
    }
  }

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
      const row: CanBusFlatData = e.data;

      const initiate: SensorLinkModalInitiateModel = {
        anchor: row.anchor,
        unit: row.sensorUnit, // only add if the sensor is "hardcoded" to specific unit
        configuredIsoCodes: this.configuredIsoCodes,
        unlinked: !row.isoCode,
        existingIsoStandard: row.isoCode ? {
          iso_code: row.isoCode,
          attributes: {
            min: row.minValue.toString(),
            max: row.maxValue.toString(),
            description: row.description,
            unit: e.data.channelUnit
          }
        } : null
      };

      this.modalService
        .confirm({
          component: SensorLinkModalComponent,
          data: initiate
        })
        .then((data: SensorLinkModalSubmitModel) => {

          const selectedRow = this.rowData.find(x => x.id === e.data.id);

          this.opcUaMap.set(selectedRow.anchor, {
            ISO_CHANNEL: data.isoStandard.iso_code,
            INTERFACE_TYPE: selectedRow.type,
            ANCHOR: selectedRow.anchor,
            DESCRIPTION: data.isoStandard.attributes.description,
            MIN: +data.isoStandard.attributes.min,
            MAX: +data.isoStandard.attributes.max,
            UNIT: data.isoStandard.attributes.unit,
            CAL_DATE: selectedRow.calibrationDate,
            CAL_PERIOD: selectedRow.calibrationPeriod
          });

          switch (data.action) {

            case SensorLinkModalSubmitAction.Add:
              this.configuredIsoCodes.push(data.isoStandard.iso_code);
              this.togglePause();

              break;

            case SensorLinkModalSubmitAction.Update:

              this.configuredIsoCodes.splice(
                this.configuredIsoCodes.indexOf(e.data.isoCode),
                1,
              );
              this.configuredIsoCodes.push(data.isoStandard.iso_code);
              this.togglePause();

              break;

            case SensorLinkModalSubmitAction.Remove:

              this.opcUaMap.delete(selectedRow.anchor);
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

  resolveRowStatus(row: CanBusFlatData) {

    if (!row.isoCode && !row.isMeasValid) {
      return RowStatus.UnlinkedSensorError;
    }

    if (!row.isoCode && row.avgMeasurement === '-') {
      return RowStatus.UnlinkedISOCode;
    }

    if (!row.isoCode) {
      return RowStatus.UnlinkedSensor;
    }

    if (row.isoCode && !row.isMeasValid) {
      return RowStatus.LinkedSensorError;
    }

    if (row.isoCode && row.avgMeasurement === '-') {
      return RowStatus.LinkedSensorNotPresent;
    }

    return RowStatus.LinkedSensor;
  }

  getLogStatData(initial: boolean) {
    this.canbusService.getLogStatData().subscribe(rawData => {

      const rowData = morfeas_logstat_commonizer(JSON.stringify(rawData));

      this.applyRowData(rowData);

      if (initial) {
        this.getOpcUaConfigData();
      }
    },
      error => {
        console.log(error);
      });
  }

  applyRowData(logstats: Logstat[]) {
    if (!logstats) {
      this.rowData = [];
      this.logstats = [];
      this.gridOptions.api.setRowData([]);
      return;
    }
    this.logstats = logstats;
    this.connectionNames = logstats.map(x => x.if_name);

    if (this.ifNames !== this.connectionNames) {
      this.ifNames = [];
      this.ifNames = this.connectionNames;
    }

    let newData = [];
    logstats.forEach(logstat => {

      if (logstat.sensors !== null) {

        const canBusFlatData = logstat.sensors.map(sensor => {

          let opcUaValue = this.opcUaMap.get(sensor.anchor);
          if (!opcUaValue) {
            opcUaValue = new OpcUaConfigModel();
          }

          const row = {
            id: sensor.anchor,
            anchor: sensor.anchor,
            deviceId: logstat.if_name,
            deviceUserId: sensor.deviceUserIdentifier,
            type: sensor.type,
            status: RowStatus.UnlinkedSensor,
            channelUnit: sensor.unit || opcUaValue.UNIT,
            avgMeasurement: sensor.avgMeasurement === null ? '-' : sensor.avgMeasurement,
            isMeasValid: sensor.Is_meas_valid,
            calibrationDate: sensor.calibrationDate,
            calibrationPeriod: sensor.calibrationPeriod,
            isVisible: true,
            sensorUserId: sensor.sensorUserId,
            minValue: opcUaValue.MIN,
            maxValue: opcUaValue.MAX,
            description: opcUaValue.DESCRIPTION,
            isoCode: opcUaValue.ISO_CHANNEL,
            sensorUnit: sensor.unit
          } as CanBusFlatData;

          row.status = this.resolveRowStatus(row);
          return row;

        });

        newData = newData.concat(canBusFlatData);
      }
    });

    if (!this.rowData && this.rowData.length === 0) {
      this.rowData = newData;
      this.gridOptions.api.setRowData(this.rowData);
    }

    this.rowData.forEach(oldRow => {
      if (!newData.find(newRow => newRow.id === oldRow.id)) {
        // unconfigured sensor lost connection
        if (!this.opcUaMap.has(oldRow.anchor)) {
          this.removeRow(oldRow);
        } else {
          // configured sensor needs to be updated to "default" as the connection is lost
          this.updateRow(this.createUnlinkedConfiguredSensor(this.opcUaMap.get(oldRow.anchor)));
        }
      }
    });

    newData.forEach(row => {
      if (this.rowData.find(newRow => newRow.id === row.id)) {
        this.updateRow(row);
      } else if (!this.opcUaMap.has(row.anchor)) {
        this.addRow(row);
      } else {
        const unavailableRow = this.rowData.find(uRow => uRow.deviceUserId === row.deviceUserId && uRow.sensorUserId === row.sensorUserId);

        this.removeRow(unavailableRow);
        this.addRow(row);
      }
    });

    this.gridOptions.api.refreshCells({ columns: ['calibrationDate'], force: true });
  }

  getRowNodeId = (data: any) => data.id;

  async getOpcUaConfigData() {
    const opcUaConfigs = await this.canbusService.getOpcUaConfigs().toPromise();
    if (opcUaConfigs && opcUaConfigs.length > 0) {
      opcUaConfigs.forEach(opcUaEntry => {
        if (opcUaEntry) {
          this.opcUaMap.set(opcUaEntry.ANCHOR, opcUaEntry);

          const relatedSensor = this.rowData.find(row => row.anchor === opcUaEntry.ANCHOR);
          let newRow: CanBusFlatData;

          if (!relatedSensor) {
            newRow = this.createUnlinkedConfiguredSensor(opcUaEntry);

            this.rowData.push(newRow);
            setTimeout(() => {
              this.gridOptions.api.batchUpdateRowData(
                { add: [newRow], },
                (result: RowNodeTransaction) => {
                }
              );
            }, 0);
          }

          if (opcUaEntry.ISO_CHANNEL) {
            this.configuredIsoCodes.push(opcUaEntry.ISO_CHANNEL);
          }
        }
      });
    }
  }

  createUnlinkedConfiguredSensor(opcUaEntry: OpcUaConfigModel): CanBusFlatData {
    const d = new Date(opcUaEntry.CAL_DATE);
    return {
      id: opcUaEntry.ANCHOR,
      anchor: opcUaEntry.ANCHOR,
      deviceId: null,
      isoCode: opcUaEntry.ISO_CHANNEL,
      deviceUserId: null,
      // sdaqSerial: serial,
      type: opcUaEntry.INTERFACE_TYPE,
      sensorUserId: null,
      channelUnit: opcUaEntry.UNIT,
      minValue: +opcUaEntry.MIN,
      maxValue: +opcUaEntry.MAX,
      description: opcUaEntry.DESCRIPTION,
      avgMeasurement: '-',
      calibrationDate: d,
      calibrationPeriod: opcUaEntry.CAL_PERIOD,
      status: RowStatus.LinkedSensorNotPresent,
      unavailable: true,
      isVisible: true,
      isMeasValid: null,
      sensorUnit: null
    };
  }

  saveOpcUaConfigs() {
    this.canbusService.saveOpcUaConfigs(this.rowData).subscribe(resp => {
      this.toastr.success('Okay');
      this.showUnsaved = false;
    }, error => {
      this.toastr.error(error.error.text, 'Fatal Error!!!', { disableTimeOut: true });
    });
  }

  toggleFilter(filters: FilterEvent[]) {
    this.filters = filters;
  }

  toggleSidebar() {
    this.showSidebar = !this.showSidebar;
  }
/*
  toggleMorfeasConfigModal() {
    this.togglePause();

    this.modalService
      .confirm({
        component: MorfeasConfigModalComponent
      })
      .then(() => {

      })
      .catch((err: any) => {
        this.togglePause();
        console.log(err);
      });
  }

  toggleConfigModal() {
    this.togglePause();

    this.modalService
      .confirm({
        component: ConfigModalComponent
      })
      .then(() => {

      })
      .catch((err: any) => {
        this.togglePause();
        console.log(err);
      });
  }

  // TODO: maybe clean up these a bit at some point
  toggleFileModal() {
    this.togglePause();

    this.modalService
      .confirm({
        component: FileModalComponent
      })
      .then(() => {

      })
      .catch((err: any) => {
        this.togglePause();
        console.log(err);
      });
  }
*/
  clearFilters() {
    this.gridOptions.api.setFilterModel(null);
  }
  isUpdatingCore = false;
  updateMorfeasCore() {
    alert('Updating Morfeas Core. This can take several minutes to complete.');
    this.isUpdatingCore = true;
    this.updateService.sendUpdateRequestForCore()
      .subscribe(response => {
        this.isUpdatingCore = false;
        alert(response.message);
        console.log(response.shell_output);
      });
  }
  isUpdatingWeb = false;
  updateMorfeasWeb() {
    alert('Updating Morfeas Web. This can take few minutes.');
    this.isUpdatingWeb = true;
    this.updateService.sendUpdateRequestForWeb()
      .subscribe(response => {
        this.isUpdatingWeb = false;
        alert(response.message);
        console.log(response.shell_output);
      });
  }
  updateButtonsVisible = false;
  toggleUpdateButtons() {
    this.updateButtonsVisible = !this.updateButtonsVisible
  }

  allComponents() {
    this.showAllComponents = !this.showAllComponents;
  }
}
