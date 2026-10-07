<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var array<int, string> $groupOptions
 * @var int[] $selectedFilterIDs
 * @var int $groupID
 * @var string $mode
 * @var int $newDays
 * @var bool|int $showStats
 * @var int $itemLimit
 */
?>
<div class="form-group">
    <label class="form-label" for="groupID"><?= t('Group') ?></label>
    <select class="form-select" name="groupID" id="groupID">
        <?php foreach ($groupOptions as $id => $name) { ?>
            <option value="<?= (int) $id ?>"<?= (int) $groupID === (int) $id ? ' selected' : '' ?>><?= h($name) ?></option>
        <?php } ?>
    </select>
    <div class="form-text"><?= t('Direct members of the group.') ?></div>
</div>
<div class="form-group">
    <label class="form-label" for="mode"><?= t('Display') ?></label>
    <select class="form-select" name="mode" id="mode">
        <option value="cards"<?= $mode !== 'directory' ? ' selected' : '' ?>><?= t('Cards with facts (board, team)') ?></option>
        <option value="directory"<?= $mode === 'directory' ? ' selected' : '' ?>><?= t('Directory with search and filters') ?></option>
    </select>
</div>
<div class="form-group">
    <label class="form-label" for="itemLimit"><?= t('Maximum number of people (0 = all)') ?></label>
    <input type="number" class="form-control" name="itemLimit" id="itemLimit" min="0" max="500" value="<?= (int) $itemLimit ?>">
</div>
<div class="form-check">
    <input type="checkbox" class="form-check-input" name="showStats" id="showStats" value="1"<?= $showStats ? ' checked' : '' ?>>
    <label class="form-check-label" for="showStats"><?= t('Show events attended and member since') ?></label>
</div>
<div class="form-group mt-3">
    <label class="form-label" for="newDays"><?= t('"New" filter: joined in the last … days (0 = no filter)') ?></label>
    <input type="number" class="form-control" name="newDays" id="newDays" min="0" max="3650" value="<?= (int) $newDays ?>">
</div>
<p class="text-muted small mb-1"><?= t('More filters: members that are also in these groups (directory)') ?></p>
<?php foreach ($groupOptions as $id => $name) {
    if ($id === 0) {
        continue;
    } ?>
    <div class="form-check">
        <input type="checkbox" class="form-check-input" name="filterGroupIDs[]" id="filterGroupIDs<?= (int) $id ?>" value="<?= (int) $id ?>"<?= in_array((int) $id, $selectedFilterIDs, true) ? ' checked' : '' ?>>
        <label class="form-check-label" for="filterGroupIDs<?= (int) $id ?>"><?= h($name) ?></label>
    </div>
<?php } ?>
