import { async, ComponentFixture, TestBed } from '@angular/core/testing';

import { CanbusDetailsBarComponent } from './canbus-details-bar.component';

describe('CanbusDetailsBarComponent', () => {
  let component: CanbusDetailsBarComponent;
  let fixture: ComponentFixture<CanbusDetailsBarComponent>;

  beforeEach(async(() => {
    TestBed.configureTestingModule({
      declarations: [ CanbusDetailsBarComponent ]
    })
    .compileComponents();
  }));

  beforeEach(() => {
    fixture = TestBed.createComponent(CanbusDetailsBarComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
