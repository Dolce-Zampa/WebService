<?php
declare(strict_types=1);

namespace PS\Webservice\Service;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    private Client $client;
    private string $model;
    private string $imageModel;

    public function __construct(string $apiKey, string $model = 'gpt-4o', string $imageModel = 'dall-e-3')
    {
        $this->model = $model;
        $this->imageModel = $imageModel;
        $this->client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 90,
            'verify'  => false,
        ]);
    }

    /**
     * Generates SEO-optimised product content using ChatGPT.
     *
     * @param string $productName The original product name (may contain "n.d.")
     * @return array{name: string, description: string, description_short: string, meta_title: string, meta_description: string}
     * @throws \RuntimeException on API failure
     */
    public function generateSeoContent(string $productName): array
    {
        $escaped = addslashes($productName);
        $prompt = <<<PROMPT
Sei un esperto di SEO e copywriting per un e-commerce di prodotti per animali domestici.
Il prodotto ha attualmente questo nome: "{$escaped}".
Genera i seguenti contenuti ottimizzati per SEO in italiano:
1. Un nome prodotto SEO ottimizzato (senza la stringa "n.d.", conciso e descrittivo)
2. Una descrizione breve (max 200 caratteri, HTML puro senza tag di blocco esterni)
3. Una descrizione completa (almeno 300 parole, HTML con paragrafi <p> e liste <ul>)
4. Un meta title (max 70 caratteri)
5. Una meta description (max 160 caratteri, testo senza HTML)

Rispondi SOLTANTO con un oggetto JSON valido con le chiavi:
"name", "description_short", "description", "meta_title", "meta_description"
PROMPT;

        try {
            $response = $this->client->post('chat/completions', [
                'json' => [
                    'model'           => $this->model,
                    'messages'        => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature'     => 0.7,
                ],
            ]);

            $body    = json_decode($response->getBody()->getContents(), true);
            $content = $body['choices'][0]['message']['content'] ?? '{}';
            $data    = json_decode($content, true);

            if (!is_array($data)) {
                throw new \RuntimeException('Invalid JSON returned by OpenAI chat/completions');
            }

            Log::info('OpenAI: SEO content generated for product "' . $productName . '"');

            return [
                'name'              => (string) ($data['name'] ?? $productName),
                'description'       => (string) ($data['description'] ?? ''),
                'description_short' => (string) ($data['description_short'] ?? ''),
                'meta_title'        => (string) ($data['meta_title'] ?? ''),
                'meta_description'  => (string) ($data['meta_description'] ?? ''),
            ];
        } catch (\Exception $e) {
            Log::error('OpenAI SEO content generation failed: ' . $e->getMessage());
            throw new \RuntimeException('Failed to generate SEO content: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generates a product image URL using DALL-E.
     *
     * @param string $productName The product name to base the image on
     * @return string URL of the generated image (expires after ~1 hour)
     * @throws \RuntimeException on API failure
     */
    public function generateProductImage(string $productName): string
    {
        $imagePrompt = "Foto prodotto professionale per e-commerce su sfondo bianco, luce uniforme e alta qualità: {$productName}. Nessun testo nell'immagine.";

        try {
            $response = $this->client->post('images/generations', [
                'json' => [
                    'model'           => $this->imageModel,
                    'prompt'          => $imagePrompt,
                    'n'               => 1,
                    'size'            => '1024x1024',
                    'quality'         => 'standard',
                    'response_format' => 'url',
                ],
            ]);

            $body     = json_decode($response->getBody()->getContents(), true);
            $imageUrl = (string) ($body['data'][0]['url'] ?? '');

            if (empty($imageUrl)) {
                throw new \RuntimeException('Empty image URL in OpenAI response');
            }

            Log::info('OpenAI: image generated for product "' . $productName . '"');

            return $imageUrl;
        } catch (\Exception $e) {
            Log::error('OpenAI image generation failed: ' . $e->getMessage());
            throw new \RuntimeException('Failed to generate product image: ' . $e->getMessage(), 0, $e);
        }
    }
}
