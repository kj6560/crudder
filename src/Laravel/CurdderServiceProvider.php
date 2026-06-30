<?php

declare(strict_types=1);

namespace Curdder\Laravel;

use Curdder\Laravel\Http\Controllers\CrudderWizardController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CurdderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/crudder.php', 'crudder');
    }

    public function boot(): void
    {
        $prefix = trim((string)config('crudder.path', 'crudder'), '/');
        $middleware = (array)config('crudder.middleware', ['web']);

        Route::middleware($middleware)
            ->prefix($prefix)
            ->name('crudder.')
            ->group(function (): void {
                Route::get('/', [CrudderWizardController::class, 'index'])->name('index');
                Route::post('/generate', [CrudderWizardController::class, 'generate'])->name('generate');
                Route::get('/{resource}', [CrudderWizardController::class, 'showResource'])->name('resource.show');
                Route::get('/{resource}/create', [CrudderWizardController::class, 'create'])->name('resource.create');
                Route::post('/{resource}', [CrudderWizardController::class, 'store'])->name('resource.store');
                Route::get('/{resource}/{id}/edit', [CrudderWizardController::class, 'edit'])->name('resource.edit');
                Route::match(['PUT', 'PATCH'], '/{resource}/{id}', [CrudderWizardController::class, 'update'])->name('resource.update');
                Route::delete('/{resource}/{id}', [CrudderWizardController::class, 'destroy'])->name('resource.destroy');
            });
    }
}
