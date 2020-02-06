import { ModalState } from '../../services/modal-state.service';
import { ModalOptions } from '../../modal-options';
import { Component, OnInit } from '@angular/core';
import { CanbusService } from 'src/app/sdaq-management/services/canbus/canbus.service';
import { IsoStandard } from 'src/app/sdaq-management/models/iso-standard-model';
import { SensorLinkModalInitiateModel, SensorLinkModalSubmitAction } from 'src/app/sdaq-management/models/sensor-link-modal-model';

@Component({
  selector: 'app-modal-sensor-link',
  templateUrl: './sensor-link-modal.component.html',
  styleUrls: ['./sensor-link-modal.component.scss']
})
export class SensorLinkModalComponent implements OnInit {
  options: ModalOptions;
  isoStandards: IsoStandard[];
  data: SensorLinkModalInitiateModel;
  selectedIsoCode = 'Select an ISO code';
  selectedIsoStandard: IsoStandard;
  error = '';

  constructor(
    private readonly state: ModalState,
    private readonly canbusService: CanbusService
  ) {
    this.options = state.options;
  }

  ngOnInit() {
    this.data = this.state.options.data;

    this.canbusService.getIsoCodesByUnit(this.data.unit).subscribe(result => {
      if (
        this.data.configuredIsoCodes &&
        this.data.configuredIsoCodes.length > 0
      ) {
        // this.data['configuredIsoCodes'] cannot be used directly in line 37 because there's
        // another 'this' which belongs to the filter scope.
        const configuredIsoCodes = this.data.configuredIsoCodes;

        if (this.data.existingIsoStandard) {

          this.selectedIsoStandard = this.data.existingIsoStandard;
          this.selectedIsoCode = this.data.existingIsoStandard.iso_code;
        }

        // remove configured ISO codes from the dropdown
        result = result.filter(
          obj => configuredIsoCodes.indexOf(obj.iso_code) < 0
        );

        // add the existing ISO code back the the dropdown if it exists
        if (this.data.existingIsoStandard) {
          result.push(this.selectedIsoStandard);
        }
      }

      this.isoStandards = result;
    });
  }

  link() {
    if (
      !isNaN(+this.selectedIsoStandard.attributes.min) &&
      !isNaN(+this.selectedIsoStandard.attributes.max)
    ) {
      if (
        +this.selectedIsoStandard.attributes.min <
        +this.selectedIsoStandard.attributes.max
      ) {


        if (this.data.unlinked) {
          this.state.modal.close({
            isoStandard: this.selectedIsoStandard,
            action: SensorLinkModalSubmitAction.Add
          });
        } else {
          this.state.modal.close({
            isoStandard: this.selectedIsoStandard,
            action: SensorLinkModalSubmitAction.Update
          });
        }

      } else {
        this.error = 'Min value cannot be greater than max value!';
      }
    } else {
      this.error = 'Min or max value must be a number.';
    }
  }

  unlink() {
    this.state.modal.close({
      isoStandard: this.selectedIsoStandard,
      action: SensorLinkModalSubmitAction.Remove
    });
  }

  dismiss() {
    this.state.modal.dismiss('not confirmed');
  }

  onSelectIsoCode() {
    this.selectedIsoStandard = this.isoStandards.find(
      x => x.iso_code === this.selectedIsoCode
    );

    this.data.existingIsoStandard = null;
  }
}
