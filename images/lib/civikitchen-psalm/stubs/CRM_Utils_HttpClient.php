<?php

/**
 * Taint SINKS: outbound HTTP — request-controlled URLs are SSRF.
 *
 * Psalm knows curl_setopt(CURLOPT_URL) and file_get_contents natively, but
 * nothing in CiviCRM goes through those directly: extensions use Guzzle (via
 * Civi::httpClient()) or CRM_Utils_HttpClient. Guzzle lives in vendor/, which
 * cktaint ignores, so without these stubs the entire outbound surface is
 * invisible.
 *
 * Only the URI argument is a sink. $options/$params carry the body and the
 * headers — an attacker-controlled request *body* to a fixed host is not
 * SSRF, and flagging it would make every proxy-style forwarder red.
 *
 * Guzzle itself is stubbed next door in Guzzle.php.
 *
 * Signatures verified against CiviCRM 6.16.2 (CRM/Utils/HttpClient.php).
 */

class CRM_Utils_HttpClient {

  /**
   * @psalm-taint-sink ssrf $remoteFile
   * @psalm-taint-sink file $localFile
   */
  public function fetch($remoteFile, $localFile) {}

  /**
   * @psalm-taint-sink ssrf $remoteFile
   */
  public function get($remoteFile) {}

  /**
   * @psalm-taint-sink ssrf $remoteFile
   */
  public function post($remoteFile, $params) {}

}
