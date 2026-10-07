<?php

namespace GroupMemberManager\Controller;

use Concrete\Core\Controller\Controller;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\UserList;
use GroupMemberManager\Membership\Membership;
use Symfony\Component\HttpFoundation\JsonResponse;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * JSON endpoints of the group allocation board (/dashboard/users/group_allocation).
 */
class Allocation extends Controller
{
    public const TOKEN = 'group_allocation';
    public const PAGE_PATH = '/dashboard/users/group_allocation';
    protected const MAX_GROUPS = 12;
    protected const MAX_USERS = 500;

    /**
     * The columns of the board: direct members of each requested group.
     */
    public function board(): JsonResponse
    {
        return $this->respond(function (Membership $membership) {
            return ['groups' => $this->serializeGroups($membership, $this->requestedGroupIDs($this->request->query->get('groups')))];
        });
    }

    /**
     * Users matching a keyword, with the ones of the shown groups they already are in.
     */
    public function search(): JsonResponse
    {
        return $this->respond(function (Membership $membership) {
            $q = trim((string) $this->request->query->get('q', ''));
            $users = [];
            if ($q !== '') {
                $list = new UserList();
                $list->includeInactiveUsers();
                $list->filterByKeywords($q);
                $list->sortByUserName();
                $list->getQueryObject()->setMaxResults(20);
                foreach ($list->getResults() as $ui) {
                    $users[] = ['id' => (int) $ui->getUserID(), 'name' => $ui->getUserName(), 'email' => $ui->getUserEmail()];
                }
                $memberships = $membership->membershipsOf(array_column($users, 'id'), $this->requestedGroupIDs($this->request->query->get('groups')));
                foreach ($users as &$user) {
                    $user['in'] = $memberships[$user['id']] ?? [];
                }
                unset($user);
            }

            return ['users' => $users];
        });
    }

    /**
     * move (from -> to), copy (to) or remove (from) for a list of users; answers with the changed columns.
     */
    public function apply(): JsonResponse
    {
        return $this->respond(function (Membership $membership) {
            $post = $this->request->request;
            if (!$this->app->make('token')->validate(self::TOKEN, $post->get('ccm_token'))) {
                throw new UserMessageException($this->app->make('token')->getErrorMessage());
            }
            $action = (string) $post->get('action');
            $userIDs = array_values(array_filter(array_map('intval', (array) $post->get('uIDs'))));
            if (!in_array($action, ['move', 'copy', 'remove'], true) || !$userIDs) {
                throw new UserMessageException(t('Invalid request.'));
            }
            if (count($userIDs) > self::MAX_USERS) {
                throw new UserMessageException(t('Too many users at once.'));
            }
            $from = $action === 'copy' ? null : $this->assignableGroup($membership, $post->get('from'));
            $to = $action === 'remove' ? null : $this->assignableGroup($membership, $post->get('to'));
            if ($from && $to && $from->getGroupID() === $to->getGroupID()) {
                throw new UserMessageException(t('Source and target group are the same.'));
            }
            $count = $membership->transfer($userIDs, $from, $to);
            $changed = array_values(array_filter([$from ? (int) $from->getGroupID() : 0, $to ? (int) $to->getGroupID() : 0]));

            if ($action === 'move') {
                $message = t2('%d user moved from %s to %s.', '%d users moved from %s to %s.', $count, $from->getGroupName(), $to->getGroupName());
            } elseif ($action === 'copy') {
                $message = t2('%d user added to %s.', '%d users added to %s.', $count, $to->getGroupName());
            } else {
                $message = t2('%d user removed from %s.', '%d users removed from %s.', $count, $from->getGroupName());
            }

            return ['message' => $message, 'groups' => $this->serializeGroups($membership, $changed)];
        });
    }

    protected function respond(callable $callback): JsonResponse
    {
        try {
            $page = Page::getByPath(self::PAGE_PATH);
            if (!$page || $page->isError() || !(new Checker($page))->canViewPage()) {
                throw new UserMessageException(t('Access Denied.'));
            }
            $membership = $this->app->make(Membership::class);

            return new JsonResponse(['success' => true] + $callback($membership));
        } catch (UserMessageException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    protected function assignableGroup(Membership $membership, $gID)
    {
        $group = $membership->getGroup($gID);
        if (!$group) {
            throw new UserMessageException(t('Invalid group.'));
        }
        if (!$membership->canEdit($group) || !$membership->canAssign($group)) {
            throw new UserMessageException(t('You are not allowed to assign users to the group %s.', $group->getGroupName()));
        }

        return $group;
    }

    /**
     * @return int[]
     */
    protected function requestedGroupIDs($value): array
    {
        $ids = is_array($value) ? $value : explode(',', (string) $value);

        return array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, self::MAX_GROUPS);
    }

    protected function serializeGroups(Membership $membership, array $groupIDs): array
    {
        $result = [];
        foreach ($groupIDs as $gID) {
            $group = $membership->getGroup($gID);
            if ($group && $membership->canEdit($group) && !$membership->isSystemGroup($group)) {
                $result[] = [
                    'id' => (int) $group->getGroupID(),
                    'name' => $group->getGroupName(),
                    'canAssign' => $membership->canAssign($group),
                    'members' => $membership->members($group),
                ];
            }
        }

        return $result;
    }
}
