import { NgbModalOptions } from '@ng-bootstrap/ng-bootstrap';

export interface ModalOptions {
    title?: string;
    message?: string;
    /* Optional: Component to be used when displaying the modal */
    component?: any;
    data?: any;
    ngbModalOptions?: NgbModalOptions;
}
