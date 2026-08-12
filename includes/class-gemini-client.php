<?php
/**
 * Gemini API client.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Gemini_Client {

    public const DEFAULT_MODEL = 'gemini-2.5-flash';
    public const MODEL_OPTION = 'tsol_site_gemini_model';

    public function is_configured() {
        return TSOL_Accountability_Modal_Settings::get_gemini_api_key() !== '';
    }

    public function generate_json($prompt, $response_schema, $args = array()) {
        $api_key = TSOL_Accountability_Modal_Settings::get_gemini_api_key();

        if ($api_key === '') {
            return new WP_Error(
                'tsol_gemini_not_configured',
                __('Gemini is not configured.', 'tomschooloflife-plugin')
            );
        }

        $model = $this->get_model();
        $timeout = (int) apply_filters('tsol_site_gemini_timeout', isset($args['timeout']) ? absint($args['timeout']) : 15);
        $temperature = isset($args['temperature']) ? (float) $args['temperature'] : 0.2;
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode($model)
        );

        $response = wp_remote_post($url, array(
            'timeout' => max(1, $timeout),
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $api_key,
            ),
            'body' => wp_json_encode(array(
                'contents' => array(
                    array(
                        'role' => 'user',
                        'parts' => array(
                            array(
                                'text' => (string) $prompt,
                            ),
                        ),
                    ),
                ),
                'generationConfig' => $this->get_generation_config($response_schema, $temperature),
            )),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($status_code < 200 || $status_code >= 300) {
            return new WP_Error(
                'tsol_gemini_http_error',
                sprintf(
                    /* translators: %d: HTTP status code. */
                    __('Gemini returned HTTP %d.', 'tomschooloflife-plugin'),
                    $status_code
                )
            );
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return new WP_Error(
                'tsol_gemini_invalid_response',
                __('Gemini returned an unreadable response.', 'tomschooloflife-plugin')
            );
        }

        $text = isset($decoded['candidates'][0]['content']['parts'][0]['text'])
            ? (string) $decoded['candidates'][0]['content']['parts'][0]['text']
            : '';

        if ($text === '') {
            return new WP_Error(
                'tsol_gemini_empty_response',
                __('Gemini returned an empty response.', 'tomschooloflife-plugin')
            );
        }

        $json = json_decode($text, true);

        if (!is_array($json)) {
            return new WP_Error(
                'tsol_gemini_invalid_json',
                __('Gemini returned invalid JSON.', 'tomschooloflife-plugin')
            );
        }

        return $json;
    }

    public function get_model() {
        $model = TSOL_Accountability_Modal_Settings::get_gemini_model();

        /**
         * Filters the Gemini model used by site features.
         *
         * @param string $model Gemini model name.
         */
        return (string) apply_filters('tsol_site_gemini_model', $model);
    }

    private function get_generation_config($response_schema, $temperature) {
        return array(
            'temperature' => $temperature,
            'responseFormat' => array(
                'text' => array(
                    'mimeType' => 'application/json',
                    'schema' => $response_schema,
                ),
            ),
        );
    }
}
