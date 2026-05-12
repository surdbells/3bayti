export class Reviews {
  constructor(
    public id: number,
    public customer_id: number,
    public customer_name: string,
    public product_name: string,
    public rating: number,
    public comment: string,
    public created: string
  ){  }
}
