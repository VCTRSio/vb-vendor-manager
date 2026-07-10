<?php

namespace Vctrs\Plugins\VbVendorManager;

use App\Audit\AuditableRegistry;
use App\Plugins\Contracts\PluginModule;
use App\Plugins\Contracts\ProvidesScheduledTasks;
use App\Plugins\PluginManifest;
use Illuminate\Support\Facades\Route;
use Vctrs\Plugins\VbVendorManager\Jobs\VendorEscalationCheckJob;
use Vctrs\Plugins\VbVendorManager\Jobs\VendorExpiryCheckJob;
use Vctrs\Plugins\VbVendorManager\Models\VendorCredential;
use Vctrs\Plugins\VbVendorManager\Models\VendorDocument;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;

class VendorManagerServiceProvider implements PluginModule, ProvidesScheduledTasks
{
    /**
     * Human-readable labels for vendor categories. Mirrors core's CATEGORY_LABELS.
     *
     * @var array<string, string>
     */
    private const CATEGORY_LABELS = [
        'oem' => 'OEM',
        'aftermarket' => 'Aftermarket',
        'marketing' => 'Marketing',
        'facility' => 'Facility',
        'technology' => 'Technology',
    ];

    public function __construct(private readonly PluginManifest $manifest, private readonly string $dir) {}

    /**
     * Compact relative-time helper for onboarded date labels. Returns strings like
     * "2h ago", "3d ago", "5w ago". Mirrors core's relativeTime().
     */
    private static function relativeTime(?\DateTimeInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        $seconds = (int) floor((time() - $date->getTimestamp()));
        if ($seconds < 60) {
            return max($seconds, 0).'s ago';
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes.'m ago';
        }
        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return $hours.'h ago';
        }
        $days = intdiv($hours, 24);
        if ($days < 7) {
            return $days.'d ago';
        }
        $weeks = intdiv($days, 7);
        if ($weeks < 5) {
            return $weeks.'w ago';
        }
        $months = intdiv($days, 30);
        if ($months < 12) {
            return $months.'mo ago';
        }
        $years = intdiv($days, 365);

        return $years.'y ago';
    }

    public function manifest(): PluginManifest
    {
        return $this->manifest;
    }

    public function register(): void
    {
        AuditableRegistry::register(VendorProfile::class);
        AuditableRegistry::register(VendorDocument::class);
        AuditableRegistry::register(VendorCredential::class);
        Route::group([], $this->dir.'/src/routes.php');
    }

    public function navItems(): array
    {
        return $this->manifest->nav;
    }

    public function widgets(): array
    {
        return [
            'vendor.activeVendors' => [
                'vendor.view.rooftop',
                fn () => [
                    'type' => 'metric',
                    'payload' => [
                        'label' => 'Active vendors',
                        'value' => VendorProfile::whereNull('deleted_at')
                            ->where('status', 'active')
                            ->count(),
                    ],
                ],
            ],
            'vendor.expiringDocuments' => [
                'vendor.view.rooftop',
                fn () => [
                    'type' => 'metric',
                    'payload' => [
                        'label' => 'Expiring documents (60d)',
                        'value' => VendorDocument::query()
                            ->whereNull('deleted_at')
                            ->whereNotNull('expires_at')
                            ->whereBetween('expires_at', [now(), now()->addDays(60)])
                            ->count(),
                    ],
                ],
            ],
            'vendor.byCategory' => [
                'vendor.view.rooftop',
                function () {
                    $rows = VendorProfile::query()
                        ->whereNull('deleted_at')
                        ->selectRaw('category, count(*) as c')
                        ->groupBy('category')
                        ->orderByDesc('c')
                        ->toBase()
                        ->get();

                    // Top 8 + "Other" rollup, mirroring core's byCategory.
                    $top = $rows->slice(0, 8)->values();
                    $tail = $rows->slice(8);
                    $otherTotal = (int) $tail->sum(fn (object $r) => (int) ($r->c ?? 0));

                    $slices = $top->map(fn (object $r) => [
                        'label' => self::CATEGORY_LABELS[(string) ($r->category ?? '')]
                            ?? (string) ($r->category ?? ''),
                        'value' => (int) ($r->c ?? 0),
                    ])->values();

                    if ($otherTotal > 0) {
                        $slices->push(['label' => 'Other', 'value' => $otherTotal]);
                    }

                    return [
                        'type' => 'chart-donut',
                        'payload' => [
                            // Top-level label: the dashboard MetricCard reads
                            // payload.label; every widget payload must supply one.
                            'label' => 'Vendors by category',
                            'slices' => $slices->values(),
                        ],
                    ];
                },
            ],
            'vendor.recentlyOnboarded' => [
                'vendor.view.rooftop',
                fn () => [
                    'type' => 'list',
                    'payload' => [
                        'label' => 'Recently onboarded',
                        'rows' => VendorProfile::query()
                            ->whereNull('deleted_at')
                            ->orderByDesc('created_at')
                            ->limit(5)->get()
                            ->map(fn (VendorProfile $v) => [
                                'label' => $v->company_name,
                                'sublabel' => self::CATEGORY_LABELS[$v->category] ?? $v->category,
                                'value' => self::relativeTime($v->created_at),
                                'href' => '/dashboard/vendor/'.$v->id,
                            ])
                            ->values(),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, cron: string, perTenant: bool, job: class-string<\App\Plugins\Scheduling\PluginScheduledJob>}>
     */
    public function scheduledTasks(): array
    {
        return [
            ['key' => 'expiry-check', 'cron' => '0 8 * * *', 'perTenant' => true, 'job' => VendorExpiryCheckJob::class],
            ['key' => 'escalation-check', 'cron' => '0 9 * * *', 'perTenant' => true, 'job' => VendorEscalationCheckJob::class],
        ];
    }

    public function permissions(): array
    {
        return [];
    }
}
