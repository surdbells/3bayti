export class Products {
  constructor(
    public id: number,
    public token: string,
    public product_id: number,
    public store_id: number,
    public store_name: string,
    public product_name: string,
    public description: string,
    public image_1: string,
    public images: [],
    public products: [  ],
    public price: string,
    public collection: number,
    public sale_price: string
  ){  }
}
