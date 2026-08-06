import type { PluginModule } from '@vctrs/plugin-ui';

type Host = {
  React: typeof import('react');
  ReactDOM: typeof import('react-dom/client');
  ui: Record<string, any>;
};

const BASE = '/dashboard/vendor/api';

async function getJson<T>(url: string): Promise<T> {
  const res = await fetch(url, {
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
  });
  if (!res.ok) throw new Error(`${res.status} ${url}`);
  const body = await res.json();
  return body.data as T;
}

// Laravel encrypts the CSRF token into the XSRF-TOKEN cookie and expects it echoed back
// (url-decoded) in the X-XSRF-TOKEN header — this is exactly what axios does automatically
// for the vendored @vctrs/plugin-ui client kit the sibling extracted plugins use
// (see vb-warranty-recall/ui/entry.tsx). This file hand-rolls fetch, so we replicate it.
function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
  return match ? decodeURIComponent(match[1]) : null;
}

// Mutating counterpart to getJson: same session-cookie auth + Accept/X-Requested-With, plus
// a JSON body and the X-XSRF-TOKEN header the `web` middleware requires for PATCH/PUT.
async function sendJson<T>(url: string, method: 'PATCH' | 'PUT', body: unknown): Promise<T> {
  const xsrf = readCookie('XSRF-TOKEN');
  const res = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
    },
    body: JSON.stringify(body),
  });
  if (!res.ok) throw new Error(`${res.status} ${url}`);
  const b = await res.json();
  return b.data as T;
}

// STATUS_VARIANT reconciled from the source Vendor/Index.tsx badge map, extended
// to cover the statuses the API actually emits (pending|active|inactive|rejected).
const STATUS_VARIANT: Record<string, string> = {
  active: 'default',
  pending: 'secondary',
  inactive: 'outline',
  rejected: 'destructive',
  suspended: 'destructive',
  archived: 'outline',
};

// Stat cards mirror the source Index.tsx (Active / Pending / COI expiring / No contract).
const STAT_CARDS: { key: string; label: string }[] = [
  { key: 'active', label: 'Active' },
  { key: 'pending', label: 'Pending' },
  { key: 'expiringCoiCount', label: 'COI expiring (30d)' },
  { key: 'noContractCount', label: 'No contract' },
];

// Filter set aligned to the /api/list status enum (source used "suspended" which the
// API rejects). Empty string == "all".
const STATUS_FILTERS = ['', 'active', 'pending', 'inactive', 'rejected'];

function IconBuilding({ R }: { R: typeof import('react') }) {
  return R.createElement(
    'svg',
    { width: 20, height: 20, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' },
    R.createElement('path', { d: 'M3 21h18M6 21V7l6-4 6 4v14M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01' }),
  );
}

function IconArrowLeft({ R }: { R: typeof import('react') }) {
  return R.createElement(
    'svg',
    { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' },
    R.createElement('path', { d: 'M19 12H5M12 19l-7-7 7-7' }),
  );
}

const plugin: PluginModule = {
  mount(el, host: Host, _props) {
    const R = host.React;
    const { Card, CardHeader, CardContent, CardTitle, Badge, Button } = host.ui;
    const root = host.ReactDOM.createRoot(el);

    function App() {
      const [view, setView] = R.useState<{ name: 'list' } | { name: 'detail'; id: string }>({ name: 'list' });
      return view.name === 'list'
        ? R.createElement(ListView, { onOpen: (id: string) => setView({ name: 'detail', id }) })
        : R.createElement(DetailView, { id: view.id, onBack: () => setView({ name: 'list' }) });
    }

    function ListView({ onOpen }: { onOpen: (id: string) => void }) {
      const [stats, setStats] = R.useState<any>(null);
      const [rows, setRows] = R.useState<any[]>([]);
      const [status, setStatus] = R.useState<string>('');
      const [loading, setLoading] = R.useState(true);
      const [error, setError] = R.useState<string | null>(null);

      R.useEffect(() => {
        let alive = true;
        setLoading(true);
        Promise.all([
          getJson<any>(`${BASE}/stats`),
          getJson<{ items: any[]; total: number }>(`${BASE}/list${status ? `?status=${status}` : ''}`),
        ])
          .then(([s, l]) => {
            if (alive) {
              setStats(s);
              setRows(l.items ?? []);
              setError(null);
            }
          })
          .catch((e) => alive && setError(String(e)))
          .finally(() => alive && setLoading(false));
        return () => {
          alive = false;
        };
      }, [status]);

      return R.createElement(
        'div',
        { 'data-testid': 'vm-list', style: { display: 'grid', gap: 16 } },
        R.createElement(
          'div',
          { style: { display: 'flex', alignItems: 'center', gap: 8 } },
          R.createElement(IconBuilding, { R }),
          R.createElement('h1', { style: { fontSize: 24, fontWeight: 600, letterSpacing: '-0.01em' } }, 'Vendors'),
        ),
        R.createElement('p', { style: { fontSize: 14, opacity: 0.7, marginTop: -8 } }, 'Vendor directory, onboarding & compliance.'),
        stats &&
          R.createElement(
            'div',
            { style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))', gap: 12 } },
            STAT_CARDS.map((c) =>
              R.createElement(
                Card,
                { key: c.key },
                R.createElement(CardHeader, null, R.createElement(CardTitle, { style: { fontSize: 14, fontWeight: 500, opacity: 0.7 } }, c.label)),
                R.createElement(CardContent, null, R.createElement('div', { style: { fontSize: 24, fontWeight: 700 } }, String(stats[c.key] ?? 0))),
              ),
            ),
          ),
        R.createElement(
          'div',
          { style: { display: 'flex', gap: 8, flexWrap: 'wrap' } },
          STATUS_FILTERS.map((s) =>
            R.createElement(
              Button,
              {
                key: s || 'all',
                size: 'sm',
                variant: status === s ? 'default' : 'outline',
                onClick: () => setStatus(s),
                style: { textTransform: 'capitalize' },
              },
              s || 'all',
            ),
          ),
        ),
        error && R.createElement('div', { 'data-testid': 'vm-error', style: { color: 'crimson' } }, error),
        R.createElement(
          Card,
          null,
          R.createElement(
            CardContent,
            { style: { padding: 0 } },
            loading
              ? R.createElement('div', { style: { padding: 32, textAlign: 'center', fontSize: 14, opacity: 0.7 } }, 'Loading…')
              : rows.length === 0
                ? R.createElement('div', { style: { padding: 32, textAlign: 'center', fontSize: 14, opacity: 0.7 } }, 'No vendors found.')
                : R.createElement(
                    'table',
                    { style: { width: '100%', borderCollapse: 'collapse', fontSize: 14 } },
                    R.createElement(
                      'thead',
                      null,
                      R.createElement(
                        'tr',
                        { style: { textAlign: 'left', opacity: 0.7 } },
                        R.createElement('th', { style: { padding: 12, fontWeight: 500 } }, 'Company'),
                        R.createElement('th', { style: { padding: 12, fontWeight: 500 } }, 'Category'),
                        R.createElement('th', { style: { padding: 12, fontWeight: 500 } }, 'Contact'),
                        R.createElement('th', { style: { padding: 12, fontWeight: 500 } }, 'Status'),
                      ),
                    ),
                    R.createElement(
                      'tbody',
                      null,
                      rows.map((v) =>
                        R.createElement(
                          'tr',
                          { key: v.id, 'data-testid': 'vm-row', style: { cursor: 'pointer', borderTop: '1px solid rgba(128,128,128,0.2)' }, onClick: () => onOpen(v.id) },
                          R.createElement('td', { style: { padding: 12, fontWeight: 500 } }, v.company_name),
                          R.createElement('td', { style: { padding: 12, opacity: 0.7 } }, v.category ?? '—'),
                          R.createElement('td', { style: { padding: 12, opacity: 0.7 } }, v.contact_name ?? '—'),
                          R.createElement(
                            'td',
                            { style: { padding: 12 } },
                            R.createElement(Badge, { variant: STATUS_VARIANT[v.status] ?? 'outline', style: { textTransform: 'capitalize' } }, v.status),
                          ),
                        ),
                      ),
                    ),
                  ),
          ),
        ),
      );
    }

    function DetailRow({ label, meta }: { label: string; meta: any }) {
      return R.createElement(
        'div',
        { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '8px 0', borderTop: '1px solid rgba(128,128,128,0.2)', fontSize: 14 } },
        R.createElement('span', null, label),
        meta,
      );
    }

    function DetailSection({ title, items, empty, renderItem }: { title: string; items: any[]; empty: string; renderItem: (it: any) => any }) {
      return R.createElement(
        Card,
        null,
        R.createElement(CardHeader, null, R.createElement(CardTitle, { style: { fontSize: 16 } }, title)),
        R.createElement(
          CardContent,
          { style: { display: 'grid', gap: 4 } },
          items.length === 0
            ? R.createElement('div', { style: { padding: 16, textAlign: 'center', fontSize: 14, opacity: 0.7 } }, empty)
            : items.map(renderItem),
        ),
      );
    }

    // Per-row vault-evidence picker + resolved-evidence affordance, shared by the
    // Documents and Credentials sections. The DISPLAY (it.evidence) is independent of
    // the picker; the <select> only renders when the vault seam returned candidates.
    function EvidenceControls({ it, kind, vaultDocs, onLinked, onError }: { it: any; kind: 'documents' | 'credentials'; vaultDocs: any[]; onLinked: () => void; onError: (e: unknown) => void }) {
      const typeLabel = kind === 'documents' ? it.document_type : it.credential_type;
      const children: any[] = [
        R.createElement('span', { key: 'type', style: { fontSize: 12, opacity: 0.7 } }, typeLabel),
      ];
      if (it.evidence) {
        children.push(
          R.createElement(
            'span',
            { key: 'cert', 'data-testid': 'vm-evidence', style: { fontSize: 12, opacity: 0.85 } },
            `Certificate: ${it.evidence.title} (v${it.evidence.current_version})`,
          ),
        );
      }
      if (vaultDocs.length > 0) {
        children.push(
          R.createElement(
            'select',
            {
              key: 'picker',
              'data-testid': `vm-vault-picker-${kind}`,
              value: it.vault_document_id ?? '',
              style: { fontSize: 12 },
              onChange: (e: any) => {
                const val = e.target.value;
                sendJson(`${BASE}/${kind}/${it.id}/evidence`, 'PATCH', { vaultDocumentId: val || null })
                  .then(onLinked)
                  .catch(onError);
              },
            },
            R.createElement('option', { key: '', value: '' }, '— no evidence —'),
            vaultDocs.map((vd: any) =>
              R.createElement('option', { key: vd.id, value: vd.id }, `${vd.title} (v${vd.current_version})`),
            ),
          ),
        );
      }
      return R.createElement('div', { style: { display: 'flex', alignItems: 'center', gap: 8 } }, children);
    }

    function DetailView({ id, onBack }: { id: string; onBack: () => void }) {
      const [data, setData] = R.useState<any>(null);
      const [error, setError] = R.useState<string | null>(null);
      const [reload, setReload] = R.useState(0);
      const [vaultDocs, setVaultDocs] = R.useState<any[]>([]);
      const [staff, setStaff] = R.useState<any[]>([]);
      R.useEffect(() => {
        let alive = true;
        getJson<any>(`${BASE}/${id}`)
          .then((d) => alive && setData(d))
          .catch((e) => alive && setError(String(e)));
        return () => {
          alive = false;
        };
      }, [id, reload]);

      // Picker candidate lists — fetched once. Both seams degrade to an empty array when
      // their host plugin is absent, in which case the corresponding picker stays hidden.
      R.useEffect(() => {
        let alive = true;
        getJson<{ documents: any[] }>(`${BASE}/vault-documents`)
          .then((d) => alive && setVaultDocs(d?.documents ?? []))
          .catch(() => alive && setVaultDocs([]));
        getJson<{ employees: any[] }>(`${BASE}/assignable-staff`)
          .then((d) => alive && setStaff(d?.employees ?? []))
          .catch(() => alive && setStaff([]));
        return () => {
          alive = false;
        };
      }, [id]);

      const bump = () => setReload((n) => n + 1);
      // Surface picker mutation failures instead of swallowing them: log for the
      // console + show the shared vm-error banner, and DON'T call bump (so the UI
      // never flips to a false "saved" state on a rejected write).
      const fail = (e: unknown) => {
        console.error('[vendor-manager] picker mutation failed', e);
        setError(String(e));
      };

      const vendor = data?.vendor;
      const documents = data?.documents ?? [];
      const onboarding = data?.onboardingHistory ?? [];
      const credentials = data?.credentials ?? [];

      return R.createElement(
        'div',
        { 'data-testid': 'vm-detail', style: { display: 'grid', gap: 16 } },
        R.createElement(
          Button,
          { variant: 'ghost', size: 'sm', onClick: onBack, style: { width: 'fit-content', display: 'inline-flex', alignItems: 'center', gap: 8 } },
          R.createElement(IconArrowLeft, { R }),
          'Vendors',
        ),
        error && R.createElement('div', { 'data-testid': 'vm-error', style: { color: 'crimson' } }, error),
        vendor &&
          R.createElement(
            'div',
            { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between' } },
            R.createElement(
              'div',
              null,
              R.createElement('h1', { style: { fontSize: 24, fontWeight: 600, letterSpacing: '-0.01em' } }, vendor.company_name ?? '—'),
              R.createElement(
                'p',
                { style: { fontSize: 14, opacity: 0.7 } },
                `${vendor.category ?? 'Uncategorized'} · ${vendor.contact_name ?? 'No contact'}`,
              ),
            ),
            R.createElement(
              'div',
              { style: { display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 4 } },
              R.createElement(Badge, { variant: STATUS_VARIANT[vendor.status] ?? 'default', style: { textTransform: 'capitalize' } }, vendor.status),
              data.accountRep &&
                R.createElement('span', { 'data-testid': 'vm-rep', style: { fontSize: 12, opacity: 0.85 } }, `Rep: ${data.accountRep.display_name}`),
              staff.length > 0 &&
                R.createElement(
                  'select',
                  {
                    'data-testid': 'vm-rep-picker',
                    value: vendor.account_rep_employee_id ?? '',
                    style: { fontSize: 12 },
                    onChange: (e: any) => {
                      const val = e.target.value;
                      sendJson(`${BASE}/${id}/account-rep`, 'PUT', { employeeId: val || null })
                        .then(bump)
                        .catch(fail);
                    },
                  },
                  R.createElement('option', { key: '', value: '' }, '— unassigned —'),
                  staff.map((s: any) => R.createElement('option', { key: s.id, value: s.id }, s.display_name)),
                ),
            ),
          ),
        data &&
          R.createElement(
            'div',
            { style: { display: 'grid', gap: 16, gridTemplateColumns: 'repeat(auto-fit,minmax(280px,1fr))' } },
            R.createElement(DetailSection, {
              title: `Onboarding (${onboarding.length})`,
              items: onboarding,
              empty: 'No onboarding steps.',
              renderItem: (s: any) =>
                R.createElement(DetailRow, {
                  key: s.id,
                  label: s.label ?? s.step_key,
                  meta: R.createElement(Badge, { variant: 'outline', style: { textTransform: 'capitalize' } }, s.status),
                }),
            }),
            R.createElement(DetailSection, {
              title: `Credentials (${credentials.length})`,
              items: credentials,
              empty: 'No credentials.',
              renderItem: (c: any) =>
                R.createElement(DetailRow, {
                  key: c.id,
                  label: c.label ?? c.credential_type ?? 'Credential',
                  meta: R.createElement(EvidenceControls, { it: c, kind: 'credentials', vaultDocs, onLinked: bump, onError: fail }),
                }),
            }),
            R.createElement(DetailSection, {
              title: `Documents (${documents.length})`,
              items: documents,
              empty: 'No documents.',
              renderItem: (d: any) =>
                R.createElement(DetailRow, {
                  key: d.id,
                  label: d.file_name ?? d.document_type ?? 'Document',
                  meta: R.createElement(EvidenceControls, { it: d, kind: 'documents', vaultDocs, onLinked: bump, onError: fail }),
                }),
            }),
          ),
      );
    }

    root.render(R.createElement(App));
    return () => root.unmount();
  },
};

export default plugin;
