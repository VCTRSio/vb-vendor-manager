# Changelog

All notable changes to Vendor Manager are documented here.

## [1.0.0] - 2026-07-09

### Added
- First-party PHP-native plugin scaffold extracted from the VCTRbase monorepo (`plugins/vendor-manager`), reshaped as a standalone signed release repo in the `Vctrs\Plugins\VendorManager` namespace.
- Vendor directory & profiles — CRUD, status lifecycle, soft-delete/restore (`VendorProfile`, `VendorService`).
- Onboarding workflow — stepwise onboarding creation and advancement (`VendorOnboarding`, `OnboardingController`, `VendorOnboardingController`).
- Documents & credentials — per-vendor document and credential add/list/remove with admin soft-delete/restore (`VendorDocument`, `VendorCredential`).
- Compliance settings — COI/contract/credential alert windows and COI/W-9 requirements (admin settings, `vendor.settings.write.rooftop`).
- Vendor API keys — issue/list/revoke per-vendor keys (`vendor.api.manage.rooftop`).
- Reports — contract report endpoint (`vendor.reports.view.rooftop`).
- Dashboard widgets: active vendors, expiring documents, vendors by category, recently onboarded.
- Scheduled jobs: daily expiry check (`0 8 * * *`) and daily escalation check (`0 9 * * *`).
- Module UI mode (`uiMode: "module"`) — ships an ESM entry (`dist/entry.js`) rendered at `/dashboard/plugins/vb-vendor-manager/view`.
- Release tooling copied from the vb-prana-buzz skeleton: signed release artifact via `tools/sign.php` + `tools/verify.php`.
