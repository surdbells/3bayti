export class Customers {
  constructor(
    public id: number,
    public token: string,
    public name: string,
    public email: string,
    public phone: string,
    public last_login: string,
    public orders: number,
    public status: boolean,

  ){  }
}
