<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

/**
 * An OAuth protocol error (RFC 6749 §4.1.2.1, §5.2; RFC 7591 §3.2.2).
 *
 * An error carries a $redirectUri only when it may be returned to the client
 * there, which is once the client and its redirect_uri have been validated.
 * Before that it must be shown to the user instead: redirecting would turn the
 * authorisation endpoint into an open redirector (RFC 6749 §4.1.2.1).
 */
final class OAuthError extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly string $description,
        public readonly int $httpStatus = 400,
        public readonly ?string $redirectUri = null,
        public readonly ?string $state = null,
    ) {
        parent::__construct($error . ': ' . $description);
    }

    public function isRedirectable(): bool
    {
        return $this->redirectUri !== null;
    }

    /**
     * @return array{error:string,error_description:string}
     */
    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->description];
    }
}
