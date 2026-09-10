<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Channels\ChannelQuotas;
use App\Domain\Sites\StorefrontCheckout;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\ChannelQuota;
use App\Models\Event;
use App\Models\Site;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * How much of one night each channel may sell.
 *
 * Behind `pricing.manage`, with the other decisions about what is on offer and to whom. It is not
 * a box-office permission: promising an agent four hundred places is a commercial decision about
 * the whole night, not something somebody does at a window between customers.
 *
 * A PUT of the whole set rather than a patch per channel, for the same reason the prices are:
 * partial edits across a set of limits are where "the agent still has last season's allocation"
 * comes from. Sending the complete intended state each time means the screen and the account
 * always agree about what was promised.
 */
class ChannelQuotaController extends Controller
{
    public function __construct(
        private readonly ChannelQuotas $quotas,
        private readonly StorefrontCheckout $storefront,
        private readonly AuditLogger $audit,
    ) {}

    /** Every channel, its promise, and how much of it is gone. */
    public function index(Request $request, Event $event)
    {
        $this->authorize($request, 'pricing.manage');

        /*
         * A hosted site's channel identity is created the first time it sells something, which
         * would leave the organiser's own website missing from this screen until somebody had
         * bought a ticket through it — exactly the moment a cap on it is too late to set. Asking
         * who the channels are is reason enough for the site to have one.
         */
        foreach (Site::all() as $site) {
            $this->storefront->clientFor($site);
        }

        return response()->json(['data' => $this->quotas->forEvent($event)]);
    }

    public function update(Request $request, Event $event)
    {
        $this->authorize($request, 'pricing.manage');

        $data = $request->validate([
            'quotas' => ['present', 'array', 'max:200'],
            'quotas.*.api_client_id' => ['required', 'uuid'],
            // Null is "no limit", which is the ordinary case and is stored as no row at all.
            'quotas.*.places' => ['present', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'quotas.*.note' => ['sometimes', 'nullable', 'string', 'max:160'],
        ]);

        // Only this account's own channels. A quota against somebody else's client would be a row
        // pointing across a tenant boundary that nothing else in this application lets anything
        // point across.
        $mine = ApiClient::pluck('id')->all();

        DB::transaction(function () use ($event, $data, $mine, $request) {
            $keep = [];

            foreach ($data['quotas'] as $row) {
                if (! in_array($row['api_client_id'], $mine, true)) {
                    continue;
                }

                if (null === $row['places']) {
                    continue;
                }

                ChannelQuota::updateOrCreate(
                    ['event_id' => $event->id, 'api_client_id' => $row['api_client_id']],
                    [
                        'tenant_id' => $event->tenant_id,
                        'places' => (int) $row['places'],
                        'note' => $row['note'] ?? null,
                    ],
                );

                $keep[] = $row['api_client_id'];
            }

            // A channel left out of the payload has no limit any more. That is what a PUT means,
            // and it is the only way "the agent's allocation is finished with" can be expressed.
            $gone = ChannelQuota::where('event_id', $event->id);

            if ([] !== $keep) {
                $gone->whereNotIn('api_client_id', $keep);
            }

            $gone->delete();

            $this->audit->record('event.quotas_set', $event, [
                'channels' => count($keep),
            ]);
        });

        return response()->json(['data' => $this->quotas->forEvent($event->fresh())]);
    }
}
