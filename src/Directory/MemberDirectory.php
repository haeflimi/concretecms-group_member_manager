<?php

namespace GroupMemberManager\Directory;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\User\Group\Group;
use Concrete\Core\User\UserInfoRepository;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * People of a group for public listings (board, member directory). Everything is derived from existing tables: the
 * membership date from the group, the activity from Users, event attendance from the event manager when installed.
 * The only data of its own is the optional user attribute "club_role" (Role in the club).
 */
class MemberDirectory
{
    public const ROLE_ATTRIBUTE = 'club_role';
    protected const MAX_PEOPLE = 500;
    /** A user counts as online for this long after the last request. */
    protected const ONLINE_SECONDS = 300;

    /** @var Connection */
    protected $db;

    /** @var UserInfoRepository */
    protected $users;

    public function __construct(Connection $db, UserInfoRepository $users)
    {
        $this->db = $db;
        $this->users = $users;
    }

    /**
     * @param int[] $filterGroupIDs further groups, each becomes a filter ("tag" g<ID>) of the people
     *
     * @return array{people: array<int, array<string, mixed>>, filters: array<int, array{value: string, label: string, count: int}>, total: int}
     */
    public function forGroup(Group $group, array $filterGroupIDs = [], int $newDays = 60, bool $withStats = true, int $limit = 0): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.uID, u.uName, u.uLastOnline, ug.ugEntered FROM UserGroups ug INNER JOIN Users u ON u.uID = ug.uID WHERE ug.gID = ? AND u.uIsActive = 1 ORDER BY ug.ugEntered ASC, u.uName ASC LIMIT ' . self::MAX_PEOPLE,
            [$group->getGroupID()]
        );
        $total = count($rows);
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }
        $ids = array_map(static function ($r) { return (int) $r['uID']; }, $rows);
        $attended = $withStats ? $this->attendance($ids) : [];
        $tagsOf = $this->tags($ids, $filterGroupIDs);

        $newSince = $newDays > 0 ? time() - $newDays * 86400 : null;
        $online = time() - self::ONLINE_SECONDS;
        $people = [];
        $newCount = 0;
        foreach ($rows as $row) {
            $ui = $this->users->getByID((int) $row['uID']);
            if (!$ui) {
                continue;
            }
            $entered = strtotime((string) $row['ugEntered']) ?: 0;
            $role = trim((string) $ui->getAttribute(self::ROLE_ATTRIBUTE));
            $tags = $tagsOf[(int) $row['uID']] ?? [];
            if ($newSince !== null && $entered >= $newSince) {
                $tags[] = 'new';
                ++$newCount;
            }
            $person = [
                'id' => (int) $row['uID'],
                'nick' => (string) $row['uName'],
                'role' => $role,
                'since' => $entered ? (int) date('Y', $entered) : null,
                'online' => (int) $row['uLastOnline'] >= $online,
                // for sorting (last login, membership): the views decide what of it reaches the page
                'lastOnline' => (int) $row['uLastOnline'],
                'entered' => $entered,
                'avatar' => $ui->hasAvatar() ? (string) $ui->getUserAvatar()->getPath() : null,
                'events' => $attended[(int) $row['uID']] ?? 0,
                'tags' => $tags,
            ];
            $people[] = $person;
        }

        $filters = [];
        if ($newCount > 0) {
            $filters[] = ['value' => 'new', 'label' => '', 'count' => $newCount];
        }
        foreach ($filterGroupIDs as $gID) {
            $filterGroup = Group::getByID((int) $gID);
            $count = count(array_filter($people, static function ($p) use ($gID) { return in_array('g' . $gID, $p['tags'], true); }));
            if ($filterGroup && $count > 0) {
                $filters[] = ['value' => 'g' . (int) $gID, 'label' => $filterGroup->getGroupDisplayName(false, false), 'count' => $count];
            }
        }

        return ['people' => $people, 'filters' => $filters, 'total' => $total];
    }

    /**
     * Past events each user was registered for (event manager, archived ones included); empty without it.
     *
     * @param int[] $userIDs
     *
     * @return array<int, int>
     */
    protected function attendance(array $userIDs): array
    {
        if ($userIDs === [] || !class_exists(\CommunityEventManager\Entity\Event::class)) {
            return [];
        }
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT p.uID AS uID, COUNT(*) AS n FROM cemParticipants p INNER JOIN cemEvents e ON e.id = p.eventID'
                . ' WHERE p.uID IN (' . implode(',', array_map('intval', $userIDs)) . ') AND p.status = ? AND e.status IN (?, ?) AND COALESCE(e.endsAt, e.startsAt) < NOW() GROUP BY p.uID',
                ['registered', 'published', 'archived']
            );
        } catch (\Throwable $e) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['uID']] = (int) $row['n'];
        }

        return $result;
    }

    /**
     * @param int[] $userIDs
     * @param int[] $groupIDs
     *
     * @return array<int, string[]> "g<ID>" tags per user
     */
    protected function tags(array $userIDs, array $groupIDs): array
    {
        $result = [];
        if ($userIDs && $groupIDs) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT uID, gID FROM UserGroups WHERE uID IN (' . implode(',', array_map('intval', $userIDs)) . ') AND gID IN (' . implode(',', array_map('intval', $groupIDs)) . ')'
            );
            foreach ($rows as $row) {
                $result[(int) $row['uID']][] = 'g' . (int) $row['gID'];
            }
        }

        return $result;
    }
}
