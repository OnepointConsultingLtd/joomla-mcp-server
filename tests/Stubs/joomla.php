<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\CMS {
    class Factory
    {
        public static ?object $application = null;
        public static ?object $dbo = null;

        public static function getApplication(): object
        {
            if (self::$application === null) {
                throw new \RuntimeException('Test application has not been installed');
            }

            return self::$application;
        }

        public static function getDbo(): object
        {
            if (self::$dbo === null) {
                throw new \RuntimeException('Test database has not been installed');
            }

            return self::$dbo;
        }

        public static function getDate(string $time = 'now'): \Joomla\CMS\Date\Date
        {
            return new \Joomla\CMS\Date\Date($time);
        }

        public static function reset(): void
        {
            self::$application = null;
            self::$dbo = null;
        }
    }

    class Version
    {
        public function getShortVersion(): string
        {
            return '5.2.0';
        }
    }
}

namespace Joomla\CMS\Date {
    /**
     * Minimal stand-in for Joomla's Date. The real class extends \DateTime,
     * so modify() mutates and returns $this; mirrored here so tests exercise
     * the same aliasing behaviour as production.
     */
    class Date
    {
        private \DateTime $date;

        public function __construct(string $time = 'now')
        {
            $this->date = new \DateTime($time, new \DateTimeZone('UTC'));
        }

        public function modify(string $modifier): self
        {
            $this->date->modify($modifier);

            return $this;
        }

        public function format(string $format): string
        {
            return $this->date->format($format);
        }

        public function toSql(): string
        {
            return $this->date->format('Y-m-d H:i:s');
        }
    }
}

namespace Joomla\CMS\Component {
    class ComponentHelper
    {
        public static ?object $params = null;

        public static function getParams(string $option): object
        {
            if (self::$params === null) {
                throw new \RuntimeException('Test component params have not been installed');
            }

            return self::$params;
        }

        public static function reset(): void
        {
            self::$params = null;
        }
    }
}

namespace Joomla\DI {
    interface ServiceProviderInterface
    {
        public function register(Container $container): void;
    }

    class Container
    {
        /** @var array<string, callable> */
        private array $factories = [];

        /** @var array<string, mixed> */
        private array $instances = [];

        /** @var array<string, bool> */
        private array $shared = [];

        public function set(string $key, mixed $value, bool $shared = false): self
        {
            if ($value instanceof \Closure) {
                $this->factories[$key] = $value;
                $this->shared[$key] = $shared;
                unset($this->instances[$key]);
            } else {
                $this->instances[$key] = $value;
            }

            return $this;
        }

        public function share(string $key, callable $factory): self
        {
            return $this->set($key, \Closure::fromCallable($factory), true);
        }

        public function get(string $key): mixed
        {
            if (array_key_exists($key, $this->instances)) {
                return $this->instances[$key];
            }

            if (!isset($this->factories[$key])) {
                throw new \RuntimeException("Key {$key} has not been registered with the container.");
            }

            $value = ($this->factories[$key])($this);

            if (!empty($this->shared[$key])) {
                $this->instances[$key] = $value;
            }

            return $value;
        }

        public function registerServiceProvider(ServiceProviderInterface $provider): self
        {
            $provider->register($this);

            return $this;
        }
    }
}

namespace Joomla\CMS\Extension\Service\Provider {
    use Joomla\DI\Container;
    use Joomla\DI\ServiceProviderInterface;

    class MVCFactory implements ServiceProviderInterface
    {
        public function __construct(private string $namespace)
        {
        }

        public function register(Container $container): void
        {
        }
    }

    class RouterFactory implements ServiceProviderInterface
    {
        public function __construct(private string $namespace)
        {
        }

        public function register(Container $container): void
        {
        }
    }
}

namespace Joomla\CMS\Cache {
    class Cache
    {
        /** @var list<string> */
        public static array $availableGroups = [];

        /** @var list<string> */
        public static array $cleaned = [];

        public function __construct(array $options = [])
        {
        }

        public function getAll(): array
        {
            $items = [];
            foreach (self::$availableGroups as $group) {
                $items[] = (object) ['group' => $group];
            }

            return $items;
        }

        public function clean(string $group): bool
        {
            self::$cleaned[] = $group;

            return true;
        }

        public static function reset(): void
        {
            self::$availableGroups = [];
            self::$cleaned = [];
        }
    }
}

namespace Joomla\CMS\Event\Cache {
    class AfterPurgeEvent
    {
        public static ?string $lastName = null;

        /** @var array<string, mixed>|null */
        public static ?array $lastArguments = null;

        public function __construct(string $name, array $arguments = [])
        {
            if (array_key_exists('subject', $arguments)) {
                $value = $arguments['subject'];
                if (!\is_string($value) || $value === '') {
                    throw new \TypeError(
                        'AfterPurgeEvent::setSubject(): Argument #1 ($value) must be of type string, '
                        . ($value === '' ? 'empty string' : \get_debug_type($value)) . ' given'
                    );
                }
            }

            self::$lastName = $name;
            self::$lastArguments = $arguments;
        }

        public static function reset(): void
        {
            self::$lastName = null;
            self::$lastArguments = null;
        }
    }
}

namespace Joomla\CMS\Uri {
    class Uri
    {
        public static string $root = 'https://example.test/';

        public static function root(bool $pathonly = false): string
        {
            return self::$root;
        }
    }
}

namespace Joomla\Component\Mcpserver\Tests\Stubs {
    /**
     * Query builder stand-in: records the clauses the service assembles so a
     * test can assert on them, without parsing SQL.
     */
    class StubQuery
    {
        /** @var list<string> */
        public array $clauses = [];

        public function select(string|array $columns): self
        {
            $this->clauses[] = 'select ' . implode(',', (array) $columns);

            return $this;
        }

        public function from(string $table): self
        {
            $this->clauses[] = 'from ' . $table;

            return $this;
        }

        public function where(string $condition): self
        {
            $this->clauses[] = 'where ' . $condition;

            return $this;
        }
    }

    /**
     * Minimal stand-in for Joomla's DatabaseDriver. Tests queue the rows that
     * loadObject() should hand back, in call order.
     */
    class StubDatabase
    {
        /** @var list<object|null> */
        public array $objects = [];

        public ?StubQuery $lastQuery = null;

        public function getQuery(bool $new = false): StubQuery
        {
            return new StubQuery();
        }

        public function quoteName(string|array $name): string|array
        {
            if (\is_array($name)) {
                return array_map(fn(string $one): string => $this->quoteName($one), $name);
            }

            return '`' . $name . '`';
        }

        public function quote(string $text): string
        {
            return "'" . $text . "'";
        }

        public function setQuery(StubQuery $query): self
        {
            $this->lastQuery = $query;

            return $this;
        }

        public function loadObject(): ?object
        {
            return array_shift($this->objects);
        }
    }
}

namespace Joomla\Registry {
    class Registry
    {
        /** @var array<string, mixed> */
        private array $data;

        /**
         * Mirrors the real Registry closely enough for the component's own use:
         * a JSON string (how #__extensions.params is stored) or an array.
         *
         * @param  array<string, mixed>|string  $data
         */
        public function __construct(array|string $data = [])
        {
            if (\is_string($data)) {
                $decoded = $data === '' ? [] : json_decode($data, true);
                $this->data = \is_array($decoded) ? $decoded : [];

                return;
            }

            $this->data = $data;
        }

        public function get(string $path, mixed $default = null): mixed
        {
            return $this->data[$path] ?? $default;
        }

        public function set(string $path, mixed $value): mixed
        {
            $this->data[$path] = $value;

            return $value;
        }

        /**
         * @return array<string, mixed>
         */
        public function toArray(): array
        {
            return $this->data;
        }

        public function __toString(): string
        {
            return (string) json_encode($this->data);
        }
    }
}

namespace Joomla\Database {
    /**
     * Declared methods bind every test double, so the interface stays at what the
     * doubles actually build; the rest of the real query builder is documented so
     * static analysis still resolves the clauses the component chains.
     *
     * @method self delete(?string $table = null)
     * @method self join(string $type, string $table, ?string $condition = null)
     * @method self order(array|string $columns)
     * @method self group(array|string $columns)
     */
    interface QueryInterface
    {
        public function select(array|string $columns): self;

        public function from(array|string $tables): self;

        public function where(array|string $conditions, string $glue = 'AND'): self;

        public function update(string $table): self;

        public function set(array|string $values): self;

        public function insert(string $table): self;

        public function columns(array|string $columns): self;

        public function values(array|string $values): self;

        public function __toString(): string;
    }

    /**
     * The real driver carries the whole load* family, but each method declared here
     * has to be implemented by every test double, so only the ones a double actually
     * needs are required. The rest are documented so static analysis still resolves
     * the calls the component makes against this type.
     *
     * @method array       loadColumn(int $offset = 0)
     * @method object|null loadObject(string $class = \stdClass::class)
     * @method array|null  loadAssocList(?string $key = null, ?string $column = null)
     * @method array|null  loadObjectList(string $key = '', string $class = \stdClass::class)
     */
    interface DatabaseInterface
    {
        public function quoteName(array|string $name, array|string|null $alias = null): array|string;

        public function quote(array|string $text, bool $escape = true): array|string;

        public function getQuery(bool $new = false): QueryInterface|string;

        public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self;

        public function loadAssoc(): ?array;

        public function loadResult(): mixed;

        public function execute(): bool;
    }
}

namespace Psr\Container {
    if (!interface_exists(ContainerInterface::class)) {
        interface ContainerInterface
        {
            public function has(string $id): bool;

            public function get(string $id);
        }
    }
}

namespace Joomla\CMS\Extension {
    if (!interface_exists(BootableExtensionInterface::class)) {
        interface BootableExtensionInterface
        {
            public function boot(\Psr\Container\ContainerInterface $container): void;
        }
    }

    if (!class_exists(MVCComponent::class)) {
        class MVCComponent
        {
        }
    }
}

namespace Joomla\CMS\Installer {
    if (!class_exists(InstallerAdapter::class)) {
        class InstallerAdapter
        {
        }
    }

    /**
     * The install/uninstall executors drive Joomla's real installer, which needs a booted
     * CMS and a writable filesystem. These stand-ins exist so the classes resolve; they
     * throw rather than fake a result, so a test that reaches them fails loudly instead of
     * reporting an install that never happened. Parameters stay loose because the real
     * methods are untyped and the executors pass values straight out of the package array.
     */
    if (!class_exists(InstallerHelper::class)) {
        class InstallerHelper
        {
            public static function unpack(mixed $packageFilename, bool $alwaysReturnArray = false): array|bool
            {
                throw new \RuntimeException('InstallerHelper::unpack() needs a booted Joomla');
            }

            public static function cleanupInstall(mixed $package, mixed $resultdir): bool
            {
                throw new \RuntimeException('InstallerHelper::cleanupInstall() needs a booted Joomla');
            }
        }
    }

    if (!class_exists(Installer::class)) {
        class Installer
        {
            /** Set by install(); the executor reports the extension name from it. */
            public ?\SimpleXMLElement $manifest = null;

            public static function getInstance(): self
            {
                throw new \RuntimeException('Installer::getInstance() needs a booted Joomla');
            }

            public function install(mixed $path = null): bool
            {
                throw new \RuntimeException('Installer::install() needs a booted Joomla');
            }

            public function uninstall(mixed $type, mixed $identifier): bool
            {
                throw new \RuntimeException('Installer::uninstall() needs a booted Joomla');
            }
        }
    }
}

namespace Joomla\CMS\Language {
    if (!class_exists(Text::class)) {
        class Text
        {
            public static function _(string $string): string
            {
                return $string;
            }

            public static function sprintf(string $string, mixed ...$args): string
            {
                return $string . ': ' . implode(', ', array_map('strval', $args));
            }
        }
    }
}

namespace {
    if (!class_exists('JLoader')) {
        class JLoader
        {
            /** @var list<array{0:string,1:string}> */
            public static array $registeredNamespaces = [];

            public static function registerNamespace(
                string $namespace,
                string $path,
                bool $reset = false,
                bool $prepend = false,
                string $type = 'psr4'
            ): void {
                // The component's classes are already on the test autoloader;
                // recording the call is enough to assert the installer binds the
                // namespace rather than relying on the compiled namespace map.
                self::$registeredNamespaces[] = [$namespace, $path];
            }
        }
    }
}
