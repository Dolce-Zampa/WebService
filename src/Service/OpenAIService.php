<?php
declare(strict_types=1);

namespace PS\Webservice\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    private Client $client;
    private string $model;
    private string $imageModel;

    public function __construct(string $apiKey, string $model = 'gpt-4o', string $imageModel = 'gpt-image-1')
    {
        $this->model = $model;
        $this->imageModel = $imageModel;
        $this->client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
            ],
            'timeout' => 90,
        ]);
    }

    /**
     * Generates SEO-optimised product content using ChatGPT.
     *
     * @param string $productName  The original product name (may contain "n.d.")
     * @param string $customPrompt Optional custom prompt. Use {product_name} and {product_short_description} as placeholders.
     * @param string $productShortDescription Current product short description to use as context.
     *                             When empty, the built-in default prompt is used.
     * @return array{name: string, description: string, description_short: string, meta_title: string, meta_description: string}
     * @throws \RuntimeException on API failure
     */
    public function generateSeoContent(string $productName, string $customPrompt = '', string $productShortDescription = ''): array
    {
        // Sanitize the product name to prevent prompt injection
        $sanitized = preg_replace('/[^\p{L}\p{N}\p{P}\s]/u', '', $productName);
        $escaped   = addslashes($sanitized);
        $sanitizedShortDescription = trim(strip_tags((string) $productShortDescription));
        $sanitizedShortDescription = preg_replace('/[^\p{L}\p{N}\p{P}\s]/u', '', $sanitizedShortDescription);
        $escapedShortDescription = addslashes($sanitizedShortDescription);

        if (!empty($customPrompt)) {
            $prompt = str_replace(
                ['{product_name}', '{product_short_description}'],
                [$escaped, $escapedShortDescription],
                $customPrompt
            );
        } else {
            $shortDescriptionContext = $escapedShortDescription !== ''
                ? "Descrizione breve attuale del prodotto (usala come spunto senza copiarla letteralmente): \"{$escapedShortDescription}\"."
                : '';

            $prompt = <<<PROMPT
Agisci come un esperto di SEO e copywriting per un e-commerce di prodotti per animali domestici. Riceverai il nome attuale del prodotto "{$escaped}" e un contesto aggiuntivo: {$shortDescriptionContext}. Il tuo compito è generare i seguenti contenuti ottimizzati per SEO, in italiano, seguendo queste istruzioni dettagliate:

- Genera un nuovo nome prodotto ottimizzato SEO, conciso e descrittivo, senza la stringa “n.d.”.
- Crea una descrizione breve del prodotto (max 200 caratteri), utilizzando solo HTML in linea (senza tag di blocco esterni, senza CSS, senza tag h1).
- Scrivi una descrizione completa e approfondita (almeno 300 parole) in HTML: usa solo paragrafi <p> e liste <ul>.
- Redigi un meta title (max 70 caratteri), pertinente e attrattivo.
- Crea una meta description (max 160 caratteri, senza HTML).

Istruzioni aggiuntive:

- Rispondi esclusivamente con un oggetto JSON valido con le seguenti chiavi: "name", "description_short", "description", "meta_title", "meta_description".
- Non includere nessun testo o commento esterno, solo la risposta JSON.
- Pensa passo-passo alle scelte terminologiche, ai benefici centrali per il cliente, all'ottimizzazione delle keywords inerenti il prodotto e l’utilità per l’utente prima di produrre i risultati finali. Ragiona internamente sulle peculiarità del prodotto in base al contesto fornito, sugli intenti di ricerca principali, e sulla chiarezza e sintesi necessarie per l’e-commerce.
- Mantieni uno stile professionale e coinvolgente, adatto a un negozio online di prodotti per animali domestici.

Formato output richiesto: Solo oggetto JSON senza alcun testo aggiuntivo, nel seguente schema:
{
  "name": "...",
  "description_short": "...",
  "description": "...",
  "meta_title": "...",
  "meta_description": "..."
}

Esempio di output atteso:
{
  "name": "Crocchette Premium per Cani Adulti Pollo e Riso",
  "description_short": "Alimento completo per cani adulti, con pollo e riso. Nutrizione bilanciata, gusto irresistibile.",
  "description": "<p>Le Crocchette Premium per Cani Adulti con Pollo e Riso offrono una nutrizione bilanciata...</p><ul><li>Alta digeribilità</li><li>Ricco di proteine animali</li><li>Ideale per tutte le razze</li></ul><p>Garantisce benessere quotidiano e supporto alle difese immunitarie.</p>",
  "meta_title": "Crocchette Pollo e Riso per Cani Adulti | Premium Nutritive",
  "meta_description": "Crocchette per cani adulti al pollo e riso, nutrizione completa e gusto irresistibile. Scopri la qualità premium!"
}

(Nota: i contenuti reali devono essere adattati alla lunghezza e al livello di dettaglio corretti, secondo la reale descrizione del prodotto.)

IMPORTANTE: Rispondi solo con l’oggetto JSON come indicato. Nessun testo extra, nessun commento.

---

RICORDA:  
Genera, dato un prodotto e-commerce per animali domestici e il suo contesto, un output JSON che includa (1) nome SEO ottimizzato – senza “n.d.” – (2) breve descrizione in HTML inline, (3) descrizione completa in <p>/<ul> HTML, (4) meta title, (5) meta description, seguendo gli step di ragionamento prima di produrre la risposta e rispettando rigorosamente formati e limiti di caratteri descritti."
PROMPT;
        }

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
     * @param string $productName  The product name to base the image on
     * @param string $customPrompt Optional custom prompt. Use {product_name} as placeholder.
     *                             When empty, the built-in default prompt is used.
     * @return string URL of the generated image (expires after ~1 hour)
     * @throws \RuntimeException on API failure
     */
    public function generateProductImage(string $productName, string $customPrompt = ''): string
    {
        $sanitizedName = preg_replace('/[^\p{L}\p{N}\p{P}\s]/u', '', $productName);

        if (!empty($customPrompt)) {
            $imagePrompt = str_replace('{product_name}', $sanitizedName, $customPrompt);
        } else {
            $imagePrompt = "Foto prodotto professionale per e-commerce su sfondo bianco, luce uniforme e alta qualità: " . $sanitizedName . ". Nessun testo nell'immagine.";
        }

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

    /**
     * Modifies an existing product image using AI instructions.
     *
     * @param string $sourceImageUrl Public URL of the source product image
     * @param string $productName    Product name
     * @param string $customPrompt   Optional custom prompt. Use {product_name} as placeholder.
     * @return string URL of the edited image
     * @throws \RuntimeException on API failure
     */
    public function editProductImage(string $sourceImageUrl, string $productName, string $customPrompt = ''): string
    {
        $sanitizedName = preg_replace('/[^\p{L}\p{N}\p{P}\s]/u', '', $productName);

        if (!empty($customPrompt)) {
            $imagePrompt = str_replace('{product_name}', $sanitizedName, $customPrompt);
        } else {
            $imagePrompt = <<<PROMPT
Migliora questa foto prodotto per uso e-commerce, mantenendo il soggetto principale ben visibile. Esegui i seguenti passaggi:

- Analizza l’immagine e identifica chiaramente il soggetto principale del prodotto.
- Isola il soggetto principale rimuovendo o sfocando qualsiasi elemento di sfondo che non appartiene al prodotto.
- Sostituisci lo sfondo con uno sfondo pet, (tipicamente una ambientazione dedicata al mondo pet family), mantenendo una coerenza visiva e un’illuminazione naturale.
- Migliora l’illuminazione in modo che il soggetto sia ben illuminato, senza ombre troppo marcate né zone sovraesposte o sottoesposte.
- Migliora la definizione e la nitidezza dell’immagine, rendendo visibili i dettagli del prodotto senza introdurre artefatti.
- Non aggiungere nessun testo, logo o grafica sovrapposta all’immagine.
- Mantieni la proporzione e la prospettiva naturale del soggetto.

Assicurati che il risultato finale rispetti tutti i requisiti sopra indicati, producendo un’immagine pronta per essere utilizzata su un sito e-commerce professionale.

**Formato output:**  
Restituisci esclusivamente la nuova immagine modificata, senza aggiungere didascalie, testo o descrizioni.

---

**Promemoria istruzioni chiave:**  
- Mantieni solo il soggetto principale; sfondo neutro e pulito.  
- Illuminazione uniforme e dettagli in alta definizione.  
- Nessun testo o elemento grafico sovrapposto.  
- Output: restituisci esclusivamente l’immagine modificata, pronta per e-commerce.
PROMPT;
        }

        try {
            $sourceResponse = $this->client->get($sourceImageUrl);
            $sourceContentType = $sourceResponse->getHeaderLine('Content-Type') ?: 'image/jpeg';
            $extension = str_contains($sourceContentType, 'png') ? 'png' : 'jpg';
            $sourceContent = $sourceResponse->getBody()->getContents();

            if ($sourceContent === '') {
                throw new \RuntimeException('Empty source image content');
            }

            $response = $this->client->post('images/edits', [
                'multipart' => [
                    ['name' => 'model', 'contents' => $this->imageModel],
                    ['name' => 'prompt', 'contents' => $imagePrompt],
                    ['name' => 'size', 'contents' => '1024x1024'],
                    ['name' => 'quality', 'contents' => 'standard'],
                    ['name' => 'response_format', 'contents' => 'url'],
                    [
                        'name' => 'image',
                        'contents' => Utils::streamFor($sourceContent),
                        'filename' => "source.{$extension}",
                        'headers' => ['Content-Type' => $sourceContentType],
                    ],
                ],
            ]);

            $body     = json_decode($response->getBody()->getContents(), true);
            $imageUrl = (string) ($body['data'][0]['url'] ?? '');

            if (empty($imageUrl)) {
                throw new \RuntimeException('Empty image URL in OpenAI edit response');
            }

            Log::info('OpenAI: image edited for product "' . $productName . '"');
            return $imageUrl;
        } catch (\Exception $e) {
            Log::error('OpenAI image edit failed: ' . $e->getMessage());
            throw new \RuntimeException('Failed to edit product image: ' . $e->getMessage(), 0, $e);
        }
    }
}
