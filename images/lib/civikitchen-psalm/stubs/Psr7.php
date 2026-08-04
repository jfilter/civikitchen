<?php

/**
 * Taint SOURCES: PSR-7 request objects.
 *
 * PSR-7 routes are the house standard for HTTP endpoints, so everything a
 * handler can read off its `$request` is request input by definition — the
 * same category as $_GET, and Psalm sees none of it, because psr/http-message
 * lives in vendor/ and cktaint ignores vendor/.
 *
 * Stubbed as the interfaces, not the Guzzle implementations: handlers type
 * their parameter as RequestInterface/ServerRequestInterface, and a taint
 * annotation on the interface method applies to every call made through that
 * type.
 *
 * The taint sits on the methods that return data, not on the ones that return
 * another object: Psalm taints values, and a tainted UriInterface would not
 * make ->getQuery() tainted. So getUri()/getBody() are plain, and the getters
 * on UriInterface/StreamInterface/UploadedFileInterface are sources
 * themselves. That also keeps `$request->getUri()->getPath()` reported at the
 * getPath() call, where the data actually enters.
 *
 * Interface shapes per PSR-7 (psr/http-message 2.x).
 */

namespace Psr\Http\Message;

interface MessageInterface {

  /**
   * @psalm-taint-source input
   */
  public function getHeaders();

  /**
   * @psalm-taint-source input
   */
  public function getHeader(string $name);

  /**
   * @psalm-taint-source input
   */
  public function getHeaderLine(string $name);

  public function getBody(): StreamInterface;

}

interface RequestInterface extends MessageInterface {

  /**
   * @psalm-taint-source input
   */
  public function getRequestTarget();

  /**
   * The verb is request-controlled too, but it is compared, never
   * concatenated — tainting it would flag every `if ($request->getMethod()
   * !== 'POST')` guard the standard asks for.
   */
  public function getMethod();

  public function getUri(): UriInterface;

}

interface ServerRequestInterface extends RequestInterface {

  /**
   * @psalm-taint-source input
   */
  public function getServerParams();

  /**
   * @psalm-taint-source input
   */
  public function getCookieParams();

  /**
   * @psalm-taint-source input
   */
  public function getQueryParams();

  /**
   * @psalm-taint-source input
   */
  public function getUploadedFiles();

  /**
   * @psalm-taint-source input
   */
  public function getParsedBody();

  /**
   * @psalm-taint-source input
   */
  public function getAttributes();

  /**
   * Route placeholders land here, and a router that fills them from the path
   * makes them request input like any other.
   *
   * @psalm-taint-source input
   */
  public function getAttribute(string $name, $default = NULL);

}

interface ResponseInterface extends MessageInterface {

  public function getStatusCode();

}

interface UriInterface {

  /**
   * @psalm-taint-source input
   */
  public function getScheme();

  /**
   * @psalm-taint-source input
   */
  public function getAuthority();

  /**
   * @psalm-taint-source input
   */
  public function getUserInfo();

  /**
   * @psalm-taint-source input
   */
  public function getHost();

  /**
   * @psalm-taint-source input
   */
  public function getPath();

  /**
   * @psalm-taint-source input
   */
  public function getQuery();

  /**
   * @psalm-taint-source input
   */
  public function getFragment();

  /**
   * @psalm-taint-source input
   */
  public function __toString();

}

interface StreamInterface {

  /**
   * The request body — the webhook payload case.
   *
   * @psalm-taint-source input
   */
  public function __toString();

  /**
   * @psalm-taint-source input
   */
  public function getContents();

  /**
   * @psalm-taint-source input
   */
  public function read(int $length);

}

interface UploadedFileInterface {

  public function getStream(): StreamInterface;

  /**
   * The one that matters: an upload's client filename is attacker-chosen and
   * reaching a path sink with it is the classic upload traversal.
   *
   * @psalm-taint-source input
   */
  public function getClientFilename();

  /**
   * @psalm-taint-source input
   */
  public function getClientMediaType();

  /**
   * @psalm-taint-sink file $targetPath
   */
  public function moveTo(string $targetPath);

}
