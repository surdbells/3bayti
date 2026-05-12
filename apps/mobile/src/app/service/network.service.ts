import { Injectable } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import {
  HttpClient,
  HttpHeaders,
  HttpErrorResponse,
} from '@angular/common/http';
import {GlobalComponent} from "../global-component";
@Injectable({
  providedIn: 'root',
})
export class NetworkService {
  headers = new HttpHeaders()
    .set('Content-Type', 'application/json')
    .set('Accept', 'application/json');


  constructor(private http: HttpClient) {}
  // Error Handler
  error(error: HttpErrorResponse) {
    let errorMessage = '';
    if (error.error instanceof ErrorEvent) {
      errorMessage = error.error.message;
    } else {
      errorMessage = `Error Code: ${error.status}\nMessage: ${error.message}`;
    }
    console.error(errorMessage);
    return throwError(() => {
      return errorMessage;
    });

  }
  post_request(data: any, endpoint: string): Observable<any> {
    return this.http.post(`${endpoint}`, data).pipe(catchError(this.error));
  }
  get_request(endpoint: string) {
    return this.http.get<any>(`${endpoint}`).pipe(catchError(this.error));
  }
}
