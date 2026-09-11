<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Onboarding\FirstSteps;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * What a new account still has to do.
 *
 * Behind `account.manage` rather than something wider, and that is a judgement about who the answer
 * is *for* as much as about what it reveals. The list crosses the whole account — venues, plans,
 * pricing, payment modules, the website — so reading it is close to reading the shape of the
 * organisation. More to the point, it is advice to the person setting the account up: a box-office
 * volunteer shown "you have not chosen how to be paid" has been handed somebody else's job.
 */
class OnboardingController extends Controller
{
    public function __construct(private readonly FirstSteps $steps) {}

    public function firstSteps(Request $request)
    {
        $this->authorize($request, 'account.manage');
        // Account-wide, like the overview. A promoter running four nights is not being told what
        // the organiser's account is missing.
        app(\App\Domain\Programme\EventManagers::class)->assertNotScoped($request->user());

        return response()->json($this->steps->all());
    }
}
