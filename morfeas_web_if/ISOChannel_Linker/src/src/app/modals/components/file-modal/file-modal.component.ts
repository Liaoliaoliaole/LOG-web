import { ModalState } from '../../services/modal-state.service';
import { Component, OnInit } from '@angular/core';
import { ToastrService } from 'ngx-toastr';
import { ConfigService } from '../../services/config.service';
import { Router } from '@angular/router';
import { environment } from 'src/environments/environment';

@Component({
    selector: 'app-modal-file',
    templateUrl: './file-modal.component.html',
    styleUrls: ['./file-modal.component.scss']
})
export class FileModalComponent implements OnInit {

    logs: string[];

    logPath: string;

    files = {};

    morfeasFilename: string;
    opcFilename: string;
    isoFilename: string;

    error: string;

    constructor(
        private readonly state: ModalState,
        private readonly configService: ConfigService,
        private readonly toastr: ToastrService,
        private readonly router: Router
    ) {
    }

    ngOnInit() {
        this.configService.getFilenames().subscribe(result => {
            // tslint:disable-next-line: no-string-literal
            this.morfeasFilename = result['morfeas_file'];
            // tslint:disable-next-line: no-string-literal
            this.opcFilename = result['opc_file'];
            // tslint:disable-next-line: no-string-literal
            this.isoFilename = result['iso_file'];
        }, error => {
            this.toastr.error(error.message + '\n' + error.error, 'Error fetching filenames ', { disableTimeOut: true });
        });
    }

    download() {
        return `${environment.API_ROOT}/morfeas/config/download`;
    }

    onSelectFile(event: any) {
        this.files = {};
        const filesAmount = event.target.files.length;

        if (filesAmount > 3) {
            this.error = 'Maximum of 3 files are allowed';
            return;
        }

        for (let i = 0; i < filesAmount; i++) {

            if (event.target.files[i].name !== this.morfeasFilename
                && event.target.files[i].name !== this.opcFilename
                && event.target.files[i].name !== this.isoFilename) {
                this.error = 'Only allowed filenames are: ' + this.morfeasFilename + ', ' + this.opcFilename + ', ' + this.isoFilename;
                return;
            }

            const reader = new FileReader();

            reader.onload = (e: any) => {
                this.files[event.target.files[i].name] = e.target.result;
            };

            reader.readAsDataURL(event.target.files[i]);

            this.error = '';
        }
    }

    upload() {
        if (this.error.length > 0) {
            return;
        }

        // tslint:disable-next-line: forin
        for (const key in this.files) {
            this.files[key] = this.files[key].replace('data:text/xml;base64,', '');
        }

        this.configService.uploadConfigs(this.files).subscribe(result => {
            this.toastr.success('Files uploaded successfully');
        }, error => {
            this.toastr.error(error.message + '\n' + error.error, 'Error uploading files ', { disableTimeOut: true });
        });
    }

    dismiss() {
        this.state.modal.dismiss('not confirmed');
    }
}
