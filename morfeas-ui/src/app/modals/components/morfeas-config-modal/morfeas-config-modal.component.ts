import { ModalState } from '../../services/modal-state.service';
import { Component, OnInit } from '@angular/core';
import { ToastrService } from 'ngx-toastr';
import { ConfigService } from '../../services/config.service';
import { MorfeasConfigComponent, MorfeasConfigModel } from '../../models/morfeas-config-model';

@Component({
    selector: 'app-modal-morfeas-config',
    templateUrl: './morfeas-config-modal.component.html',
    styleUrls: ['./morfeas-config-modal.component.scss']
})
export class MorfeasConfigModalComponent implements OnInit {

    constructor(
        private readonly state: ModalState,
        private readonly configService: ConfigService,
        private readonly toastr: ToastrService,
    ) {
    }

    appName: string;
    options: string[];
    sdaqComponents: MorfeasConfigComponent[];
    mdaqComponents: MorfeasConfigComponent[];
    ioboxComponents: MorfeasConfigComponent[];
    mtiComponents: MorfeasConfigComponent[];
    selectedHandler: any;
    handlerOptions = [];
    maxHandlers = 18;

    concatComponent(result: MorfeasConfigModel, name: string) {
        if (result.components[name]) {
            return [].concat(result.components[name]);
        }

        return [];
    }

    ngOnInit() {
        this.configService.getMorfeasConfig().subscribe(result => {

            this.appName = result.app_name;
            this.options = result.canbus_if_options;

            this.sdaqComponents = this.concatComponent(result, 'sdaq_handlers');
            this.mdaqComponents = this.concatComponent(result, 'mdaq_handlers');
            this.ioboxComponents = this.concatComponent(result, 'iobox_handlers');
            this.mtiComponents = this.concatComponent(result, 'mti_handlers');

            this.handlerOptions = [
                { handler: this.sdaqComponents, name: 'SDAQ Handler' },
                { handler: this.mdaqComponents, name: 'MDAQ Handler' },
                { handler: this.ioboxComponents, name: 'IOBOX Handler' },
                { handler: this.mtiComponents, name: 'MTI Handler' },
            ];

        }, error => {
            this.toastr.error(error.message + '\n' + error.error, 'Error fetching Morfeas Config', { disableTimeOut: true });
        });
    }

    getCurrentHandlerAmount() {
        // if one of these doesnt exist, none do, after init theyre all empty arrays at least
        // 1 because appname always exists
        if (!this.sdaqComponents) {
            return 1;
        }

        return 1 + this.sdaqComponents.length
            + this.mdaqComponents.length
            + this.ioboxComponents.length
            + this.mtiComponents.length;
    }

    addHandler() {
        if (this.getCurrentHandlerAmount() >= this.maxHandlers) {
            return;
        }

        if (this.selectedHandler) {
            this.selectedHandler.handler.push({});
            this.selectedHandler = null;
        }
    }

    apply() {

        const config = {
            app_name: this.appName,
            canbus_if_options: this.options,
            components: {
                sdaq_handlers: this.sdaqComponents,
                mdaq_handlers: this.mdaqComponents,
                iobox_handlers: this.ioboxComponents,
                mti_handlers: this.mtiComponents
            }
        };

        this.configService.saveMorfeasConfig(config).subscribe(result => {
            this.toastr.success('Morfeas configurations changed successfully');
        }, error => {
            console.log(error);
            this.toastr.error(error.message + '\n' + error.error, 'Error changing morfeas config', { disableTimeOut: true });
        });
    }

    dismiss() {
        this.state.modal.dismiss('not confirmed');
    }
}
