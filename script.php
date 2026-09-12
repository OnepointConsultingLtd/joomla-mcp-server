<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Language\Text;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceSetupService;
use Joomla\Registry\Registry;

class com_mcpserverInstallerScript
{
    public function install(InstallerAdapter $parent): void {}
    public function uninstall(InstallerAdapter $parent): void {}
    public function update(InstallerAdapter $parent): void {}
    public function preflight(string $type, InstallerAdapter $parent): void {}

    /**
     * Provision the Governed Mode credential salt so no operator has to press a
     * button to generate a random number. Runs after the component's files and
     * SQL are in place, which is both the earliest point the credential table
     * can be counted and a naturally serialised one — deriving the salt lazily
     * on first use would let two concurrent requests each mint one, the second
     * write winning and silently orphaning anything encrypted under the first.
     */
    public function postflight(string $type, InstallerAdapter $parent): void
    {
        $this->provisionCredentialSalt();
    }

    /**
     * Delegates every decision to GovernanceSetupService::enable(), which keeps
     * an existing valid salt, refuses to replace an unreadable one, and refuses
     * to mint a first salt while credentials already exist (that state means the
     * salt was lost, and a fresh one cannot decrypt them). This method only
     * supplies the storage adapters and makes sure a refusal is visible.
     *
     * Never lets the installation fail: if provisioning cannot run here, the
     * Credentials screen still offers the manual fallback, and the refusal
     * message explains which of the cases above applies.
     */
    private function provisionCredentialSalt(): void
    {
        try {
            // The component's namespace is not necessarily registered yet during
            // its own installation, so bind it explicitly rather than relying on
            // the compiled namespace map being rebuilt before postflight runs.
            \JLoader::registerNamespace(
                'Joomla\\Component\\Mcpserver\\Administrator',
                JPATH_ADMINISTRATOR . '/components/com_mcpserver/src',
                false,
                false,
                'psr4'
            );

            $db = Factory::getDbo();

            $service = new GovernanceSetupService(
                static function () use ($db): array {
                    $query = $db->getQuery(true)
                        ->select($db->quoteName('params'))
                        ->from($db->quoteName('#__extensions'))
                        ->where($db->quoteName('element') . ' = ' . $db->quote('com_mcpserver'))
                        ->where($db->quoteName('type') . ' = ' . $db->quote('component'));
                    $stored = $db->setQuery($query)->loadResult();

                    // An unreadable row must stay distinguishable from a readable
                    // one: enable() refuses to persist when governed_mode is
                    // absent, so returning [] here fails closed instead of
                    // writing governed_mode = 0 over a site that had it on.
                    if ($stored === null) {
                        return [];
                    }

                    $params = (new Registry((string) $stored))->toArray();

                    // A component that has never had its options saved has no
                    // governed_mode key at all. That is a legitimate "off", not
                    // the failed read the guard above is watching for.
                    return $params + ['governed_mode' => 0];
                },
                static function (array $values) use ($db): void {
                    $query = $db->getQuery(true)
                        ->select($db->quoteName('params'))
                        ->from($db->quoteName('#__extensions'))
                        ->where($db->quoteName('element') . ' = ' . $db->quote('com_mcpserver'))
                        ->where($db->quoteName('type') . ' = ' . $db->quote('component'));
                    $params = new Registry((string) $db->setQuery($query)->loadResult());

                    foreach ($values as $key => $value) {
                        $params->set($key, $value);
                    }

                    $update = $db->getQuery(true)
                        ->update($db->quoteName('#__extensions'))
                        ->set($db->quoteName('params') . ' = ' . $db->quote((string) $params))
                        ->where($db->quoteName('element') . ' = ' . $db->quote('com_mcpserver'))
                        ->where($db->quoteName('type') . ' = ' . $db->quote('component'));
                    $db->setQuery($update)->execute();
                },
                static fn (): string => (string) Factory::getApplication()->get('secret', ''),
                static function () use ($db): int {
                    $query = $db->getQuery(true)
                        ->select('COUNT(*)')
                        ->from($db->quoteName('#__mcpserver_credential'));

                    return (int) $db->setQuery($query)->loadResult();
                }
            );

            $service->enable();
        } catch (\Throwable $e) {
            $this->warn($e->getMessage());
        }
    }

    /**
     * Surface a provisioning refusal in the installer output. Silence here would
     * leave an operator to discover the missing salt only when issuing a
     * credential fails, long after the cause.
     */
    private function warn(string $message): void
    {
        try {
            $app = Factory::getApplication();
            $app->getLanguage()->load('com_mcpserver.sys', JPATH_ADMINISTRATOR . '/components/com_mcpserver');
            $app->enqueueMessage(Text::sprintf('COM_MCPSERVER_INSTALL_SALT_FAILED', $message), 'warning');
        } catch (\Throwable) {
            // No application to report through (CLI installs); the Credentials
            // screen still shows the manual fallback and the same explanation.
        }
    }
}
