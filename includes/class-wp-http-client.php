<?php

namespace Autotix;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Response;

/**
 * Bridges the SDK's PSR-18 HTTP contract to WordPress's `wp_remote_*` API.
 *
 * Why bother instead of bundling Guzzle?
 *  - WordPress already ships an HTTP API (`wp_remote_post()`) that respects
 *    `WP_HTTP_BLOCK_EXTERNAL`, the host's curl options, request hooks, etc.
 *    Bypassing it with our own Guzzle client would surprise admins who
 *    expect their existing HTTP filters to apply.
 *  - Saves ~400KB in the plugin .zip.
 *
 * The adapter only needs to handle POST + headers + body — everything the
 * Autotix SDK uses. If the SDK ever sends GETs, this needs widening.
 */
class WpHttpClient implements ClientInterface {

	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$body    = (string) $request->getBody();
		$headers = [];
		foreach ( $request->getHeaders() as $name => $values ) {
			$headers[ $name ] = implode( ', ', $values );
		}

		$wp_response = wp_remote_post( (string) $request->getUri(), [
			'method'      => $request->getMethod(),
			'headers'     => $headers,
			'body'        => $body,
			// SDK passes its own timeout via the request; default to a sane
			// upper bound so a misbehaving endpoint can't hang the request.
			'timeout'     => 20,
			// Don't follow redirects — webhook endpoints shouldn't redirect.
			'redirection' => 0,
		] );

		if ( is_wp_error( $wp_response ) ) {
			throw new WpHttpClientException(
				$wp_response->get_error_message(),
				0,
				null,
				$request,
			);
		}

		$status      = (int) wp_remote_retrieve_response_code( $wp_response );
		$resp_body   = (string) wp_remote_retrieve_body( $wp_response );
		$resp_headers = wp_remote_retrieve_headers( $wp_response );

		// `wp_remote_retrieve_headers()` returns a Requests_Utility_CaseInsensitiveDictionary
		// in older WP, an array in newer; Nyholm just needs string-or-array values.
		$normalized = [];
		if ( is_iterable( $resp_headers ) ) {
			foreach ( $resp_headers as $name => $value ) {
				$normalized[ $name ] = $value;
			}
		}

		return new Response( $status, $normalized, $resp_body );
	}
}

/**
 * Wraps a wp_error so the SDK gets the PSR-18 ClientExceptionInterface
 * shape it expects. Includes the request for debug breadcrumbs.
 */
class WpHttpClientException extends \RuntimeException implements ClientExceptionInterface {

	private ?RequestInterface $request;

	public function __construct(
		string $message,
		int $code = 0,
		?\Throwable $previous = null,
		?RequestInterface $request = null,
	) {
		parent::__construct( $message, $code, $previous );
		$this->request = $request;
	}

	public function getRequest(): ?RequestInterface {
		return $this->request;
	}
}
