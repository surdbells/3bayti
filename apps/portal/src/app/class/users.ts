export class Users {
  constructor(
    public id: number,
    public first_name: string,
    public last_name: string,
    public email: string,
    public phone: string,
    public last_login: string,
    public is_admin: boolean,
    public is_host: boolean,
    public is_active: boolean

  ){  }
}
