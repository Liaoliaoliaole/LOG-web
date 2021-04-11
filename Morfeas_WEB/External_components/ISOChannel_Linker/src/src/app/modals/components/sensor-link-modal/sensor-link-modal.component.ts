import { ModalState } from '../../services/modal-state.service';
import { ModalOptions } from '../../modal-options';
import { Component, OnInit, ViewChild, TemplateRef } from '@angular/core';
import { CanbusService } from 'src/app/sdaq-management/services/canbus/canbus.service';
import { IsoStandard } from 'src/app/sdaq-management/models/iso-standard-model';
import { SensorLinkModalInitiateModel, SensorLinkModalSubmitAction } from 'src/app/sdaq-management/models/sensor-link-modal-model';
declare const iso_standard: any;

@Component({
  selector: 'app-modal-sensor-link',
  templateUrl: './sensor-link-modal.component.html',
  styleUrls: ['./sensor-link-modal.component.scss']
})
export class SensorLinkModalComponent implements OnInit {

  @ViewChild('isoSelect', { static: false }) isoSelect: any;

  options: ModalOptions;
  isoStandards: IsoStandard[];
  filteredIsoStandards: IsoStandard[];
  data: SensorLinkModalInitiateModel;
  selectedIsoStandard: IsoStandard = new IsoStandard();
  dropdownIsoStandard: IsoStandard;
  error = '';
  searchTerm: string;

  constructor(
    private readonly state: ModalState,
    private readonly canbusService: CanbusService
  ) {
    this.options = state.options;
  }

  ngOnInit() {
    
	var postfix = document.getElementById("postfix") as HTMLSelectElement;
	var option = document.createElement("option");
	option.text = "N/A";
	postfix.add(option);
	for(let i=1;i<=20;i++)
	{
		option = document.createElement("option");
		option.text = i.toString();
		postfix.add(option);
	}
	
	this.data = this.state.options.data;
    this.selectedIsoStandard.attributes.unit = this.data.unit;
	
    var result = iso_standard.get_isostandard_by_unit(this.data.unit);
	if(result)
	{
		if(this.data.configuredIsoCodes && this.data.configuredIsoCodes.length > 0) 
		{

			// this.data['configuredIsoCodes'] cannot be used directly in line 37 because there's
			// another 'this' which belongs to the filter scope.
			const configuredIsoCodes = this.data.configuredIsoCodes;

			if (this.data.existingIsoStandard) 
			{
			  //console.log(this.isoSelect);
			  this.selectedIsoStandard = this.data.existingIsoStandard;
			  //this.isoSelect.searchInput.nativeElement.value = (' ' + this.selectedIsoStandard.iso_code).slice(1);
			}

			// remove configured ISO codes from the dropdown
			result = result.filter(
			  obj => configuredIsoCodes.indexOf(obj.iso_code) < 0
			);
		}
		this.filteredIsoStandards = Object.assign([], result);

		result.forEach(code => { code.iso_code = code.iso_code + ' | ' + code.attributes.description; });

		this.isoStandards = result;
		//this.selectedIsoStandard.attributes.min = "0";
		//this.selectedIsoStandard.attributes.max = "0";
    }
	
  }

  validateAttributes() {

    this.error = '';

    if (
      isNaN(+this.selectedIsoStandard.attributes.min) ||
      isNaN(+this.selectedIsoStandard.attributes.max)
    ) {
      this.error += 'Min or max value must be a number.';
    }

    if (
      +this.selectedIsoStandard.attributes.min >
      +this.selectedIsoStandard.attributes.max
    ) {
      this.error += 'Min value cannot be greater than max value!';
    }
  }

  link() {
    if (this.error.length > 0) {
      return;
    }
	var postfix = document.getElementById("postfix") as HTMLSelectElement; 
	if(postfix.selectedIndex && !this.selectedIsoStandard.attributes.description.includes(' cyl '))
	{
		this.selectedIsoStandard.iso_code += '_'+postfix.value;
		this.selectedIsoStandard.attributes.description += ' cyl '+postfix.value;
	}
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
    if (this.dropdownIsoStandard) {
      this.selectedIsoStandard = this.dropdownIsoStandard;
      this.selectedIsoStandard.iso_code = this.selectedIsoStandard.iso_code
        .substring(0, this.selectedIsoStandard.iso_code.indexOf('|')).trim();
    }

    this.data.existingIsoStandard = null;
    this.error = '';

    if (this.selectedIsoStandard.iso_code) {
      this.searchTerm = this.selectedIsoStandard.iso_code;
	  /*
      const temp = Object.assign([], this.filteredIsoStandards);

      temp.splice(
        temp.indexOf(this.selectedIsoStandard),
        1,
      );
      temp.forEach((code: IsoStandard) => {
        if (!code.iso_code.includes('|')) {
          code.iso_code = code.iso_code + ' | ' + code.attributes.description;
        }
      });

      this.isoStandards = temp;
	  */
	}
  }

  onSearch(event: any) {
    this.searchTerm = event.term.trim();

    this.error = '';
    if (this.searchTerm.length <= 0) {
      this.error += 'ISO Code must not be null \n';
    }
    if (this.searchTerm.includes(' ')) {
      this.error += 'ISO Code must not contain whitespaces \n';
    }
    if (this.searchTerm.includes('.')) {
      this.error += 'ISO Code must not contain dots \n';
    }
    if (this.searchTerm.length > 20) {
      this.error += 'ISO Code must be less or equal to 20 characters \n';
    }
    if(this.data.configuredIsoCodes.some(code => code === this.searchTerm)) 
	{
      this.error += 'ISO Code must not be a duplicate ';
    }
	
    if (this.error === '' && this.selectedIsoStandard) {
      this.selectedIsoStandard.iso_code = this.searchTerm;
    }
  }

  onClose() {
    // TODO: maybe one day replace the library so we dont have to do this to keep the search in the search box
    setTimeout(() => {
      if ((this.searchTerm && this.searchTerm.length > 0) || (this.selectedIsoStandard && this.selectedIsoStandard.iso_code)) {
        this.isoSelect.searchInput.nativeElement.value = (this.searchTerm && this.searchTerm.length > 0)
          ? this.searchTerm : this.selectedIsoStandard.iso_code;
      }
    }, 0);
  }
	
	validate_postfix()
	{
		var postfix = document.getElementById("postfix") as HTMLSelectElement;
		if(!postfix.selectedIndex)
			return;
		this.error = '';
		var iso_code_for_check = this.selectedIsoStandard.iso_code+'_'+postfix.value;
		for(var i=0;i<this.data.configuredIsoCodes.length;i++)
		{
			if(this.data.configuredIsoCodes[i]===iso_code_for_check)
				break;
		}
		if(i<this.data.configuredIsoCodes.length)
			this.error += 'ISO Code "'+this.selectedIsoStandard.iso_code+'" for cyl '+postfix.value+' is already in use\n';
	}
}
