<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Plain list; themes override this view to render cards.
 *
 * @var array<int, array<string, mixed>> $people
 */
?>
<div class="gmm-member-list">
    <?php if ($people === []) { ?>
        <p class="text-muted"><?= t('Nobody here yet.') ?></p>
    <?php } else { ?>
        <ul class="list-unstyled">
            <?php foreach ($people as $p) { ?>
                <li class="mb-1"><span class="fw-semibold"><?= h($p['nick']) ?></span><?= $p['role'] !== '' ? ' <span class="text-muted">· ' . h($p['role']) . '</span>' : '' ?></li>
            <?php } ?>
        </ul>
    <?php } ?>
</div>
