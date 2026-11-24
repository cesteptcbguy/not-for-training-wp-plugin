<?php
/**
 * Crawler lists. Returned as data, not hooked: read them through
 * notfortraining_get_bots(), which applies the notfortraining_bots filter.
 *
 * "training": crawlers and opt-out tokens that gather material for model
 * training or datasets. Blocked site-wide in robots.txt.
 *
 * "search": AI search indexers and fetchers acting on a user's request.
 * They index and fetch pages for answers and links, not for training, so
 * in "training" mode they get the same access as search engines.
 *
 * Googlebot, Bingbot and Applebot are in neither list and keep the
 * "User-agent: *" rules. Google-Extended and Applebot-Extended are opt-out
 * tokens for AI training only, not separate crawlers, so listing them
 * doesn't affect search.
 *
 * Bump list_version and list_date whenever either list changes.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

return array(
	'list_version' => '1',
	'list_date'    => '2026-11-20',
	'training'     => array(
		'GPTBot',             // OpenAI: model training.
		'ClaudeBot',          // Anthropic: model training.
		'anthropic-ai',       // Anthropic: older training token.
		'CCBot',              // Common Crawl: open dataset used for training.
		'Google-Extended',    // Google: Gemini training opt-out token.
		'Applebot-Extended',  // Apple: AI training opt-out token.
		'Bytespider',         // ByteDance: training.
		'meta-externalagent', // Meta: training.
		'Amazonbot',          // Amazon: training and Alexa answers.
		'cohere-ai',          // Cohere: training.
		'Diffbot',            // Diffbot: dataset and knowledge graph.
		'omgili',             // Webz.io: dataset resale.
		'omgilibot',          // Webz.io: dataset resale.
	),
	'search'       => array(
		'OAI-SearchBot',    // OpenAI: ChatGPT search index.
		'ChatGPT-User',     // OpenAI: fetch when a ChatGPT user asks.
		'Claude-SearchBot', // Anthropic: Claude search index.
		'Claude-User',      // Anthropic: fetch when a Claude user asks.
		'PerplexityBot',    // Perplexity: search index.
		'Perplexity-User',  // Perplexity: fetch when a Perplexity user asks.
	),
);
