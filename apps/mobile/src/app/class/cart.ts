export class Cart {
  constructor(
    public id: number,
    public token: string,
    public item: number,
    public product_id: number,
    public product: number,
    public product_name: string,
    public product_image: string,
    public description: string,
    public quantity: number,
    public store: number,
    public discount: number,
    public price: number,
    public price_formatted: string,
    public size: string,
    public color: string,
    public status: string,
    public is_custom: boolean
  ){  }

  /** Live pricing: true while the line shows a "price updated" badge. */
  price_changed?: boolean;
  /** The price the customer saw before the vendor changed it, or null. */
  previous_unit_price?: string | null;
}
