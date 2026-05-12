export class Sales {
  constructor(
    public id: number,
    public code: string,
    public product_name: string,
    public customer_name: string,
    public customer_email: string,
    public amount: string,
    public payment_fee: string,
    public shipping_amount: string,
    public payment_ref: string,
    public status: string,
  ){  }
}
