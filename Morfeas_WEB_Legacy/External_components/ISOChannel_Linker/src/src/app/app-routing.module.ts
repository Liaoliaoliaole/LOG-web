import { NgModule } from '@angular/core';
import { Routes, RouterModule } from '@angular/router';
import { DeviceInfoTableComponent } from './sdaq-management/device-info-table/device-info-table.component';

const routes: Routes = [
  { path: '', component: DeviceInfoTableComponent }
];

@NgModule({
  imports: [RouterModule.forRoot(routes)],
  exports: [RouterModule]
})
export class AppRoutingModule { }
