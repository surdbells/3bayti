export class Stores {
  constructor(
    public id: number,
    public token: string,
    public store_name: string,
    public store_email: string,
    public store_phone: string,
    public store_address: string,
    public last_login: string,
    public status: boolean,
    public approved: boolean

  ){  }
}
