import { TestBed } from '@angular/core/testing';
import { HttpClientTestingModule } from '@angular/common/http/testing';
import { ToastrModule } from 'ngx-toastr';
import { CanbusService } from './canbus.service';

describe('CanbusService', () => {
  beforeEach(() => TestBed.configureTestingModule({
    imports: [HttpClientTestingModule, ToastrModule.forRoot()]
  }));

  it('should be created', () => {
    const service: CanbusService = TestBed.get(CanbusService);
    expect(service).toBeTruthy();
  });
});
