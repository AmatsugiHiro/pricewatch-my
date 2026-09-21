<?php

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\ItemBrowser;
use App\Livewire\ItemDetail;
use App\Livewire\PipelineDashboard;
use App\Livewire\Watchlist;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
| Public pages. Browsing prices needs no account; only the watchlist does.
*/
Route::get('/', ItemBrowser::class)->name('items.index');
Route::get('/item/{itemCode}', ItemDetail::class)->name('items.show');
Route::get('/pipeline', PipelineDashboard::class)->name('pipeline');

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
    Route::get('/register', Register::class)->name('register');
});

Route::middleware('auth')->group(function () {
    Route::get('/watchlist', Watchlist::class)->name('watchlist');

    // POST, so that a prefetched or crawled link cannot sign the user out.
    Route::post('/logout', function () {
        Auth::logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('items.index');
    })->name('logout');
});
