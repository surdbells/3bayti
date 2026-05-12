export class ROrders {
  constructor(
    public id: string,
    public order_ref: string,
    public product_name: string,
    public customer_name: string,
    public store_name: string,
    public quantity: number,
    public total_price: string,
    public added: string,
    public status: string
  ){  }
}
