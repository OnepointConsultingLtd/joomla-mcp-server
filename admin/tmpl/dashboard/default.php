<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Joomla\Component\Mcpserver\Administrator\View\Dashboard\HtmlView $this */

$summary = $this->summary;

// Largest daily count drives the chart's vertical scale.
$maxDay = 0;
foreach ($this->perDay as $point) {
    $maxDay = max($maxDay, (int) $point['count']);
}

$statusBadge = static function (string $status): string {
    switch ($status) {
        case 'ok':
            return 'bg-success';
        case 'error':
            return 'bg-danger';
        case 'blocked':
            return 'bg-dark';
        case 'rate_limited':
            return 'bg-warning text-dark';
        case 'auth_failed':
            return 'bg-secondary';
        case 'invalid_request':
            return 'bg-info text-dark';
        default:
            return 'bg-light text-dark';
    }
};

// A restricted viewer sees only their own rows, so the generic "no requests
// recorded yet" would assert something about the whole site that this page
// never queried.
$noDataText = $this->scopeUserId === null
    ? Text::_('COM_MCPSERVER_DASHBOARD_NO_DATA')
    : Text::_('COM_MCPSERVER_DASHBOARD_NO_DATA_OWN');

// Tool Name filter choices, grouped so a filter that returns nothing explains
// itself: the column holds prompt names as well as tool names, a disabled
// entry can still have older rows, and an unregistered one is a name this
// build no longer knows. A lone "available" group carries no information, so
// it renders as a flat list rather than a one-label optgroup.
$toolOptions = $this->toolFilterOptions;
$toolGroupsAreMixed = $toolOptions['prompts'] !== []
    || $toolOptions['disabled'] !== []
    || $toolOptions['unregistered'] !== [];

$toolFilterGroups = [];

if ($toolOptions['available'] !== []) {
    $toolFilterGroups[] = [
        'label' => $toolGroupsAreMixed ? Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_TOOL_AVAILABLE') : null,
        'names' => $toolOptions['available'],
    ];
}

if ($toolOptions['prompts'] !== []) {
    $toolFilterGroups[] = [
        'label' => Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_TOOL_PROMPTS'),
        'names' => $toolOptions['prompts'],
    ];
}

if ($toolOptions['disabled'] !== []) {
    $toolFilterGroups[] = [
        'label' => Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_TOOL_DISABLED'),
        'names' => $toolOptions['disabled'],
    ];
}

if ($toolOptions['unregistered'] !== []) {
    $toolFilterGroups[] = [
        'label' => Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_TOOL_UNREGISTERED'),
        'names' => $toolOptions['unregistered'],
    ];
}

// How an account is named in the User filter and the User column. The audit
// trail's job is attribution, so an account deleted from Joomla since the row
// was written must still be identifiable: fall back to its id rather than an
// anonymous "Unavailable", which would lose the only identifier left.
$auditUserLabel = static function (array $user): string {
    $name = $user['user_name'] ?? null;

    return $name !== null && $name !== ''
        ? $name
        : Text::sprintf('COM_MCPSERVER_GOVERNANCE_AUDIT_USER_DELETED', (int) $user['user_id']);
};

$toolOptionTag = static function (string $name, ?string $selected): string {
    return '<option value="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"'
        . ($name === $selected ? ' selected' : '') . '>'
        . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
        . '</option>';
};

$cards = [
    ['label' => Text::_('COM_MCPSERVER_DASHBOARD_CARD_TOTAL'),        'value' => number_format((int) $summary['total'])],
    ['label' => Text::_('COM_MCPSERVER_DASHBOARD_CARD_24H'),          'value' => number_format((int) $summary['last_24h'])],
    ['label' => Text::_('COM_MCPSERVER_DASHBOARD_CARD_7D'),           'value' => number_format((int) $summary['last_7d'])],
    ['label' => Text::_('COM_MCPSERVER_DASHBOARD_CARD_ERROR_RATE'),   'value' => (float) $summary['error_rate'] . '%'],
    ['label' => Text::_('COM_MCPSERVER_DASHBOARD_CARD_AVG_LATENCY'),  'value' => number_format((float) $summary['avg_latency_ms'], 1)],
    ['label' => Text::_('COM_MCPSERVER_DASHBOARD_CARD_RATE_LIMITED'), 'value' => number_format((int) $summary['rate_limited'])],
    ['label' => Text::_('COM_MCPSERVER_DASHBOARD_CARD_AUTH_FAILED'),  'value' => number_format((int) $summary['auth_failed'])],
];
?>
<div class="container-fluid py-3">

    <div class="card border-warning shadow-sm mb-4" id="mcpserverStarBanner" hidden>
        <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="icon-star text-warning fs-2" aria-hidden="true"></span>
                <div>
                    <h5 class="mb-1"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_STAR_TITLE'); ?></h5>
                    <p class="mb-0 text-muted"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_STAR_TEXT'); ?></p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-warning text-nowrap" href="https://github.com/OnepointConsultingLtd/joomla-mcp-server" target="_blank" rel="noopener noreferrer">
                    <span class="icon-star" aria-hidden="true"></span>
                    <?php echo Text::_('COM_MCPSERVER_DASHBOARD_STAR_BUTTON'); ?>
                </a>
                <button type="button" class="btn btn-secondary text-nowrap" id="mcpserverStarDismiss">
                    <?php echo Text::_('COM_MCPSERVER_DASHBOARD_STAR_DISMISS'); ?>
                </button>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var key = 'mcpserver.starBannerDismissed';
            var banner = document.getElementById('mcpserverStarBanner');
            if (!banner) {
                return;
            }
            try {
                if (window.localStorage.getItem(key) !== '1') {
                    banner.hidden = false;
                }
            } catch (e) {
                banner.hidden = false;
            }
            document.getElementById('mcpserverStarDismiss').addEventListener('click', function () {
                banner.hidden = true;
                try {
                    window.localStorage.setItem(key, '1');
                } catch (e) {}
            });
        })();
    </script>

    <?php if (!$this->metricsEnabled) : ?>
        <div class="alert alert-warning d-flex justify-content-between align-items-center" role="alert">
            <span><?php echo Text::_('COM_MCPSERVER_DASHBOARD_METRICS_DISABLED'); ?></span>
            <a class="btn btn-sm btn-primary" href="index.php?option=com_config&amp;view=component&amp;component=com_mcpserver">
                <span class="icon-options" aria-hidden="true"></span>
                <?php echo Text::_('JOPTIONS'); ?>
            </a>
        </div>
    <?php endif; ?>

    <?php if ($this->scopeUserId !== null) : ?>
        <div class="alert alert-info" role="alert">
            <?php echo Text::_('COM_MCPSERVER_DASHBOARD_SCOPE_OWN_ONLY'); ?>
        </div>
    <?php endif; ?>

    <div class="row row-cols-2 row-cols-md-4 row-cols-xl-7 g-3 mb-4">
        <?php foreach ($cards as $card) : ?>
            <div class="col">
                <div class="card shadow-sm h-100 text-center">
                    <div class="card-body py-3">
                        <div class="fs-3 fw-bold"><?php echo htmlspecialchars((string) $card['value']); ?></div>
                        <div class="text-muted small"><?php echo htmlspecialchars($card['label']); ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header">
            <h5 class="mb-0"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_REQUESTS_PER_DAY'); ?></h5>
        </div>
        <div class="card-body">
            <?php if ($maxDay === 0) : ?>
                <p class="text-muted mb-0"><?php echo $noDataText; ?></p>
            <?php else : ?>
                <div class="d-flex align-items-end justify-content-between" style="height: 180px; gap: 4px;">
                    <?php foreach ($this->perDay as $point) :
                        $count   = (int) $point['count'];
                        $errors  = (int) $point['errors'];
                        $ok      = max(0, $count - $errors);
                        $heightPct = $maxDay > 0 ? ($count / $maxDay) * 100 : 0;
                        $okPct     = $count > 0 ? ($ok / $count) * 100 : 0;
                        $errPct    = $count > 0 ? ($errors / $count) * 100 : 0;
                        $title     = $point['day'] . ': ' . $count . ' (' . $errors . ' err)';
                        ?>
                        <div class="d-flex flex-column justify-content-end align-items-center" style="flex: 1 1 0; height: 100%;" title="<?php echo htmlspecialchars($title); ?>">
                            <div class="w-100 d-flex flex-column justify-content-end" style="height: <?php echo round($heightPct, 2); ?>%; min-height: 2px;">
                                <?php if ($errors > 0) : ?>
                                    <div class="bg-danger" style="height: <?php echo round($errPct, 2); ?>%;"></div>
                                <?php endif; ?>
                                <div class="bg-primary" style="height: <?php echo round($okPct, 2); ?>%;"></div>
                            </div>
                            <div class="text-muted mt-1" style="font-size: 0.65rem;"><?php echo htmlspecialchars(substr((string) $point['day'], 5)); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex gap-3 mt-3 small text-muted">
                    <span><span class="badge bg-primary">&nbsp;</span> <?php echo Text::_('COM_MCPSERVER_DASHBOARD_LEGEND_OK'); ?></span>
                    <span><span class="badge bg-danger">&nbsp;</span> <?php echo Text::_('COM_MCPSERVER_DASHBOARD_LEGEND_ERRORS'); ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header"><h5 class="mb-0"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_TOP_TOOLS'); ?></h5></div>
                <div class="card-body p-0">
                    <?php if (empty($this->topTools)) : ?>
                        <p class="text-muted m-3"><?php echo $noDataText; ?></p>
                    <?php else : ?>
                        <table class="table table-hover mb-0">
                            <thead><tr><th class="ps-3"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COL_TOOL'); ?></th><th class="text-end pe-3"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COUNT'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($this->topTools as $row) : ?>
                                <tr>
                                    <td class="ps-3"><code><?php echo htmlspecialchars((string) $row['tool_name']); ?></code></td>
                                    <td class="text-end pe-3"><?php echo number_format((int) $row['count']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header"><h5 class="mb-0"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_TOP_METHODS'); ?></h5></div>
                <div class="card-body p-0">
                    <?php if (empty($this->topMethods)) : ?>
                        <p class="text-muted m-3"><?php echo $noDataText; ?></p>
                    <?php else : ?>
                        <table class="table table-hover mb-0">
                            <thead><tr><th class="ps-3"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COL_METHOD'); ?></th><th class="text-end pe-3"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COUNT'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($this->topMethods as $row) : ?>
                                <tr>
                                    <td class="ps-3"><code><?php echo htmlspecialchars((string) $row['method']); ?></code></td>
                                    <td class="text-end pe-3"><?php echo number_format((int) $row['count']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header"><h5 class="mb-0"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_RECENT'); ?></h5></div>
        <div class="card-body p-0">

            <?php if ($this->canViewAudit) : ?>
                <div class="p-3 pb-0">
                    <p class="text-muted small"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_DESC'); ?></p>
                    <form action="index.php?option=com_mcpserver&amp;view=dashboard" method="get" class="row gy-2 gx-2 align-items-end mb-3">
                        <input type="hidden" name="option" value="com_mcpserver">
                        <input type="hidden" name="view" value="dashboard">
                        <?php // Only a Super User can filter by user: everyone else is already restricted to their own rows. ?>
                        <?php if ($this->scopeUserId === null) : ?>
                            <div class="col-auto">
                                <label class="form-label" for="audit_user_id"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_USER'); ?></label>
                                <?php if ($this->auditUsers === []) : ?>
                                    <?php // No attributed rows yet, or the lookup failed: keep a usable filter rather than an empty dropdown. ?>
                                    <input type="number" class="form-control" id="audit_user_id" name="audit_user_id" value="<?php echo $this->auditFilters['userId'] !== null ? (int) $this->auditFilters['userId'] : ''; ?>" min="1" style="max-width: 140px;">
                                <?php else : ?>
                                    <select class="form-select" id="audit_user_id" name="audit_user_id" style="max-width: 220px;">
                                        <option value=""><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_USER_ANY'); ?></option>
                                        <?php foreach ($this->auditUsers as $auditUser) : ?>
                                            <option value="<?php echo (int) $auditUser['user_id']; ?>"<?php echo (int) $auditUser['user_id'] === (int) $this->auditFilters['userId'] ? ' selected' : ''; ?>>
                                                <?php echo htmlspecialchars($auditUserLabel($auditUser), ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="col-auto">
                            <label class="form-label" for="audit_tool"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_TOOL'); ?></label>
                            <?php if ($toolFilterGroups === []) : ?>
                                <?php // The registry could not be read; fall back to free text rather than an empty dropdown that can filter nothing. ?>
                                <input type="text" class="form-control" id="audit_tool" name="audit_tool_name" value="<?php echo htmlspecialchars((string) $this->auditFilters['toolName'], ENT_QUOTES, 'UTF-8'); ?>" style="max-width: 200px;">
                            <?php else : ?>
                                <select class="form-select" id="audit_tool" name="audit_tool_name" style="max-width: 240px;">
                                    <option value=""><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_TOOL_ANY'); ?></option>
                                    <?php foreach ($toolFilterGroups as $group) : ?>
                                        <?php if ($group['label'] !== null) : ?>
                                            <optgroup label="<?php echo htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php endif; ?>
                                        <?php foreach ($group['names'] as $toolOptionName) : ?>
                                            <?php echo $toolOptionTag($toolOptionName, $this->auditFilters['toolName']); ?>
                                        <?php endforeach; ?>
                                        <?php if ($group['label'] !== null) : ?>
                                            </optgroup>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <label class="form-label" for="audit_date_from"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_FROM'); ?></label>
                            <input type="date" class="form-control" id="audit_date_from" name="audit_date_from" value="<?php echo htmlspecialchars((string) $this->auditFilters['dateFrom'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-auto">
                            <label class="form-label" for="audit_date_to"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_TO'); ?></label>
                            <input type="date" class="form-control" id="audit_date_to" name="audit_date_to" value="<?php echo htmlspecialchars((string) $this->auditFilters['dateTo'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-secondary"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_BUTTON'); ?></button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($this->auditError !== null) : ?>
                <div class="alert alert-danger m-3" role="alert">
                    <?php echo $this->auditError; ?>
                </div>
            <?php elseif (empty($this->recent)) : ?>
                <p class="text-muted m-3"><?php echo $noDataText; ?></p>
            <?php elseif ($this->canViewAudit) : ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-3"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COL_TIME'); ?></th>
                                <th><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COL_METHOD'); ?></th>
                                <th><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COL_TOOL'); ?></th>
                                <th><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COL_STATUS'); ?></th>
                                <th><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_COL_USER_NAME'); ?></th>
                                <th><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_SELECTOR'); ?></th>
                                <th><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_COL_TARGET'); ?></th>
                                <th class="pe-3"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COL_IP'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->recent as $row) : ?>
                                <tr>
                                    <td class="ps-3 text-nowrap"><?php echo htmlspecialchars((string) ($row['created'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><code><?php echo htmlspecialchars((string) ($row['method'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                                    <td><?php echo htmlspecialchars((string) ($row['tool_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><span class="badge <?php echo $statusBadge((string) ($row['status'] ?? '')); ?>"><?php echo htmlspecialchars((string) ($row['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td>
                                        <?php if (($row['user_id'] ?? null) === null) : ?>
                                            <?php // No principal was resolved: legacy shared-token mode, or a failure before authentication. Not the same as an unknown name. ?>
                                            <span class="text-muted" title="<?php echo htmlspecialchars(Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_USER_UNATTRIBUTED_DESC'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_USER_UNATTRIBUTED'); ?></span>
                                        <?php else : ?>
                                            <?php echo htmlspecialchars($auditUserLabel($row), ENT_QUOTES, 'UTF-8'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?php echo htmlspecialchars((string) ($row['credential_selector'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                                    <td><code><?php echo htmlspecialchars((string) ($row['target'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                                    <td class="pe-3"><?php echo htmlspecialchars((string) ($row['client_ip'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-3"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COL_TIME'); ?></th>
                                <th><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COL_CONTEXT'); ?></th>
                                <th><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COL_METHOD'); ?></th>
                                <th><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COL_TOOL'); ?></th>
                                <th><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COL_STATUS'); ?></th>
                                <th class="text-end"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COL_HTTP'); ?></th>
                                <th class="text-end"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COL_DURATION'); ?></th>
                                <th class="pe-3"><?php echo Text::_('COM_MCPSERVER_DASHBOARD_COL_IP'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($this->recent as $row) : ?>
                            <tr>
                                <td class="ps-3 text-nowrap"><?php echo htmlspecialchars(HTMLHelper::_('date', $row->created, 'Y-m-d H:i:s')); ?></td>
                                <td><?php echo htmlspecialchars((string) $row->context); ?></td>
                                <td><code><?php echo htmlspecialchars((string) $row->method); ?></code></td>
                                <td><?php echo $row->tool_name !== '' ? '<code>' . htmlspecialchars((string) $row->tool_name) . '</code>' : '<span class="text-muted">&mdash;</span>'; ?></td>
                                <td><span class="badge <?php echo $statusBadge((string) $row->status); ?>"><?php echo htmlspecialchars((string) $row->status); ?></span></td>
                                <td class="text-end"><?php echo (int) $row->http_status; ?></td>
                                <td class="text-end"><?php echo (int) $row->duration_ms; ?></td>
                                <td class="pe-3"><?php echo htmlspecialchars((string) $row->client_ip); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($this->isCoreAdmin) : ?>
                <div class="p-3 border-top">
                    <p class="text-muted small mb-2"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_PRUNE_DESC'); ?></p>
                    <form action="index.php?option=com_mcpserver&amp;task=credentials.prune" method="post" class="d-flex gap-2 align-items-end" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_PRUNE_CONFIRM')), ENT_QUOTES, 'UTF-8'); ?>);">
                        <div>
                            <label class="form-label" for="prune_retention_days"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_PRUNE_OLDER_THAN_LABEL'); ?></label>
                            <input type="number" class="form-control" id="prune_retention_days" name="prune_retention_days" value="360" min="1" max="3650" style="max-width: 160px;">
                        </div>
                        <button type="submit" class="btn btn-outline-danger"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_PRUNE_BUTTON'); ?></button>
                        <?php echo HTMLHelper::_('form.token'); ?>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
