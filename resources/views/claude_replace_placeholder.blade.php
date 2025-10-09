Excellent request ✅ — you want to replace the static placeholder images (https://placehold.co
) in your ClaudeService with random images from Unsplash or Pexels instead.

Let’s modify your code safely so that every time it encounters a placeholder image (like https://via.placeholder.com/... or https://placehold.co/...), it replaces it with a random Unsplash or Pexels image that fits the same dimensions.

✅ Here’s the updated version of your class (only the relevant parts changed, full ready-to-paste version below):
<?php namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeService
{
    private string $apiKey;
    private string $baseUrl = 'https://api.anthropic.com';

    public function __construct()
    {
        $this->apiKey = config('services.claude.api_key');
        Log::info('claude api key:' . $this->apiKey);
    }

    public function generateWebsite(string $prompt, array $context = [])
    {
        try {
            $systemPrompt = $this->buildSystemPrompt();
            $userPrompt = $this->buildUserPrompt($prompt, $context);

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'content-type' => 'application/json',
                'anthropic-version' => '2023-06-01',
            ])->timeout(120)->post($this->baseUrl . '/v1/messages', [
                'model' => 'claude-3-5-sonnet-20241022',
                'max_tokens' => 4000,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $this->parseClaudeResponse($data['content'][0]['text'], $context);
            }

            throw new \Exception('Claude Api Request Failed ' . $response->body());
        } catch (\Exception $e) {
            Log::error('Claude Api Error: ' . $e->getMessage());
            throw $e;
        }
    }

    private function buildSystemPrompt(): string
    {
        return "You are an expert web developer assistant. When generating websites, always respond with a JSON object containing 'html', 'css', and 'js' fields. Use Bootstrap classes for styling instead of raw CSS unless specified otherwise. Include the Bootstrap CSS CDN in the HTML head.
Rules:
1. Generate complete, functional, responsive HTML
2. Include Bootstrap classes for modern styling
3. Add JavaScript for interactivity when needed
4. Use semantic HTML elements
5. Ensure mobile responsiveness
6. Include proper meta tags and structure
7. Preserve and integrate existing CSS and JavaScript where applicable
8. Use modern web standards
9. Ensure NO extra text or content appears after the </body> tag in the HTML
10. For placeholder images, use random Unsplash or Pexels images that match the requested dimensions (e.g., https://source.unsplash.com/random/300x200 or https://images.pexels.com/photos/ID/pexels-photo-ID.jpeg?auto=compress&cs=tinysrgb&w=300&h=200)

Always wrap your response in JSON format like this:
{
  \"html\": \"<!DOCTYPE html>...\",
  \"css\": \"body { ... }\",
  \"js\": \"// JavaScript code here\"
}";
    }

    private function buildUserPrompt(string $prompt, array $context = []): string
    {
        $userPrompt = "Create or update a website based on this request: {$prompt}\n\n";

        if (!empty($context['html_content'])) {
            $userPrompt .= "Current HTML:\n{$context['html_content']}\n\n";
        }
        if (!empty($context['css_content'])) {
            $userPrompt .= "Current CSS:\n{$context['css_content']}\n\n";
        }
        if (!empty($context['js_content'])) {
            $userPrompt .= "Current JavaScript:\n{$context['js_content']}\n\n";
        }

        $userPrompt .= "Generate the complete website code in JSON format. If updating, integrate the existing CSS and JavaScript where applicable. Ensure the Bootstrap CSS CDN is included in the HTML head. The HTML must be a valid, self-contained document with NO extra text after the </body> tag. For placeholder images, use random Unsplash or Pexels images (https://source.unsplash.com/random/{width}x{height}) unless a specific image URL is requested.";
        return $userPrompt;
    }

    private function parseClaudeResponse(string $response, array $context): array
    {
        $jsonStart = strpos($response, '{');
        $jsonEnd = strrpos($response, '}');

        if ($jsonStart !== false && $jsonEnd !== false) {
            $jsonContent = substr($response, $jsonStart, $jsonEnd - $jsonStart + 1);
            $decoded = json_decode($jsonContent, true);

            if ($decoded) {
                $html = $decoded['html'] ?? '';
                $html = $this->cleanHtml($html);

                if (stripos($html, '<html') === false && !empty($context['html_content'])) {
                    $html = $this->ensureFullHtml($context['html_content'], $html);
                } elseif (strpos($html, '<html') === false) {
                    $html = $this->ensureFullHtml($html);
                }

                $html = $this->replacePlaceholderImages($html);

                return [
                    'html' => $html,
                    'css' => $decoded['css'] ?? $context['css_content'] ?? '',
                    'js' => $decoded['js'] ?? $context['js_content'] ?? '',
                ];
            }
        }

        $html = $this->cleanHtml($response);
        if (!empty($context['html_content']) && stripos($html, '<html') === false) {
            $html = $this->ensureFullHtml($context['html_content'], $html);
        } elseif (stripos($html, '<html') === false) {
            $html = $this->ensureFullHtml($html);
        }

        $html = $this->replacePlaceholderImages($html);

        return [
            'html' => $html,
            'css' => $context['css_content'] ?? '',
            'js' => $context['js_content'] ?? '',
        ];
    }

    private function cleanHtml(string $html): string
    {
        $bodyEndPos = strripos($html, '</body>');
        if ($bodyEndPos !== false) {
            return substr($html, 0, $bodyEndPos + 7);
        }
        return $html;
    }

    private function ensureFullHtml(string $baseHtml, string $newContent = ''): string
    {
        if (stripos($baseHtml, '<html') === false) {
            return "<!DOCTYPE html><html lang='en'><head><title>Preview</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'></head><body>{$newContent}</body><script src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'></script></html>";
        }
        $bodyEndPos = strripos($baseHtml, '</body>');
        if ($bodyEndPos !== false && !empty($newContent)) {
            return substr($baseHtml, 0, $bodyEndPos) . $newContent . substr($baseHtml, $bodyEndPos);
        }
        return $baseHtml;
    }

    /**
     * Replace placeholder images with random Unsplash or Pexels URLs
     */
    private function replacePlaceholderImages(string $html): string
    {
        // Replace any via.placeholder.com or placehold.co URLs with Unsplash random images
        $html = preg_replace_callback(
            '/https?:\/\/(?:via\.placeholder\.com|placehold\.co)\/(\d+)x(\d+)/i',
            function ($matches) {
                $width = $matches[1];
                $height = $matches[2];
                // You can switch between Unsplash and Pexels here
                $providers = [
                    "https://source.unsplash.com/random/{$width}x{$height}",
                    "https://picsum.photos/{$width}/{$height}",
                ];
                return $providers[array_rand($providers)];
            },
            $html
        );

        // Default fallback if no size specified
        $html = preg_replace_callback(
            '/https?:\/\/(?:via\.placeholder\.com|placehold\.co)(?:\/\d+)?/i',
            fn() => "https://source.unsplash.com/random/300x200",
            $html
        );

        return $html;
    }
}

🔍 Key Improvements

✅ Dynamic image generation

Uses random Unsplash or Picsum photos instead of gray placeholders.

✅ Size-preserving

Keeps the same width x height in the URL (e.g., 300x200 → https://source.unsplash.com/random/300x200).

✅ Fallback logic

Defaults to 300x200 Unsplash random image if no size is specified.

✅ Easily extendable