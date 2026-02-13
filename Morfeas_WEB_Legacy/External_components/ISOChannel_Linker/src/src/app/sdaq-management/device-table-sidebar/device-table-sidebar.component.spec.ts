import { async, ComponentFixture, TestBed } from '@angular/core/testing';

import { DeviceTableSidebarComponent } from './device-table-sidebar.component';

describe('DeviceTableSidebarComponent', () => {
  let component: DeviceTableSidebarComponent;
  let fixture: ComponentFixture<DeviceTableSidebarComponent>;

  beforeEach(async(() => {
    TestBed.configureTestingModule({
      declarations: [ DeviceTableSidebarComponent ]
    })
    .compileComponents();
  }));

  beforeEach(() => {
    fixture = TestBed.createComponent(DeviceTableSidebarComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
