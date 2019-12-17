import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { AgGridModule } from '@ag-grid-community/angular';
import { DeviceInfoTableComponent } from './device-info-table/device-info-table.component';
import { DeviceTableSidebarComponent } from './device-table-sidebar/device-table-sidebar.component';
import { ModalsModule } from '../modals/modals.module';


@NgModule({
  declarations: [DeviceInfoTableComponent, DeviceTableSidebarComponent],
  imports: [
    CommonModule,
    AgGridModule.withComponents([]),
    ModalsModule
  ],
  exports: [DeviceInfoTableComponent]
})
export class SdaqManagementModule { }
