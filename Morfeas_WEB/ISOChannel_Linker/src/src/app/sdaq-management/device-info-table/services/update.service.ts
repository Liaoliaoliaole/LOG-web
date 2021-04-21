import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable, of } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { UpdateResponse } from '../../models/update-response';
import { environment } from 'src/environments/environment';

declare const compress: any;

@Injectable({
  providedIn: 'root'
})
export class UpdateService {

  constructor(private readonly http: HttpClient) { }
  
  sendUpdateRequestForWeb(fromOnline = false): Observable<UpdateResponse> {
    const url = environment.UPDATE_ROOT;
    const headers = new HttpHeaders({
       'Content-Type': 'text/plain; charset=UTF-8'
    });
    const body = {
      update: 'web',
      online: fromOnline
    }
    return this.http.post<any>(url, compress(JSON.stringify(body)), {headers});
  }

  sendUpdateRequestForCore(fromOnline = false): Observable<UpdateResponse> {
    const url = environment.UPDATE_ROOT;
    const headers = new HttpHeaders({
       'Content-Type': 'text/plain; charset=UTF-8'
    });
    const body = {
      update: 'core',
      online: fromOnline
    }
    return this.http.post<any>(url, compress(JSON.stringify(body)), {headers});
  }
}
