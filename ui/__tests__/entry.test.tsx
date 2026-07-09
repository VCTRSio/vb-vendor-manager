// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach } from 'vitest';
import * as React from 'react';
import * as ReactDOMClient from 'react-dom/client';
import plugin from '../entry';

const host = {
  React,
  ReactDOM: ReactDOMClient,
  ui: {
    Card: ({ children }: any) => React.createElement('div', null, children),
    CardHeader: ({ children }: any) => React.createElement('div', null, children),
    CardContent: ({ children }: any) => React.createElement('div', null, children),
    CardTitle: ({ children }: any) => React.createElement('div', null, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Button: ({ children, onClick }: any) => React.createElement('button', { onClick }, children),
  },
};

beforeEach(() => {
  global.fetch = vi.fn(async (url: string) => ({
    ok: true,
    json: async () =>
      url.includes('/stats')
        ? { data: { total: 1, active: 1, pending: 0, expiringCoiCount: 0, noContractCount: 0 } }
        : url.includes('/list')
          ? { data: { items: [{ id: 'v1', company_name: 'Acme', category: 'oem', contact_name: 'Jane', status: 'active' }], total: 1 } }
          : { data: { vendor: { company_name: 'Acme', status: 'active', category: 'oem', contact_name: 'Jane' }, documents: [], credentials: [], onboardingHistory: [] } },
  })) as any;
});

describe('vendor-manager esm entry', () => {
  it('exports a mount function that renders and returns cleanup', async () => {
    const el = document.createElement('div');
    expect(typeof plugin.mount).toBe('function');
    const cleanup = plugin.mount(el, host as any, { slug: 'vb-vendor-manager', route: '', entryKey: undefined });
    await new Promise((r) => setTimeout(r, 0));
    expect(typeof cleanup).toBe('function');
    cleanup?.();
  });
});
