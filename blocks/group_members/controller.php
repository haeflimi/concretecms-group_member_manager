<?php

namespace Concrete\Package\GroupMemberManager\Block\GroupMembers;

use Concrete\Core\Block\BlockController;
use Concrete\Core\User\Group\GroupList;
use GroupMemberManager\Directory\MemberDirectory;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * People of a group: large cards (board, team) or a searchable directory with filters. The plain view is a list; a
 * theme renders the cards from the same variables.
 */
class Controller extends BlockController
{
    public const MODE_CARDS = 'cards';
    public const MODE_DIRECTORY = 'directory';
    /** avatars, member count and how many are new: a teaser, e.g. next to "become a member" */
    public const MODE_SUMMARY = 'summary';

    protected $btTable = 'btGmmGroupMembers';
    protected $btInterfaceWidth = 500;
    protected $btInterfaceHeight = 440;
    protected $btDefaultSet = 'social';
    protected $btCacheBlockOutput = false;
    protected $btCacheBlockRecord = true;

    /** @var int */
    public $groupID;
    /** @var string cards | directory */
    public $mode;
    /** @var string|null comma separated group IDs */
    public $filterGroupIDs;
    /** @var int */
    public $newDays;
    /** @var bool|int */
    public $showStats;
    /** @var int 0 = all */
    public $itemLimit;

    public function getBlockTypeName()
    {
        return t('Member List');
    }

    public function getBlockTypeDescription()
    {
        return t('The people of a group as cards or as searchable directory.');
    }

    public function add()
    {
        $this->set('groupID', 0);
        $this->set('mode', self::MODE_CARDS);
        $this->set('filterGroupIDs', '');
        $this->set('newDays', 60);
        $this->set('showStats', true);
        $this->set('itemLimit', 0);
        $this->setFormData();
    }

    public function edit()
    {
        $this->setFormData();
    }

    public function save($args)
    {
        parent::save([
            'groupID' => (int) ($args['groupID'] ?? 0),
            'mode' => in_array($args['mode'] ?? '', [self::MODE_DIRECTORY, self::MODE_SUMMARY], true) ? $args['mode'] : self::MODE_CARDS,
            'filterGroupIDs' => implode(',', array_filter(array_map('intval', (array) ($args['filterGroupIDs'] ?? [])))),
            'newDays' => max(0, min(3650, (int) ($args['newDays'] ?? 60))),
            'showStats' => !empty($args['showStats']) ? 1 : 0,
            'itemLimit' => max(0, min(500, (int) ($args['itemLimit'] ?? 0))),
        ]);
    }

    public function view()
    {
        $group = $this->groupID ? \Concrete\Core\User\Group\Group::getByID((int) $this->groupID) : null;
        $result = ['people' => [], 'filters' => [], 'total' => 0];
        if ($group) {
            $result = $this->app->make(MemberDirectory::class)->forGroup(
                $group,
                array_filter(array_map('intval', explode(',', (string) $this->filterGroupIDs))),
                (int) $this->newDays,
                (bool) $this->showStats,
                (int) $this->itemLimit
            );
        }
        $this->set('people', $result['people']);
        $this->set('filters', $result['filters']);
        $this->set('total', $result['total']);
        $this->set('modeName', in_array($this->mode, [self::MODE_DIRECTORY, self::MODE_SUMMARY], true) ? $this->mode : self::MODE_CARDS);
        $this->set('newCount', count(array_filter($result['people'], static function ($p) { return in_array('new', $p['tags'], true); })));
    }

    protected function setFormData(): void
    {
        $options = [0 => t('— Choose a group —')];
        $list = new GroupList();
        $list->includeAllGroups();
        foreach ($list->getResults() as $group) {
            if (!in_array((int) $group->getGroupID(), [GUEST_GROUP_ID, REGISTERED_GROUP_ID], true)) {
                $options[(int) $group->getGroupID()] = $group->getGroupDisplayName(false);
            }
        }
        $this->set('groupOptions', $options);
        $this->set('selectedFilterIDs', array_filter(array_map('intval', explode(',', (string) $this->filterGroupIDs))));
    }
}
