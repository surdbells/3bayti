export class Orders {
  constructor(
    public id: number,
    public order_ref: string,
    public product: string,
    public image: string,
    public quantity: number,
    public email: string,
    public total_price: string,
    public name: string,
    public created: string,
    public status: string,
  ){  }
}
