<?php

namespace App\Services\Notifications;

use App\Contracts\OutboundChannelInterface;

class OutboundChannelManager
{
    /** @var array<string, OutboundChannelInterface> */
    private array $channels = [];

    /**
     * @param  iterable<OutboundChannelInterface>  $channels
     */
    public function __construct(iterable $channels = [])
    {
        foreach ($channels as $channel) {
            $this->register($channel);
        }
    }

    public function register(OutboundChannelInterface $channel): void
    {
        $this->channels[strtolower($channel->channel())] = $channel;
    }

    public function resolve(?string $channel): ?OutboundChannelInterface
    {
        $key = strtolower(trim($channel ?? ''));
        if ($key === '') {
            $key = 'whatsapp';
        }

        return $this->channels[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public function registeredChannels(): array
    {
        return array_keys($this->channels);
    }
}
