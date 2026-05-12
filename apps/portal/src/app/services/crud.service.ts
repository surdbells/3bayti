import { Injectable } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { HttpClient, HttpHeaders, HttpErrorResponse } from '@angular/common/http';
@Injectable({
  providedIn: 'root',
})
export class CrudService {
  constructor(private http: HttpClient) {}
  // Handle Errors
  error(error: HttpErrorResponse) {
    let errorMessage = '';
    if (error.error instanceof ErrorEvent) {
      errorMessage = error.error.message;
    } else {
      errorMessage = `Error Code: ${error.status}\nMessage: ${error.message}`;
    }
    console.log(errorMessage);
    return throwError(() => {
      return errorMessage;
    });
  }
  post_request(data: any, endpoint: string): Observable<any> {
    let API_URL = `${endpoint}`;
    return this.http.post(API_URL, data).pipe(catchError(this.error));
  }
  get_request(endpoint: string) {
    let API_URL = `${endpoint}`;
    return this.http.get<any>(API_URL).pipe(catchError(this.error));
  }
}
