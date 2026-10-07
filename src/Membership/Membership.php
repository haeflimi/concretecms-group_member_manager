<?php

namespace GroupMemberManager\Membership;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\Group\Group;
use Concrete\Core\User\Group\GroupRepository;
use Concrete\Core\User\User;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Direct group memberships and the permission rules around changing them, shared by the group edit panel and the
 * allocation board.
 */
class Membership
{
    /** @var Connection */
    protected $db;

    /** @var GroupRepository */
    protected $groups;

    public function __construct(Connection $db, GroupRepository $groups)
    {
        $this->db = $db;
        $this->groups = $groups;
    }

    public function getGroup($gID): ?Group
    {
        return $this->groups->getGroupById((int) $gID) ?: null;
    }

    public function isSystemGroup(Group $group): bool
    {
        // Guest and Registered Users have implicit membership
        return in_array((int) $group->getGroupID(), [GUEST_GROUP_ID, REGISTERED_GROUP_ID], true);
    }

    public function canEdit(Group $group): bool
    {
        return (new Checker($group))->canEditGroup();
    }

    public function canAssign(Group $group): bool
    {
        return !$this->isSystemGroup($group) && (new Checker($group))->canAssignGroup();
    }

    /**
     * Groups the current user can edit, without the system groups.
     *
     * @return array<int, array{id: int, name: string, path: string, members: int, canAssign: bool}>
     */
    public function listGroups(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT g.gID, g.gName, g.gPath, (SELECT COUNT(*) FROM UserGroups ug WHERE ug.gID = g.gID) AS members FROM `Groups` g WHERE g.gID NOT IN (?, ?) ORDER BY g.gPath',
            [GUEST_GROUP_ID, REGISTERED_GROUP_ID]
        );
        $result = [];
        foreach ($rows as $row) {
            $group = $this->getGroup($row['gID']);
            if ($group && $this->canEdit($group)) {
                $result[] = [
                    'id' => (int) $row['gID'],
                    'name' => $row['gName'],
                    'path' => $row['gPath'],
                    'members' => (int) $row['members'],
                    'canAssign' => $this->canAssign($group),
                ];
            }
        }

        return $result;
    }

    /**
     * @return array<int, array{id: int, name: string, email: string}> direct members, by name
     */
    public function members(Group $group): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.uID, u.uName, u.uEmail FROM UserGroups ug INNER JOIN Users u ON u.uID = ug.uID WHERE ug.gID = ? ORDER BY u.uName',
            [$group->getGroupID()]
        );

        return array_map(static function ($r) {
            return ['id' => (int) $r['uID'], 'name' => $r['uName'], 'email' => $r['uEmail']];
        }, $rows);
    }

    /**
     * Group IDs (of the given ones) each user is a direct member of.
     *
     * @param int[] $userIDs
     * @param int[] $groupIDs
     *
     * @return array<int, int[]>
     */
    public function membershipsOf(array $userIDs, array $groupIDs): array
    {
        $result = [];
        if ($userIDs && $groupIDs) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT uID, gID FROM UserGroups WHERE uID IN (' . implode(',', array_map('intval', $userIDs)) . ') AND gID IN (' . implode(',', array_map('intval', $groupIDs)) . ')'
            );
            foreach ($rows as $row) {
                $result[(int) $row['uID']][] = (int) $row['gID'];
            }
        }

        return $result;
    }

    /**
     * Adds the users to $to and, when $from is given, removes them from it (a move). Users that cannot be loaded are
     * skipped.
     *
     * @param int[] $userIDs
     *
     * @return int the number of users processed
     */
    public function transfer(array $userIDs, ?Group $from, ?Group $to): int
    {
        $count = 0;
        foreach (array_unique(array_map('intval', $userIDs)) as $uID) {
            $user = User::getByUserID($uID);
            if (!$user || !$user->getUserID()) {
                continue;
            }
            if ($to) {
                $user->enterGroup($to);
            }
            if ($from) {
                $user->exitGroup($from);
            }
            ++$count;
        }

        return $count;
    }
}
