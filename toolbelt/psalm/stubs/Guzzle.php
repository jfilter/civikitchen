<?php

/**
 * Taint SINKS: Guzzle — the HTTP client CiviCRM ships and Civi::httpClient()
 * hands out. A request-controlled URI here is SSRF.
 *
 * Guzzle lives in vendor/, which cktaint ignores, so nothing about it is
 * visible to Psalm without this file.
 *
 * Only the URI is a sink: $options carries body and headers, and an
 * attacker-controlled body sent to a fixed host is not SSRF — flagging it
 * would make every forwarder red.
 *
 * Signatures per guzzlehttp/guzzle 7.x (ClientInterface plus the __call verb
 * shortcuts, declared explicitly because Psalm cannot annotate __call).
 */

namespace GuzzleHttp;

interface ClientInterface {

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function request(string $method, $uri, array $options = []);

  public function send(\Psr\Http\Message\RequestInterface $request, array $options = []);

  public function sendAsync(\Psr\Http\Message\RequestInterface $request, array $options = []);

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function requestAsync(string $method, $uri, array $options = []);

}

/**
 * The magic verb methods (get/post/put/…) are not on the interface — they go
 * through __call — so they are declared here as @method-style stubs with the
 * same sink on the URI.
 */
class Client implements ClientInterface {

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function request(string $method, $uri, array $options = []) {}

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function requestAsync(string $method, $uri, array $options = []) {}

  public function send(\Psr\Http\Message\RequestInterface $request, array $options = []) {}

  public function sendAsync(\Psr\Http\Message\RequestInterface $request, array $options = []) {}

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function get($uri, array $options = []) {}

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function head($uri, array $options = []) {}

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function post($uri, array $options = []) {}

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function put($uri, array $options = []) {}

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function patch($uri, array $options = []) {}

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function delete($uri, array $options = []) {}

}
