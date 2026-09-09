<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Messaging\ChannelRegistry;
use App\Domain\Messaging\MessageDispatcher;
use App\Domain\Messaging\MessageKinds;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\MessageChannelSetting;
use App\Models\MessageDelivery;
use App\Models\MessageTemplate;
use App\Support\Audit\AuditLogger;
use App\Support\Locale\Locales;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * What a buyer is told, and whether it arrived.
 *
 * Two halves. The wording — per kind, per channel, per language, with the platform's own as the
 * fallback so an account that has written nothing still sends a real message. And the log, which
 * exists because "did they get their confirmation" is asked at a window with somebody waiting.
 */
class MessagingController extends Controller
{
    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly MessageDispatcher $dispatcher,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $settings = MessageChannelSetting::all()->groupBy('kind');

        return response()->json([
            'kinds' => array_map(function (array $kind) use ($settings) {
                $kind['channels'] = $this->dispatcher->enabledChannels($kind['key']);
                $kind['recorded'] = ($settings[$kind['key']] ?? collect())
                    ->pluck('enabled', 'channel')->all();

                return $kind;
            }, MessageKinds::describe()),
            'channels' => $this->channels->describe(),
            'locales' => Locales::menu(),
            'templates' => MessageTemplate::all()->map(fn (MessageTemplate $template) => [
                'id' => $template->id,
                'kind' => $template->kind,
                'channel' => $template->channel,
                'locale' => $template->locale,
                'subject' => $template->subject,
                'body' => $template->body,
            ])->values(),
        ]);
    }

    /** The wording that would be used right now — the organiser's, or ours. */
    public function template(Request $request, string $kind, string $channel, string $locale)
    {
        $this->authorize($request, 'account.manage');
        $this->assertKind($kind);

        $rendered = $this->dispatcher->render($kind, $channel, Locales::normalise($locale), []);
        $own = MessageTemplate::where('kind', $kind)->where('channel', $channel)
            ->where('locale', Locales::normalise($locale))->first();

        return response()->json([
            'kind' => $kind,
            'channel' => $channel,
            'locale' => Locales::normalise($locale),
            'subject' => $rendered['subject'],
            'body' => $rendered['body'],
            // So the screen can say "this is ours" rather than implying somebody wrote it.
            'is_default' => null === $own,
            'placeholders' => MessageKinds::placeholders($kind),
        ]);
    }

    public function saveTemplate(Request $request, string $kind, string $channel, string $locale)
    {
        $this->authorize($request, 'account.manage');
        $this->assertKind($kind);
        $this->assertChannel($channel);

        $data = $request->validate([
            'subject' => ['sometimes', 'nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $template = MessageTemplate::updateOrCreate(
            ['kind' => $kind, 'channel' => $channel, 'locale' => Locales::normalise($locale)],
            ['subject' => $data['subject'] ?? null, 'body' => $data['body']],
        );

        $this->audit->record('message_template.saved', $template, [
            'kind' => $kind,
            'channel' => $channel,
            'locale' => $template->locale,
        ]);

        return response()->json(['saved' => true]);
    }

    /** Delete the organiser's wording, which puts ours back. */
    public function resetTemplate(Request $request, string $kind, string $channel, string $locale)
    {
        $this->authorize($request, 'account.manage');

        MessageTemplate::where('kind', $kind)->where('channel', $channel)
            ->where('locale', Locales::normalise($locale))->delete();

        $this->audit->record('message_template.reset', null, [
            'kind' => $kind, 'channel' => $channel, 'locale' => $locale,
        ]);

        return response()->json(['reset' => true]);
    }

    public function setChannel(Request $request, string $kind, string $channel)
    {
        $this->authorize($request, 'account.manage');
        $this->assertKind($kind);
        $this->assertChannel($channel);

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        if (! $data['enabled'] && 'email' === $channel && ! MessageKinds::isOptional($kind)) {
            // A buyer is entitled to a confirmation. An organiser may choose *how* it reaches
            // them, not whether it does.
            throw ApiException::unprocessable(
                'channel_required',
                'A buyer has to be told their order was confirmed.'
            );
        }

        MessageChannelSetting::updateOrCreate(
            ['kind' => $kind, 'channel' => $channel],
            ['enabled' => $data['enabled']],
        );

        $this->audit->record('message_channel.changed', null, [
            'kind' => $kind, 'channel' => $channel, 'enabled' => $data['enabled'],
        ]);

        return response()->json(['enabled' => $data['enabled']]);
    }

    /** Send the wording to somebody, with example values, so it can be read before it is used. */
    public function test(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $data = $request->validate([
            'kind' => ['required', 'string'],
            'channel' => ['required', 'string'],
            'locale' => ['required', 'string', 'max:12'],
            'to' => ['required', 'string', 'max:190'],
        ]);

        $this->assertKind($data['kind']);
        $this->assertChannel($data['channel']);

        $delivery = $this->dispatcher->send(
            $data['kind'],
            $data['channel'],
            $data['to'],
            $this->exampleValues($data['locale']),
            $data['locale'],
        );

        return response()->json([
            'status' => $delivery->status,
            'reason' => $delivery->reason,
            'preview' => $delivery->preview,
        ]);
    }

    public function log(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $deliveries = MessageDelivery::query()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('kind'), fn ($q, $kind) => $q->where('kind', $kind))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 30), 100));

        return $this->paginated($deliveries, fn (MessageDelivery $delivery) => [
            'id' => $delivery->id,
            'kind' => $delivery->kind,
            'channel' => $delivery->channel,
            'recipient' => $delivery->recipient,
            'status' => $delivery->status,
            'reason' => $delivery->reason,
            'preview' => $delivery->preview,
            'attempts' => $delivery->attempts,
            'created_at' => $delivery->created_at?->toIso8601String(),
        ]);
    }

    /* --------------------------------------------------------------------------- helpers */

    private function assertKind(string $kind): void
    {
        if (! MessageKinds::exists($kind)) {
            throw ApiException::unprocessable('unknown_message_kind', 'There is no such message.');
        }
    }

    private function assertChannel(string $channel): void
    {
        if (! $this->channels->has($channel)) {
            throw ApiException::unprocessable('unknown_channel', 'That channel is not available.');
        }
    }

    /** Values a test message is rendered with — recognisably examples, in the right language. */
    private function exampleValues(string $locale): array
    {
        return [
            'buyer' => __('messaging.example.buyer', [], $locale),
            'event' => __('messaging.example.event', [], $locale),
            'venue' => __('messaging.example.venue', [], $locale),
            'starts' => \App\Support\Locale\Dates::longWhen(now()->addWeek(), $locale),
            'seats' => __('messaging.example.seats', [], $locale),
            'total' => \App\Support\Locale\Money::format(9000, 'EUR', $locale),
            'reference' => 'TEST-0001',
            'site' => (string) config('app.url'),
        ];
    }
}
