<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

defined('_JEXEC') or die;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Dispatcher\DispatcherInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\MVCFactory as MVCFactoryProvider;
use Joomla\CMS\Extension\Service\Provider\RouterFactory as RouterFactoryProvider;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Component\Router\RouterFactoryInterface;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Mcpserver\Administrator\Dispatcher\Dispatcher;
use Joomla\Component\Mcpserver\Administrator\Extension\McpserverComponent;
use Joomla\Component\Mcpserver\Administrator\Service\AuthService;
use Joomla\Component\Mcpserver\Administrator\Service\CacheService;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialCipher;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialLifecycleService;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceAuditQueryService;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceAuditRetentionService;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceAuditService;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceKeyMaterial;
use Joomla\Component\Mcpserver\Administrator\Service\GovernedAuthService;
use Joomla\Component\Mcpserver\Administrator\Service\GovernedCredentialAuthenticator;
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaActionLogService;
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaCache;
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaCredentialLifecycleStore;
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaCredentialStore;
use Joomla\Component\Mcpserver\Administrator\Service\McpbService;
use Joomla\Component\Mcpserver\Administrator\Service\MetricsService;
use Joomla\Component\Mcpserver\Administrator\Service\MonologFactory;
use Joomla\Component\Mcpserver\Administrator\Service\PolicyService;
use Joomla\Component\Mcpserver\Administrator\Service\PromptRegistry;
use Joomla\Component\Mcpserver\Administrator\Service\RateLimiter;
use Joomla\Component\Mcpserver\Administrator\Service\RestClient;
use Joomla\Component\Mcpserver\Administrator\Service\RestClientFactory;
use Joomla\Component\Mcpserver\Administrator\Service\RpcService;
use Joomla\Component\Mcpserver\Administrator\Service\SchemaValidator;
use Joomla\Component\Mcpserver\Administrator\Service\ToolRegistry;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Input\Input;
use Joomla\Registry\Registry;
use Psr\Log\LoggerInterface;

return new class implements ServiceProviderInterface {

    public function register(Container $container): void
    {
        $container->registerServiceProvider(new MVCFactoryProvider('\\Joomla\\Component\\Mcpserver'));
        $container->registerServiceProvider(new RouterFactoryProvider('\\Joomla\\Component\\Mcpserver'));

        $container->set(Registry::class, new Registry());

        // Governed-mode credential cipher. Derives its key from the Joomla
        // application secret (injected via callable, never an environment
        // variable) and the component's own salt. Fails closed (throws) until
        // an admin enable action provisions a valid credential_salt.
        $container->share(CredentialCipher::class, function () {
            $params = ComponentHelper::getParams('com_mcpserver');
            $salt = (string) $params->get('credential_salt', '');

            $keyMaterial = new GovernanceKeyMaterial(
                static fn (): string => (string) Factory::getApplication()->get('secret', ''),
                $salt
            );

            return $keyMaterial->createCipher();
        });

        // Governed-mode credential store
        $container->share(JoomlaCredentialStore::class, function () {
            return new JoomlaCredentialStore(Factory::getDbo());
        });

        // Credential lifecycle store: persists issuance/listing/revocation of
        // MCP credentials in #__mcpserver_credential.
        $container->share(JoomlaCredentialLifecycleStore::class, function () {
            return new JoomlaCredentialLifecycleStore(Factory::getDbo());
        });

        // Credential lifecycle service: owns issuance, listing, and
        // revocation invariants for MCP credentials.
        $container->share(CredentialLifecycleService::class, function (Container $container) {
            return new CredentialLifecycleService(
                $container->get(JoomlaCredentialLifecycleStore::class),
                $container->get(CredentialCipher::class)
            );
        });

        // Governed-mode credential authenticator
        $container->share(GovernedCredentialAuthenticator::class, function (Container $container) {
            return new GovernedCredentialAuthenticator(
                $container->get(JoomlaCredentialStore::class),
                $container->get(CredentialCipher::class)
            );
        });

        // Governed-mode auth service
        $container->share(GovernedAuthService::class, function (Container $container) {
            return new GovernedAuthService(
                $container->get(GovernedCredentialAuthenticator::class),
                static function (string $name, string $default): string {
                    return Factory::getApplication()->input->server->getString($name, $default);
                }
            );
        });

        // Auth service
        $container->share(AuthService::class, function (Container $container) {
            $governedAuthService = null;

            try {
                $governedAuthService = $container->get(GovernedAuthService::class);
            } catch (\RuntimeException) {
                // Governed key material is not yet provisioned (e.g. empty or
                // malformed credential_salt). Fail closed: legacy mode keeps
                // working; governed mode reports "not configured" at request time.
                $governedAuthService = null;
            }

            return new AuthService(ComponentHelper::getParams('com_mcpserver'), $governedAuthService);
        });

        // Tool registry
        $container->share(ToolRegistry::class, function () {
            return new ToolRegistry();
        });

        // Prompt registry
        $container->share(PromptRegistry::class, function () {
            return new PromptRegistry();
        });

        // Schema validator
        $container->share(SchemaValidator::class, function () {
            return new SchemaValidator();
        });

        // Policy service
        $container->share(PolicyService::class, function () {
            return new PolicyService(ComponentHelper::getParams('com_mcpserver'));
        });

        // Logger
        $container->share(LoggerInterface::class, function () {
            $params = ComponentHelper::getParams('com_mcpserver');
            $serverName = (string) $params->get('server_name', 'joomla-mcp-server');
            return MonologFactory::createComponentLogger('mcpserver', $serverName);
        });

        // Rate limiter
        $container->share(RateLimiter::class, function () {
            $params = ComponentHelper::getParams('com_mcpserver');
            $cacheBackend = new JoomlaCache('com_mcpserver_ratelimit');
            return new RateLimiter(
                $cacheBackend,
                (int) $params->get('rate_limit_requests', 60),
                (int) $params->get('rate_limit_window', 60)
            );
        });

        // Metrics service
        $container->share(MetricsService::class, function () {
            return new MetricsService(ComponentHelper::getParams('com_mcpserver'));
        });

        // Governance audit service: persists one row per MCP request into
        // #__mcpserver_request_log, attributed to the authenticated principal
        // when one is available (null attribution in legacy shared-token mode).
        $container->share(GovernanceAuditService::class, function () {
            return new GovernanceAuditService(
                Factory::getDbo(),
                static fn (): \DateTimeImmutable => new \DateTimeImmutable('now')
            );
        });

        // Governance audit query service: read-only, safe-column search over
        // #__mcpserver_request_log for the credentials screen's audit panel.
        $container->share(GovernanceAuditQueryService::class, function () {
            return new GovernanceAuditQueryService(Factory::getDbo());
        });

        // Governance audit retention service: admin-triggered prune of
        // #__mcpserver_request_log rows older than the configured retention.
        $container->share(GovernanceAuditRetentionService::class, function () {
            return new GovernanceAuditRetentionService(
                Factory::getDbo(),
                static fn (): \DateTimeImmutable => new \DateTimeImmutable('now')
            );
        });

        // Joomla Action Log writer for successful mutating MCP tool calls.
        // Resolved lazily: if com_actionlogs is not installed/available the
        // writer is a safe no-op, and any failure inside it is already
        // swallowed by JoomlaActionLogService so it never affects the MCP
        // response being reported on.
        $container->share(JoomlaActionLogService::class, function () {
            return new JoomlaActionLogService(static function (int $userId, string $messageKey, string $context, array $message): void {
                $modelClass = '\\Joomla\\Component\\Actionlogs\\Administrator\\Model\\ActionlogModel';

                if (!class_exists($modelClass)) {
                    return;
                }

                $model = new $modelClass();
                $model->addLog(
                    [[
                        'action'    => $message['tool'],
                        'id'        => $message['target'],
                        'title'     => $message['tool'],
                        'itemlink'  => '',
                        'extension' => 'com_mcpserver',
                    ]],
                    $messageKey,
                    $context,
                    $userId
                );
            });
        });

        // MCPB bundle builder
        $container->share(McpbService::class, function () {
            return new McpbService(ComponentHelper::getParams('com_mcpserver'));
        });

        // REST client factory: builds a RestClient against the configured base
        // URL/SSL/resolve settings, either with the shared configured token or
        // (in governed mode) with an individual authenticated principal's own
        // Joomla API token.
        $container->share(RestClientFactory::class, function (Container $container) {
            return new RestClientFactory(
                ComponentHelper::getParams('com_mcpserver'),
                $container->get(LoggerInterface::class)
            );
        });

        // REST client (shared/legacy token)
        $container->share(RestClient::class, function (Container $container) {
            return $container->get(RestClientFactory::class)->createShared();
        });

        // Cache service
        $container->share(CacheService::class, function () {
            $params = ComponentHelper::getParams('com_mcpserver');
            $cacheBackend = new JoomlaCache('com_mcpserver');
            return new CacheService($cacheBackend, (int) $params->get('cache_ttl', 60));
        });

        // RPC service
        $container->share(RpcService::class, function (Container $container) {
            $params = ComponentHelper::getParams('com_mcpserver');
            $serverName = (string) $params->get('server_name', 'joomla-mcp-server');

            return new RpcService(
                $container->get(RestClient::class),
                $container->get(CacheService::class),
                $container->get(PolicyService::class),
                $container->get(LoggerInterface::class),
                $container->get(ToolRegistry::class),
                $container->get(SchemaValidator::class),
                $container->get(PromptRegistry::class),
                $serverName,
                (int) $params->get('tools_list_page_size', 100)
            );
        });

        $container->set(
            ComponentDispatcherFactoryInterface::class,
            function (Container $container) {
                return new class ($container) implements ComponentDispatcherFactoryInterface {
                    private Container $container;

                    public function __construct(Container $container)
                    {
                        $this->container = $container;
                    }

                    public function createDispatcher(CMSApplicationInterface $application, ?Input $input = null): DispatcherInterface
                    {
                        return new Dispatcher(
                            $application,
                            $input ?? $application->getInput(),
                            $this->container->get(MVCFactoryInterface::class)
                        );
                    }
                };
            }
        );

        $container->set(
            ComponentInterface::class,
            static function (Container $container): ComponentInterface {
                return new McpserverComponent(
                    $container->get(ComponentDispatcherFactoryInterface::class),
                    $container->get(MVCFactoryInterface::class),
                    $container->get(Registry::class),
                    $container->get(RouterFactoryInterface::class),
                    $container
                );
            }
        );
    }
};
