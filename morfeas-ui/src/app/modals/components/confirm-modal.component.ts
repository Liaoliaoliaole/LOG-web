import { ModalOptions } from '../modal-options';
import { ModalState } from '../services/modal-state.service';
import { Component } from '@angular/core';

@Component({
    selector: 'app-confirm-modal-component',
    template: `<div class="modal-header">
      <h4 class="modal-title">{{ options.title }}</h4>
      <button type="button" class="close" (click)="no()">
          <span aria-hidden="true">&times;</span>
      </button>
    </div>
    <div class="modal-body">
      <p>{{ options.message }}</p>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-danger" (click)="yes()">Yes</button>
      <button type="button" class="btn btn-secondary" (click)="no()">No</button>
    </div>`
})
export class ConfirmModalComponent {

    options: ModalOptions;

    constructor(private readonly state: ModalState) {
        this.options = state.options;
    }

    yes() {
        this.state.modal.close('confirmed');
    }

    no() {
        this.state.modal.dismiss('not confirmed');
    }
}
