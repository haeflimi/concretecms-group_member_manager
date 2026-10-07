<?php

namespace GroupMemberManager\Controller;

use Concrete\Core\Controller\Controller;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\Group\Group;
use Concrete\Core\User\Group\GroupRepository;
use GroupMemberManager\Membership\Membership;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserList;
use Symfony\Component\HttpFoundation\JsonResponse;

defined('C5_EXECUTE') or die('Access Denied.');

class Members extends Controller
{
    protected const PAGE_SIZE = 25;

    /**
     * Direct members only (members of child groups are listed by core but cannot be removed from this group).
     */
    public function members($gID): JsonResponse
    {
        $group = $this->getGroup($gID, false);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        $db = $this->app->make(Connection::class);
        $query = trim((string) $this->request->query->get('q', ''));
        $page = max(1, (int) $this->request->query->get('page', 1));

        $where = 'ug.gID = :gID';
        $params = ['gID' => $group->getGroupID()];
        if ($query !== '') {
            $where .= ' AND (u.uName LIKE :q OR u.uEmail LIKE :q)';
            $params['q'] = '%' . addcslashes($query, '%_\\') . '%';
        }
        $total = (int) $db->fetchOne("SELECT COUNT(*) FROM UserGroups ug INNER JOIN Users u ON u.uID = ug.uID WHERE $where", $params);
        $rows = $db->fetchAllAssociative(
            "SELECT u.uID, u.uName, u.uEmail, ug.ugEntered FROM UserGroups ug INNER JOIN Users u ON u.uID = ug.uID WHERE $where ORDER BY u.uName LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
            $params
        );

        return new JsonResponse([
            'canAssign' => $this->canAssign($group),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'members' => array_map(static function ($r) {
                return ['id' => (int) $r['uID'], 'name' => $r['uName'], 'email' => $r['uEmail'], 'entered' => $r['ugEntered']];
            }, $rows),
        ]);
    }

    /**
     * Users that are not yet in the group, matched by name or email.
     */
    public function candidates($gID): JsonResponse
    {
        $group = $this->getGroup($gID, true);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        $q = trim((string) $this->request->query->get('q', ''));
        $results = [];
        if ($q !== '') {
            $list = new UserList();
            $list->includeInactiveUsers();
            $list->filterByKeywords($q);
            $list->filterByGroup($group, false);
            $list->sortByUserName();
            $list->getQueryObject()->setMaxResults(10);
            foreach ($list->getResults() as $ui) {
                if ($ui instanceof UserInfo) {
                    $results[] = ['id' => (int) $ui->getUserID(), 'name' => $ui->getUserName(), 'email' => $ui->getUserEmail()];
                }
            }
        }

        return new JsonResponse($results);
    }

    public function add($gID): JsonResponse
    {
        return $this->change($gID, true);
    }

    public function remove($gID): JsonResponse
    {
        return $this->change($gID, false);
    }

    protected function change($gID, bool $add): JsonResponse
    {
        $group = $this->getGroup($gID, true);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        if (!$this->app->make('token')->validate('group_member_manager', $this->request->request->get('ccm_token'))) {
            return $this->error($this->app->make('token')->getErrorMessage(), 403);
        }
        $user = User::getByUserID((int) $this->request->request->get('uID'));
        if (!$user || !$user->getUserID()) {
            return $this->error(t('Invalid user.'), 404);
        }
        if ($add) {
            $user->enterGroup($group);
        } else {
            $user->exitGroup($group);
        }

        return new JsonResponse(['success' => true]);
    }

    protected function canAssign(Group $group): bool
    {
        return $this->app->make(Membership::class)->canAssign($group);
    }

    /**
     * @return Group|JsonResponse the group, or the error response to return
     */
    protected function getGroup($gID, bool $needAssign)
    {
        $group = $this->app->make(GroupRepository::class)->getGroupById((int) $gID);
        if (!$group) {
            return $this->error(t('Invalid group.'), 404);
        }
        if (!(new Checker($group))->canEditGroup()) {
            return $this->error(t('Access Denied.'), 403);
        }
        if ($needAssign && !$this->canAssign($group)) {
            return $this->error(t('You are not allowed to assign users to this group.'), 403);
        }

        return $group;
    }

    protected function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
