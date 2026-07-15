import { describe, expect, it } from 'vitest';
import { paginationWindow, safeJson } from '../../assets/src/frontend/utils.js';

describe('frontend utility contracts', () => {
  it('renders a bounded pagination window around the active page', () => {
    expect(paginationWindow(50, 100)).toEqual([1, null, 48, 49, 50, 51, 52, null, 100]);
    expect(paginationWindow(2, 4)).toEqual([1, 2, 3, 4]);
  });

  it('falls back safely when serialized widget data is invalid', () => {
    expect(safeJson('{invalid', { safe: true })).toEqual({ safe: true });
    expect(safeJson('{"valid":true}', {})).toEqual({ valid: true });
  });
});
