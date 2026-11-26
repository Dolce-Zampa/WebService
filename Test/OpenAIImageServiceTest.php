<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\OpenAIService;

final class OpenAIImageServiceTest extends TestCase
{
    public function testEditingUsesSupportedModelAndSavesTheReturnedImage(): void
    {
        $requests = [];
        $service = $this->service($requests);

        self::assertSame('https://example.test/image.png', $service->editImage('Improve the image', 'https://example.test/source.jpg', 'image.png'));
        self::assertSame(['image-data', 'image.png'], $service->saved);
        self::assertSame('/v1/images/edits', $requests[0]['request']->getUri()->getPath());
        $body = (string) $requests[0]['request']->getBody();
        self::assertStringContainsString('gpt-image-2.5-sunburst', $body);
        self::assertStringContainsString('name="image"', $body);
        self::assertStringContainsString('Improve the image', $body);
    }

    public function testGenerationUsesBase64AndConfiguredModel(): void
    {
        $requests = [];
        $service = $this->service($requests, 'gpt-image-2.5-flare');

        self::assertSame('https://example.test/image.png', $service->generateProductImage('Test product'));
        self::assertSame(['image-data', null], $service->saved);
        self::assertSame('/v1/images/generations', $requests[0]['request']->getUri()->getPath());
        $body = json_decode((string) $requests[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('gpt-image-2.5-flare', $body['model']);
        self::assertArrayNotHasKey('response_format', $body);
    }

    public function testBlankModelFallsBackToDefault(): void
    {
        $requests = [];
        $service = $this->service($requests, '  ');
        $service->editProductImage('https://example.test/source.jpg', 'Test product');
        self::assertStringContainsString(OpenAIService::DEFAULT_IMAGE_MODEL, (string) $requests[0]['request']->getBody());
    }

    private function service(array &$requests, ?string $model = null): TestableOpenAIImageService
    {
        $service = $model === null ? new TestableOpenAIImageService('test') : new TestableOpenAIImageService('test', 'gpt-4o', $model);
        $mock = new MockHandler([new Response(200, [], json_encode(['data' => [['b64_json' => 'image-data']]]))]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($requests));
        $client = new Client(['base_uri' => 'https://api.openai.com/v1/', 'handler' => $stack]);
        (new ReflectionProperty(OpenAIService::class, 'client'))->setValue($service, $client);
        return $service;
    }
}

final class TestableOpenAIImageService extends OpenAIService
{
    public array $saved = [];

    protected function fetchSourceImage(string $sourceImageUrl): array
    {
        return ['content' => 'source-image', 'content_type' => 'image/jpeg', 'extension' => 'jpg'];
    }

    protected function saveImage(string $b64, ?string $fileName = null): string
    {
        $this->saved = [$b64, $fileName];
        return 'https://example.test/image.png';
    }
}
