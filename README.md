# Vendor Manager

**Vendor lifecycle management for VCTRbase.** Vendor directory, onboarding
workflow, documents, credentials, API keys, and compliance settings — with
expiry alerting and escalation.

**Verified · by Carmelo Santana.** First-party, signed, PHP-native plugin.
Slug `vb-vendor-manager` · namespace `Vctrs\Plugins\VbVendorManager`. Ships from
outside the monorepo and is autoloaded by `App\Plugins\RuntimeAutoloader`. Its
release ZIP is signed with the Carmelo Santana Ed25519 key
(`keyId carmelo-ed25519-2026`), so it installs as **Verified** and its server
code boots under the `signed_first_party` trust tier. **Touches no core files.**

## What it does (v1.0)

- **Vendor directory** — profiles with categories, status lifecycle, and
  soft-delete/restore (`VendorProfile`, `VendorService`).
- **Onboarding workflow** — stepwise onboarding creation and advancement
  (`VendorOnboarding`).
- **Documents & credentials** — per-vendor documents and credentials with
  add/list/remove and admin soft-delete/restore (`VendorDocument`,
  `VendorCredential`).
- **Compliance settings** — COI/contract/credential alert windows plus COI and
  W-9 requirement toggles.
- **API keys** — issue/list/revoke per-vendor API keys.
- **Reports** — contract report endpoint.
- **Widgets** — active vendors, expiring documents, vendors by category,
  recently onboarded.

## Install

Vendor Manager is distributed through the VCTRbase marketplace as a **signed
release artifact**. Install it from **Dashboard → Marketplace** (or the plugin
admin at `/dashboard/plugins`): pick Vendor Manager, and the platform downloads
the release ZIP, verifies its Ed25519 signature against the trusted keyring, and
boots the server code under the `signed_first_party` trust tier.

Manual/offline install of a specific release:

```bash
# download the release assets for the tag you want, e.g. v1.0.0
gh release download v1.0.0 -R carmelosantana/vb-vendor-manager \
  -p 'vb-vendor-manager-1.0.0.zip' -p 'vb-vendor-manager-1.0.0.zip.sig'
# upload the .zip (+ .sig) at /dashboard/plugins — the installer verifies the
# signature against keyId "carmelo-ed25519-2026" before enabling server code.
```

## Usage

Once enabled, all endpoints live under `/dashboard/vendor` and are tenant-scoped
and permission-gated. The module UI is served at
`/dashboard/plugins/vb-vendor-manager/view`.

### JSON API (tenant-scoped, under `/dashboard/vendor`)

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/api/stats` | `vendor.view.rooftop` | Vendor stats summary |
| GET | `/api/list` | `vendor.view.rooftop` | List vendors |
| GET | `/api/{id}` | `vendor.view.rooftop` | Get one vendor |
| POST | `/api` | `vendor.manage.rooftop` | Create a vendor |
| PUT | `/api/{id}` | `vendor.manage.rooftop` | Update a vendor |
| POST | `/api/{id}/status` | `vendor.manage.rooftop` | Update vendor status |
| POST | `/api/{vendorId}/onboarding` | `vendor.onboard.rooftop` | Advance onboarding |
| POST | `/api/{vendorId}/documents` | `vendor.documents.write.rooftop` | Add a document |
| GET | `/api/{vendorId}/documents` | `vendor.view.rooftop` | List vendor documents |
| DELETE | `/api/documents/{id}` | `vendor.documents.write.rooftop` | Remove a document |
| POST | `/api/{vendorId}/credentials` | `vendor.manage.rooftop` | Add a credential |
| GET | `/api/{vendorId}/credentials` | `vendor.view.rooftop` | List vendor credentials |
| DELETE | `/api/credentials/{id}` | `vendor.manage.rooftop` | Remove a credential |
| GET | `/api/keys` | `vendor.api.manage.rooftop` | List API keys |
| POST | `/api/{vendorId}/key` | `vendor.api.manage.rooftop` | Issue a vendor API key |
| DELETE | `/api/{vendorId}/key` | `vendor.api.manage.rooftop` | Revoke a vendor API key |
| GET | `/api/reports/contract` | `vendor.reports.view.rooftop` | Contract report |
| PUT | `/{id}/admin` | `vendor.admin.manage.rooftop` | Admin update vendor |
| DELETE | `/{id}/admin` | `vendor.admin.manage.rooftop` | Soft-delete vendor |
| POST | `/{id}/admin/restore` | `vendor.admin.manage.rooftop` | Restore vendor |
| DELETE | `/documents/{id}/admin` | `vendor.admin.manage.rooftop` | Soft-delete document |
| POST | `/documents/{id}/admin/restore` | `vendor.admin.manage.rooftop` | Restore document |
| POST | `/onboarding` | `vendor.onboard.rooftop` | Create onboarding |

Permissions: `vendor.view.rooftop`, `vendor.manage.rooftop`,
`vendor.onboard.rooftop`, `vendor.documents.write.rooftop`,
`vendor.settings.write.rooftop`, `vendor.admin.manage.rooftop`,
`vendor.reports.view.rooftop`, `vendor.api.manage.rooftop`.

### Module UI

This plugin ships in **module mode** (`uiMode: "module"`): the front-end is an
ESM bundle (`dist/entry.js`) that the host mounts at
`/dashboard/plugins/vb-vendor-manager/view`. The `dist/` bundle is built fresh
before packaging and staged into the release ZIP (it is gitignored in source
control).

### Scheduled jobs

| Job | Schedule | Purpose |
|---|---|---|
| `vendor-manager/expiry-check` | `0 8 * * *` | Daily COI/contract/credential expiry alerts — Tasks + Feed |
| `vendor-manager/escalation-check` | `0 9 * * *` | Daily escalation of <7d unresolved expiries to Feed |

## Channels soft-dependency (revisit)

On the `approved` onboarding step, `VendorOnboardingController::advance()` mirrors
the Next.js core's `getOrCreateVendorChannel`: when the active tenant is a rooftop
it **best-effort** get-or-creates the vendor's private channel (`slug
vendor-<first-8-of-vendor-id>`) and, on first creation, seeds an owner member and a
welcome message. This is a **new soft (optional) dependency on the `channels`
plugin**:

- The whole block is guarded by `class_exists('Vctrs\Plugins\Channels\Models\Channel')`
  and references the channels models only via fully-qualified **string** class names,
  so there is no compile-time coupling (and PHPStan stays quiet).
- It is wrapped in `try/catch (\Throwable) { report($e); }` — faithful to core, a
  channel failure must **never** block onboarding. If `channels` is not installed the
  block is simply a no-op and the vendor is still activated.

**Revisit:** the clean fix is for the `channels` plugin to export a
`ChannelDirectory::getOrCreateVendorChannel(tenantType, tenantId, vendorId)`
singleton (mirroring `StaffDirectory`), which would let this controller drop the
string-class coupling and depend on a real contract instead. Tracked in
`docs/superpowers/core-flags-vendor-manager.md` in the core repo. Because `channels`
is absent from the standalone test harness, the channel-create path is a documented
cross-plugin coverage gap (the graceful no-op path IS covered — see
`tests/VendorOnboardingTest.php`).

## Migrations & upgrades

The five genesis migrations in `database/migrations/` each open with an
`if (Schema::hasTable('<table>')) return;` **adopt-existing** guard. This makes
first-install idempotent and lets a host that already owns the vendor tables (e.g.
one that previously ran the in-monorepo `plugins/vendor-manager`) **adopt** them —
data preserved — rather than dropping and recreating. Both paths are proven by
`tests/VendorMigrationsTest.php` (fresh-create + adopt).

**Upgrade policy:** never mutate a genesis migration to evolve the schema — a host
that already has the table would skip the change entirely. Ship every future schema
change as a **new, additive, dated migration**, each independently idempotent (e.g.
`if (Schema::hasColumn(...)) return;`), so fresh and existing hosts converge.

## License

AGPLv3 with a plugin-API exception, mirroring the VCTRbase platform license — see
[`LICENSE`](LICENSE). Copyright (C) 2026 VCTRS LLC; author Carmelo Santana.
