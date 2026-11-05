<?php

namespace Tests\Unit;

use App\Integrations\Magento\OAuth\OAuth1Signer;
use PHPUnit\Framework\TestCase;

class OAuth1SignerTest extends TestCase
{
    public function test_it_generates_valid_oauth1_header(): void
    {
        $credentials = [
            'consumer_key' => 'test_consumer_key',
            'consumer_secret' => 'test_consumer_secret',
            'access_token' => 'test_access_token',
            'access_token_secret' => 'test_token_secret',
        ];

        $header = OAuth1Signer::buildAuthHeader('GET', 'https://shop.company.vn/rest/default/V1/store/websites', $credentials);

        $this->assertStringStartsWith('OAuth ', $header);
        $this->assertStringContainsString('oauth_consumer_key="test_consumer_key"', $header);
        $this->assertStringContainsString('oauth_token="test_access_token"', $header);
        $this->assertStringContainsString('oauth_signature=', $header);
        $this->assertStringContainsString('oauth_nonce=', $header);
        $this->assertStringContainsString('oauth_timestamp=', $header);
    }
}
