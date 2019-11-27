import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { AgGridModule } from '@ag-grid-community/angular';
import { DeviceInfoTableComponent } from './device-info-table/device-info-table.component';


@NgModule({
  declarations: [DeviceInfoTableComponent],
  imports: [
    CommonModule,
    AgGridModule.withComponents([])
  ],
  exports: [DeviceInfoTableComponent]
})
export class SdaqManagementModule { }
