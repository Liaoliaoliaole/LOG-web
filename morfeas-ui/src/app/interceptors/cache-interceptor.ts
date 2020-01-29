import { Injectable } from '@angular/core';
import { HttpInterceptor, HttpRequest, HttpHandler, HttpHeaders } from '@angular/common/http';

@Injectable()
export class CacheInterceptor implements HttpInterceptor {

    intercept(req: HttpRequest<any>, next: HttpHandler) {
        const httpRequest = req.clone({
            headers: new HttpHeaders({
                'Cache-Control': 'no-store',
                Pragma: 'no-cache',
                Expires: '0'
            })
        });

        return next.handle(httpRequest);
    }
}
