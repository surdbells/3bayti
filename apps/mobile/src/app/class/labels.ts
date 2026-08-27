export class Labels {
  constructor(
    public id: number,
    public name: string,
    public count: number,
    // Label slug, the vendor storefront filters products by label slug
    // (works for v3-native labels that have no legacy id).
    public slug: string = ''
  ){  }
}
