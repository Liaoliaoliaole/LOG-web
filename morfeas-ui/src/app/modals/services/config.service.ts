import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from 'src/environments/environment';
import { NetworkInterface, NetworkInterfaceResponse } from '../models/network-interface-model';
import { NTPSettingsModel } from '../models/NTP-settings-model';
import { LogListModel } from '../models/log-list-model';
import { HostnameModel } from '../models/hostname-model';


@Injectable({
    providedIn: 'root'
})
export class ConfigService {
    constructor(private readonly http: HttpClient) { }

    getHostname(): Observable<HostnameModel> {
        const url = `${environment.API_ROOT}/settings/network/hostname`;
        return this.http.get<HostnameModel>(url);
    }

    saveHostname(hostname: HostnameModel): Observable<void> {
        const url = `${environment.API_ROOT}/settings/network/hostname`;
        const httpHeaders = new HttpHeaders({
            'Content-Type': 'application/json',
        });
        const options = {
            headers: httpHeaders
        };

        return this.http.post<void>(url, JSON.stringify(hostname), options);
    }

    getNTPSettings(): Observable<NTPSettingsModel> {
        const url = `${environment.API_ROOT}/settings/network/ntp`;
        return this.http.get<NTPSettingsModel>(url);
    }

    saveNTPSettings(ntpSettings: NTPSettingsModel): Observable<void> {
        const url = `${environment.API_ROOT}/settings/network/ntp`;
        const httpHeaders = new HttpHeaders({
            'Content-Type': 'application/json',
        });
        const options = {
            headers: httpHeaders
        };

        return this.http.post<void>(url, JSON.stringify(ntpSettings), options);
    }

    getNetworkInterfaces(): Observable<NetworkInterfaceResponse> {
        const url = `${environment.API_ROOT}/settings/network/interfaces`;
        return this.http.get<NetworkInterfaceResponse>(url);
    }

    saveNetworkInterfaces(networkInterfaces: NetworkInterface[]): Observable<void> {
        const url = `${environment.API_ROOT}/settings/network/interfaces`;
        const httpHeaders = new HttpHeaders({
            'Content-Type': 'application/json',
        });
        const options = {
            headers: httpHeaders
        };

        return this.http.post<void>(url, JSON.stringify(networkInterfaces), options);
    }

    getLogList(): Observable<LogListModel> {
        const url = `${environment.API_ROOT}/logs`;
        return this.http.get<LogListModel>(url);
    }
}
