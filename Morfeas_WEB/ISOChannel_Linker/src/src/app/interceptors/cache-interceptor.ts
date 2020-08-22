import { Injectable } from '@angular/core';
import { HttpInterceptor, HttpRequest, HttpHandler, HttpHeaders } from '@angular/common/http';

@Injectable()
export class CacheInterceptor implements HttpInterceptor {

    intercept(req: HttpRequest<any>, next: HttpHandler) {
        req = req.clone({ headers: req.headers.set('Cache-Control', 'no-store') });
        req = req.clone({ headers: req.headers.set('Pragma', 'no-cache') });
        req = req.clone({ headers: req.headers.set('Expires', '0') });

        return next.handle(req);
    }
}
