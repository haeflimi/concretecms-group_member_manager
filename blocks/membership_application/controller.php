<?php

namespace Concrete\Package\GroupMemberManager\Block\MembershipApplication;

use Concrete\Core\Block\BlockController;
use Concrete\Core\Entity\Express\Entity;
use Concrete\Core\Entity\Express\Entry;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Http\ResponseFactoryInterface;
use Concrete\Core\Page\Page;
use Concrete\Core\User\Group\Group;
use Concrete\Core\User\Group\GroupList;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfo;
use Concrete\Core\Validation\CSRF\Token;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Membership application for signed-in users: what the account knows (username, names, e-mail, phone, place,
 * Discord) is shown instead of asked, only the questions and missing details are filled in. The application is stored
 * as an entry of an Express form (results in Dashboard › Reports › Forms, author = the user) and mailed to the
 * recipients with the form block's mail template. Guests are asked to create an account or sign in and come back.
 * Nothing happens to groups: the board appoints the applicant.
 */
class Controller extends BlockController
{
    public const TOKEN = 'gmm_membership_application';

    /** handles in the Express entity → what goes in */
    public const FIELDS = [
        'nickname' => 'nickname',
        'first_name' => 'vorname',
        'last_name' => 'name_2',
        'email' => 'e_mail',
        'discord' => 'discord_username',
        'phone' => 'handynr',
        'place' => 'wohnort',
        'sources' => 'woher_kennst_du_uns',
        'motivation' => 'was_motiviert_dich_uns_beizutreten',
        'remarks' => 'bemerkungen_sonstiges',
    ];

    /** an application within this many days counts as open (until the user is in a member group) */
    public const OPEN_DAYS = 365;

    protected $btTable = 'btGmmMembershipApplication';
    protected $btInterfaceWidth = 560;
    protected $btInterfaceHeight = 520;
    protected $btDefaultSet = 'form';
    protected $btCacheBlockOutput = false;
    protected $btCacheBlockRecord = true;

    /** @var string */
    public $entityHandle;
    /** @var string|null comma separated */
    public $recipients;
    /** @var string|null comma separated group IDs */
    public $memberGroupIDs;
    /** @var string|null */
    public $submitLabel;

    public function getBlockTypeName()
    {
        return t('Membership Application');
    }

    public function getBlockTypeDescription()
    {
        return t('Signed-in users apply for membership; their account details are filled in.');
    }

    public function add()
    {
        $this->set('entityHandle', 'join');
        $this->set('recipients', '');
        $this->set('submitLabel', '');
        $this->setFormData();
    }

    public function edit()
    {
        $this->setFormData();
    }

    public function save($args)
    {
        $groups = array_filter(array_map('intval', (array) ($args['memberGroupIDs'] ?? [])));
        parent::save([
            'entityHandle' => trim((string) ($args['entityHandle'] ?? 'join')),
            'recipients' => trim((string) ($args['recipients'] ?? '')),
            'memberGroupIDs' => implode(',', $groups),
            'submitLabel' => trim((string) ($args['submitLabel'] ?? '')),
        ]);
    }

    public function view()
    {
        $page = Page::getCurrentPage();
        $pageUrl = $page && !$page->isError() ? (string) $page->getCollectionLink() : '';
        $url = $this->app->make('url/manager');
        $flash = $this->app->make('session')->getFlashBag();
        $this->set('flashes', $flash->get('gmm.application.' . $this->bID));
        // the answers of a submission that failed validation
        $old = $flash->get('gmm.application.' . $this->bID . '.old');
        $this->set('old', is_array($old[0] ?? null) ? $old[0] : []);
        // register/forward stores rcURL as the post-login location: after validating the e-mail and signing in the
        // applicant lands here again
        $this->set('registerUrl', (string) $url->resolve($page && !$page->isError() ? ['/register/forward', $page->getCollectionID()] : ['/register']) . '?rcURL=' . rawurlencode($pageUrl . '#beitreten'));
        $this->set('loginUrl', $page && !$page->isError() ? (string) $url->resolve(['/login/forward', $page->getCollectionID()]) : (string) $url->resolve(['/login']));
        $this->set('profileUrl', (string) $url->resolve(['/account/edit_profile']));
        $this->set('actionUrl', (string) $this->getActionURL('submit'));
        $this->set('token', $this->app->make(Token::class));
        $this->set('tokenName', self::TOKEN);
        $this->set('buttonLabel', $this->submitLabel ?: t('Send application'));

        $user = $this->currentUser();
        $state = 'guest';
        $appliedAt = null;
        $profile = [];
        if ($user) {
            $profile = $this->profile($user);
            if ($this->isMember($user)) {
                $state = 'member';
            } elseif ($appliedAt = $this->openApplication($user)) {
                $state = 'pending';
            } else {
                $state = 'form';
            }
        }
        $this->set('state', $state);
        $this->set('appliedAt', $appliedAt);
        $this->set('profile', $profile);
        $this->set('sourceOptions', $this->sourceOptions());
    }

    public function action_submit($bID = false)
    {
        if ((int) $bID !== (int) $this->bID) {
            return false;
        }
        $session = $this->app->make('session');
        $key = 'gmm.application.' . $this->bID;
        try {
            if (!$this->request->isMethod('POST') || !$this->app->make(Token::class)->validate(self::TOKEN)) {
                throw new UserMessageException(t('Invalid request, please try again.'));
            }
            $user = $this->currentUser();
            if ($user === null) {
                throw new UserMessageException(t('Please sign in first.'));
            }
            if ($this->isMember($user) || $this->openApplication($user)) {
                throw new UserMessageException(t('You have already applied.'));
            }
            $post = $this->request->request;
            $sources = array_values(array_intersect((array) $post->all('sources'), $this->sourceOptions()));
            $motivation = trim((string) $post->get('motivation'));
            if ($this->sourceOptions() && $sources === []) {
                throw new UserMessageException(t('Please tell us how you know us.'));
            }
            if ($motivation === '') {
                throw new UserMessageException(t('Please tell us what makes you want to join.'));
            }
            $profile = $this->profile($user);
            // what the account doesn't know may be given here
            foreach (['phone', 'discord', 'place'] as $field) {
                if ($profile[$field] === '') {
                    $profile[$field] = mb_substr(trim((string) $post->get($field)), 0, 255);
                }
            }
            $entry = $this->storeEntry($profile + ['sources' => $sources, 'motivation' => $motivation, 'remarks' => trim((string) $post->get('remarks'))]);
            try {
                $this->notify($entry, $profile);
            } catch (\Throwable $e) {
                // the application is stored (Reports › Forms) even when the mail can't go out
                $this->app->make('log')->error('Membership application ' . $entry->getID() . ': notification failed: ' . $e->getMessage());
            }
            $session->getFlashBag()->add($key, ['type' => 'success', 'message' => t('Thanks, %s! Your application is in. The board will get back to you.', $profile['first_name'] ?: $profile['nickname'])]);
        } catch (UserMessageException $e) {
            $session->getFlashBag()->add($key, ['type' => 'danger', 'message' => $e->getMessage()]);
            $session->getFlashBag()->add($key . '.old', $this->request->request->all());
        }
        $page = Page::getCurrentPage();
        $target = $page && !$page->isError() ? (string) $page->getCollectionLink() : (string) $this->app->make('url/manager')->resolve(['/']);

        return $this->app->make(ResponseFactoryInterface::class)->redirect($target . '#beitreten');
    }

    /**
     * What the account knows. Missing values are ''.
     *
     * @return array{nickname: string, first_name: string, last_name: string, email: string, phone: string, place: string, discord: string}
     */
    public function profile(User $user): array
    {
        $ui = $user->getUserInfoObject();
        $text = static fn ($v) => trim(is_object($v) && method_exists($v, '__toString') ? (string) $v : (is_scalar($v) ? (string) $v : ''));
        $address = $ui instanceof UserInfo ? $ui->getAttribute('billing_address') : null;
        $discord = '';
        if (class_exists(\DiscordConnect\DiscordAccounts::class)) {
            try {
                $profile = $this->app->make(\DiscordConnect\DiscordAccounts::class)->getProfile($user);
                $discord = $profile && method_exists($profile, 'getUsername') ? (string) $profile->getUsername() : '';
            } catch (\Throwable $e) {
                $discord = '';
            }
        }

        return [
            'nickname' => (string) $user->getUserName(),
            'first_name' => $ui ? $text($ui->getAttribute('billing_first_name')) : '',
            'last_name' => $ui ? $text($ui->getAttribute('billing_last_name')) : '',
            'email' => $ui ? (string) $ui->getUserEmail() : '',
            'phone' => $ui ? $text($ui->getAttribute('user_phone')) : '',
            'place' => is_object($address) && method_exists($address, 'getCity') ? trim((string) $address->getCity()) : '',
            'discord' => $discord,
        ];
    }

    /**
     * Option labels of the "how do you know us" select, in their order.
     *
     * @return string[]
     */
    public function sourceOptions(): array
    {
        $key = $this->attributeKey(self::FIELDS['sources']);
        $settings = $key ? $key->getAttributeKeySettings() : null;
        if (!$settings || !method_exists($settings, 'getOptionList') || !$settings->getOptionList()) {
            return [];
        }
        $options = [];
        foreach ($settings->getOptionList()->getOptions() as $option) {
            if (!$option->isEndUserAdded() && !$option->isOptionDeleted()) {
                $options[] = (string) $option->getSelectAttributeOptionValue();
            }
        }

        return $options;
    }

    protected function currentUser(): ?User
    {
        $user = $this->app->make(User::class);

        return $user->isRegistered() ? $user : null;
    }

    protected function isMember(User $user): bool
    {
        foreach (array_filter(array_map('intval', explode(',', (string) $this->memberGroupIDs))) as $gID) {
            $group = Group::getByID($gID);
            if ($group && $user->inGroup($group)) {
                return true;
            }
        }

        return false;
    }

    /** date of the user's application within OPEN_DAYS, or null */
    protected function openApplication(User $user): ?DateTime
    {
        $entity = $this->entity();
        if (!$entity) {
            return null;
        }
        $date = $this->app->make('database')->connection()->fetchOne(
            'SELECT MAX(exEntryDateCreated) FROM ExpressEntityEntries WHERE exEntryEntityID = ? AND uID = ? AND exEntryDateCreated > ?',
            [$entity->getID(), (int) $user->getUserID(), (new DateTime('-' . self::OPEN_DAYS . ' days'))->format('Y-m-d H:i:s')]
        );

        return $date ? new DateTime($date) : null;
    }

    protected function entity(): ?Entity
    {
        return $this->app->make(EntityManagerInterface::class)->getRepository(Entity::class)->findOneBy(['handle' => (string) ($this->entityHandle ?: 'join')]);
    }

    protected function attributeKey(string $handle)
    {
        $entity = $this->entity();

        return $entity ? $entity->getAttributeKeyCategory()->getAttributeKeyByHandle($handle) : null;
    }

    protected function storeEntry(array $data): Entry
    {
        $entity = $this->entity();
        if (!$entity) {
            throw new UserMessageException(t('The application form is not set up. Please contact the board.'));
        }
        $builder = $this->app->make('express')->buildEntry($entity);
        foreach (self::FIELDS as $field => $handle) {
            $value = $data[$field] ?? null;
            if ($value === null || $value === '' || $value === [] || !$this->attributeKey($handle)) {
                continue;
            }
            $builder->setAttribute($handle, $value);
        }

        return $builder->save();
    }

    protected function notify(Entry $entry, array $profile): void
    {
        $to = array_filter(array_map('trim', explode(',', (string) $this->recipients)));
        if ($to === []) {
            return;
        }
        $config = $this->app->make('config');
        $entity = $entry->getEntity();
        $mh = $this->app->make('mail');
        foreach ($to as $address) {
            $mh->to($address);
        }
        $mh->from((string) ($config->get('concrete.email.form_block.address') ?: $config->get('concrete.email.default.address')), (string) $config->get('concrete.email.default.name'));
        if ($profile['email'] !== '') {
            $mh->replyto($profile['email']);
        }
        $mh->addParameter('entity', $entity);
        $mh->addParameter('formName', $entity->getName());
        $mh->addParameter('dataSaveEnabled', true);
        $mh->addParameter('attributes', $entity->getAttributeKeyCategory()->getAttributeValues($entry));
        $mh->addParameter('associations', []);
        $mh->load('block_express_form_submission');
        $mh->setSubject(t('Membership application: %s', $profile['nickname']));
        $mh->sendMail();
    }

    protected function setFormData(): void
    {
        $entities = [];
        foreach ($this->app->make(EntityManagerInterface::class)->getRepository(Entity::class)->findAll() as $entity) {
            $entities[(string) $entity->getHandle()] = (string) $entity->getName();
        }
        $this->set('entityOptions', $entities);
        $groups = [];
        $list = new GroupList();
        $list->includeAllGroups();
        foreach ($list->getResults() as $group) {
            if (!in_array((int) $group->getGroupID(), [GUEST_GROUP_ID, REGISTERED_GROUP_ID], true)) {
                $groups[(int) $group->getGroupID()] = $group->getGroupDisplayName(false);
            }
        }
        $this->set('groupOptions', $groups);
        $this->set('selectedGroupIDs', array_filter(array_map('intval', explode(',', (string) $this->memberGroupIDs))));
    }
}
