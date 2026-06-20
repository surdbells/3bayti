export class Reviews {
  constructor(
    public id: number,
    public store_name: string,
    public customer: string,
    public comment: string,
    public star: number,
    // v3 GET /me/reviews (mineShape) emits product_name, not a store name.
    // The My Reviews card shows what was reviewed (the product).
    public product_name?: string
  ){  }
}
