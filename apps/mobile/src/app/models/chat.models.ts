/**
 * Chat System TypeScript Models
 */

export interface ChatVendor {
  vendor_id: number;
  store_name: string;
  store_logo: string | null;
  store_slug: string;
  order_count: number;
  last_order_date: string;
  total_unread: number;
}

export interface ChatOrder {
  order_item_id: number;
  order_id: string;
  product_id: number;
  product_name: string;
  product_image: string;
  quantity: number;
  price: number;
  total_price: number;
  size: string | null;
  color: string | null;
  status: OrderStatus;
  is_custom: number;
  measurement: string | null;
  order_date: string;
  delivery_time: string | null;
  conversation_id: number | null;
  unread_count: number;
  last_message_at: string | null;
}

export type OrderStatus =
  | 'Pending' | 'Accepted' | 'Processing' | 'Ready for Delivery'
  | 'Delivered' | 'Return Requested' | 'Returned' | 'Cancelled' | 'Refunded';

export type SenderType = 'customer' | 'vendor' | 'system';
export type MessageType = 'text' | 'image' | 'prompt' | 'system';
export type MessageStatus = 'sending' | 'sent' | 'delivered' | 'read' | 'failed';
export type ConversationStatus = 'active' | 'archived' | 'closed' | 'suspended';

export interface Conversation {
  conversation_id: number;
  uuid: string;
  customer_id: number;
  vendor_id: number;
  order_item_id: number;
  status: ConversationStatus;
  customer_can_send: number | boolean;
  vendor_can_send: number | boolean;
  customer_unread_count: number;
  vendor_unread_count: number;
  last_message_at: string | null;
  is_flagged?: boolean | number;
  created_at?: string;
}

export interface OrderContext {
  order_item_id: number;
  order_id: string;
  product_id: number;
  product_name: string;
  product_image: string;
  description: string;
  quantity: number;
  price: number;
  total_price: number;
  size: string | null;
  color: string | null;
  status: OrderStatus;
  is_custom: number;
  measurement: string | null;
  measurement_parsed: Record<string, string> | null;
  extra_measurement: string | null;
  note: string | null;
  order_date: string;
  discount: number;
  vendor_id: number;
  store_name: string;
  store_logo: string | null;
  delivery_time: string | null;
}

export interface ChatMessage {
  message_id: number;
  uuid: string;
  conversation_id: number;
  sender_id: number;
  sender_type: 'customer' | 'vendor' | 'system';
  message_type: 'text' | 'image' | 'prompt' | 'system';
  content: string | null;
  content_ar: string | null;
  prompt_id: number | null;
  prompt_category: string | null;
  has_attachments: number;
  attachments?: ChatAttachment[];
  status: 'sending' | 'sent' | 'delivered' | 'read' | 'failed';
  delivered_at: string | null;
  read_at: string | null;
  is_flagged: boolean | number;
  created_at: string;
  sender_name: string;
  sender_first_name?: string;
  sender_last_name?: string;
  sender_store_name?: string | null;
  sender_avatar?: string | null;
}

export interface ChatAttachment {
  attachment_id: number;
  file_name: string;
  file_path: string;
  file_type: string;
  file_size: number;
  mime_type: string;
  width: number | null;
  height: number | null;
  thumbnail_path: string | null;
}

export interface PromptCategory {
  category_id: number;
  slug: string;
  name: string;
  name_en: string;
  name_ar: string;
  icon: string;
  prompts: Prompt[];
}

export interface Prompt {
  prompt_id: number;
  text: string;
  text_en: string;
  text_ar: string;
}

export interface VendorConversation extends Conversation {
  order_id: string;
  product_name: string;
  product_image: string;
  order_status: OrderStatus;
  customer_name: string;
  customer_avatar: string | null;
  last_message_content: string | null;
  last_message_preview: string | null;
  last_message_sender: 'customer' | 'vendor' | 'system' | null;
}

export interface ChatOrdersResponse {
  orders: ChatOrder[];
  total_count: number;
  skip_selection: boolean;
}

export interface ConversationResponse {
  conversation: Conversation;
  order_context: OrderContext;
  can_send: boolean;
  user_role: 'customer' | 'vendor';
}

export interface MessagesResponse {
  messages: ChatMessage[];
  has_more: boolean;
}

export interface SendMessageResponse {
  message: ChatMessage;
  flagged: boolean;
}

export interface VendorConversationsResponse {
  conversations: VendorConversation[];
  total_unread: number;
  has_more: boolean;
}

export interface UnreadCountResponse {
  customer_unread: number;
  vendor_unread: number;
  total: number;
}
