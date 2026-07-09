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

// Let queued microtasks/effects flush (fetch resolves, React re-renders).
const flush = () => new Promise((r) => setTimeout(r, 0));

// Poll until `check` returns truthy or we give up — accommodates the async
// fetch → setState → React commit chain without hard-coding a tick count.
async function waitFor<T>(check: () => T | null | undefined, tries = 50): Promise<T> {
  for (let i = 0; i < tries; i++) {
    const v = check();
    if (v) return v;
    await flush();
  }
  throw new Error('waitFor: condition never met');
}

describe('vendor-manager esm entry', () => {
  it('exports a mount function that renders and returns cleanup', async () => {
    const el = document.createElement('div');
    expect(typeof plugin.mount).toBe('function');
    const cleanup = plugin.mount(el, host as any, { slug: 'vb-vendor-manager', route: '', entryKey: undefined });
    await flush();
    expect(typeof cleanup).toBe('function');
    cleanup?.();
  });

  it('renders the list view with a vendor row from the mocked /list fetch', async () => {
    const el = document.createElement('div');
    const cleanup = plugin.mount(el, host as any, { slug: 'vb-vendor-manager', route: '', entryKey: undefined });

    // stats + list resolve via Promise.all; wait for the table row to commit.
    await waitFor(() => el.querySelector('[data-testid="vm-row"]'));

    const rows = el.querySelectorAll('[data-testid="vm-row"]');
    expect(rows.length).toBe(1);
    expect(el.querySelector('[data-testid="vm-list"]')).not.toBeNull();
    expect(el.textContent).toContain('Acme');
    cleanup?.();
  });

  it('navigates to the detail view when a vendor row is clicked', async () => {
    const el = document.createElement('div');
    const cleanup = plugin.mount(el, host as any, { slug: 'vb-vendor-manager', route: '', entryKey: undefined });

    const row = (await waitFor(() => el.querySelector('[data-testid="vm-row"]'))) as HTMLElement;
    // Fire a real click through the DOM so React's onClick handler runs.
    row.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

    // detail effect fires GET /api/{id}, then re-renders.
    const detail = await waitFor(() => el.querySelector('[data-testid="vm-detail"]'));
    // list view is gone once the detail view mounts.
    expect(el.querySelector('[data-testid="vm-list"]')).toBeNull();
    expect(detail.textContent).toContain('Acme');
    cleanup?.();
  });
});
