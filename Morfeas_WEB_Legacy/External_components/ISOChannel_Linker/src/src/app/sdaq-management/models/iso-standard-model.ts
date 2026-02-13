export class Attributes {
  description: string;
  max: string;
  min: string;
  unit: string;
}

export class IsoStandard {
  attributes: Attributes;
  // tslint:disable-next-line: variable-name
  iso_code: string;

  constructor() {
    this.attributes = new Attributes();
  }
}
