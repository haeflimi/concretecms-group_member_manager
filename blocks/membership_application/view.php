<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var int $bID
 * @var string $state guest | member | pending | form
 * @var DateTime|null $appliedAt
 * @var array $profile nickname, first_name, last_name, email, phone, place, discord
 * @var string[] $sourceOptions
 * @var array $flashes
 * @var array $old
 * @var string $registerUrl
 * @var string $loginUrl
 * @var string $profileUrl
 * @var string $actionUrl
 * @var Concrete\Core\Validation\CSRF\Token $token
 * @var string $tokenName
 * @var string $buttonLabel
 */

$date = app('date');
?>
<div class="gmm-application" id="gmm-application-<?= (int) $bID ?>">
    <?php foreach ($flashes as $flash) { ?>
        <div class="alert alert-<?= h($flash['type']) ?>" role="status"><?= h($flash['message']) ?></div>
    <?php } ?>

    <?php if ($state === 'guest') { ?>
        <p><?= t('To apply you need an account on this website – you need one for the LAN anyway. Your details from the account are filled in automatically.') ?></p>
        <p>
            <a class="btn btn-primary" href="<?= h($registerUrl) ?>"><?= t('Create an account') ?></a>
            <a class="btn btn-secondary" href="<?= h($loginUrl) ?>"><?= t('Sign in') ?></a>
        </p>
    <?php } elseif ($state === 'member') { ?>
        <p><?= t('You are already in – welcome to the club, %s!', h($profile['nickname'])) ?></p>
    <?php } elseif ($state === 'pending') { ?>
        <p><?= t('Your application from %s is in. The board will get back to you.', h($date->formatDate($appliedAt))) ?></p>
    <?php } else { ?>
        <form method="post" action="<?= h($actionUrl) ?>">
            <?php $token->output($tokenName) ?>
            <dl>
                <dt><?= t('Nickname') ?></dt><dd><?= h($profile['nickname']) ?></dd>
                <dt><?= t('Name') ?></dt><dd><?= h(trim($profile['first_name'] . ' ' . $profile['last_name'])) ?></dd>
                <dt><?= t('E-mail') ?></dt><dd><?= h($profile['email']) ?></dd>
                <?php foreach (['phone' => t('Phone'), 'place' => t('Place'), 'discord' => t('Discord')] as $field => $label) { ?>
                    <dt><?= $label ?></dt>
                    <dd><?php if ($profile[$field] !== '') { echo h($profile[$field]); } else { ?><input type="text" class="form-control" name="<?= $field ?>" value="<?= h($old[$field] ?? '') ?>"><?php } ?></dd>
                <?php } ?>
            </dl>
            <p class="small"><a href="<?= h($profileUrl) ?>"><?= t('Something wrong? Change it in your profile.') ?></a></p>
            <?php if ($sourceOptions) { ?>
                <fieldset class="mb-3">
                    <legend class="form-label"><?= t('How do you know us?') ?></legend>
                    <?php foreach ($sourceOptions as $i => $option) { ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="sources[]" id="gmm-source-<?= (int) $bID ?>-<?= $i ?>" value="<?= h($option) ?>"<?= in_array($option, (array) ($old['sources'] ?? []), true) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="gmm-source-<?= (int) $bID ?>-<?= $i ?>"><?= h($option) ?></label>
                        </div>
                    <?php } ?>
                </fieldset>
            <?php } ?>
            <div class="mb-3">
                <label class="form-label" for="gmm-motivation-<?= (int) $bID ?>"><?= t('What makes you want to join?') ?></label>
                <textarea class="form-control" name="motivation" id="gmm-motivation-<?= (int) $bID ?>" rows="4" required><?= h($old['motivation'] ?? '') ?></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label" for="gmm-remarks-<?= (int) $bID ?>"><?= t('Anything else?') ?></label>
                <textarea class="form-control" name="remarks" id="gmm-remarks-<?= (int) $bID ?>" rows="3"><?= h($old['remarks'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><?= h($buttonLabel) ?></button>
        </form>
    <?php } ?>
</div>
