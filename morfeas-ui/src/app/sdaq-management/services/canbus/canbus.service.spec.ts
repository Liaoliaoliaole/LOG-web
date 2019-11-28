import { TestBed } from '@angular/core/testing';

import { CanbusService } from './canbus.service';

describe('CanbusService', () => {
  beforeEach(() => TestBed.configureTestingModule({}));

  it('should be created', () => {
    const service: CanbusService = TestBed.get(CanbusService);
    expect(service).toBeTruthy();
  });
});
