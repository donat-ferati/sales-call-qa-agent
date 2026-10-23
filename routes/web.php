<?php

use App\Http\Controllers\AudioController;
use App\Livewire\Calls;
use App\Livewire\Dashboard;
use App\Livewire\Evaluations;
use App\Livewire\Templates;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function ()
{
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/templates', Templates\Index::class)->name('templates.index');
    Route::get('/templates/create', Templates\Create::class)->name('templates.create');
    Route::get('/templates/{template}/edit', Templates\Edit::class)->name('templates.edit');
    Route::get('/templates/{template}', Templates\Show::class)->name('templates.show');
    Route::get('/calls', Calls\Index::class)->name('calls.index');
    Route::get('/calls/create', Calls\Create::class)->name('calls.create');
    Route::get('/calls/batches/{batch}', Calls\Batches\Show::class)->name('calls.batches.show');
    Route::get('/calls/{call}/audio', AudioController::class)->name('calls.audio');
    Route::get('/evaluations/{evaluation}', Evaluations\Show::class)->name('evaluations.show');
});
