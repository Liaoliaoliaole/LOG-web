import { Component, OnInit, Input, ChangeDetectionStrategy } from '@angular/core';
import { Logstat } from '../sdaq-management/models/can-model';

@Component({
  selector: 'app-canbus-details-bar',
  templateUrl: './canbus-details-bar.component.html',
  styleUrls: ['./canbus-details-bar.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class CanbusDetailsBarComponent {
  @Input() logstats: Logstat[];
  @Input() ifNames: string[];
  @Input() showAllComponents: boolean;

  toggledLogstats = [];

  constructor() { }

  toggleComponent(name: any) {
    if (this.toggledLogstats.includes(name)) {
      this.toggledLogstats.splice(this.toggledLogstats.indexOf(name), 1);
    } else {
      this.toggledLogstats.push(name);
    }
  }
}
