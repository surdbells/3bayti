import { Routes } from '@angular/router';

/**
 * Chat Routes
 * Add these routes to your main app.routes.ts
 */
export const CHAT_ROUTES: Routes = [
  {
    path: 'chat-vendors',
    loadComponent: () => import('./pages/chat-vendors/chat-vendors.page').then(m => m.ChatVendorsPage),
    title: 'Messages'
  },
  {
    path: 'chat-orders',
    loadComponent: () => import('./pages/chat-orders/chat-orders.page').then(m => m.ChatOrdersPage),
    title: 'Select Order'
  },
  {
    path: 'chat',
    loadComponent: () => import('./pages/chat/chat.page').then(m => m.ChatPage),
    title: 'Chat'
  },
  {
    path: 'vendor-chat-list',
    loadComponent: () => import('./pages/vendor-chat-list/vendor-chat-list.page').then(m => m.VendorChatListPage),
    title: 'Customer Messages'
  }
];

/**
 * Example app.routes.ts integration:
 *
 * import { CHAT_ROUTES } from './chat.routes';
 *
 * export const routes: Routes = [
 *   // ... your existing routes ...
 *
 *   // Chat routes
 *   ...CHAT_ROUTES,
 *
 *   // ... more routes ...
 * ];
 */
