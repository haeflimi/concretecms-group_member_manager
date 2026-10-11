<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var array<string, string> $entityOptions handle => name
 * @var array<int, string> $groupOptions
 * @var int[] $selectedGroupIDs
 * @var string $entityHandle
 * @var string $recipients
 * @var string $submitLabel
 */
?>
<div class="form-group">
    <label class="form-label" for="entityHandle"><?= t('Express form the applications are stored in') ?></label>
    <select class="form-select" name="entityHandle" id="entityHandle">
        <?php foreach ($entityOptions as $handle => $name) { ?>
            <option value="<?= h($handle) ?>"<?= $handle === $entityHandle ? ' selected' : '' ?>><?= h($name) ?></option>
        <?php } ?>
    </select>
    <div class="form-text"><?= t('Fields are matched by handle: nickname, vorname, name_2, e_mail, discord_username, handynr, wohnort, woher_kennst_du_uns, was_motiviert_dich_uns_beizutreten, bemerkungen_sonstiges.') ?></div>
</div>
<div class="form-group">
    <label class="form-label" for="recipients"><?= t('Notify these e-mail addresses (comma separated)') ?></label>
    <input type="text" class="form-control" name="recipients" id="recipients" value="<?= h($recipients) ?>">
</div>
<div class="form-group">
    <label class="form-label" for="submitLabel"><?= t('Button label') ?></label>
    <input type="text" class="form-control" name="submitLabel" id="submitLabel" value="<?= h($submitLabel) ?>" placeholder="<?= h(t('Send application')) ?>">
</div>
<p class="text-muted small mb-1"><?= t('Members of these groups see "You are already in" instead of the form') ?></p>
<div style="max-height: 220px; overflow: auto">
    <?php foreach ($groupOptions as $id => $name) { ?>
        <div class="form-check">
            <input type="checkbox" class="form-check-input" name="memberGroupIDs[]" id="memberGroupIDs<?= (int) $id ?>" value="<?= (int) $id ?>"<?= in_array((int) $id, $selectedGroupIDs, true) ? ' checked' : '' ?>>
            <label class="form-check-label" for="memberGroupIDs<?= (int) $id ?>"><?= h($name) ?></label>
        </div>
    <?php } ?>
</div>
