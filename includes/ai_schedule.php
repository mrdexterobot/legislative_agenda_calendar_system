<?php
/**
 * Bounded Groq integration for Calendar Scheduling.
 *
 * The caller supplies only server-generated, conflict-free candidate slots.
 * The model ranks those candidates and explains the ranking; it cannot invent
 * a date that the application will silently accept.
 */

require_once __DIR__ . '/config.php';

/**
 * @param array<int, array<string, mixed>> $agendaItems
 * @param array<int, array<string, string>> $candidateSlots
 * @param array<string, string> $context
 * @return array{suggestions: array<int, array{date: string, reasoning: string}>, error: bool, message?: string}
 */
function getAIScheduleSuggestions(array $agendaItems, array $candidateSlots, array $context): array
{
    if (GROQ_API_KEY === '' || GROQ_API_KEY === 'PASTE_YOUR_GROQ_KEY_HERE') {
        return [
            'suggestions' => [],
            'error'       => true,
            'message'     => 'AI scheduling is unavailable because GROQ_API_KEY is not configured for this runtime.',
        ];
    }

    $itemSummary = array_map(static function (array $item): array {
        return [
            'id'                 => $item['id'],
            'title'              => $item['title'],
            'type'               => $item['item_type'],
            'category'           => $item['category'],
            'committee'          => $item['committee'],
            'date_filed'         => $item['date_filed'],
            'confirmed_priority' => $item['confirmed_priority'],
        ];
    }, $agendaItems);

    $prompt = "You are assisting a Philippine city council scheduler. Rank up to three "
        . "dates for a legislative session using only the agenda items, scheduling context, "
        . "and conflict-free candidate dates below. Prefer earlier dates for High-priority "
        . "items and measures that have waited longer, but do not claim a statutory deadline "
        . "unless one is explicitly present in the supplied data. Return only valid candidate "
        . "dates. Explain each recommendation in one concise sentence referencing the actual "
        . "items or their priority.\n\n"
        . "Scheduling context:\n"
        . json_encode($context, JSON_UNESCAPED_SLASHES)
        . "\n\nAgenda items:\n"
        . json_encode($itemSummary, JSON_UNESCAPED_SLASHES)
        . "\n\nConflict-free candidate slots:\n"
        . json_encode($candidateSlots, JSON_UNESCAPED_SLASHES)
        . "\n\nRespond only in this exact JSON shape:\n"
        . '{"suggestions":[{"date":"YYYY-MM-DD","reasoning":"one sentence"}]}';

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_POST            => true,
        CURLOPT_HTTPHEADER      => [
            'Authorization: Bearer ' . GROQ_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS      => json_encode([
            'model'           => GROQ_MODEL,
            'messages'        => [['role' => 'user', 'content' => $prompt]],
            'response_format' => ['type' => 'json_object'],
            'temperature'     => 0.2,
        ]),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 20,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || !$response) {
        error_log('Groq schedule suggestion failed: ' . ($curlError ?: 'empty response'));
        return [
            'suggestions' => [],
            'error'       => true,
            'message'     => 'The AI provider could not be reached. Please check the key and outbound HTTPS connection.',
        ];
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        $providerMessage = $data['error']['message'] ?? 'unknown provider error';
        error_log("Groq schedule suggestion returned HTTP {$httpCode}: {$providerMessage}");
        return [
            'suggestions' => [],
            'error'       => true,
            'message'     => 'The AI provider rejected the request. Check the configured model and API key.',
        ];
    }

    $content = $data['choices'][0]['message']['content'] ?? '';
    $decoded = json_decode($content, true);
    if (!is_array($decoded) || !is_array($decoded['suggestions'] ?? null)) {
        error_log('Groq schedule suggestion returned invalid JSON.');
        return [
            'suggestions' => [],
            'error'       => true,
            'message'     => 'The AI returned an unusable response. Please try again.',
        ];
    }

    $allowedDates = array_fill_keys(array_column($candidateSlots, 'date'), true);
    $suggestions = [];
    foreach ($decoded['suggestions'] as $suggestion) {
        if (!is_array($suggestion)) {
            continue;
        }

        $date = trim((string) ($suggestion['date'] ?? ''));
        $reasoning = trim((string) ($suggestion['reasoning'] ?? ''));
        if ($date === '' || !isset($allowedDates[$date]) || $reasoning === '') {
            continue;
        }
        if (isset($suggestions[$date])) {
            continue;
        }

        $suggestions[$date] = [
            'date'      => $date,
            'reasoning' => mb_substr($reasoning, 0, 500),
        ];
        if (count($suggestions) >= 3) {
            break;
        }
    }

    if (!$suggestions) {
        return [
            'suggestions' => [],
            'error'       => true,
            'message'     => 'The AI did not select any valid available date. Please try again.',
        ];
    }

    return [
        'suggestions' => array_values($suggestions),
        'error'       => false,
    ];
}
