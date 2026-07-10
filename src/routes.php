<?php

use Illuminate\Support\Facades\Route;
use Vctrs\Plugins\VbVendorManager\Http\Controllers\VendorAdminController;
use Vctrs\Plugins\VbVendorManager\Http\Controllers\VendorCredentialController;
use Vctrs\Plugins\VbVendorManager\Http\Controllers\VendorDocumentAdminController;
use Vctrs\Plugins\VbVendorManager\Http\Controllers\VendorDocumentController;
use Vctrs\Plugins\VbVendorManager\Http\Controllers\VendorApiKeyController;
use Vctrs\Plugins\VbVendorManager\Http\Controllers\VendorMutationController;
use Vctrs\Plugins\VbVendorManager\Http\Controllers\VendorOnboardingController;
use Vctrs\Plugins\VbVendorManager\Http\Controllers\VendorReadController;
use Vctrs\Plugins\VbVendorManager\Http\Controllers\VendorReportController;

Route::middleware(['web', 'auth', 'tenant'])->prefix('dashboard/vendor')->name('vendor.')->group(function () {
    Route::put('/{id}/admin', [VendorAdminController::class, 'update'])
        ->middleware('can:vendor.admin.manage.rooftop')->where('id', '[0-9a-f-]+')->name('admin.update');
    Route::delete('/{id}/admin', [VendorAdminController::class, 'softDelete'])
        ->middleware('can:vendor.admin.manage.rooftop')->where('id', '[0-9a-f-]+')->name('admin.softDelete');
    Route::post('/{id}/admin/restore', [VendorAdminController::class, 'restore'])
        ->middleware('can:vendor.admin.manage.rooftop')->where('id', '[0-9a-f-]+')->name('admin.restore');

    Route::delete('/documents/{id}/admin', [VendorDocumentAdminController::class, 'softDelete'])
        ->middleware('can:vendor.admin.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('admin.documents.softDelete');
    Route::post('/documents/{id}/admin/restore', [VendorDocumentAdminController::class, 'restore'])
        ->middleware('can:vendor.admin.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('admin.documents.restore');

    Route::get('/api/reports/contract', [VendorReportController::class, 'contract'])
        ->middleware('can:vendor.reports.view.rooftop')->name('api.reports.contract');

    Route::get('/api/keys', [VendorApiKeyController::class, 'list'])
        ->middleware('can:vendor.api.manage.rooftop')->name('api.keys.list');
    Route::post('/api/{vendorId}/key', [VendorApiKeyController::class, 'issue'])
        ->middleware('can:vendor.api.manage.rooftop')->where('vendorId', '[0-9a-f-]{36}')->name('api.keys.issue');
    Route::delete('/api/{vendorId}/key', [VendorApiKeyController::class, 'revoke'])
        ->middleware('can:vendor.api.manage.rooftop')->where('vendorId', '[0-9a-f-]{36}')->name('api.keys.revoke');

    Route::get('/api/stats', [VendorReadController::class, 'stats'])
        ->middleware('can:vendor.view.rooftop')->name('api.stats');
    Route::get('/api/list', [VendorReadController::class, 'list'])
        ->middleware('can:vendor.view.rooftop')->name('api.list');
    Route::get('/api/{id}', [VendorReadController::class, 'get'])
        ->middleware('can:vendor.view.rooftop')->where('id', '[0-9a-f-]{36}')->name('api.get');

    Route::post('/api/{vendorId}/documents', [VendorDocumentController::class, 'add'])
        ->middleware('can:vendor.documents.write.rooftop')->where('vendorId', '[0-9a-f-]{36}')->name('api.documents.add');
    Route::get('/api/{vendorId}/documents', [VendorDocumentController::class, 'list'])
        ->middleware('can:vendor.view.rooftop')->where('vendorId', '[0-9a-f-]{36}')->name('api.documents.list');
    Route::delete('/api/documents/{id}', [VendorDocumentController::class, 'remove'])
        ->middleware('can:vendor.documents.write.rooftop')->where('id', '[0-9a-f-]{36}')->name('api.documents.remove');

    Route::post('/api/{vendorId}/onboarding', [VendorOnboardingController::class, 'advance'])
        ->middleware('can:vendor.onboard.rooftop')->where('vendorId', '[0-9a-f-]{36}')->name('api.onboarding.advance');

    Route::post('/api/{vendorId}/credentials', [VendorCredentialController::class, 'add'])
        ->middleware('can:vendor.manage.rooftop')->where('vendorId', '[0-9a-f-]{36}')->name('api.credentials.add');
    Route::get('/api/{vendorId}/credentials', [VendorCredentialController::class, 'list'])
        ->middleware('can:vendor.view.rooftop')->where('vendorId', '[0-9a-f-]{36}')->name('api.credentials.list');
    Route::delete('/api/credentials/{id}', [VendorCredentialController::class, 'remove'])
        ->middleware('can:vendor.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('api.credentials.remove');

    Route::post('/api', [VendorMutationController::class, 'create'])
        ->middleware('can:vendor.manage.rooftop')->name('api.create');
    Route::put('/api/{id}', [VendorMutationController::class, 'update'])
        ->middleware('can:vendor.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('api.update');
    Route::post('/api/{id}/status', [VendorMutationController::class, 'updateStatus'])
        ->middleware('can:vendor.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('api.status');
});
