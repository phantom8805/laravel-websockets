<?php

namespace BeyondCode\LaravelWebSockets\HttpApi\Controllers;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use React\Promise\PromiseInterface;
use BeyondCode\LaravelWebSockets\PubSub\ReplicationInterface;
use BeyondCode\LaravelWebSockets\WebSockets\Channels\PresenceChannel;

class FetchChannelsController extends Controller
{
    public function __invoke(Request $request)
    {
        $channels = Collection::make($this->channelManager->getChannels($request->appId))->filter(function ($channel) {
            return $channel instanceof PresenceChannel;
        });

        if ($request->has('filter_by_prefix')) {
            $channels = $channels->filter(function ($channel, $channelName) use ($request) {
                return Str::startsWith($channelName, $request->filter_by_prefix);
            });
        }

        if (config('websockets.replication.enabled') === true) {
            // We want to get the channel user count all in one shot when
            // using a replication backend rather than doing individual queries.
            // To do so, we first collect the list of channel names.
            $channelNames = $channels->map(function (PresenceChannel $channel) use ($request) {
                return $channel->getChannelName();
            })->toArray();

            /** @var PromiseInterface $memberCounts */
            // We ask the replication backend to get us the member count per channel
            $memberCounts = app(ReplicationInterface::class)
                ->channelMemberCounts($request->appId, $channelNames);

            // We return a promise since the backend runs async. We get $counts back
            // as a key-value array of channel names and their member count.
            return $memberCounts->then(function (array $counts) use ($channels) {
                return $this->collectUserCounts($channels, function (PresenceChannel $channel) use ($counts) {
                    return $counts[$channel->getChannelName()];
                });
            });
        }

        return $this->collectUserCounts($channels, function (PresenceChannel $channel) {
            return $channel->getUserCount();
        });
    }

    protected function collectUserCounts(Collection $channels, callable $transformer)
    {
        return [
            'channels' => $channels->map(function (PresenceChannel $channel) use ($transformer) {
                return [
                    'user_count' => $transformer($channel),
                ];
            })->toArray() ?: new \stdClass,
        ];
    }
}
