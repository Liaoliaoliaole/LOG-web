import { NgModule } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { AgGridModule } from '@ag-grid-community/angular';
import { DeviceInfoTableComponent } from './device-info-table/device-info-table.component';
import { DeviceTableSidebarComponent } from './device-table-sidebar/device-table-sidebar.component';
import { ModalsModule } from '../modals/modals.module';
import { CanbusDetailsBarComponent } from '../canbus-details-bar/canbus-details-bar.component';

@NgModule({
  declarations: [
    DeviceInfoTableComponent,
    DeviceTableSidebarComponent,
    CanbusDetailsBarComponent
  ],
  imports: [CommonModule, AgGridModule.withComponents([]), ModalsModule],
  exports: [DeviceInfoTableComponent],
  providers: [DatePipe]
})
export class SdaqManagementModule { }
