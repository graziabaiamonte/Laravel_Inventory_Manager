<?php

use App\Http\Controllers\AreaController;
use App\Http\Controllers\ArtistController;
use App\Http\Controllers\BackorderController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DiscogsController;
use App\Http\Controllers\FormatController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\RecordsImportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserManualController;
use App\Http\Controllers\WholesaleInController;
use App\Http\Controllers\WholesaleOutController;
use App\Models\Customer;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// use App\Enums\PermissionsEnum;

Route::get('/', function () {
    return redirect()
        ->route('dashboard');
});

Route::get('/dashboard', function () {
    // Count customers without WholesaleOut orders in the last month
    // Filtered by user's assigned locations for managers/operators
    $date = now()->subMonth();
    $customersWithoutRecentOrders = Customer::filterByAdminRoles()
        ->whereDoesntHave('wholesaleOuts', function ($query) use ($date) {
            $query->where('created_at', '>=', $date);
        })->count();

    return Inertia::render('Dashboard', [
        'sales' => App::make(SaleController::class)->getSummary(request()),
        'customersWithoutRecentOrders' => $customersWithoutRecentOrders,
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    // User Manual routes
    Route::get('/user-manual', [UserManualController::class, 'index'])->name('user-manual.index');
    Route::get('/user-manual/{page}', [UserManualController::class, 'show'])->name('user-manual.show');
    Route::get('/user-manual/image/{filename}', [UserManualController::class, 'image'])->name('user-manual.image');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    // Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::resource('user', UserController::class)
        ->middleware(['role_or_permission:admin|manage_users']);

    Route::get('/users/trash/', [UserController::class, 'indexTrash'])
        ->name('userstrash.index')
        ->middleware(['role_or_permission:admin|manage_users']);

    Route::patch('/users/{user}/restore', [UserController::class, 'restore'])
        ->name('user.restore')
        ->withTrashed()
        ->middleware(['role_or_permission:admin|manage_users']);

    Route::delete('/users/{user}/force-delete', [UserController::class, 'destroy'])
        ->name('user.force-destroy')
        ->withTrashed()
        ->middleware(['role_or_permission:admin|manage_users']);

    // NOTE: Store perms are routed in StoreRequest and checked in StorePolicy
    Route::resource('location', LocationController::class);
    // ->middleware(['role_or_permission:admin|all|'.collect(PermissionsEnum::getStores())->pluck('value')->join('|')])

    Route::get('/locations/trash/', [LocationController::class, 'indexTrash'])
        ->name('locationstrash.index');

    Route::patch('/locations/{location}/restore', [LocationController::class, 'restore'])
        ->name('location.restore')
        ->withTrashed();

    Route::delete('/locations/{location}/force-delete', [LocationController::class, 'destroy'])
        ->name('location.force-destroy')
        ->withTrashed();

    Route::resource('area', AreaController::class);

    Route::get('/areas/trash/', [AreaController::class, 'indexTrash'])
        ->name('areastrash.index');

    Route::patch('/areas/{area}/restore', [AreaController::class, 'restore'])
        ->name('area.restore')
        ->withTrashed();

    Route::delete('/areas/{area}/force-delete', [AreaController::class, 'destroy'])
        ->name('area.force-destroy')
        ->withTrashed();

    Route::resource('format', FormatController::class);

    Route::get('/formats/trash/', [FormatController::class, 'indexTrash'])
        ->name('formatstrash.index');

    Route::patch('/formats/{format}/restore', [FormatController::class, 'restore'])
        ->name('format.restore')
        ->withTrashed();

    Route::delete('/formats/{format}/force-delete', [FormatController::class, 'destroy'])
        ->name('format.force-destroy')
        ->withTrashed();

    Route::resource('artist', ArtistController::class);

    Route::get('/artists/trash/', [ArtistController::class, 'indexTrash'])
        ->name('artiststrash.index');

    Route::patch('/artists/{artist}/restore', [ArtistController::class, 'restore'])
        ->name('artist.restore')
        ->withTrashed();

    Route::delete('/artists/{artist}/force-delete', [ArtistController::class, 'destroy'])
        ->name('artist.force-destroy')
        ->withTrashed();

    Route::resource('label', LabelController::class);

    Route::get('/labels/trash/', [LabelController::class, 'indexTrash'])
        ->name('labelstrash.index');

    Route::patch('/labels/{label}/restore', [LabelController::class, 'restore'])
        ->name('label.restore')
        ->withTrashed();

    Route::delete('/labels/{label}/force-delete', [LabelController::class, 'destroy'])
        ->name('label.force-destroy')
        ->withTrashed();

    Route::resource('customer', CustomerController::class);

    Route::get('/customers/trash/', [CustomerController::class, 'indexTrash'])
        ->name('customerstrash.index');

    Route::patch('/customers/{customer}/restore', [CustomerController::class, 'restore'])
        ->name('customer.restore')
        ->withTrashed();

    Route::delete('/customers/{customer}/force-delete', [CustomerController::class, 'destroy'])
        ->name('customer.force-destroy')
        ->withTrashed();

    Route::resource('supplier', SupplierController::class);

    Route::get('/suppliers/trash/', [SupplierController::class, 'indexTrash'])
        ->name('supplierstrash.index');

    Route::patch('/suppliers/{supplier}/restore', [SupplierController::class, 'restore'])
        ->name('supplier.restore')
        ->withTrashed();

    Route::delete('/suppliers/{supplier}/force-delete', [SupplierController::class, 'destroy'])
        ->name('supplier.force-destroy')
        ->withTrashed();

    Route::get('/sale/summary', [SaleController::class, 'getSummary'])
        ->name('sale.summary');

    Route::resource('sale', SaleController::class);

    Route::get('sales/export/', [SaleController::class, 'export'])
        ->name('sale.export');

    Route::get('sales/export/{exportId}/status', [SaleController::class, 'exportStatus'])
        ->name('sale.export.status');

    Route::post('sales/export/{exportId}/cancel', [SaleController::class, 'cancelExport'])
        ->name('sale.export.cancel');

    Route::get('sales/export/{exportId}/download', [SaleController::class, 'downloadExport'])
        ->name('sale.export.download');

    Route::resource('record', RecordController::class);

    Route::get('/records/trash/', [RecordController::class, 'indexTrash'])
        ->name('recordstrash.index');

    Route::patch('/records/{record}/restore', [RecordController::class, 'restore'])
        ->name('record.restore')
        ->withTrashed();

    Route::delete('/records/{record}/force-delete', [RecordController::class, 'destroy'])
        ->name('record.force-destroy')
        ->withTrashed();

    Route::get('/records/{record}/barcode', [RecordController::class, 'printBarcode'])
        ->name('record.barcode');

    Route::get('/records/{record}/history', [RecordController::class, 'history'])
        ->name('record.history');

    Route::get('records/export/', [RecordController::class, 'export'])
        ->name('record.export');

    Route::get('records/export/{exportId}/status', [RecordController::class, 'exportStatus'])
        ->name('record.export.status');

    Route::post('records/export/{exportId}/cancel', [RecordController::class, 'cancelExport'])
        ->name('record.export.cancel');

    Route::get('records/export/{exportId}/download', [RecordController::class, 'downloadExport'])
        ->name('record.export.download');

    Route::get('records-import/download-template', [RecordsImportController::class, 'downloadTemplate'])
        ->name('records-import.download-template');

    Route::resource('records-import', RecordsImportController::class);

    // Routes for managing individual records in a draft import
    Route::patch('records-import/{recordsImport}/record/{record}', [RecordsImportController::class, 'updateRecord'])
        ->name('records-import.update-record');

    Route::delete('records-import/{recordsImport}/record/{record}', [RecordsImportController::class, 'deleteRecord'])
        ->name('records-import.delete-record');

    Route::post('records-import/{recordsImport}/record', [RecordsImportController::class, 'addRecord'])
        ->name('records-import.add-record');

    Route::resource('stock', StockController::class);

    Route::patch('stock/{dragging}/{target}', [StockController::class, 'swap'])
        ->name('stock.swap');

    // Specific routes must come before resource routes to avoid conflicts
    Route::get('wholesale-in/download-template', [WholesaleInController::class, 'downloadTemplate'])
        ->name('wholesale-in.download-template');

    Route::get('wholesale-in/{wholesaleIn}/export', [WholesaleInController::class, 'export'])
        ->name('wholesale-in.export');

    Route::get('wholesale-in/{wholesaleIn}/barcodes', [WholesaleInController::class, 'printBarcodes'])
        ->name('wholesale-in.barcodes');

    Route::get('wholesale-out/download-template', [WholesaleOutController::class, 'downloadTemplate'])
        ->name('wholesale-out.download-template');

    Route::get('wholesale-out/{wholesaleOut}/export', [WholesaleOutController::class, 'export'])
        ->name('wholesale-out.export');

    Route::post('wholesale-out/activation-preview', [WholesaleOutController::class, 'activationPreview'])
        ->name('wholesale-out.activation-preview');

    Route::resource('wholesale-in', WholesaleInController::class);

    Route::resource('wholesale-out', WholesaleOutController::class);

    Route::resource('backorder', BackorderController::class);

    Route::get('backorder/{backorder}/export', [BackorderController::class, 'export'])
        ->name('backorder.export');

    Route::post('backorder/{backorder}/deactivate', [BackorderController::class, 'deactivate'])
        ->name('backorder.deactivate');

    Route::get('media/{media}', [MediaController::class, 'show'])
        ->name('media.show');
    Route::get('media/{media}/download', [MediaController::class, 'download'])
        ->name('media.download');

    Route::prefix('discogs')->name('discogs.')->group(function () {
        Route::post('release', [DiscogsController::class, 'getRelease'])->name('getRelease');
        Route::post('search', [DiscogsController::class, 'search'])->name('search');
        // Route::post('records/{record}/list', [DiscogsController::class, 'listRecord'])->name('list');
        // Route::patch('records/{record}/update', [DiscogsController::class, 'updateListing'])->name('update');
        // Route::delete('records/{record}/remove', [DiscogsController::class, 'removeListing'])->name('remove');
        // Route::post('sync-inventory', [DiscogsController::class, 'syncInventory'])->name('sync');
    });

    Route::get('/content', [ContentController::class, 'index'])->name('content.index');

});

require __DIR__.'/auth.php';
