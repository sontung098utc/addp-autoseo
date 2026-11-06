<?php

namespace App\Integrations\WordPress;

use App\Contracts\PublisherInterface;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;

class WordPressServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // PublisherManager (không phải singleton) chỉ dùng app(PublisherInterface::class) cho 'wordpress'.
        $this->app->bind(PublisherInterface::class, fn ($app) => $app->make(WordPressPublisher::class));
        $this->app->bind(WordPressClient::class, fn () => new WordPressClient(new Client()));
    }
}
