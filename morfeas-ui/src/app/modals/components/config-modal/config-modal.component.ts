import { ModalState } from '../../services/modal-state.service';
import { ModalOptions } from '../../modal-options';
import { Component, OnInit, ViewChild } from '@angular/core';
import { ConfigService } from '../../services/config.service';
import { NetworkInterface } from '../../models/network-interface-model';
import { ToastrService } from 'ngx-toastr';
import { environment } from 'src/environments/environment';

@Component({
    selector: 'app-modal-config',
    templateUrl: './config-modal.component.html',
    styleUrls: ['./config-modal.component.scss']
})
export class ConfigModalComponent implements OnInit {

    @ViewChild('interfaceSelect', { static: false }) interfaceSelect: any;

    options: ModalOptions;

    interfaces: NetworkInterface[];
    modifiedInterfaces: NetworkInterface[] = [{ interface: '', ipAddress: '', subnetMask: '', defaultGateway: '' }];
    index = 0;

    dropdownInterface: NetworkInterface;

    error = '';
    showInfo = false;
    networkSettingsChanged = false;

    ntp = '';
    ntpSettingsChanged = false;

    // NOTE: im not a networking guru so dunno whats actually valid and not, the user should know...
    // https://www.regextester.com/104851
    ipRegexp = /^(([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.){3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])$/;
    // https://stackoverflow.com/a/5362024
    // tslint:disable-next-line: max-line-length
    subRegexp = /^(((255\.){3}(255|254|252|248|240|224|192|128|0+))|((255\.){2}(255|254|252|248|240|224|192|128|0+)\.0)|((255\.)(255|254|252|248|240|224|192|128|0+)(\.0+){2})|((255|254|252|248|240|224|192|128|0+)(\.0+){3}))$/;

    constructor(
        private readonly state: ModalState,
        private readonly configService: ConfigService,
        private readonly toastr: ToastrService
    ) {
        this.options = state.options;
    }

    ngOnInit() {

        this.configService.getNetworkInterfaces().subscribe(result => {

            if (result.retcode !== 0) {
                this.toastr.error(result.error, 'Error fetching network interfaces', { disableTimeOut: true });
                return;
            }

            this.interfaces = result.interfaces;
            this.modifiedInterfaces = Object.assign([], result.interfaces);

            this.index = 0;

            this.dropdownInterface = this.modifiedInterfaces[this.index];

            this.interfaceSelect.searchInput.nativeElement.value = (' ' + this.dropdownInterface.interface).slice(1);

        }, error => {
            this.toastr.error(error.message + '\n' + error.error, 'Error fetching network interfaces', { disableTimeOut: true });
        });

        this.configService.getNTPSettings().subscribe(result => {

            this.ntp = result.NTP;

        }, error => {
            this.toastr.error(error.message + '\n' + error.error, 'Error fetching NTP settings ', { disableTimeOut: true });
        });
    }

    onNetworkSettingsChange() {
        this.networkSettingsChanged = true;
        this.validateAttributes();
    }

    onNTPSettingsChange() {
        this.ntpSettingsChanged = true;
        this.validateAttributes();
    }

    validateAttributes() {

        this.error = '';

        const ip = this.modifiedInterfaces[this.index].ipAddress;
        const subnetMask = this.modifiedInterfaces[this.index].subnetMask;
        const gateway = this.modifiedInterfaces[this.index].defaultGateway;

        if (!this.ipRegexp.test(ip)) {
            this.error += 'IP Address must be valid\n';
        }
        if (!this.subRegexp.test(subnetMask) && subnetMask !== '') {
            this.error += 'Subnet mask must be valid\n';
        }
        if (!this.ipRegexp.test(gateway) && gateway !== '') {
            this.error += 'Default gateway must be valid\n';
        }
        if (!this.ipRegexp.test(this.ntp) && this.ntp !== undefined && this.ntp !== '') {
            this.error += 'NTP must be valid\n';
        }
    }

    apply() {
        this.validateAttributes();

        if (this.error !== '') {
            return;
        }

        if (!(this.interfaces && this.interfaces.length > 0)) {
            return;
        }

        if (this.ntpSettingsChanged) {

            const ntp = { NTP: this.ntp };

            this.configService.saveNTPSettings(ntp).subscribe(result => {
                this.ntpSettingsChanged = false;
                this.toastr.success('NTP settings saving successful');
            }, error => {
                this.toastr.error(error.message + '\n' + error.error, 'Error saving NTP settings', { disableTimeOut: true });
            });
        }

        if (this.networkSettingsChanged) {

            this.showInfo = true;

            this.configService.saveNetworkInterfaces(this.modifiedInterfaces).subscribe(result => {

                this.showInfo = false;

                this.redirect();

            }, error => {
                // NOTE: janky. the idea here is that this 0 status "unknown error" means that the
                // backend network restarted successfully so we can redirect safely, altough mmmm probably not the best way to do this
                if (error.status === 0) {

                    this.redirect();

                } else {
                    this.showInfo = false;
                    this.toastr.error(error.message + '\n' + error.error, 'Error saving network interfaces', { disableTimeOut: true });
                }
            });
        }
    }

    redirect() {

        this.networkSettingsChanged = false;

        // NOTE: im not sure where exactly we should redirect if there are multiple interfaces so just take the first one
        window.location.href = 'http://' + this.modifiedInterfaces[0].ipAddress;

        this.toastr.success('Network interface saving successful');
        this.state.modal.dismiss('');
    }

    onSelectInterface() {
        this.index = this.modifiedInterfaces.indexOf(this.dropdownInterface);
        this.interfaceSelect.searchInput.nativeElement.value = (' ' + this.dropdownInterface.interface).slice(1);
    }

    dismiss() {
        if (!this.showInfo) {
            this.state.modal.dismiss('not confirmed');
        }
    }
}
