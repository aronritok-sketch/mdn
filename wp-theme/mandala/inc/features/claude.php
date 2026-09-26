<?php
/**
 * Közös Anthropic Messages API hívó a téma Claude-os funkcióihoz (kategorizálás, tanácsadó chat).
 *
 * WordPress HTTP API-val (wp_remote_post) – a témába nem csomagolunk Composer-függőséget, és így a
 * tárhely proxybeállításai is érvényesek. API-kulcs: wp-config.php → MANDALA_ANTHROPIC_API_KEY
 * (vagy a Claude migráció beállításainál).
 *
 *  - Biztonsági elutasítás esetén a szerveroldali visszaesés (`fallbacks: "default"`) egy másik
 *    modellen újrafuttatja a kérést; ha a lánc is elutasít, `stop_reason: refusal` jön – a hívó kezeli.
 *  - 429 / 529 / 5xx: WP_Error `retry_after` adattal (a hívó dönti el, újrapróbál-e).
 */

defined('ABSPATH') || exit;

const MANDALA_CLAUDE_DEFAULT_MODEL = 'claude-opus-5';

/** Modellek, amelyeken a szerveroldali visszaesés (fallbacks: "default") elérhető. */
function mandala_claude_supports_fallbacks(string $model): bool
{
    return in_array($model, ['claude-opus-5', 'claude-opus-5-5', 'claude-fable-5', 'claude-fable-5-1'], true);
}

/** Kényszerített eszközhívást (tool_choice any / tool) elutasító modellek. */
function mandala_claude_rejects_forced_tools(string $model): bool
{
    return in_array($model, ['claude-opus-5-5', 'claude-fable-5-1', 'claude-mythos-5-1'], true);
}

/**
 * Egy Messages API kérés. $body: a kérés törzse (model, max_tokens, system, tools, messages…).
 * Visszaad: a dekódolt válasz (tömb) vagy WP_Error.
 */
function mandala_claude_request(array $body, int $timeout = 60)
{
    $key = function_exists('mandala_ai_key') ? mandala_ai_key() : (defined('MANDALA_ANTHROPIC_API_KEY') ? MANDALA_ANTHROPIC_API_KEY : '');
    if ($key === '') {
        return new WP_Error('mandala_ai_key', 'Nincs beállítva Anthropic API-kulcs.');
    }
    $headers = ['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'];
    if (mandala_claude_supports_fallbacks((string) $body['model']) && !isset($body['fallbacks'])) {
        $body['fallbacks'] = 'default';
        $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
    }
    $response = wp_remote_post(apply_filters('mandala_ai_endpoint', 'https://api.anthropic.com/v1/messages'), [
        'timeout' => $timeout,
        'headers' => $headers,
        'body' => wp_json_encode($body),
    ]);
    if (is_wp_error($response)) {
        return new WP_Error('mandala_ai_http', $response->get_error_message(), ['retry_after' => 30]);
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code === 429 || $code === 529 || $code >= 500) {
        return new WP_Error('mandala_ai_busy', 'Az API túlterhelt vagy korlátozott (' . $code . ').', ['retry_after' => max(5, (int) wp_remote_retrieve_header($response, 'retry-after')), 'status' => $code]);
    }
    if ($code !== 200 || !is_array($data)) {
        return new WP_Error('mandala_ai_api', 'API hiba (' . $code . '): ' . mb_substr((string) ($data['error']['message'] ?? wp_remote_retrieve_body($response)), 0, 300), ['status' => $code]);
    }
    return $data;
}

/**
 * A válasz tartalma a következő kérésbe (eszközhívás-körön belül): változatlanul, kivéve ha a
 * visszaesés a válasz közben történt – akkor az utolsó `fallback` jel előtti gondolkodás- és
 * eszközhívás-blokkok kimaradnak (a visszaesési modell nem folytathatja őket).
 */
function mandala_claude_echo_content(array $content): array
{
    $last = null;
    foreach ($content as $i => $block) {
        if (($block['type'] ?? '') === 'fallback') {
            $last = $i;
        }
    }
    if ($last === null) {
        return $content;
    }
    $out = [];
    foreach ($content as $i => $block) {
        $type = $block['type'] ?? '';
        if ($type === 'fallback' || ($i < $last && in_array($type, ['thinking', 'redacted_thinking', 'tool_use', 'server_tool_use'], true))) {
            continue;
        }
        $out[] = $block;
    }
    return $out;
}

/** A válasz szöveges blokkjai egyben. */
function mandala_claude_text(array $data): string
{
    $text = '';
    foreach ((array) ($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }
    return trim($text);
}
