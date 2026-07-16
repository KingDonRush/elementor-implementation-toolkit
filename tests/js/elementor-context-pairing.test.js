import { describe, expect, it } from 'vitest';
import { pairingStatus } from '../../assets/src/editor/collection-pairing.js';
import { compatibleFieldOptions } from '../../assets/src/editor/dynamic-tag-context.js';

describe('Elementor context and connector contracts', () => {
  const catalog = {
    entities: {
      books: {
        fields: {
          title: { label: 'Title', categories: ['text'] },
          price: { label: 'Price', categories: ['number'] },
        },
      },
    },
  };

  it('filters the editor field cascade by Entity and typed category', () => {
    expect(compatibleFieldOptions(catalog, 'books', 'text')).toEqual({ title: 'Title' });
    expect(compatibleFieldOptions(catalog, 'books', 'all')).toEqual({ title: 'Title', price: 'Price' });
    expect(compatibleFieldOptions(catalog, 'missing', 'all')).toEqual({});
  });

  it('reports automatic, ambiguous and mismatched Collection pairings', () => {
    expect(pairingStatus(['a'], '', 'eit-toolkit-filter-surface').code).toBe('automatic');
    expect(pairingStatus(['a'], 'b', 'eit-toolkit-filter-surface').code).toBe('mismatch');
    expect(pairingStatus(['a', 'b'], '', 'eit-toolkit-filter-surface').code).toBe('ambiguous');
    expect(pairingStatus(['a', 'b'], 'b', 'eit-toolkit-filter-surface').code).toBe('disambiguated');
  });
});
