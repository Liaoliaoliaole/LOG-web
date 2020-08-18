import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable, of } from 'rxjs';
import { environment } from 'src/environments/environment';
import { catchError } from 'rxjs/operators';
import { ToastrService } from 'ngx-toastr';

@Injectable({
    providedIn: 'root'
})
export class LogWindowService {
    constructor(private readonly http: HttpClient, private readonly toastr: ToastrService) { }

    getLogData(log: string): Observable<string> {

        const url = `${environment.ROOT}/${log}`;
        const headers = new HttpHeaders().set('Content-Type', 'text/plain; charset=utf-8');
        // NOTE: responseType: 'text' as 'json' ???
        const data = this.http.get<string>(url, { headers, responseType: 'text' as 'json' }).pipe(catchError(this.handleError));

        return data as any;
    }

    private handleError = (error) => {
        console.error(error);
        this.toastr.error(error.message, 'Error fetching log', { disableTimeOut: true });
        return of(null);
    }
}
