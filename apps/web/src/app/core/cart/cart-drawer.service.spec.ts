import { describe, it, expect, beforeEach } from 'vitest';
import { CartDrawerService } from './cart-drawer.service';

describe('CartDrawerService', () => {
  let service: CartDrawerService;

  beforeEach(() => {
    service = new CartDrawerService();
  });

  it('starts closed with no highlight', () => {
    expect(service.isOpen()).toBe(false);
    expect(service.highlightedItemId()).toBeNull();
  });

  it('open() sets isOpen to true and clears highlight', () => {
    service.openWithHighlight(42);
    expect(service.highlightedItemId()).toBe(42);
    service.close();
    service.open();
    expect(service.isOpen()).toBe(true);
    expect(service.highlightedItemId()).toBeNull();
  });

  it('openWithHighlight() sets both isOpen and highlight', () => {
    service.openWithHighlight(99);
    expect(service.isOpen()).toBe(true);
    expect(service.highlightedItemId()).toBe(99);
  });

  it('close() clears both isOpen and highlight', () => {
    service.openWithHighlight(7);
    service.close();
    expect(service.isOpen()).toBe(false);
    expect(service.highlightedItemId()).toBeNull();
  });

  it('toggle() flips from closed to open', () => {
    service.toggle();
    expect(service.isOpen()).toBe(true);
  });

  it('toggle() flips from open to closed', () => {
    service.open();
    service.toggle();
    expect(service.isOpen()).toBe(false);
  });

  it('toggle() clears highlight when going from open-with-highlight to closed', () => {
    service.openWithHighlight(3);
    service.toggle();
    expect(service.isOpen()).toBe(false);
    expect(service.highlightedItemId()).toBeNull();
  });

  it('open() after openWithHighlight() clears the highlight', () => {
    service.openWithHighlight(5);
    service.open();
    expect(service.highlightedItemId()).toBeNull();
  });
});
