<?php

namespace App\Domain\Messaging;

use App\Domain\Messaging\Channels\EmailChannel;
use App\Modules\Contracts\MessageChannel;
use App\Modules\ModuleRegistry;

/**
 * Every way this account can reach a buyer: email, and whatever the enabled modules add.
 *
 * Built per tenant, because a channel carries that organiser's own credentials — a channel built
 * for one account must never send from another's.
 */
class ChannelRegistry
{
    private ?array $channels = null;

    private ?string $builtFor = null;

    public function __construct(private readonly ModuleRegistry $modules) {}

    /** @return array<string, MessageChannel> */
    public function all(): array
    {
        $tenantId = app(\App\Support\Tenancy\TenantContext::class)->id();

        if (null !== $this->channels && $this->builtFor === $tenantId) {
            return $this->channels;
        }

        $channels = ['email' => new EmailChannel()];

        foreach ($this->modules->contributions('messaging') as $channel) {
            if ($channel instanceof MessageChannel) {
                $channels[$channel->key()] = $channel;
            }
        }

        $this->builtFor = $tenantId;

        return $this->channels = $channels;
    }

    public function find(string $key): ?MessageChannel
    {
        return $this->all()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return null !== $this->find($key);
    }

    public function forget(): void
    {
        $this->channels = null;
        $this->builtFor = null;
    }

    /** What the panel lists: the key, its name, and what an address looks like. */
    public function describe(): array
    {
        return array_values(array_map(fn (MessageChannel $channel) => [
            'key' => $channel->key(),
            'name' => __($channel->labelKey()),
            'address_kind' => $channel->addressKind(),
        ], $this->all()));
    }
}
