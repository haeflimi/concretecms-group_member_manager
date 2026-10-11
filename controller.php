<?php

namespace Concrete\Package\GroupMemberManager;

use Concrete\Core\Asset\Asset;
use Concrete\Core\Attribute\Key\UserKey;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Asset\AssetList;
use Concrete\Core\Http\Request;
use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;
use Concrete\Core\Page\View\PageView;
use Concrete\Core\Routing\Router;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Adds a member manager (list, add, remove) to the core group edit page (/dashboard/users/groups/edit/<id>) without
 * overriding any core view: an on_before_render listener loads a script on that page, which builds the panel and talks
 * to the JSON routes of this package.
 */
class Controller extends Package
{
    protected $pkgHandle = 'group_member_manager';
    protected $appVersionRequired = '9.0.0';
    protected $pkgVersion = '1.3.0';
    protected $pkgAutoloaderRegistries = [
        'src' => 'GroupMemberManager',
    ];

    public function getPackageName()
    {
        return t('Group Member Manager');
    }

    public function getPackageDescription()
    {
        return t('Add and remove the members of a group directly on the dashboard group edit page.');
    }

    public function on_start()
    {
        $router = $this->app->make(Router::class);
        $router->get('/ccm/group_member_manager/members/{gID}', 'GroupMemberManager\Controller\Members::members');
        $router->get('/ccm/group_member_manager/candidates/{gID}', 'GroupMemberManager\Controller\Members::candidates');
        $router->post('/ccm/group_member_manager/add/{gID}', 'GroupMemberManager\Controller\Members::add');
        $router->post('/ccm/group_member_manager/remove/{gID}', 'GroupMemberManager\Controller\Members::remove');

        $router->get('/ccm/group_member_manager/allocation/board', 'GroupMemberManager\Controller\Allocation::board');
        $router->get('/ccm/group_member_manager/allocation/search', 'GroupMemberManager\Controller\Allocation::search');
        $router->post('/ccm/group_member_manager/allocation/apply', 'GroupMemberManager\Controller\Allocation::apply');

        $assets = AssetList::getInstance();
        $options = ['version' => $this->pkgVersion, 'position' => Asset::ASSET_POSITION_FOOTER, 'minify' => false, 'combine' => false];
        $assets->register('javascript', 'group_member_manager/panel', 'js/panel.js', $options, $this);
        $assets->register('javascript', 'group_member_manager/allocation', 'js/allocation.js', $options, $this);
        $assets->register('css', 'group_member_manager/allocation', 'css/allocation.css', ['version' => $this->pkgVersion, 'minify' => false, 'combine' => false], $this);
        $assets->register('css', 'group_member_manager/panel', 'css/panel.css', ['version' => $this->pkgVersion, 'minify' => false, 'combine' => false], $this);

        $this->app->make('director')->addListener('on_before_render', function ($event) {
            $view = $event->getArgument('view');
            if ($view instanceof PageView && preg_match('#^/dashboard/users/groups/edit/\d+#', $this->app->make(Request::class)->getPath())) {
                $texts = [
                    'title' => t('Members'),
                    'addLabel' => t('Add a member'),
                    'addPlaceholder' => t('Search users by name or email'),
                    'filter' => t('Filter members'),
                    'name' => t('Name'),
                    'email' => t('Email'),
                    'entered' => t('Added'),
                    'remove' => t('Remove'),
                    'confirmRemove' => t('Remove %s from this group?'),
                    'empty' => t('No members.'),
                    'total' => t('members'),
                    'noResults' => t('No matching users outside this group.'),
                ];
                $view->addFooterItem(
                    '<script>window.ccmGroupMemberManagerText = ' . json_encode($texts, JSON_HEX_TAG | JSON_HEX_AMP) .
                    '; window.ccmGroupMemberManagerToken = ' . json_encode($this->app->make('token')->generate('group_member_manager')) . ';</script>'
                );
                $view->requireAsset('javascript', 'group_member_manager/panel');
                $view->requireAsset('css', 'group_member_manager/panel');
            }
        });
    }

    public function install()
    {
        $pkg = parent::install();
        $this->installContent();

        return $pkg;
    }

    public function upgrade()
    {
        parent::upgrade();
        $this->installContent();
    }

    protected function installContent(): void
    {
        $pkg = $this->getPackageEntity();
        foreach (['group_members', 'membership_application'] as $handle) {
            if (!BlockType::getByHandle($handle)) {
                BlockType::installBlockType($handle, $pkg);
            }
        }
        if (!UserKey::getByHandle(\GroupMemberManager\Directory\MemberDirectory::ROLE_ATTRIBUTE)) {
            // set by administrators only, shown by the Member List block
            UserKey::add('text', [
                'akHandle' => \GroupMemberManager\Directory\MemberDirectory::ROLE_ATTRIBUTE,
                'akName' => t('Role in the club'),
                'akIsSearchable' => false,
                'uakProfileDisplay' => false,
                'uakProfileEdit' => false,
                'uakProfileEditRequired' => false,
                'uakRegisterEdit' => false,
                'uakRegisterEditRequired' => false,
                'uakMemberListDisplay' => false,
            ], $pkg);
        }

        $page = Page::getByPath('/dashboard/users/group_allocation');
        if (!$page || $page->isError()) {
            $page = SinglePage::add('/dashboard/users/group_allocation', $this->getPackageEntity());
            $page->update(['cName' => t('Group Allocation'), 'cDescription' => t('Allocate users to groups and move them between groups.')]);
        }
    }
}
