import { ModalState } from '../../services/modal-state.service';
import { Component, OnInit } from '@angular/core';
import { ToastrService } from 'ngx-toastr';
import { ConfigService } from '../../services/config.service';
import { Router } from '@angular/router';

@Component({
    selector: 'app-modal-log',
    templateUrl: './log-modal.component.html',
    styleUrls: ['./log-modal.component.scss']
})
export class LogModalComponent implements OnInit {

    logs: string[];

    logPath: string;

    constructor(
        private readonly state: ModalState,
        private readonly configService: ConfigService,
        private readonly toastr: ToastrService,
        private readonly router: Router
    ) {
    }

    ngOnInit() {

        this.configService.getLogList().subscribe(result => {

            this.logs = result.logList;
            this.logPath = result.logPath;

        }, error => {
            this.toastr.error(error.message + '\n' + error.error, 'Error fetching list of logs', { disableTimeOut: true });
        });
    }

    onLogOpen(log: string) {
        window.open(window.location.origin + '/logs?path=' + this.logPath + '&log=' + log, '', 'height=600,width=800');
    }

    dismiss() {
        this.state.modal.dismiss('not confirmed');
    }
}
