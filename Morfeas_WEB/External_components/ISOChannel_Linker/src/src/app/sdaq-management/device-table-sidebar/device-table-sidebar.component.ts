import { Component, Input, Output, EventEmitter } from '@angular/core';
import { CanBusFlatData } from '../device-info-table/device-info-table.component';

export class TableColumn {
  id: string;
  displayName: string;
  isVisible: boolean;

  constructor(id: string, displayName: string) {
    this.id = id;
    this.displayName = displayName;
    this.isVisible = true;
  }
}

export type FilterEvent = (row: CanBusFlatData) => boolean;

@Component({
  selector: 'app-device-table-sidebar',
  templateUrl: './device-table-sidebar.component.html',
  styleUrls: ['./device-table-sidebar.component.scss']
})
export class DeviceTableSidebarComponent {

  @Input() columns: TableColumn[];
  @Input() showUnsaved: boolean;
  @Input() connections: string[];

  @Output() columnToggle = new EventEmitter<string>();
  @Output() saveConfigs = new EventEmitter();
  @Output() activeFilters = new EventEmitter<FilterEvent[]>();

  @Output() logToggle = new EventEmitter();

  filters = new Map<string, FilterEvent>();
  showOptions = true;

  unlinkedFilter = ((row: CanBusFlatData) => row.isoCode != null);
  linkedFilter = ((row: CanBusFlatData) => !this.unlinkedFilter(row));
  deviceFilter = (name) => ((row: CanBusFlatData) => row.deviceId !== name);

  constructor() { }

  onColumnClick(column: TableColumn): void {
    column.isVisible = !column.isVisible;
    this.columnToggle.next(column.id);
  }

  toggleShowOptions() {
    this.showOptions = !this.showOptions;
  }

  onSaveConfigs() {
    this.saveConfigs.emit();
  }

  toggleLogModal() {
    this.logToggle.emit();
  }

  filterConnection(name: string, filter: FilterEvent) {
    if (this.filters.has(name)) {
      this.filters.delete(name);
    } else {
      this.filters.set(name, filter);
    }
    this.activeFilters.next([...this.filters.values()]);
  }
}
