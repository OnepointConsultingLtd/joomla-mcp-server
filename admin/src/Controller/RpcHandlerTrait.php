<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Mcpserver\Administrator\Extension\McpserverComponent;
use Joomla\Component\Mcpserver\Administrator\Service\AuthenticatedPrincipal;
use Joomla\Component\Mcpserver\Administrator\Service\AuthService;
use Joomla\Component\Mcpserver\Administrator\Service\CacheService;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceAuditService;
use Joomla\Component\Mcpserver\Administrator\Service\GovernedToolAuthorizer;
use Joomla\Component\Mcpserver\Administrator\Service\HttpProgressSink;
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaActionLogService;
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaCache;
use Joomla\Component\Mcpserver\Administrator\Service\JsonRpc;
use Joomla\Component\Mcpserver\Administrator\Service\McpHttpTransport;
use Joomla\Component\Mcpserver\Administrator\Service\McpProtocolError;
use Joomla\Component\Mcpserver\Administrator\Service\MetricsService;
use Joomla\Component\Mcpserver\Administrator\Service\MonologFactory;
use Joomla\Component\Mcpserver\Administrator\Service\PolicyService;
use Joomla\Component\Mcpserver\Administrator\Service\PrincipalCache;
use Joomla\Component\Mcpserver\Administrator\Service\PromptRegistry;
use Joomla\Component\Mcpserver\Administrator\Service\RateLimiter;
use Joomla\Component\Mcpserver\Administrator\Service\RestClient;
use Joomla\Component\Mcpserver\Administrator\Service\RestClientFactory;
use Joomla\Component\Mcpserver\Administrator\Service\RpcService;
use Joomla\Component\Mcpserver\Administrator\Service\SchemaValidator;
use Joomla\Component\Mcpserver\Administrator\Service\ToolRegistry;
use Joomla\Component\Mcpserver\Administrator\Service\ToolAccessPolicy;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Registry\Registry;
use Psr\Log\LoggerInterface;

/**
 * Shared RPC request handling logic for both admin and site controllers.
 *
 * Services are resolved from the DI container (registered in provider.php)
 * when available, with fallback to direct instantiation.
 */
trait RpcHandlerTrait
{
    public function sse(): void
    {
        $startTime = microtime(true);
        $app = Factory::getApplication();
        $context = $app->getName() === 'administrator' ? 'admin' : 'site';
        $params = ComponentHelper::getParams('com_mcpserver');

        $this->handleCors($params);

        $authService = $this->resolveService(AuthService::class) ?? new AuthService($params);
        $clientIp    = $authService->getClientIp() ?: 'unknown';

        // Throttle stream establishment before auth: each SSE connection holds a
        // PHP worker for the lifetime below, so unbounded opens are a DoS vector.
        $rateLimiter = $this->resolveService(RateLimiter::class) ?? $this->createRateLimiter($params);
        $rateLimit = $rateLimiter->checkLimit($clientIp);
        if ($rateLimit !== null) {
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: ' . $rateLimit['retry_after']);
            http_response_code(429);
            echo json_encode(JsonRpc::errorResponse(null, JsonRpc::RATE_LIMITED, 'Rate limit exceeded'));
            // Audited like handle()'s equivalent path: a credential-guessing
            // campaign against rpc.sse must not be invisible in the trail.
            $this->recordGovernanceAudit(
                $startTime, '', '', 'rate_limited', JsonRpc::RATE_LIMITED, 429, $clientIp, $context, null, null, null
            );
            $app->close();
            return;
        }

        if (!$this->isOriginAcceptable($params)) {
            $this->rejectOrigin($startTime, $clientIp, $context);
            return;
        }

        // Resolve the principal first: see handle() below for why a governed
        // credential never falls through to authenticate().
        $principal = $authService->authenticatePrincipal();
        $authError = $principal !== null ? null : $authService->authenticate();
        if ($authError !== null) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($authError['code'] === JsonRpc::UNAUTHORIZED ? 401 : 403);
            echo json_encode(JsonRpc::errorResponse(null, $authError['code'], $authError['error']));
            $this->recordGovernanceAudit(
                $startTime,
                '',
                '',
                'auth_failed',
                $authError['code'],
                $authError['code'] === JsonRpc::UNAUTHORIZED ? 401 : 403,
                $clientIp,
                $context,
                null,
                null,
                null
            );
            $app->close();
            return;
        }

        $sessionId = bin2hex(random_bytes(16));
        // Bound to this principal's credential so a response posted under a
        // different governed credential (even with the same sessionId) is
        // never delivered here. Legacy (null-principal) sessions are unaffected.
        $cacheKey = PrincipalCache::sessionKeyFor($sessionId, $principal);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $postUrl = Uri::root() . 'index.php?option=com_mcpserver&task=rpc.handle&sessionId=' . $sessionId;

        echo "event: endpoint\n";
        echo "data: " . $postUrl . "\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        $cache = new JoomlaCache('mcp_sse');

        // Deterministically reclaim orphaned SSE responses left by sessions whose
        // consumer disconnected before reading. Safe here because this instance keeps
        // its default lifetime (we never call set()/setLifeTime() on it) — gc() only
        // removes entries already past the global cachetime.
        $cache->gc();

        $startTime = time();
        $lastPingTime = $startTime;

        // Cap the worker hold time. Clients using EventSource reconnect
        // automatically, so a short cap bounds resource use without breaking
        // long-lived sessions.
        $timeout = 300;

        while (time() - $startTime < $timeout) {
            if (connection_aborted()) {
                break;
            }

            $message = $cache->get($cacheKey);
            if ($message) {
                echo "event: message\n";
                echo "data: " . $message . "\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                $cache->delete($cacheKey);
            }

            if ((time() - $lastPingTime) >= 15) {
                echo ": keep-alive\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                $lastPingTime = time();
            }

            usleep(200000);
        }

        // Drop this session's own entry in case a response was written into the gap
        // between the final poll and loop exit, so it is not left for gc to reclaim.
        $cache->delete($cacheKey);

        $app->close();
    }

    public function handle(): void
    {
        $app = Factory::getApplication();
        $sessionId = $app->input->get('sessionId', '', 'string');
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $mcpHeaders = McpHttpTransport::headersFromServer($_SERVER);

        // A GET without MCP-Protocol-Version opens the legacy 2024-11-05 HTTP+SSE
        // stream. Streamable HTTP clients from 2025-06-18 on send the header and
        // fall through to the 405 below: this server has nothing to push on a
        // standalone stream, and sse() would pin a worker for 300 s. (2025-03-26
        // clients predate the header and cannot be told apart from 2024-11-05.)
        if ($requestMethod === 'GET' && empty($sessionId) && !isset($mcpHeaders['mcp-protocol-version'])) {
            $this->sse();
            return;
        }

        $startTime = microtime(true);
        $context   = $app->getName() === 'administrator' ? 'admin' : 'site';

        header('Content-Type: application/json; charset=utf-8');

        $params = ComponentHelper::getParams('com_mcpserver');

        $this->handleCors($params);

        // MCP messages are POSTed. There are no sessions for a DELETE to end, and
        // GET survives only for the legacy stream handled above.
        if ($requestMethod !== 'POST') {
            header('Allow: GET, POST, OPTIONS');
            http_response_code(405);
            $app->close();
            return;
        }

        $authService = $this->resolveService(AuthService::class) ?? new AuthService($params);
        $clientIp    = $authService->getClientIp() ?: 'unknown';

        // Rate limit BEFORE authentication so failed auth attempts (bearer-token
        // guessing) are throttled too. Keyed on the proxy-aware client IP.
        $rateLimiter = $this->resolveService(RateLimiter::class) ?? $this->createRateLimiter($params);
        $rateLimit = $rateLimiter->checkLimit($clientIp);
        if ($rateLimit !== null) {
            header('Retry-After: ' . $rateLimit['retry_after']);
            http_response_code(429);
            echo json_encode(JsonRpc::errorResponse(null, JsonRpc::RATE_LIMITED, 'Rate limit exceeded'));
            $this->recordGovernanceAudit($startTime, '', '', 'rate_limited', JsonRpc::RATE_LIMITED, 429, $clientIp, $context, null, null, null);
            $app->close();
            return;
        }

        // After the rate limit, so a flood of rejected origins is throttled too,
        // and before auth, so a hostile page never gets a token checked.
        if (!$this->isOriginAcceptable($params)) {
            $this->rejectOrigin($startTime, $clientIp, $context);
            return;
        }

        // Resolve the principal first: a governed-mode credential authenticates
        // successfully as an AuthenticatedPrincipal, never as an error array, so
        // authenticate() below is only reached (and only touches credential state)
        // on the non-principal paths (legacy mode, or a failed/absent credential).
        $principal = $authService->authenticatePrincipal();
        $authError = $principal !== null ? null : $authService->authenticate();
        if ($authError !== null) {
            $code = $authError['code'] === JsonRpc::UNAUTHORIZED ? 401 : 403;
            http_response_code($code);
            echo json_encode(JsonRpc::errorResponse(null, $authError['code'], $authError['error']));
            $this->recordGovernanceAudit($startTime, '', '', 'auth_failed', $authError['code'], $code, $clientIp, $context, null, null, null);
            $app->close();
            return;
        }

        $body = file_get_contents('php://input') ?: '';
        $decoded = json_decode($body, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(JsonRpc::errorResponse(null, JsonRpc::PARSE_ERROR, 'Parse error'));
            $this->recordGovernanceAudit($startTime, '', '', 'invalid_request', JsonRpc::PARSE_ERROR, 400, $clientIp, $context, $principal, null, null);
            $app->close();
            return;
        }

        if (JsonRpc::isBatch($decoded)) {
            try {
                McpHttpTransport::validateBatch($decoded, $mcpHeaders);
            } catch (McpProtocolError $e) {
                $this->rejectProtocolError($e, null, '', '', $startTime, $clientIp, $context, $principal);
                return;
            }

            $this->handleBatch($decoded, $this->rpcServiceFor($params, $principal), $startTime, $clientIp, $context, $sessionId, $mcpHeaders, $principal);
            return;
        }

        $request = JsonRpc::parseRequestData($decoded);

        if ($request === null) {
            http_response_code(400);
            echo json_encode(JsonRpc::errorResponse(null, JsonRpc::INVALID_REQUEST, 'Invalid JSON-RPC 2.0 request'));
            $this->recordGovernanceAudit($startTime, '', '', 'invalid_request', JsonRpc::INVALID_REQUEST, 400, $clientIp, $context, $principal, null, null);
            $app->close();
            return;
        }

        $method   = (string) ($request['method'] ?? '');
        $toolName = $this->extractToolName($request);

        try {
            McpHttpTransport::validate($request, $mcpHeaders);
            McpHttpTransport::validateParamHeaders($request, $mcpHeaders, $this->paramHeadersFor($request));
        } catch (McpProtocolError $e) {
            $this->rejectProtocolError(
                $e,
                $request['id'] ?? null,
                $method,
                $toolName,
                $startTime,
                $clientIp,
                $context,
                $principal,
                $this->extractRequestId($request)
            );
            return;
        }

        // Built only once the request has passed validation: in Governed Mode this
        // decrypts the principal's API token, work a rejected request never needs.
        $rpcService = $this->rpcServiceFor($params, $principal);

        // The legacy ?sessionId relay parks one complete response in a cache, so
        // it cannot carry a live stream.
        $progressSink = empty($sessionId) ? $this->createProgressSink() : null;
        $rpcService->setProgressSink($progressSink);

        try {
            [$response, $dispatchFailed] = $this->dispatchToService($rpcService, $request, $method);
        } finally {
            // The container's shared RpcService outlives this request.
            $rpcService->setProgressSink(null);
        }
        $streamNotifications = $rpcService->takeStreamNotifications();

        // Policy denials (disabled tool, read-only mode) and tool execution
        // failures are MCP tool results with isError=true, not JSON-RPC errors,
        // so both are invisible in the response envelope — ask the service so
        // neither is logged as 'ok'.
        $okStatus = match (true) {
            $rpcService->wasLastCallBlocked() => 'blocked',
            $rpcService->wasLastCallFailed() => 'error',
            default => 'ok',
        };

        if ($response === null) {
            $notificationStatus = McpHttpTransport::notificationStatus($mcpHeaders);
            http_response_code($notificationStatus);
            $this->recordGovernanceAudit(
                $startTime,
                $method,
                $toolName,
                $okStatus,
                null,
                $notificationStatus,
                $clientIp,
                $context,
                $principal,
                $this->extractRequestId($request),
                $this->extractMutationTarget($request)
            );
            $app->close();
            return;
        }

        // Settled before the audit write so the row records what is sent. Once
        // progress has streamed the status was 200, or 499 if the client left.
        $httpStatus = match (true) {
            $progressSink?->hasStarted() === true => $rpcService->getLastHttpStatus() ?? 200,
            $dispatchFailed => 500,
            default => McpHttpTransport::responseStatus($rpcService->getLastHttpStatus(), $response),
        };

        $this->recordGovernanceAudit(
            $startTime,
            $method,
            $toolName,
            McpHttpTransport::auditStatus($response, $httpStatus, $okStatus),
            $response['error']['code'] ?? null,
            $httpStatus,
            $clientIp,
            $context,
            $principal,
            $this->extractRequestId($request),
            $this->extractMutationTarget($request)
        );

        if ($progressSink?->hasStarted() === true) {
            // The response joins the progress already streamed, unless the client left.
            $progressSink->finish($response, $streamNotifications);
            $app->close();
            return;
        }

        $jsonResponse = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!empty($sessionId)) {
            $sseCache = new JoomlaCache('mcp_sse');
            // Same principal-bound key derivation as sse()'s poller, so only the
            // matching authenticated principal (or a legacy null principal) can
            // retrieve this response for this sessionId.
            $sseCache->set(PrincipalCache::sessionKeyFor($sessionId, $principal), $jsonResponse, 30);
            http_response_code(202);
            echo json_encode(['status' => 'accepted', 'sessionId' => $sessionId]);
        } elseif ($streamNotifications !== []) {
            // Request-scoped notifications precede the response on its own
            // stream (subscriptions/listen's acknowledgement, for one).
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
            http_response_code($httpStatus);
            echo McpHttpTransport::sseFrames([...$streamNotifications, $response]);
        } else {
            http_response_code($httpStatus);
            echo $jsonResponse;
        }

        $app->close();
    }

    /**
     * Governed mode: each principal must use their own Joomla API token, not the
     * shared configured one, so a per-request RpcService is built against a
     * per-principal RestClient rather than the DI container's shared service.
     */
    private function rpcServiceFor(Registry $params, ?AuthenticatedPrincipal $principal): RpcService
    {
        return $principal !== null
            ? $this->createRpcServiceForPrincipal($params, $principal)
            : ($this->resolveService(RpcService::class) ?? $this->createRpcService($params));
    }

    /**
     * The live progress stream for one request. The stream starts only when a
     * tool first reports progress; until then the response is ordinary JSON.
     */
    private function createProgressSink(): HttpProgressSink
    {
        return new HttpProgressSink(
            static function (): void {
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('X-Accel-Buffering: no');
                http_response_code(200);
                // Compression and output buffers would hold every event until the
                // end; a disconnect must not end the script before it is audited.
                @ini_set('zlib.output_compression', '0');
                HttpProgressSink::drainOutputBuffers();
                ignore_user_abort(true);
            },
            static function (string $bytes): void {
                echo $bytes;
                flush();
            },
            static fn (): bool => connection_aborted() === 1
        );
    }

    /**
     * Run one request through the service. The handlers validate their own
     * params, but an unexpected throwable must still produce a JSON-RPC error
     * and an audit row rather than an HTML error page and nothing.
     *
     * @return array{0: ?array, 1: bool}  the response, and whether dispatch threw
     */
    private function dispatchToService(RpcService $rpcService, array $request, string $method): array
    {
        try {
            return [$rpcService->handle($request), false];
        } catch (\Throwable $e) {
            $this->resolveService(LoggerInterface::class)?->error('RPC dispatch failed', [
                'method' => $method,
                'error'  => $e->getMessage(),
            ]);

            return [JsonRpc::errorResponse($request['id'] ?? null, JsonRpc::INTERNAL_ERROR, 'Internal error'), true];
        }
    }

    /**
     * Reply to a request rejected at the protocol layer (unsupported version,
     * header mismatch, a modern batch) and audit it as an invalid request.
     */
    private function rejectProtocolError(
        McpProtocolError $error,
        mixed $id,
        string $method,
        string $toolName,
        float $startTime,
        string $clientIp,
        string $context,
        ?AuthenticatedPrincipal $principal,
        ?string $requestId = null
    ): void {
        http_response_code($error->httpStatus);
        echo json_encode($error->toResponse($id), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->recordGovernanceAudit(
            $startTime,
            $method,
            $toolName,
            'invalid_request',
            $error->jsonRpcCode,
            $error->httpStatus,
            $clientIp,
            $context,
            $principal,
            $requestId,
            null
        );
        Factory::getApplication()->close();
    }

    /**
     * An absent Origin is a non-browser client; a present one must be in the
     * Allowed Origins list (DNS-rebinding protection — see isOriginAllowed()).
     */
    private function isOriginAcceptable(Registry $params): bool
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

        return $origin === '' || McpHttpTransport::isOriginAllowed($origin, $this->allowedOrigins($params));
    }

    private function rejectOrigin(float $startTime, string $clientIp, string $context): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(JsonRpc::errorResponse(null, JsonRpc::FORBIDDEN, 'Origin not allowed'));
        $this->recordGovernanceAudit($startTime, '', '', 'invalid_request', JsonRpc::FORBIDDEN, 403, $clientIp, $context, null, null, null);
        Factory::getApplication()->close();
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(Registry $params): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $params->get('allowed_origins', '')))));
    }

    /**
     * Handle a JSON-RPC 2.0 batch request (required by MCP protocol revision
     * 2025-03-26; removed again in 2025-06-18 and forbidden in 2026-07-28, so
     * batches carrying a modern request are rejected before reaching here).
     * Entries are dispatched independently; notification entries produce no
     * response, and an all-notification batch is accepted without a body.
     */
    private function handleBatch(
        array $batch,
        RpcService $rpcService,
        float $startTime,
        string $clientIp,
        string $context,
        string $sessionId,
        array $mcpHeaders,
        ?AuthenticatedPrincipal $principal = null
    ): void {
        $app = Factory::getApplication();
        $responses = [];

        foreach ($batch as $entry) {
            $request = JsonRpc::parseRequestData($entry);

            if ($request === null) {
                $responses[] = JsonRpc::errorResponse(null, JsonRpc::INVALID_REQUEST, 'Invalid JSON-RPC 2.0 request');
                $this->recordGovernanceAudit($startTime, '', '', 'invalid_request', JsonRpc::INVALID_REQUEST, 200, $clientIp, $context, $principal, null, null);
                continue;
            }

            [$response] = $this->dispatchToService($rpcService, $request, (string) ($request['method'] ?? ''));

            // See handle(): policy denials and tool failures are tool results,
            // not JSON-RPC errors.
            $okStatus = match (true) {
                $rpcService->wasLastCallBlocked() => 'blocked',
                $rpcService->wasLastCallFailed() => 'error',
                default => 'ok',
            };
            $entryMethod = (string) ($request['method'] ?? '');
            $entryToolName = $this->extractToolName($request);
            $entryStatus = isset($response['error']) ? 'error' : $okStatus;

            $this->recordGovernanceAudit(
                $startTime,
                $entryMethod,
                $entryToolName,
                $entryStatus,
                $response['error']['code'] ?? null,
                200,
                $clientIp,
                $context,
                $principal,
                $this->extractRequestId($request),
                $this->extractMutationTarget($request)
            );

            if ($response !== null) {
                $responses[] = $response;
            }
        }

        if (empty($responses)) {
            http_response_code(McpHttpTransport::notificationStatus($mcpHeaders));
            $app->close();
            return;
        }

        $jsonResponse = json_encode($responses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!empty($sessionId)) {
            $sseCache = new JoomlaCache('mcp_sse');
            $sseCache->set(PrincipalCache::sessionKeyFor($sessionId, $principal), $jsonResponse, 30);
            http_response_code(202);
            echo json_encode(['status' => 'accepted', 'sessionId' => $sessionId]);
        } else {
            http_response_code(200);
            echo $jsonResponse;
        }

        $app->close();
    }

    private function handleCors(Registry $params): void
    {
        $allowedOrigins = $this->allowedOrigins($params);

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (!empty($allowedOrigins) && !empty($origin) && in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: POST, OPTIONS');
        // Streamable HTTP clients send these (Mcp-Method and Mcp-Name are required
        // from 2026-07-28; Mcp-Session-Id by 2025-03-26..2025-11-25 clients);
        // without them here, browser-based clients fail CORS preflight.
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id, MCP-Protocol-Version, Mcp-Method, Mcp-Name');
        header('Access-Control-Max-Age: 3600');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header('Content-Length: 0');
            http_response_code(204);
            Factory::getApplication()->close();
        }
    }

    /**
     * Resolve a service from the DI container if available.
     */
    private function resolveService(string $className): ?object
    {
        $container = McpserverComponent::getServiceContainer();
        if ($container !== null && $container->has($className)) {
            return $container->get($className);
        }

        return null;
    }

    /**
     * Record a single request to the metrics log. Resilient: recording is
     * internally guarded (enabled check + try/catch in MetricsService), so this
     * never disrupts the response or prevents $app->close() from running.
     */
    private function recordMetric(
        float $startTime,
        string $method,
        string $toolName,
        string $status,
        ?int $errorCode,
        int $httpStatus,
        string $clientIp,
        string $context
    ): void {
        $metrics = $this->resolveService(MetricsService::class)
            ?? $this->createMetricsService(ComponentHelper::getParams('com_mcpserver'));

        $metrics->record([
            'created'     => Factory::getDate()->toSql(),
            'method'      => $method,
            'tool_name'   => $toolName,
            'status'      => $status,
            'error_code'  => $errorCode,
            'http_status' => $httpStatus,
            'duration_ms' => (int) round((microtime(true) - $startTime) * 1000),
            'client_ip'   => $clientIp,
            'context'     => $context,
        ]);
    }

    /**
     * Extract a metrics label: tool name, prompt name, or resource URI.
     */
    private function extractToolName(array $request): string
    {
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        // Runs before RpcService rejects malformed params, so a non-string name
        // must not be cast (an array would log a conversion warning).
        $label = match ($request['method'] ?? '') {
            'tools/call', 'prompts/get' => $params['name'] ?? '',
            'resources/read' => $params['uri'] ?? '',
            default => '',
        };

        return is_string($label) ? $label : '';
    }

    /**
     * @return array<string, string>  the called tool's x-mcp-header map
     */
    private function paramHeadersFor(array $request): array
    {
        $name = $this->extractToolName($request);
        if (($request['method'] ?? '') !== 'tools/call' || $name === '') {
            return [];
        }

        return ($this->resolveService(ToolRegistry::class) ?? new ToolRegistry())->paramHeaders($name);
    }

    /**
     * Identifier-only argument keys eligible to become the audit/action-log
     * "target". Deliberately an allowlist of IDs/paths, never free-text
     * fields (title, content, introtext, ...), so mutation content is never
     * persisted to the governance audit trail or Joomla Action Log.
     *
     * A local array rather than a class constant: traits may not declare
     * constants before PHP 8.2, and the component supports PHP 8.1.
     *
     * @return list<string>
     */
    private function targetIdKeys(): array
    {
        return ['id', 'version_id', 'extension_id', 'catid', 'path', 'new_path'];
    }

    /**
     * Build a sanitized target string ("id=10;path=banners/logo.png") from a
     * tools/call request's arguments, restricted to targetIdKeys(). Returns
     * null for non-tool-call methods or when no identifier is present (e.g.
     * a create_* call that has not yet been assigned an id).
     */
    private function extractMutationTarget(array $request): ?string
    {
        if (($request['method'] ?? '') !== 'tools/call') {
            return null;
        }

        $params = is_array($request['params'] ?? null) ? $request['params'] : [];
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $parts = [];
        foreach ($this->targetIdKeys() as $key) {
            $value = $arguments[$key] ?? null;
            if (is_string($value) || is_int($value)) {
                $parts[] = $key . '=' . $value;
            }
        }

        return $parts === [] ? null : implode(';', $parts);
    }

    /**
     * Extract the JSON-RPC request id as a string for audit correlation.
     * Notifications (no id) and non-scalar ids yield null.
     */
    private function extractRequestId(array $request): ?string
    {
        $id = $request['id'] ?? null;

        return array_key_exists('id', $request) && is_scalar($id) ? (string) $id : null;
    }

    /**
     * True when the tool is a mutating tool per its tools/list annotation
     * (readOnlyHint === false), the same definition MCP clients see.
     */
    private function isMutatingTool(string $toolName): bool
    {
        $toolRegistry = $this->resolveService(ToolRegistry::class) ?? new ToolRegistry();
        $tool = $toolRegistry->get($toolName);

        return $tool !== null && ($tool['annotations']['readOnlyHint'] ?? true) === false;
    }

    /**
     * Record exactly one request-log row and, for a successful mutating tool
     * call made by an authenticated principal, a Joomla Action Log entry.
     *
     * This is the only request-log writer on the RPC path. GovernanceAuditService
     * writes a superset of MetricsService's columns, so recordMetric() is used
     * solely as a fallback for contexts where the DI container is unavailable
     * and the audit service cannot be resolved — never in addition to it.
     *
     * Neither write may fail the RPC call it reports on, but a failed audit
     * write is logged at critical: silently losing the row would destroy the
     * accountability guarantee that is this feature's entire purpose. Note both
     * run before the success response is echoed, so they add latency to the
     * request. A legacy null principal is still audited (with null attribution)
     * but never emits an Action Log entry — there is no Joomla user to
     * attribute it to.
     */
    private function recordGovernanceAudit(
        float $startTime,
        string $method,
        string $toolName,
        string $status,
        ?int $errorCode,
        int $httpStatus,
        string $clientIp,
        string $context,
        ?AuthenticatedPrincipal $principal,
        ?string $requestId,
        ?string $target
    ): void {
        $audit = $this->resolveService(GovernanceAuditService::class);

        if ($audit === null) {
            // No container: fall back to the base-column writer so the request
            // is still logged, just without attribution.
            $this->recordMetric($startTime, $method, $toolName, $status, $errorCode, $httpStatus, $clientIp, $context);
        } else {
            try {
                $audit->record(
                    method: $method,
                    toolName: $toolName !== '' ? $toolName : null,
                    status: $status,
                    errorCode: $errorCode,
                    httpStatus: $httpStatus,
                    durationMs: (int) round((microtime(true) - $startTime) * 1000),
                    clientIp: $clientIp,
                    context: $context,
                    principal: $principal,
                    requestId: $requestId,
                    target: $target,
                );
            } catch (\Throwable $e) {
                // Must not disrupt the RPC response, but must not vanish either:
                // a persistent failure here (e.g. the 1.8.0 attribution columns
                // never applied) would otherwise leave the audit trail silently
                // empty while the server looks perfectly healthy.
                $this->resolveService(LoggerInterface::class)?->critical(
                    'Governed audit write failed — this request is NOT in the audit trail',
                    [
                        'error'      => $e->getMessage(),
                        'method'     => $method,
                        'tool'       => $toolName,
                        'user_id'    => $principal?->userId,
                        'selector'   => $principal?->selector,
                        'request_id' => $requestId,
                    ]
                );
            }
        }

        if (
            $principal !== null
            && $status === 'ok'
            && $method === 'tools/call'
            && $toolName !== ''
            && $this->isMutatingTool($toolName)
        ) {
            $actionLog = $this->resolveService(JoomlaActionLogService::class);
            $actionLog?->recordSuccess($principal, $toolName, $target, $requestId ?? '');
        }
    }

    /**
     * Fallback: create MetricsService when DI container is not available.
     */
    private function createMetricsService(Registry $params): MetricsService
    {
        return new MetricsService($params);
    }

    /**
     * Fallback: create RateLimiter when DI container is not available.
     */
    private function createRateLimiter(Registry $params): RateLimiter
    {
        $cacheBackend = new JoomlaCache('com_mcpserver_ratelimit');
        return new RateLimiter(
            $cacheBackend,
            (int) $params->get('rate_limit_requests', 60),
            (int) $params->get('rate_limit_window', 60)
        );
    }

    /**
     * Fallback: create RpcService when DI container is not available.
     */
    private function createRpcService(Registry $params): RpcService
    {
        $serverName = (string) $params->get('server_name', 'joomla-mcp-server');
        $logger = MonologFactory::createComponentLogger('mcpserver', $serverName);
        $rest = (new RestClientFactory($params, $logger))->createShared();

        return $this->buildRpcService($params, $rest, $logger, $serverName);
    }

    /**
     * Governed mode: build an RpcService whose RestClient is bound to the
     * authenticated principal's own Joomla API token, never the shared
     * configured token. Always request-local — the DI container only holds
     * the shared RpcService — so it cannot leak between requests/principals.
     */
    private function createRpcServiceForPrincipal(Registry $params, AuthenticatedPrincipal $principal): RpcService
    {
        $serverName = (string) $params->get('server_name', 'joomla-mcp-server');
        $logger = $this->resolveService(LoggerInterface::class)
            ?? MonologFactory::createComponentLogger('mcpserver', $serverName);
        $rest = (new RestClientFactory($params, $logger))->createForPrincipal($principal);

        return $this->buildRpcService($params, $rest, $logger, $serverName, $principal);
    }

    private function buildRpcService(
        Registry $params,
        RestClient $rest,
        LoggerInterface $logger,
        string $serverName,
        ?AuthenticatedPrincipal $principal = null
    ): RpcService {
        $cacheTtl = (int) $params->get('cache_ttl', 60);
        $cacheBackend = new JoomlaCache('com_mcpserver');
        // Governed mode: namespace the request-local RpcService cache by the
        // authenticated user so distinct governed users never observe each
        // other's cached results. Legacy (null principal) is unaffected.
        $cache = new CacheService(new PrincipalCache($cacheBackend, $principal), $cacheTtl);
        $policy = new PolicyService(ComponentHelper::getParams('com_mcpserver'));
        $toolRegistry = new ToolRegistry();
        $promptRegistry = new PromptRegistry();
        $validator = new SchemaValidator();

        return new RpcService(
            $rest,
            $cache,
            $policy,
            $logger,
            $toolRegistry,
            $validator,
            $promptRegistry,
            $serverName,
            (int) $params->get('tools_list_page_size', 100),
            $principal,
            $principal !== null ? $this->createGovernedToolAuthorizer() : null
        );
    }

    /**
     * Build a request-local local-ACL guard for direct executors. The user is
     * loaded on each authorization attempt so disabled/deleted accounts cannot
     * keep using an already-issued governed credential.
     */
    private function createGovernedToolAuthorizer(): GovernedToolAuthorizer
    {
        return new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            static function (int $userId): ?object {
                try {
                    return Factory::getContainer()
                        ->get(UserFactoryInterface::class)
                        ->loadUserById($userId);
                } catch (\Throwable) {
                    return null;
                }
            },
            fn (string $kind, int $id): ?array => $this->resolveGovernedAclItem($kind, $id)
        );
    }

    /** @return array{id:int, created_by?:int, type?:string}|null */
    private function resolveGovernedAclItem(string $kind, int $id): ?array
    {
        try {
            $db = Factory::getDbo();
            if ($kind === 'article_version') {
                $query = $db->getQuery(true)
                    ->select($db->quoteName('item_id'))
                    ->from($db->quoteName('#__history'))
                    ->where($db->quoteName('version_id') . ' = ' . $id);
                $itemId = (string) $db->setQuery($query)->loadResult();
                if (!preg_match('/^com_content\\.article\\.(\\d+)$/', $itemId, $matches)) {
                    return null;
                }
                $id = (int) $matches[1];
            }

            if ($kind === 'article' || $kind === 'article_version') {
                $query = $db->getQuery(true)
                    ->select($db->quoteName(['id', 'created_by']))
                    ->from($db->quoteName('#__content'))
                    ->where($db->quoteName('id') . ' = ' . $id);
                $row = $db->setQuery($query)->loadAssoc();

                return is_array($row) && (int) ($row['id'] ?? 0) > 0
                    ? ['id' => (int) $row['id'], 'created_by' => (int) ($row['created_by'] ?? 0)]
                    : null;
            }

            $source = match ($kind) {
                'module' => ['#__modules', 'id', ['id']],
                'menu_item' => ['#__menu', 'id', ['id']],
                'plugin_extension' => ['#__extensions', 'extension_id', ['extension_id', 'type']],
                default => null,
            };
            if ($source === null) {
                return null;
            }

            [$table, $idColumn, $columns] = $source;
            $query = $db->getQuery(true)
                ->select($db->quoteName($columns))
                ->from($db->quoteName($table))
                ->where($db->quoteName($idColumn) . ' = ' . $id);
            $row = $db->setQuery($query)->loadAssoc();
            if (!is_array($row)) {
                return null;
            }

            $resolvedId = (int) ($row[$idColumn] ?? 0);

            return $resolvedId === $id
                ? ['id' => $resolvedId, 'type' => isset($row['type']) ? (string) $row['type'] : null]
                : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
