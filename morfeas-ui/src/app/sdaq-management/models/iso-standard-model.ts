export interface Attributes {
  description: string;
  max: string;
  min: string;
  unit: string;
}

export interface IsoStandard {
  attributes: Attributes;
  iso_code: string;
}
