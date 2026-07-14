import { ModalState } from '../services/modal-state.service';
import { ModalOptions } from '../modal-options';
import { Component } from '@angular/core';

@Component({
    selector: 'app-modal-information',
    template: `
    <div class="modal-header">
    <h6 class="modal-title">{{ options.title }}</h6>
      <button type="button" class="close" (click)="no()">
          <span aria-hidden="true">&times;</span>
      </button>
      </div>
    <div class="modal-body">
      <p>{{ options.message }}</p>
    </div>
`
})
export class InformationModalComponent {

    options: ModalOptions;

    constructor(private readonly state: ModalState) {
        this.options = state.options;
    }

    no() {
        this.state.modal.dismiss();
    }
}
