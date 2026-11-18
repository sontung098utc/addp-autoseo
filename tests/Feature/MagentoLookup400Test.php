<?php

namespace Tests\Feature;

use App\Contracts\CmsError;
use App\Filament\Support\Schedule\ErrorExplainer;
use App\Integrations\Magento\MagentoBridgeClient;
use App\Models\Website;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MagentoLookup400Test extends TestCase
{
    use RefreshDatabase;

    public function test_400_on_lookup_by_key_gives_actionable_message_without_secret(): void
    {
        $site = Website::create(['name' => 'M', 'cms_type' => 'magento2', 'base_url' => 'https://shop.company.vn', 'store_scope' => 'default',
            'encrypted_credentials' => ['consumer_key' => 'ck', 'consumer_secret' => 'SUPERSECRETVALUE99', 'access_token' => 'at', 'access_token_secret' => 'ats'], 'status' => 'active']);
        $client = new MagentoBridgeClient(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(400, [], '{"message":"bad"}')]))]));

        try {
            $client->getByPublicationKey($site, 'pub-1-a2-v1');
            $this->fail('Phải ném CmsError');
        } catch (CmsError $e) {
            $this->assertSame('ERR06', $e->errorCode());
            $this->assertFalse($e->isAmbiguous());
            $text = ErrorExplainer::explain('['.$e->errorCode().'] '.$e->getMessage());
            $this->assertStringContainsString('tra cứu bài đã có trên CMS', $text);
            $this->assertStringContainsString('Bị chặn tích hợp', $text);
            $this->assertStringNotContainsString('SUPERSECRETVALUE99', $text.$e->getMessage());
        }
    }
}
