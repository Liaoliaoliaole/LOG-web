import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable, of } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { UpdateResponse } from '../../models/update-response';
import { environment } from 'src/environments/environment';


@Injectable({
  providedIn: 'root'
})
export class UpdateService {

  constructor(private readonly http: HttpClient) { }
  
  sendUpdateRequestForWeb(): Observable<UpdateResponse> {
    const url = environment.UPDATE_ROOT;
    const headers = new HttpHeaders({
       'Content-Type': 'text/plain; charset=UTF-8'
    });
    const body = {
      update: 'web'
    }
    return this.http.post<any>(url, JSON.stringify(body), {headers});
  }

  sendUpdateRequestForCore(): Observable<UpdateResponse> {
    const url = environment.UPDATE_ROOT;
    const headers = new HttpHeaders({
       'Content-Type': 'text/plain; charset=UTF-8'
    });
    const body = {
      update: 'core'
    }
    return this.http.post<any>(url, JSON.stringify(body), {headers});
  }
}
