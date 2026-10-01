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
 * A request rejected at the protocol layer — before any method runs — with the
 * JSON-RPC error and HTTP status the MCP specification prescribes for it.
 *
 * Revision 2026-07-28 ties specific HTTP statuses to these rejections (400 for
 * a missing _meta field, an unsupported version or a header mismatch), so the
 * status travels with the error rather than being re-derived from the code:
 * -32602 is a 400 here but an ordinary in-body error elsewhere.
 */
final class McpProtocolError extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        string $message,
        public readonly int $jsonRpcCode,
        public readonly array $data = [],
        public readonly int $httpStatus = 400
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponse(mixed $id): array
    {
        return JsonRpc::errorResponse($id, $this->jsonRpcCode, $this->getMessage(), $this->data);
    }
}
