<?php

namespace BeyondCode\LaravelWebSockets\Console;

use React\Dns\Resolver\ResolverInterface;
use React\Socket\Connector;
use Clue\React\Buzz\Browser;
use Illuminate\Console\Command;
use React\Dns\Config\Config as DnsConfig;
use React\EventLoop\Factory as LoopFactory;
use React\Dns\Resolver\Factory as DnsFactory;
use React\Dns\Resolver\Resolver as ReactDnsResolver;
use BeyondCode\LaravelWebSockets\Statistics\DnsResolver;
use BeyondCode\LaravelWebSockets\Facades\StatisticsLogger;
use BeyondCode\LaravelWebSockets\Facades\WebSocketsRouter;
use BeyondCode\LaravelWebSockets\PubSub\Redis\RedisClient;
use BeyondCode\LaravelWebSockets\Server\Logger\HttpLogger;
use BeyondCode\LaravelWebSockets\PubSub\ReplicationInterface;
use BeyondCode\LaravelWebSockets\Server\WebSocketServerFactory;
use BeyondCode\LaravelWebSockets\Server\Logger\ConnectionLogger;
use BeyondCode\LaravelWebSockets\Server\Logger\WebsocketsLogger;
use BeyondCode\LaravelWebSockets\WebSockets\Channels\ChannelManager;
use BeyondCode\LaravelWebSockets\Statistics\Logger\HttpStatisticsLogger;
use BeyondCode\LaravelWebSockets\Statistics\Logger\StatisticsLogger as StatisticsLoggerInterface;

class StartWebSocketServer extends Command
{
    protected $signature = 'websockets:serve {--host=0.0.0.0} {--port=6001} {--debug : Forces the loggers to be enabled and thereby overriding the app.debug config setting } ';

    protected $description = 'Start the Laravel WebSocket Server';

    /** @var \React\EventLoop\LoopInterface */
    protected $loop;

    public function __construct()
    {
        parent::__construct();

        $this->loop = LoopFactory::create();
    }

    public function handle()
    {
        $this
            ->configureStatisticsLogger()
            ->configureHttpLogger()
            ->configureMessageLogger()
            ->configureConnectionLogger()
            ->registerEchoRoutes()
            ->configurePubSubReplication()
            ->startWebSocketServer();
    }

    protected function configureStatisticsLogger()
    {
        $connector = new Connector($this->loop, [
            'dns' => $this->getDnsResolver(),
            'tls' => [
                'verify_peer' => config('app.env') === 'production',
                'verify_peer_name' => config('app.env') === 'production',
            ],
        ]);

        $browser = new Browser($this->loop, $connector);

        $this->laravel->singleton(StatisticsLoggerInterface::class, function () use ($browser) {
            return new HttpStatisticsLogger($this->laravel->make(ChannelManager::class), $browser);
        });

        $this->loop->addPeriodicTimer(config('websockets.statistics.interval_in_seconds'), function () {
            StatisticsLogger::save();
        });

        return $this;
    }

    protected function configureHttpLogger()
    {
        $this->laravel->singleton(HttpLogger::class, function () {
            return (new HttpLogger($this->output))
                ->enable($this->option('debug') ?: config('app.debug'))
                ->verbose($this->output->isVerbose());
        });

        return $this;
    }

    protected function configureMessageLogger()
    {
        $this->laravel->singleton(WebsocketsLogger::class, function () {
            return (new WebsocketsLogger($this->output))
                ->enable($this->option('debug') ?: config('app.debug'))
                ->verbose($this->output->isVerbose());
        });

        return $this;
    }

    protected function configureConnectionLogger()
    {
        $this->laravel->bind(ConnectionLogger::class, function () {
            return (new ConnectionLogger($this->output))
                ->enable(config('app.debug'))
                ->verbose($this->output->isVerbose());
        });

        return $this;
    }

    protected function registerEchoRoutes()
    {
        WebSocketsRouter::echo();

        return $this;
    }

    protected function startWebSocketServer()
    {
        $this->info("Starting the WebSocket server on port {$this->option('port')}...");

        $routes = WebSocketsRouter::getRoutes();

        /* 🛰 Start the server 🛰  */
        (new WebSocketServerFactory())
            ->setLoop($this->loop)
            ->useRoutes($routes)
            ->setHost($this->option('host'))
            ->setPort($this->option('port'))
            ->setConsoleOutput($this->output)
            ->createServer()
            ->run();
    }

    protected function configurePubSubReplication()
    {
        if (config('websockets.replication.enabled') !== true) {
            return $this;
        }

        if (config('websockets.replication.driver') === 'redis') {
            $this->laravel->singleton(ReplicationInterface::class, function () {
                return (new RedisClient())->boot($this->loop);
            });
        }

        return $this;
    }

    protected function getDnsResolver(): ResolverInterface
    {
        if (! config('websockets.statistics.perform_dns_lookup')) {
            return new DnsResolver;
        }

        $dnsConfig = DnsConfig::loadSystemConfigBlocking();

        return (new DnsFactory)->createCached(
            $dnsConfig->nameservers
                ? reset($dnsConfig->nameservers)
                : '1.1.1.1',
            $this->loop
        );
    }
}
