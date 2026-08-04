<?php

namespace App\AI\Support;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Anthropic Messages API — the only place in the
 * codebase that knows its HTTP shape. Every AI capability calls through
 * here (RequestExtractor today) so retries, timeouts, and failure handling
 * stay in one place — see ARCHITECTURE.md's "don't call the Claude API
 * directly from a Filament resource or an Action outside app/AI" rule.
 *
 * Deliberately not forcing tool_choice: Claude Opus 5 has thinking on by
 * default, and disabling thinking to safely force a tool can make the model
 * write the tool call into plain text instead of a tool_use block — the
 * opposite of what a structured-extraction caller needs. Leaving tool_choice
 * on "auto" (the default) with a single tool and a clear system prompt is
 * the reliable pattern; callers check for a missing tool_use block instead
 * of assuming one is always present.
 */
class ClaudeClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
    ) {}

    /**
     * @param  array<int, array{role: string, content: mixed}>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<string, mixed> the parsed Messages API response body
     */
    public function messages(array $messages, array $tools = [], ?string $system = null, int $maxTokens = 4096): array
    {
        if (! $this->apiKey) {
            throw new ClaudeApiException('ANTHROPIC_API_KEY is not configured.');
        }

        $payload = array_filter([
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
            'tools' => $tools ?: null,
            'output_config' => ['effort' => 'medium'],
        ], fn ($value): bool => $value !== null);

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
        ])->timeout(60)->post(self::ENDPOINT, $payload);

        if ($response->failed()) {
            throw new ClaudeApiException("Claude API request failed with status {$response->status()}: {$response->body()}");
        }

        return $response->json();
    }

    /**
     * The input of the named tool_use block, or null if Claude didn't call
     * it — callers must treat that as "extraction failed", not assume it.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    public function toolInput(array $response, string $toolName): ?array
    {
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $toolName) {
                return $block['input'];
            }
        }

        return null;
    }
}
