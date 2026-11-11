<?php

namespace Tests\Feature;

use App\Contracts\CmsError;
use App\Contracts\CmsErrorCode;
use App\Contracts\CmsErrorInterface;
use App\Contracts\CmsErrorMapper;
use App\Contracts\PublisherInterface;
use App\Integrations\Magento\MagentoBridgeClient;
use App\Integrations\WordPress\Exceptions\WordPressAmbiguousException;
use App\Integrations\WordPress\Exceptions\WordPressErrorCode;
use App\Integrations\WordPress\Exceptions\WordPressException;
use App\Integrations\WordPress\Taxonomy\WordPressTaxonomyService;
use App\Integrations\WordPress\WordPressClient;
use App\Integrations\WordPress\WordPressPublisher;
use App\Jobs\PublishArticleJob;
use App\Jobs\ReconcilePublicationJob;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\Website;
use App\Services\Publishing\PublisherManager;
use App\Services\Security\UrlSafetyService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherContractCmsErrorTest extends TestCase
{
    use RefreshDatabase;

    private Website $magentoSite;
    private Website $wpSite;
    private Article $article;
    private ArticleVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->magentoSite = Website::create([
            'name' => 'Magento Test',
            'cms_type' => 'magento2',
            'base_url' => 'https://shop.company.vn',
            'store_scope' => 'default',
            'encrypted_credentials' => [
                'consumer_key' => 'ck_test',
                'consumer_secret' => 'cs_test',
                'access_token' => 'at_test',
                'access_token_secret' => 'ats_test',
            ],
            'status' => 'active',
        ]);

        $this->wpSite = Website::create([
            'name' => 'WP Test',
            'cms_type' => 'wordpress',
            'base_url' => 'https://blog.company.vn',
            'encrypted_credentials' => [
                'username' => 'wpuser',
                'application_password' => 'xxxx-yyyy-zzzz',
            ],
            'status' => 'active',
        ]);

        $this->article = Article::create([
            'website_id' => $this->magentoSite->id,
            'primary_keyword' => 'tu khoa test pr d',
            'search_intent' => 'informational',
            'workflow_status' => 'approved',
        ]);

        $this->version = ArticleVersion::create([
            'article_id' => $this->article->id,
            'version' => 1,
            'title' => 'Bài viết kiểm thử hợp đồng PR-D',
            'slug' => 'bai-viet-kiem-thu-pr-d',
            'html_content' => '<p>Nội dung chuẩn SEO</p>',
            'meta_description' => 'Mô tả ngắn chuẩn SEO cho bài viết kiểm thử hợp đồng.',
            'review_status' => 'approved',
        ]);
    }

    public function test_cms_error_masks_sensitive_tokens_and_urls(): void
    {
        $err = CmsError::definitive(
            CmsErrorCode::ERR02,
            'Bearer secret_token_value_123456 at https://site.vn/api?token=secret999'
        );

        $this->assertInstanceOf(CmsErrorInterface::class, $err);
        $this->assertSame(CmsErrorCode::ERR02, $err->errorCode());
        $this->assertSame('auth', $err->category());
        $this->assertFalse($err->isRetryable());
        $this->assertFalse($err->isAmbiguous());
        $this->assertStringNotContainsString('secret_token_value_123456', $err->getMessage());
        $this->assertStringNotContainsString('secret999', $err->getMessage());
    }

    public function test_cms_error_mapper_classifies_http_statuses_and_connect_errors(): void
    {
        $e401 = CmsErrorMapper::fromHttpStatus(401, true);
        $this->assertSame(CmsErrorCode::ERR02, $e401->errorCode());
        $this->assertFalse($e401->isAmbiguous());
        $this->assertFalse($e401->isRetryable());

        $e404 = CmsErrorMapper::fromHttpStatus(404, false);
        $this->assertSame(CmsErrorCode::ERR03, $e404->errorCode());
        $this->assertSame(404, $e404->httpStatus());

        $e429 = CmsErrorMapper::fromHttpStatus(429, true);
        $this->assertSame(CmsErrorCode::ERR04, $e429->errorCode());
        $this->assertTrue($e429->isRetryable());
        $this->assertFalse($e429->isAmbiguous());

        $e500Read = CmsErrorMapper::fromHttpStatus(500, false);
        $this->assertSame(CmsErrorCode::ERR05, $e500Read->errorCode());
        $this->assertTrue($e500Read->isRetryable());
        $this->assertFalse($e500Read->isAmbiguous());

        $e502Write = CmsErrorMapper::fromHttpStatus(502, true);
        $this->assertSame(CmsErrorCode::ERR05, $e502Write->errorCode());
        $this->assertFalse($e502Write->isRetryable());
        $this->assertTrue($e502Write->isAmbiguous());

        $preSend = new ConnectException('cURL error 7: Failed to connect', new Request('POST', 'https://shop.company.vn'), null, ['errno' => 7]);
        $ePre = CmsErrorMapper::fromThrowable($preSend, true);
        $this->assertSame(CmsErrorCode::ERR07, $ePre->errorCode());
        $this->assertTrue($ePre->isRetryable());
        $this->assertFalse($ePre->isAmbiguous());

        $timeout = new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://shop.company.vn'), null, ['errno' => 28, 'connect_time' => 0.4]);
        $eTimeout = CmsErrorMapper::fromThrowable($timeout, true);
        $this->assertSame(CmsErrorCode::ERR01, $eTimeout->errorCode());
        $this->assertTrue($eTimeout->isAmbiguous());
        $this->assertFalse($eTimeout->isRetryable());
    }

    public function test_e13_magento_timeout_during_publish_transitions_job_to_needs_reconcile(): void
    {
        $publication = Publication::create([
            'website_id' => $this->magentoSite->id,
            'article_version_id' => $this->version->id,
            'publication_key' => 'pub-1-a1-v1',
            'scheduled_at_utc' => now()->subMinute(),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => Publication::STATUS_SCHEDULED,
        ]);

        // getByPublicationKey returns 404, createDraft returns 200, then publish POST times out!
        $mock = new MockHandler([
            new Response(404, [], json_encode(['message' => 'Not found'])),
            new Response(200, [], json_encode(['post_id' => 301, 'publication_key' => 'pub-1-a1-v1'])),
            new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://shop.company.vn'), null, ['errno' => 28, 'connect_time' => 0.5]),
        ]);
        $client = new MagentoBridgeClient(new Client(['handler' => HandlerStack::create($mock)]));

        $manager = new PublisherManager();
        $manager->registerPublisher('magento2', $client);

        $job = new PublishArticleJob($publication);
        $job->handle($manager);

        $publication->refresh();
        $this->assertSame(Publication::STATUS_NEEDS_RECONCILE, $publication->status);
        $this->assertStringContainsString('[ERR01]', (string) $publication->last_error);
    }

    public function test_e13_magento_504_during_publish_transitions_job_to_needs_reconcile(): void
    {
        $publication = Publication::create([
            'website_id' => $this->magentoSite->id,
            'article_version_id' => $this->version->id,
            'publication_key' => 'pub-1-a1-v1-504',
            'scheduled_at_utc' => now()->subMinute(),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => Publication::STATUS_SCHEDULED,
            'cms_post_id' => '305',
        ]);

        $mock = new MockHandler([
            new Response(504, [], 'Gateway Timeout'),
        ]);
        $client = new MagentoBridgeClient(new Client(['handler' => HandlerStack::create($mock)]));

        $manager = new PublisherManager();
        $manager->registerPublisher('magento2', $client);

        $job = new PublishArticleJob($publication);
        $job->handle($manager);

        $publication->refresh();
        $this->assertSame(Publication::STATUS_NEEDS_RECONCILE, $publication->status);
        $this->assertStringContainsString('[ERR05]', (string) $publication->last_error);
    }

    public function test_magento_401_transitions_job_to_failed_with_err02(): void
    {
        $publication = Publication::create([
            'website_id' => $this->magentoSite->id,
            'article_version_id' => $this->version->id,
            'publication_key' => 'pub-1-a1-v1-401',
            'scheduled_at_utc' => now()->subMinute(),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => Publication::STATUS_SCHEDULED,
            'cms_post_id' => '306',
        ]);

        $mock = new MockHandler([
            new Response(401, [], json_encode(['message' => 'Consumer is not authorized'])),
        ]);
        $client = new MagentoBridgeClient(new Client(['handler' => HandlerStack::create($mock)]));

        $manager = new PublisherManager();
        $manager->registerPublisher('magento2', $client);

        $job = new PublishArticleJob($publication);
        $job->handle($manager);

        $publication->refresh();
        $this->assertSame(Publication::STATUS_FAILED, $publication->status);
        $this->assertStringContainsString('[ERR02]', (string) $publication->last_error);
    }

    public function test_magento_get_by_publication_key_throws_cms_error_on_500_instead_of_returning_null(): void
    {
        $mock = new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]);
        $client = new MagentoBridgeClient(new Client(['handler' => HandlerStack::create($mock)]));

        $this->expectException(CmsError::class);
        $client->getByPublicationKey($this->magentoSite, 'pub-1-a1-v1');
    }

    public function test_magento_blocks_ssrf_local_urls_before_sending_request(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
        $stack->push(Middleware::history($history));
        $client = new MagentoBridgeClient(new Client(['handler' => $stack]));

        $badSite = Website::create([
            'name' => 'Bad SSRF Site',
            'cms_type' => 'magento2',
            'base_url' => 'https://169.254.169.254',
            'encrypted_credentials' => ['consumer_key' => 'a', 'consumer_secret' => 'b', 'access_token' => 'c', 'access_token_secret' => 'd'],
            'status' => 'active',
        ]);

        try {
            $client->getByPublicationKey($badSite, 'pub-ssrf-test');
            $this->fail('Phải chặn SSRF với mã ERR03');
        } catch (CmsError $e) {
            $this->assertSame(CmsErrorCode::ERR03, $e->errorCode());
            $this->assertSame([], $history);
        }
    }

    public function test_e9_create_draft_uses_system_publication_key_for_magento_and_wordpress(): void
    {
        // 1. Magento defaults to ReviewWorkflow key format (pub-w-a-v) when omitted, and respects passed key
        $mHistory = [];
        $mStack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['post_id' => 11])),
            new Response(200, [], json_encode(['post_id' => 12])),
        ]));
        $mStack->push(Middleware::history($mHistory));
        $mClient = new MagentoBridgeClient(new Client(['handler' => $mStack]));

        $rDefault = $mClient->createDraft($this->magentoSite, $this->version);
        $expectedDefaultKey = "pub-{$this->magentoSite->id}-a{$this->article->id}-v{$this->version->version}";
        $this->assertSame($expectedDefaultKey, $rDefault['publication_key']);

        $rCustom = $mClient->createDraft($this->magentoSite, $this->version, 'pub-custom-key-77');
        $this->assertSame('pub-custom-key-77', $rCustom['publication_key']);

        // 2. WordPress accepts optional publicationKey and embeds marker when given
        $wHistory = [];
        $wStack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([])), // lookup by key
            new Response(201, [], json_encode(['id' => 88, 'link' => 'https://blog.company.vn/?p=88'])),
        ]));
        $wStack->push(Middleware::history($wHistory));
        $fakeSafety = new class extends UrlSafetyService {
            public function validateUrl(string $url, array $domainAllowlist = []): bool
            {
                return true;
            }
        };
        $wpClient = new WordPressClient(new Client(['handler' => $wStack]), $fakeSafety);
        $wpPub = new WordPressPublisher($wpClient, new WordPressTaxonomyService($wpClient));

        $wpRes = $wpPub->createDraft($this->wpSite, $this->version, 'pub-wp-draft-99');
        $this->assertSame('88', $wpRes['remote_id']);
        $this->assertSame('pub-wp-draft-99', $wpRes['publication_key']);
        $postBody = json_decode((string) $wHistory[1]['request']->getBody(), true);
        $this->assertStringContainsString('<!-- autoseo:publication_key=pub-wp-draft-99 -->', $postBody['content']);
    }

    public function test_wordpress_exceptions_implement_cms_error_interface(): void
    {
        $definitive = new WordPressException(WordPressErrorCode::ERR02, 'bad pass');
        $this->assertInstanceOf(CmsErrorInterface::class, $definitive);
        $this->assertSame('ERR02', $definitive->errorCode());
        $this->assertFalse($definitive->isAmbiguous());
        $this->assertFalse($definitive->isRetryable());

        $ambiguous = new WordPressAmbiguousException(WordPressErrorCode::ERR01, 'timeout after POST');
        $this->assertInstanceOf(CmsErrorInterface::class, $ambiguous);
        $this->assertSame('ERR01', $ambiguous->errorCode());
        $this->assertTrue($ambiguous->isAmbiguous());
        $this->assertFalse($ambiguous->isRetryable());
    }

    public function test_reconcile_job_preserves_needs_reconcile_and_masks_error_on_cms_error(): void
    {
        $publication = Publication::create([
            'website_id' => $this->magentoSite->id,
            'article_version_id' => $this->version->id,
            'publication_key' => 'pub-reconcile-err-1',
            'scheduled_at_utc' => now()->subMinutes(5),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => Publication::STATUS_NEEDS_RECONCILE,
            'last_error' => 'Old error',
        ]);

        $mock = new MockHandler([
            new Response(503, [], 'Service Unavailable'),
        ]);
        $client = new MagentoBridgeClient(new Client(['handler' => HandlerStack::create($mock)]));

        $manager = new PublisherManager();
        $manager->registerPublisher('magento2', $client);

        $job = new ReconcilePublicationJob($publication);

        try {
            $job->handle($manager);
            $this->fail('Phải rethrow CmsError để queue retry');
        } catch (CmsError $e) {
            $this->assertSame(CmsErrorCode::ERR05, $e->errorCode());
        }

        $publication->refresh();
        $this->assertSame(Publication::STATUS_NEEDS_RECONCILE, $publication->status);
        $this->assertStringContainsString('[ERR05]', (string) $publication->last_error);
    }
}
