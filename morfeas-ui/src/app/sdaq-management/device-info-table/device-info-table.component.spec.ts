import { async, ComponentFixture, TestBed } from '@angular/core/testing';

import { DeviceInfoTableComponent } from './device-info-table.component';
import { AgGridModule } from '@ag-grid-community/angular';
import { DeviceTableSidebarComponent } from '../device-table-sidebar/device-table-sidebar.component';
import { HttpClientTestingModule } from '@angular/common/http/testing';
import { ModalsModule } from 'src/app/modals/modals.module';
import { CanbusDetailsBarComponent } from 'src/app/canbus-details-bar/canbus-details-bar.component';

import { BrowserAnimationsModule } from '@angular/platform-browser/animations';
import { ToastrModule } from 'ngx-toastr';
import { DatePipe } from '@angular/common';

describe('DeviceInfoTableComponent', () => {
  let component: DeviceInfoTableComponent;
  let fixture: ComponentFixture<DeviceInfoTableComponent>;

  beforeEach(async(() => {
    TestBed.configureTestingModule({
      declarations: [DeviceInfoTableComponent, DeviceTableSidebarComponent, CanbusDetailsBarComponent
      ],
      imports: [
        HttpClientTestingModule,
        ModalsModule,
        AgGridModule.forRoot(),
        BrowserAnimationsModule,
        ToastrModule.forRoot({
          timeOut: 2000,
          positionClass: 'toast-bottom-center',
        })],
      providers: [DatePipe]
    })
      .compileComponents();
  }));

  beforeEach(() => {
    fixture = TestBed.createComponent(DeviceInfoTableComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
