import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { CanBus } from '../../can-model';
import { Observable, of, forkJoin } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { environment } from 'src/environments/environment';

@Injectable({
  providedIn: 'root'
})
export class CanbusService {

  constructor(private readonly http: HttpClient) { }

  getCanbusData(): Observable<CanBus[]> {
    const url = `${environment.API_ROOT}/ramdisk/`;
    const can0 = this.http.get<CanBus>(url + 'logstat_can0.json').pipe(
      catchError(this.handleCanbusError));
    const can1 = this.http.get<CanBus>(url + 'logstat_can1.json').pipe(
      catchError(this.handleCanbusError));
      
    return forkJoin(can0, can1);
  }

  private handleCanbusError = (error) => {
    console.error(error);
    return of(null);
  }
}
