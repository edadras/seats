<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the door scanner (checkin-app) at /checkin.
 *
 * The compiled app lives in public/, so a web server hands out its assets without PHP ever running.
 * This action exists for the two URLs the file system cannot answer: `/checkin` itself, and any
 * deep link inside the app — a single-page app has no file behind its routes.
 *
 * The build is not committed, so a fresh checkout has no app here. That case is answered with a
 * plain instruction rather than a stack trace: it is a build step someone has not run, not a fault.
 */
class CheckinAppController extends Controller
{
    public function __invoke(): Response
    {
        $index = public_path('checkin/index.html');

        if (! is_file($index)) {
            if (! app()->hasDebugModeEnabled()) {
                throw new NotFoundHttpException('The check-in app is not installed on this server.');
            }

            return response(
                'The check-in app has not been built yet. Run checkin-app/build.sh.',
                503,
                ['Content-Type' => 'text/plain; charset=utf-8']
            );
        }

        // No caching of the shell. Its asset URLs change with every build, and a door phone that
        // held on to yesterday's index.html would ask for files that are no longer there.
        return response(file_get_contents($index), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
