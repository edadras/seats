<?php

use Illuminate\Support\Facades\Route;

/*
| The panel is served as a single page that talks to /v1. Deep links are handled client-side, so
| every non-API path returns the same shell.
*/

Route::view('/', 'panel')->name('panel');
Route::view('/{any}', 'panel')->where('any', '^(?!v1|up|storage).*$');
