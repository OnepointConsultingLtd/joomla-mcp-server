<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Minimal query recorder: enough structure for the installer's params read,
 * credential count and params write to be told apart by the fake database.
 */
final class FakeInstallerQuery implements QueryInterface
{
    /** @var list<string> */
    public array $selectColumns = [];
    public string $fromTable = '';
    public string $updateTable = '';
    /** @var list<string> */
    public array $setValues = [];

    public function select(array|string $columns): self
    {
        $this->selectColumns = is_array($columns) ? $columns : [$columns];

        return $this;
    }

    public function from(array|string $tables): self
    {
        $this->fromTable = is_array($tables) ? implode(',', $tables) : $tables;

        return $this;
    }

    public function where(array|string $conditions, string $glue = 'AND'): self
    {
        return $this;
    }

    public function update(string $table): self
    {
        $this->updateTable = $table;

        return $this;
    }

    public function set(array|string $values): self
    {
        $this->setValues = is_array($values) ? $values : [$values];

        return $this;
    }

    public function insert(string $table): self
    {
        return $this;
    }

    public function columns(array|string $columns): self
    {
        return $this;
    }

    public function values(array|string $values): self
    {
        return $this;
    }

    public function __toString(): string
    {
        return '';
    }
}

/**
 * Fake database serving the three statements the installer issues, keyed on
 * which table the query targets.
 */
final class FakeInstallerDatabase implements DatabaseInterface
{
    public ?string $storedParams;
    public int $credentialCount;
    /** @var list<string> Params JSON written back, in order. */
    public array $writes = [];
    /** @var ?string Table whose read should throw, simulating a missing table. */
    public ?string $failReadsOn = null;

    private ?FakeInstallerQuery $current = null;

    public function __construct(?string $storedParams, int $credentialCount = 0)
    {
        $this->storedParams = $storedParams;
        $this->credentialCount = $credentialCount;
    }

    public function quoteName(array|string $name, array|string|null $alias = null): array|string
    {
        return $name;
    }

    public function quote(array|string $text, bool $escape = true): array|string
    {
        return is_array($text) ? $text : "'" . $text . "'";
    }

    public function getQuery(bool $new = false): QueryInterface|string
    {
        return new FakeInstallerQuery();
    }

    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self
    {
        $this->current = $query instanceof FakeInstallerQuery ? $query : null;

        return $this;
    }

    public function loadAssoc(): ?array
    {
        return null;
    }

    public function loadResult(): mixed
    {
        if ($this->current === null) {
            return null;
        }

        if ($this->failReadsOn !== null && $this->current->fromTable === $this->failReadsOn) {
            throw new \RuntimeException('Table ' . $this->failReadsOn . ' does not exist');
        }

        if ($this->current->fromTable === '#__mcpserver_credential') {
            return $this->credentialCount;
        }

        return $this->storedParams;
    }

    public function execute(): bool
    {
        if ($this->current !== null && $this->current->updateTable === '#__extensions') {
            $written = $this->current->setValues[0] ?? '';
            // "params = '<json>'" — keep the JSON payload only.
            $json = substr($written, (int) strpos($written, "'") + 1, -1);
            $this->writes[] = $json;
            $this->storedParams = $json;
        }

        return true;
    }
}

final class FakeInstallerApplication
{
    /** @var list<array{0:string,1:string}> */
    public array $messages = [];

    public function get(string $name, mixed $default = null): mixed
    {
        return $name === 'secret' ? 'installer-test-secret' : $default;
    }

    public function getLanguage(): object
    {
        return new class {
            public function load(string $extension, string $path): bool
            {
                return true;
            }
        };
    }

    public function enqueueMessage(string $message, string $type = 'message'): void
    {
        $this->messages[] = [$message, $type];
    }
}

/**
 * Covers the automatic credential-salt provisioning that replaced the manual
 * "Provision credential salt" button for the normal case. The installer must
 * mint a salt where that is safe, must refuse in exactly the cases that would
 * orphan stored credentials, and must never abort the installation itself.
 */
class InstallerSaltProvisioningTest extends TestCase
{
    private FakeInstallerApplication $app;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/script.php';
    }

    protected function setUp(): void
    {
        $this->app = new FakeInstallerApplication();
        Factory::$application = $this->app;
    }

    protected function tearDown(): void
    {
        Factory::reset();
    }

    private function runPostflight(FakeInstallerDatabase $db, string $type = 'install'): void
    {
        Factory::$dbo = $db;

        (new \com_mcpserverInstallerScript())->postflight($type, new InstallerAdapter());
    }

    /**
     * @return array<string, mixed>
     */
    private function storedParams(FakeInstallerDatabase $db): array
    {
        $decoded = json_decode((string) $db->storedParams, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function testFreshInstallGeneratesASalt(): void
    {
        // A newly installed component has an empty params row: no governed_mode
        // key at all. That is a legitimate "off", not a failed read.
        $db = new FakeInstallerDatabase('{}');

        $this->runPostflight($db);

        $params = $this->storedParams($db);
        $this->assertArrayHasKey('credential_salt', $params);
        $decoded = base64_decode((string) $params['credential_salt'], true);
        $this->assertNotFalse($decoded);
        $this->assertSame(32, strlen($decoded), 'The generated salt must be 32 random bytes.');
        $this->assertSame([], $this->app->messages, 'A successful provision must not warn.');
    }

    public function testFreshInstallDoesNotTurnGovernedModeOn(): void
    {
        $db = new FakeInstallerDatabase('{}');

        $this->runPostflight($db);

        $this->assertSame(
            0,
            $this->storedParams($db)['governed_mode'],
            'Provisioning the salt must never enable Governed Mode by itself.'
        );
    }

    public function testUpdateKeepsAnExistingSaltAndGovernedModeSetting(): void
    {
        $salt = base64_encode(random_bytes(32));
        $db = new FakeInstallerDatabase(json_encode([
            'governed_mode' => 1,
            'credential_salt' => $salt,
            'metrics_retention_days' => 30,
        ]), 4);

        $this->runPostflight($db, 'update');

        $params = $this->storedParams($db);
        $this->assertSame($salt, $params['credential_salt'], 'An existing salt must survive an update untouched.');
        $this->assertSame(1, $params['governed_mode']);
        $this->assertSame(30, $params['metrics_retention_days'], 'Unrelated options must not be disturbed.');
    }

    public function testUpdateRefusesToMintASaltWhileCredentialsExist(): void
    {
        // Salt missing but credentials present means the salt was lost, not that
        // the site is new. Minting one here would "succeed" and leave every
        // stored token permanently undecryptable.
        $db = new FakeInstallerDatabase(json_encode(['governed_mode' => 1]), 2);

        $this->runPostflight($db, 'update');

        $this->assertSame([], $db->writes, 'Nothing may be written when the salt cannot be safely minted.');
        $this->assertCount(1, $this->app->messages, 'The refusal must be surfaced, not swallowed.');
        $this->assertSame('warning', $this->app->messages[0][1]);
        $this->assertStringContainsString('Credentials already exist', $this->app->messages[0][0]);
    }

    public function testUpdateRefusesToReplaceAnUnreadableSalt(): void
    {
        $db = new FakeInstallerDatabase(json_encode([
            'governed_mode' => 1,
            'credential_salt' => 'not valid base64!!',
        ]));

        $this->runPostflight($db, 'update');

        $this->assertSame([], $db->writes);
        $this->assertCount(1, $this->app->messages);
        $this->assertStringContainsString('already stored', $this->app->messages[0][0]);
    }

    public function testUnreadableParamsRowIsRefusedRatherThanTreatedAsGovernedModeOff(): void
    {
        // A failed read must not be mistaken for "governed_mode is off": writing
        // that back would silently revert a governed site to shared tokens.
        $db = new FakeInstallerDatabase(null);

        $this->runPostflight($db, 'update');

        $this->assertSame([], $db->writes);
        $this->assertCount(1, $this->app->messages);
    }

    public function testAMissingCredentialTableDoesNotAbortTheInstallation(): void
    {
        $db = new FakeInstallerDatabase('{}');
        $db->failReadsOn = '#__mcpserver_credential';

        $this->runPostflight($db);

        $this->assertSame([], $db->writes, 'Without a countable credential table, provisioning must fail closed.');
        $this->assertCount(1, $this->app->messages);
    }

    public function testProvisioningFailureNeverPropagatesOutOfPostflight(): void
    {
        // No database installed at all: Factory::getDbo() throws. The install
        // must still complete — the Credentials screen keeps the manual
        // fallback for exactly this case.
        Factory::$dbo = null;

        (new \com_mcpserverInstallerScript())->postflight('install', new InstallerAdapter());

        $this->assertCount(1, $this->app->messages);
        $this->assertSame('warning', $this->app->messages[0][1]);
    }

    public function testInstallerBindsTheComponentNamespaceBeforeUsingIt(): void
    {
        \JLoader::$registeredNamespaces = [];
        $db = new FakeInstallerDatabase('{}');

        $this->runPostflight($db);

        $this->assertNotSame(
            [],
            \JLoader::$registeredNamespaces,
            'The component namespace must be bound explicitly; the namespace map may not list it yet during its own install.'
        );
        $this->assertSame(
            'Joomla\\Component\\Mcpserver\\Administrator',
            \JLoader::$registeredNamespaces[0][0]
        );
    }
}
