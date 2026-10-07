<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var array $groups id, name, path, members, canAssign
 * @var int[] $selected
 * @var string $allocationToken
 * @var string $baseUrl
 */
?>
<div id="gma" data-base="<?= h($baseUrl) ?>" data-token="<?= h($allocationToken) ?>">
    <div class="d-flex flex-wrap align-items-end gap-3 mb-3">
        <div>
            <label class="form-label" for="gma-picker"><?= t('Groups on the board') ?></label>
            <select id="gma-picker" class="form-select" multiple size="6" aria-describedby="gma-picker-help">
                <?php foreach ($groups as $g) { ?>
                    <option value="<?= (int) $g['id'] ?>"<?= in_array($g['id'], $selected, true) ? ' selected' : '' ?>><?= h($g['path']) ?> (<?= (int) $g['members'] ?>)</option>
                <?php } ?>
            </select>
            <div id="gma-picker-help" class="form-text"><?= t('Ctrl/Cmd-click to pick several, up to 12.') ?></div>
        </div>
        <div class="flex-grow-1" style="max-width: 24rem">
            <label class="form-label" for="gma-filter"><?= t('Filter members') ?></label>
            <input type="search" id="gma-filter" class="form-control" placeholder="<?= t('Name or email') ?>">
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header fw-bold"><?= t('Find users') ?></div>
        <div class="card-body">
            <input type="search" id="gma-search" class="form-control mb-2" style="max-width: 24rem" placeholder="<?= t('Search by name or email, then drag a user onto a group') ?>" aria-label="<?= t('Search users') ?>">
            <div id="gma-results" class="d-flex flex-wrap gap-2"></div>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
        <span class="text-muted small"><i class="fas fa-info-circle"></i>
            <?= t('Drag members onto another group to move them, hold Ctrl/Alt to add them without leaving the old group. Tick several members to move them together.') ?>
        </span>
    </div>

    <div id="gma-board" class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3"></div>
    <p id="gma-empty" class="text-muted"><?= t('Pick one or more groups to start.') ?></p>
    <div id="gma-status" class="visually-hidden" role="status" aria-live="polite"></div>
    <div id="gma-toast" class="position-fixed bottom-0 end-0 p-3" style="z-index: 2000"></div>

    <script type="application/json" id="gma-i18n"><?= json_encode([
        'remove' => t('Remove from group'),
        'confirmRemove' => t('Remove %s from %s?'),
        'moveSelected' => t('Move selected to…'),
        'copySelected' => t('Add selected to…'),
        'selected' => t('selected'),
        'noMembers' => t('No members.'),
        'readOnly' => t('You may not change the members of this group.'),
        'inGroups' => t('Already in: %s'),
        'noResults' => t('No users found.'),
        'failed' => t('Request failed.'),
        'close' => t('Close'),
        'members' => t('members'),
        'selectAll' => t('Select all shown'),
    ], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</div>
