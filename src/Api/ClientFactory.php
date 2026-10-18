<?php

namespace ChallongeTournaments\Api;

use ChallongeTournaments\Config\Settings;
use Concrete\Core\Application\Application;

/**
 * The real client when an API key is configured, the fake one otherwise (or when forced, see config/settings.php).
 */
class ClientFactory
{
    /** @var Application */
    protected $app;

    /** @var Settings */
    protected $settings;

    public function __construct(Application $app, Settings $settings)
    {
        $this->app = $app;
        $this->settings = $settings;
    }

    public function create(): ClientInterface
    {
        if ($this->settings->usesFakeClient()) {
            return $this->app->make(FakeClient::class);
        }

        return $this->app->make(HttpClient::class);
    }
}
