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

        public static function reset(): void
        {
            self::$application = null;
            self::$dbo = null;
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

namespace Joomla\Registry {
    class Registry
    {
        /**
         * @param  array<string, mixed>  $data
         */
        public function __construct(private array $data = [])
        {
        }

        public function get(string $path, mixed $default = null): mixed
        {
            return $this->data[$path] ?? $default;
        }
    }
}

namespace Joomla\Database {
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
