<?php

namespace Concrete\Package\GroupMemberManager\Controller\SinglePage\Dashboard\Users;

use Concrete\Core\Page\Controller\DashboardPageController;
use GroupMemberManager\Controller\Allocation;
use GroupMemberManager\Membership\Membership;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Dashboard > Users & Groups > Group Allocation: columns of groups, users are dragged between them (move) or from the
 * search onto them (add). Everything is done by js/allocation.js against the Allocation JSON endpoints.
 */
class GroupAllocation extends DashboardPageController
{
    public function view()
    {
        $groups = $this->app->make(Membership::class)->listGroups();
        $known = array_column($groups, 'id');
        $selected = array_values(array_intersect(array_map('intval', explode(',', (string) $this->request->query->get('groups'))), $known));

        $this->set('groups', $groups);
        $this->set('selected', $selected);
        $this->set('allocationToken', $this->app->make('token')->generate(Allocation::TOKEN));
        $this->set('baseUrl', rtrim((string) $this->app->make('url/manager')->resolve(['/ccm/group_member_manager/allocation']), '/'));
        $this->requireAsset('javascript', 'group_member_manager/allocation');
        $this->requireAsset('css', 'group_member_manager/allocation');
    }
}
