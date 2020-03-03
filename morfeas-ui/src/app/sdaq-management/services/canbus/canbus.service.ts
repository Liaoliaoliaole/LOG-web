import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders, HttpParams } from '@angular/common/http';
import { CanBusModel } from '../../models/can-model';
import { Observable, of, forkJoin } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { environment } from 'src/environments/environment';
import { CanBusFlatData } from '../../device-info-table/device-info-table.component';
import { IsoStandard } from '../../models/iso-standard-model';
import { OpcUaConfigModel } from '../../models/opcua-config-model';
import { ToastrService } from 'ngx-toastr';

@Injectable({
  providedIn: 'root'
})
export class CanbusService {
  constructor(private readonly http: HttpClient, private readonly toastr: ToastrService) { }

  getLogStatData(): Observable<CanBusModel[]> {
    const url = `${environment.API_ROOT}/ramdisk/`;
    const can0 = this.http.get<CanBusModel>(url + 'logstat_can0.json').pipe(
      catchError(this.handleCanbusError));
    const can1 = this.http.get<CanBusModel>(url + 'logstat_can1.json').pipe(
      catchError(this.handleCanbusError));

    return forkJoin(can0, can1);
  }

  getIsoCodesByUnit(unit: string): Observable<IsoStandard[]> {
    const url = `${environment.API_ROOT}/api/get_iso_codes_by_unit`;
    const params = new HttpParams().set('unit', unit);
    return this.http.get<IsoStandard[]>(url, { params });
  }

  getOpcUaConfigs(): Observable<OpcUaConfigModel[]> {
    const url = `${environment.API_ROOT}/api/get_opc_ua_configs`;
    const result = this.http.get<any>(url).pipe(
      catchError(this.handleCanbusError)
    );

    return result;
  }

  saveOpcUaConfigs(canbusData: CanBusFlatData[]): Observable<void> {
    const url = `${environment.API_ROOT}/api/save_opc_ua_configs`;
    const httpHeaders = new HttpHeaders({
      'Content-Type': 'application/json',
    });
    const options = {
      headers: httpHeaders
    };

    // NOTE: uncomment this after linking sensors with isocodes has been implemented.
    // canbusData = canbusData.filter(sensor => {
    //   return (sensor.isoCode);
    // });

    return this.http.post<void>(url, JSON.stringify(canbusData), options);
  }

  generateAnchor(sdaqSerial: number, channelNumber: number) {
    return sdaqSerial + '.CH' + channelNumber;
  }

  private handleCanbusError = (error) => {
    console.error(error);
    this.toastr.error(error.message, 'Error fetching logstat data, check ip address', { disableTimeOut: true });
    return of(null);
  }
}
