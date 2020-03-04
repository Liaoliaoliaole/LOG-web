import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from 'src/environments/environment';
import { NetworkInterface, NetworkInterfaceResponse } from '../models/network-interface-model';


@Injectable({
    providedIn: 'root'
})
export class ConfigService {
    constructor(private readonly http: HttpClient) { }

    getNetworkInterfaces(): Observable<NetworkInterfaceResponse> {
        const url = `${environment.API_ROOT}/api/settings/network/interfaces`;
        return this.http.get<NetworkInterfaceResponse>(url);
    }

    saveNetworkInterfaces(networkInterfaces: NetworkInterface[]): Observable<void> {
        const url = `${environment.API_ROOT}/api/settings/network/interfaces`;
        const httpHeaders = new HttpHeaders({
            'Content-Type': 'application/json',
        });
        const options = {
            headers: httpHeaders
        };

        return this.http.post<void>(url, JSON.stringify(networkInterfaces), options);
    }
}
