<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use DateTimeImmutable;

/**
 * The built-in OAuth 2.1 authorisation server, free of HTTP and Joomla
 * plumbing: callers pass request parameters in and get arrays, URLs or
 * OAuthError out.
 *
 * Supported: the authorisation code grant with PKCE (S256 only), rotating
 * refresh tokens, Dynamic Client Registration (RFC 7591), token revocation
 * (RFC 7009), resource indicators (RFC 8707) and the `iss` authorisation
 * response parameter (RFC 9207).
 *
 * Every grant is bound to one Governed Mode credential chosen by its owner at
 * consent. Tokens carry no authority of their own: each use re-resolves that
 * credential (OAuthTokenAuthenticator), so revoking or expiring the credential
 * ends every OAuth grant built on it.
 */
final class OAuthServer
{
    public const SCOPE = 'mcp';

    private const CODE_TTL = 300;

    private const MAX_REDIRECT_URIS = 10;

    private const MAX_CLIENT_NAME = 150;

    private const MAX_CLIENT_ID_URL = 191;

    private const CLIENT_METADATA_TTL = 3600;

    /** @var callable(): DateTimeImmutable */
    private $clock;

    private OAuthRedirectUriPolicy $redirectPolicy;

    /**
     * @param callable(): DateTimeImmutable $clock Returns the current time in UTC.
     */
    public function __construct(
        private readonly OAuthStoreInterface $store,
        private readonly OAuthConfig $config,
        callable $clock,
        private readonly ?OAuthClientMetadataFetcher $metadataFetcher = null,
    ) {
        $this->clock = $clock;
        $this->redirectPolicy = new OAuthRedirectUriPolicy($config->allowedRedirectHosts);
    }

    public function config(): OAuthConfig
    {
        return $this->config;
    }

    /**
     * Dynamic Client Registration (RFC 7591 §3).
     *
     * @param array<string,mixed> $metadata The decoded JSON request body.
     *
     * @return array<string,mixed> The client information response (§3.2.1).
     */
    public function register(array $metadata): array
    {
        $redirectUris = $metadata['redirect_uris'] ?? null;
        $refusal = $this->redirectUrisRefusal($redirectUris);
        if ($refusal !== null) {
            throw new OAuthError('invalid_redirect_uri', $refusal);
        }

        // RFC 7591 §2: client_secret_basic is the default when none is named.
        $authMethod = $metadata['token_endpoint_auth_method'] ?? OAuthClient::AUTH_SECRET_BASIC;
        if (!in_array($authMethod, [OAuthClient::AUTH_NONE, OAuthClient::AUTH_SECRET_POST, OAuthClient::AUTH_SECRET_BASIC], true)) {
            throw new OAuthError('invalid_client_metadata', 'Unsupported token_endpoint_auth_method');
        }

        $refusal = self::flowRefusal($metadata);
        if ($refusal !== null) {
            throw new OAuthError('invalid_client_metadata', $refusal);
        }
        $grantTypes = (array) ($metadata['grant_types'] ?? ['authorization_code']);

        $name = self::clientName($metadata);

        $now = ($this->clock)();
        $clientId = 'mcpc_' . self::random(16);
        $secret = $authMethod === OAuthClient::AUTH_NONE ? null : self::random(32);

        $this->store->purge($now);
        $this->store->createClient(new OAuthClient(
            $clientId,
            $name,
            array_values($redirectUris),
            $authMethod,
            $secret === null ? null : self::hash($secret),
            $now,
        ));

        $response = [
            'client_id' => $clientId,
            'client_id_issued_at' => $now->getTimestamp(),
            'client_name' => $name,
            'redirect_uris' => array_values($redirectUris),
            'grant_types' => array_values(array_unique([...$grantTypes, 'refresh_token'])),
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $authMethod,
            'scope' => self::SCOPE,
        ];

        if ($secret !== null) {
            $response['client_secret'] = $secret;
            $response['client_secret_expires_at'] = 0;
        }

        return $response;
    }

    /**
     * Validates the parameters of an authorisation request (OAuth 2.1 §4.1.1).
     *
     * Errors about the client or redirect URI are thrown without a redirect
     * URI and must be shown to the user; later ones carry it (see OAuthError).
     *
     * @param array<string,mixed> $params The query parameters.
     */
    public function validateAuthorizationRequest(array $params): OAuthAuthorizationRequest
    {
        $clientId = self::stringParam($params, 'client_id');
        $client = $clientId === null ? null : $this->findClient($clientId);
        if ($client === null) {
            throw new OAuthError('invalid_request', 'Unknown or missing client_id');
        }

        $requestedRedirect = self::stringParam($params, 'redirect_uri');
        $redirectUri = $this->resolveRedirectUri($client, $requestedRedirect);

        // From here on errors go back to the client's validated redirect URI.
        $state = self::stringParam($params, 'state');
        $fail = static fn (string $error, string $description): OAuthError => new OAuthError($error, $description, 400, $redirectUri, $state);

        if (self::stringParam($params, 'response_type') !== 'code') {
            throw $fail('unsupported_response_type', 'Only response_type=code is supported');
        }

        $challenge = self::stringParam($params, 'code_challenge');
        if ($challenge === null || preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $challenge) !== 1) {
            throw $fail('invalid_request', 'A PKCE code_challenge is required');
        }
        if (self::stringParam($params, 'code_challenge_method') !== 'S256') {
            throw $fail('invalid_request', 'code_challenge_method must be S256');
        }

        $resource = self::stringParam($params, 'resource') ?? $this->config->defaultResource();
        if (!$this->config->ownsResource($resource)) {
            throw $fail('invalid_target', 'resource is not an MCP endpoint of this site');
        }

        return new OAuthAuthorizationRequest(
            $client->clientId,
            $client->clientName,
            $redirectUri,
            $challenge,
            $state,
            // Only one scope exists. Others a client asks for (openid,
            // offline_access, ...) are not refused but not granted either;
            // the token response reports the scope actually granted.
            self::SCOPE,
            $resource,
        );
    }

    /**
     * The credentials the user may bind this client to.
     *
     * @return list<array{id:int,name:string,expires:?DateTimeImmutable}>
     */
    public function usableCredentials(int $userId): array
    {
        return $this->store->usableCredentials($userId, ($this->clock)());
    }

    /**
     * Records the user's consent and returns the URL that delivers the
     * authorisation code to the client.
     */
    public function approve(OAuthAuthorizationRequest $request, int $userId, int $credentialId): string
    {
        $usable = array_column($this->usableCredentials($userId), 'id');
        if (!in_array($credentialId, $usable, true)) {
            throw new OAuthError('invalid_request', 'Choose one of your active MCP credentials');
        }

        $now = ($this->clock)();
        $this->store->purge($now);

        $grantId = $this->store->createGrant($request->clientId, $userId, $credentialId, $request->scope, $request->resource, $now);
        $code = self::random(32);
        $this->store->createCode($grantId, self::hash($code), $request->redirectUri, $request->codeChallenge, $now->modify('+' . self::CODE_TTL . ' seconds'));

        return $this->redirectWith($request->redirectUri, ['code' => $code, 'state' => $request->state]);
    }

    public function denyRedirect(OAuthAuthorizationRequest $request): string
    {
        return $this->errorRedirect(
            new OAuthError('access_denied', 'The user declined the request', 400, $request->redirectUri, $request->state)
        );
    }

    /**
     * The URL that reports a redirectable error to the client.
     */
    public function errorRedirect(OAuthError $error): string
    {
        if (!$error->isRedirectable()) {
            throw new \LogicException('This error must be shown to the user, not redirected');
        }

        return $this->redirectWith((string) $error->redirectUri, $error->toArray() + ['state' => $error->state]);
    }

    /**
     * The token endpoint (OAuth 2.1 §3.2).
     *
     * @param array<string,mixed> $post                The form-encoded body.
     * @param string              $authorizationHeader The raw Authorization header, possibly empty.
     *
     * @return array<string,mixed> The token response.
     */
    public function token(array $post, string $authorizationHeader): array
    {
        $client = $this->authenticateClient($post, $authorizationHeader);
        $now = ($this->clock)();
        $this->store->touchClient($client->clientId, $now);

        return match (self::stringParam($post, 'grant_type')) {
            'authorization_code' => $this->exchangeCode($client, $post, $now),
            'refresh_token' => $this->refresh($client, $post, $now),
            default => throw new OAuthError('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token'),
        };
    }

    /**
     * Token revocation (RFC 7009). Revoking a refresh token ends the whole
     * grant. Unknown tokens and tokens of other clients are ignored, as §2.2
     * requires: the response never says whether a token existed.
     *
     * @param array<string,mixed> $post
     */
    public function revoke(array $post, string $authorizationHeader): void
    {
        $client = $this->authenticateClient($post, $authorizationHeader);
        $token = self::stringParam($post, 'token');
        $record = $token === null ? null : $this->store->findToken(self::hash($token));

        if ($record === null || $record->clientId !== $client->clientId) {
            return;
        }

        $now = ($this->clock)();
        if ($record->type === OAuthTokenRecord::REFRESH) {
            $this->store->revokeGrant($record->grantId, $now);
        } else {
            $this->store->revokeToken($record->id, $now);
        }
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * @param array<string,mixed> $post
     *
     * @return array<string,mixed>
     */
    private function exchangeCode(OAuthClient $client, array $post, DateTimeImmutable $now): array
    {
        $code = self::stringParam($post, 'code');
        $verifier = self::stringParam($post, 'code_verifier');
        if ($code === null || $verifier === null) {
            throw new OAuthError('invalid_request', 'code and code_verifier are required');
        }

        $record = $this->store->consumeCode(self::hash($code), $now);
        if ($record === null) {
            throw new OAuthError('invalid_grant', 'Invalid authorization code');
        }

        if ($record->alreadyUsed) {
            // A code redeemed twice has leaked. Revoke whatever the first
            // redemption produced (OAuth 2.1 §4.1.3).
            $this->store->revokeGrant($record->grantId, $now);
            throw new OAuthError('invalid_grant', 'Authorization code has already been used');
        }

        $redirectUri = self::stringParam($post, 'redirect_uri');
        $fails = match (true) {
            $record->expires <= $now => 'Authorization code has expired',
            !hash_equals($record->clientId, $client->clientId) => 'Authorization code was issued to another client',
            $redirectUri !== null && !hash_equals($record->redirectUri, $redirectUri) => 'redirect_uri does not match the authorization request',
            preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) !== 1,
            !hash_equals($record->codeChallenge, self::base64Url(hash('sha256', $verifier, true))) => 'PKCE verification failed',
            default => null,
        };
        if ($fails !== null) {
            throw new OAuthError('invalid_grant', $fails);
        }

        $this->checkResourceParam($post, $record->resource);

        return $this->issueTokens($record->grantId, $record->scope, $now);
    }

    /**
     * @param array<string,mixed> $post
     *
     * @return array<string,mixed>
     */
    private function refresh(OAuthClient $client, array $post, DateTimeImmutable $now): array
    {
        $token = self::stringParam($post, 'refresh_token');
        $record = $token === null ? null : $this->store->findToken(self::hash($token));

        if ($record === null || $record->type !== OAuthTokenRecord::REFRESH || !hash_equals($record->clientId, $client->clientId)) {
            throw new OAuthError('invalid_grant', 'Invalid refresh token');
        }

        if ($record->revoked !== null && $record->grantRevoked === null) {
            // Refresh tokens rotate, so a revoked one coming back means it was
            // copied. Treat the whole grant as compromised (OAuth 2.1 §4.3.1).
            $this->store->revokeGrant($record->grantId, $now);
        }

        if (!$record->isLive($now)) {
            throw new OAuthError('invalid_grant', 'Refresh token is expired or revoked');
        }

        if (!in_array($record->credentialId, array_column($this->store->usableCredentials($record->userId, $now), 'id'), true)) {
            $this->store->revokeGrant($record->grantId, $now);
            throw new OAuthError('invalid_grant', 'The MCP credential behind this sign-in is no longer active');
        }

        $scope = self::stringParam($post, 'scope');
        if ($scope !== null && array_diff(preg_split('/\s+/', trim($scope)) ?: [], [self::SCOPE]) !== []) {
            throw new OAuthError('invalid_scope', 'Only the mcp scope can be granted');
        }

        $this->checkResourceParam($post, $record->resource);

        if (!$this->store->revokeToken($record->id, $now)) {
            // Lost a race with another redemption of the same token.
            $this->store->revokeGrant($record->grantId, $now);
            throw new OAuthError('invalid_grant', 'Refresh token has already been used');
        }

        return $this->issueTokens($record->grantId, $record->scope, $now);
    }

    /**
     * @return array<string,mixed>
     */
    private function issueTokens(int $grantId, string $scope, DateTimeImmutable $now): array
    {
        $access = OAuthTokenAuthenticator::PREFIX . self::random(32);
        $refresh = 'mcprt_' . self::random(32);

        $this->store->createToken($grantId, OAuthTokenRecord::ACCESS, self::hash($access), $now->modify('+' . $this->config->accessTokenTtl . ' seconds'), $now);
        $this->store->createToken($grantId, OAuthTokenRecord::REFRESH, self::hash($refresh), $now->modify('+' . $this->config->refreshTokenTtl . ' seconds'), $now);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $this->config->accessTokenTtl,
            'refresh_token' => $refresh,
            'scope' => $scope,
        ];
    }

    /**
     * Client authentication at the token and revocation endpoints (OAuth 2.1
     * §2.4.1). A confidential client may use either secret method regardless
     * of which it registered; a public client only identifies itself.
     *
     * @param array<string,mixed> $post
     */
    private function authenticateClient(array $post, string $authorizationHeader): OAuthClient
    {
        $clientId = self::stringParam($post, 'client_id');
        $secret = self::stringParam($post, 'client_secret');

        if (stripos($authorizationHeader, 'Basic ') === 0) {
            $decoded = base64_decode(substr($authorizationHeader, 6), true);
            if ($decoded === false || !str_contains($decoded, ':')) {
                throw new OAuthError('invalid_client', 'Malformed Basic credentials', 401);
            }
            [$basicId, $basicSecret] = array_map('urldecode', explode(':', $decoded, 2));
            if ($clientId !== null && $clientId !== $basicId) {
                throw new OAuthError('invalid_request', 'client_id does not match the Authorization header');
            }
            $clientId = $basicId;
            $secret = $basicSecret;
        }

        $client = $clientId === null ? null : $this->findClient($clientId);
        if ($client === null) {
            throw new OAuthError('invalid_client', 'Unknown client', 401);
        }

        if ($client->isConfidential()
            && ($secret === null || $client->secretHash === null || !hash_equals($client->secretHash, self::hash($secret)))) {
            throw new OAuthError('invalid_client', 'Client authentication failed', 401);
        }

        return $client;
    }

    /**
     * A registered client, or one identified by a Client ID Metadata Document
     * at its client_id URL. A document is kept for CLIENT_METADATA_TTL and
     * then fetched again, so a client's changes (or withdrawal) take effect.
     */
    private function findClient(string $clientId): ?OAuthClient
    {
        $client = $this->store->findClient($clientId);
        if ($this->metadataFetcher === null || !$this->config->registrationEnabled || !self::isMetadataDocumentUrl($clientId)) {
            return $client;
        }

        $now = ($this->clock)();
        if ($client !== null && $client->created > $now->modify('-' . self::CLIENT_METADATA_TTL . ' seconds')) {
            return $client;
        }

        try {
            $document = $this->metadataFetcher->fetch($clientId);
        } catch (\RuntimeException $e) {
            throw new OAuthError('invalid_request', 'The client metadata document could not be retrieved: ' . $e->getMessage());
        }

        if (($document['client_id'] ?? null) !== $clientId) {
            throw new OAuthError('invalid_request', 'The client metadata document does not name its own URL as client_id');
        }

        // The document is public, so it can only describe a public client: there
        // is no secret to share, and private_key_jwt is not supported here.
        $refusal = $this->redirectUrisRefusal($document['redirect_uris'] ?? null)
            ?? self::flowRefusal($document)
            ?? (($document['token_endpoint_auth_method'] ?? OAuthClient::AUTH_NONE) !== OAuthClient::AUTH_NONE
                || isset($document['client_secret']) || isset($document['client_secret_expires_at'])
                ? 'token_endpoint_auth_method must be none' : null);
        if ($refusal !== null) {
            throw new OAuthError('invalid_request', 'The client metadata document is not acceptable: ' . $refusal);
        }

        $fetched = new OAuthClient(
            $clientId,
            self::clientName($document),
            array_values((array) ($document['redirect_uris'] ?? [])),
            OAuthClient::AUTH_NONE,
            null,
            $now,
        );
        $client === null ? $this->store->createClient($fetched) : $this->store->replaceClient($fetched);

        return $fetched;
    }

    /**
     * The client's self-chosen name, made safe to display: control characters
     * removed and length capped.
     *
     * @param array<string,mixed> $metadata
     */
    private static function clientName(array $metadata): string
    {
        $name = $metadata['client_name'] ?? '';
        $name = is_string($name) ? trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $name)) : '';

        return $name === '' ? 'Unnamed MCP client' : mb_substr($name, 0, self::MAX_CLIENT_NAME);
    }

    /**
     * Whether a client_id is a URL this server will fetch a Client ID Metadata
     * Document from: https with a path, and no credentials, query, fragment or
     * dot segments (draft-ietf-oauth-client-id-metadata-document §3). Capped at
     * the 191 characters the client_id columns hold.
     */
    private static function isMetadataDocumentUrl(string $clientId): bool
    {
        if (strlen($clientId) > self::MAX_CLIENT_ID_URL || !str_starts_with($clientId, 'https://')) {
            return false;
        }

        $parts = parse_url($clientId);
        if ($parts === false || !isset($parts['host'], $parts['path']) || $parts['path'] === '/'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        return !in_array('.', explode('/', $parts['path']), true) && !in_array('..', explode('/', $parts['path']), true);
    }

    /**
     * Why a client's grant_types and response_types (registered or in a
     * metadata document) are unacceptable, or null when they are fine.
     *
     * @param array<string,mixed> $metadata
     */
    private static function flowRefusal(array $metadata): ?string
    {
        $grantTypes = $metadata['grant_types'] ?? ['authorization_code'];
        if (!is_array($grantTypes) || !in_array('authorization_code', $grantTypes, true)
            || array_diff($grantTypes, ['authorization_code', 'refresh_token']) !== []) {
            return 'grant_types must include authorization_code and may add only refresh_token';
        }

        $responseTypes = $metadata['response_types'] ?? ['code'];
        if (!is_array($responseTypes) || array_values(array_unique($responseTypes)) !== ['code']) {
            return 'response_types must be ["code"]';
        }

        return null;
    }

    /**
     * Why a client's redirect_uris (registered or in a metadata document) are
     * unacceptable, or null when they are fine.
     */
    private function redirectUrisRefusal(mixed $redirectUris): ?string
    {
        if (!is_array($redirectUris) || $redirectUris === [] || !array_is_list($redirectUris)) {
            return 'redirect_uris must be a non-empty array';
        }
        if (count($redirectUris) > self::MAX_REDIRECT_URIS) {
            return 'At most ' . self::MAX_REDIRECT_URIS . ' redirect_uris may be registered';
        }
        foreach ($redirectUris as $uri) {
            $refusal = is_string($uri) ? $this->redirectPolicy->refusal($uri) : 'redirect_uris must be strings';
            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
    }

    private function resolveRedirectUri(OAuthClient $client, ?string $requested): string
    {
        if ($requested === null) {
            if (count($client->redirectUris) === 1) {
                return $client->redirectUris[0];
            }

            throw new OAuthError('invalid_request', 'redirect_uri is required');
        }

        foreach ($client->redirectUris as $registered) {
            if (OAuthRedirectUriPolicy::matches($registered, $requested)) {
                return $requested;
            }
        }

        throw new OAuthError('invalid_request', 'redirect_uri is not registered for this client');
    }

    /**
     * @param array<string,mixed> $post
     */
    private function checkResourceParam(array $post, string $granted): void
    {
        $resource = self::stringParam($post, 'resource');
        if ($resource !== null && $resource !== $granted && !$this->config->ownsResource($resource)) {
            throw new OAuthError('invalid_target', 'resource is not an MCP endpoint of this site');
        }
    }

    /**
     * @param array<string,string|null> $params
     */
    private function redirectWith(string $redirectUri, array $params): string
    {
        $params['iss'] = $this->config->issuer();
        $query = http_build_query(array_filter($params, static fn ($v): bool => $v !== null), '', '&', PHP_QUERY_RFC3986);

        return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . $query;
    }

    /**
     * A request parameter as a non-empty string, or null. Arrays (from
     * `name[]=` tricks) count as absent rather than being coerced.
     *
     * @param array<string,mixed> $params
     */
    private static function stringParam(array $params, string $name): ?string
    {
        $value = $params[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function random(int $bytes): string
    {
        return self::base64Url(random_bytes($bytes));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
