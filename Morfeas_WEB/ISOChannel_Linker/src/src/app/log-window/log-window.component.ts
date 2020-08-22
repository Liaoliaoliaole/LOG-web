import { Component, OnInit, Input } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { LogWindowService } from './log-window.service';

@Component({
    selector: 'app-log-window',
    templateUrl: './log-window.component.html',
    styleUrls: ['./log-window.component.scss']
})
export class LogWindowComponent implements OnInit {

    log: string;
    logPath: string;
    logPoller: any;

    logData: string;

    constructor(private routeParams: ActivatedRoute, private logService: LogWindowService) { }

    ngOnInit() {
        this.routeParams.queryParams.subscribe(params => {
            // tslint:disable-next-line: no-string-literal
            this.log = params['log'];
            // tslint:disable-next-line: no-string-literal
            this.logPath = params['path'];

            if (!this.log || !this.logPath) {
                this.logData = 'No log path or log file query parameter supplied';
                return;
            }

            this.logPoller = setInterval(() => {
                this.getLog();
            }, 500);
        });
    }

    getLog() {
        this.logService.getLogData(this.logPath + this.log).subscribe(result => {
            this.logData = result;
        });
    }
}
