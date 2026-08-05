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

// Records every mutating (PATCH/PUT) request the UI issues, so the assertions can
// verify the exact url/method/body without reaching a real server.
let mutations: { url: string; method: string; body: any }[] = [];

// Detail payload the mocked GET /{id} returns — a document with resolved evidence,
// a credential without evidence linked (vault_document_id null) for the picker test,
// and a resolved accountRep for the header display.
const DETAIL = {
  vendor: { id: 'v1', company_name: 'Acme', status: 'active', category: 'oem', contact_name: 'Jane', account_rep_employee_id: null },
  documents: [
    { id: 'd1', document_type: 'coi', file_name: 'coi.pdf', vault_document_id: 'vd1', evidence: { id: 'vd1', title: 'COI 2026', document_class: 'insurance', current_version: 3 } },
  ],
  credentials: [
    { id: 'c1', credential_type: 'license', label: 'State License', vault_document_id: null, evidence: null },
  ],
  onboardingHistory: [],
  accountRep: { id: 'emp1', display_name: 'Dana Rep' },
};

beforeEach(() => {
  mutations = [];
  global.fetch = vi.fn(async (url: string, opts?: any) => {
    const method = (opts?.method ?? 'GET').toUpperCase();
    if (method === 'PATCH' || method === 'PUT') {
      mutations.push({ url, method, body: JSON.parse(opts.body) });
      return { ok: true, json: async () => ({ data: {} }) };
    }
    const data = url.includes('/vault-documents')
      ? { documents: [{ id: 'vd1', title: 'COI 2026', document_class: 'insurance', current_version: 3 }] }
      : url.includes('/assignable-staff')
        ? { employees: [{ id: 'emp1', display_name: 'Dana Rep' }] }
        : url.includes('/stats')
          ? { total: 1, active: 1, pending: 0, expiringCoiCount: 0, noContractCount: 0 }
          : url.includes('/list')
            ? { items: [{ id: 'v1', company_name: 'Acme', category: 'oem', contact_name: 'Jane', status: 'active' }], total: 1 }
            : DETAIL;
    return { ok: true, json: async () => ({ data }) };
  }) as any;
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

  // Mounts the plugin, clicks the first vendor row, and waits for the detail view.
  async function openDetail(el: HTMLElement) {
    const cleanup = plugin.mount(el, host as any, { slug: 'vb-vendor-manager', route: '', entryKey: undefined });
    const row = (await waitFor(() => el.querySelector('[data-testid="vm-row"]'))) as HTMLElement;
    row.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    await waitFor(() => el.querySelector('[data-testid="vm-detail"]'));
    return cleanup;
  }

  it('renders the resolved vault evidence for a document row', async () => {
    const el = document.createElement('div');
    const cleanup = await openDetail(el);
    // evidence display is driven by the row's own `evidence` field.
    await waitFor(() => (el.textContent?.includes('COI 2026') ? el : null));
    expect(el.textContent).toContain('Certificate: COI 2026 (v3)');
    cleanup?.();
  });

  it('PATCHes credential evidence when a vault document is chosen from the per-row picker', async () => {
    const el = document.createElement('div');
    const cleanup = await openDetail(el);
    const picker = (await waitFor(() => el.querySelector('[data-testid="vm-vault-picker-credentials"]'))) as HTMLSelectElement;
    picker.value = 'vd1';
    picker.dispatchEvent(new window.Event('change', { bubbles: true }));

    const call = await waitFor(() => mutations.find((m) => m.url.includes('/credentials/c1/evidence')));
    expect(call.method).toBe('PATCH');
    expect(call.body).toEqual({ vaultDocumentId: 'vd1' });
    cleanup?.();
  });

  it('renders the resolved account rep display name in the header', async () => {
    const el = document.createElement('div');
    const cleanup = await openDetail(el);
    await waitFor(() => (el.textContent?.includes('Dana Rep') ? el : null));
    expect(el.textContent).toContain('Rep: Dana Rep');
    cleanup?.();
  });

  it('PUTs the account rep when a staff member is chosen from the rep picker', async () => {
    const el = document.createElement('div');
    const cleanup = await openDetail(el);
    const picker = (await waitFor(() => el.querySelector('[data-testid="vm-rep-picker"]'))) as HTMLSelectElement;
    picker.value = 'emp1';
    picker.dispatchEvent(new window.Event('change', { bubbles: true }));

    const call = await waitFor(() => mutations.find((m) => m.url.includes('/v1/account-rep')));
    expect(call.method).toBe('PUT');
    expect(call.body).toEqual({ employeeId: 'emp1' });
    cleanup?.();
  });
});
