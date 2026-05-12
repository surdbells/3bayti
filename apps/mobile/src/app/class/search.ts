export class Search {
  constructor(
    public id: number,
    public token: string,
    public item: number,
    public product_id: number,
    public product_name: string,
    public product_image: string,
    public store_name: string,
    public store: number,
    public description: string,
    public price_formatted: string,
    public price: string,
    public size: string,
    public color: string,
    public is_custom: boolean
  ){  }
}
