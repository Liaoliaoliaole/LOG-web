import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { CanBus } from '../../can-model';
import { Observable, of, forkJoin } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { environment } from 'src/environments/environment';
import { CanBusFlatData } from '../../device-info-table/device-info-table.component';

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

  saveOpcUaConfigs(canbusData:CanBusFlatData[]): Observable<any> {
    let url = `${environment.API_ROOT}/api/save_opc_ua_configs`;
    let httpHeaders = new HttpHeaders({
      'Content-Type' : 'application/json',
      'Cache-Control': 'no-cache'
    });
    let options = {
      headers: httpHeaders
    };

    // NOTE: uncomment this after linking sensors with isocodes has been implemented.
    // canbusData = canbusData.filter(sensor => {
    //   return (sensor.isoCode);
    // });
    
    this.http.post(url, JSON.stringify(canbusData), options).subscribe((response) => {
      console.log(response);
    });

    return null;
  }

  private handleCanbusError = (error) => {
    console.error(error);
    return of(null);
  }
}
