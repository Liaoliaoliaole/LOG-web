import { Injectable } from '@angular/core';
import { NgbModal } from '@ng-bootstrap/ng-bootstrap';
import { ModalOptions } from '../modal-options';
import { ConfirmModalComponent } from '../components/confirm-modal.component';
import { ModalState } from './modal-state.service';
import { InformationModalComponent } from '../components/information-modal.component';

/**
 * Reference : https://gist.github.com/jnizet/15c7a0ab4188c9ce6c79ca9840c71c4e
 */

/**
 * A confirmation service, allowing to open a confirmation modal from anywhere and get back a promise.
 */
@Injectable()
export class ModalService {

  constructor(private readonly modalService: NgbModal, private readonly state: ModalState) {}

  /**
   * Opens a confirmation modal
   * @param options the options for the modal
   * @returns a promise that is fulfilled when the user chooses to confirm, and rejected when
   * the user chooses not to confirm, or closes the modal
   */
  confirm(options: ModalOptions): Promise<any> {
    this.state.options = options;
    const component = options.component ? options.component : ConfirmModalComponent;
    this.state.modal = this.modalService.open(component, options.ngbModalOptions);

    return this.state.modal.result;
  }

  open(options: ModalOptions): void {
    this.state.options = options;
    const component = options.component ? options.component : InformationModalComponent;
    this.state.modal = this.modalService.open(component);
  }
}
