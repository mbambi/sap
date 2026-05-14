<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateUuid;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

$router->add('GET', '/api/gamification/my-xp', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $xp = $db->query(
        'SELECT * FROM `UserXP` WHERE userId = :userId AND tenantId = :tenantId LIMIT 1',
        ['userId' => $user['userId'], 'tenantId' => $user['tenantId']]
    )[0] ?? null;

    if (!$xp) {
        $id = generateUuid();
        $db->execute(
            'INSERT INTO `UserXP` (id, userId, tenantId, totalXP, level, streak, lastActivityDate)
             VALUES (:id, :userId, :tenantId, :totalXP, :level, :streak, :lastActivityDate)',
            [
                'id' => $id,
                'userId' => $user['userId'],
                'tenantId' => $user['tenantId'],
                'totalXP' => 0,
                'level' => 1,
                'streak' => 0,
                'lastActivityDate' => null,
            ]
        );
        $xp = $db->query('SELECT * FROM `UserXP` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    }

    Router::json($xp ?? []);
});

$router->add('POST', '/api/gamification/award-xp', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    $amount = $body['amount'] ?? null;
    if ($amount === null || $amount < 0) {
        Router::error('amount (non-negative) is required', 400);
        return;
    }

    $db = DB::getInstance();
    $xp = $db->query(
        'SELECT * FROM `UserXP` WHERE userId = :userId AND tenantId = :tenantId LIMIT 1',
        ['userId' => $user['userId'], 'tenantId' => $user['tenantId']]
    )[0] ?? null;

    if (!$xp) {
        $db->execute(
            'INSERT INTO `UserXP` (id, userId, tenantId, totalXP, level, streak, lastActivityDate)
             VALUES (:id, :userId, :tenantId, :totalXP, :level, :streak, :lastActivityDate)',
            [
                'id' => generateUuid(),
                'userId' => $user['userId'],
                'tenantId' => $user['tenantId'],
                'totalXP' => 0,
                'level' => 1,
                'streak' => 0,
                'lastActivityDate' => null,
            ]
        );
        $xp = $db->query(
            'SELECT * FROM `UserXP` WHERE userId = :userId AND tenantId = :tenantId LIMIT 1',
            ['userId' => $user['userId'], 'tenantId' => $user['tenantId']]
        )[0] ?? null;
    }

    $today = date('Y-m-d');
    $lastDate = $xp['lastActivityDate'] ? substr($xp['lastActivityDate'], 0, 10) : null;
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $newStreak = (int) ($xp['streak'] ?? 0);
    if ($lastDate === $yesterday) {
        $newStreak++;
    } elseif ($lastDate !== $today) {
        $newStreak = 1;
    }

    $newTotalXP = (float) ($xp['totalXP'] ?? 0) + (float) $amount;
    $newLevel = (int) floor($newTotalXP / 100) + 1;

    $db->execute(
        'UPDATE `UserXP` SET totalXP = :totalXP, level = :level, streak = :streak, lastActivityDate = :lastActivityDate WHERE id = :id',
        [
            'totalXP' => $newTotalXP,
            'level' => $newLevel,
            'streak' => $newStreak,
            'lastActivityDate' => date('Y-m-d H:i:s'),
            'id' => $xp['id'],
        ]
    );

    $updated = $db->query('SELECT * FROM `UserXP` WHERE id = :id LIMIT 1', ['id' => $xp['id']])[0] ?? null;
    Router::json(array_merge($updated ?? [], ['reason' => $body['reason'] ?? null]));
});

$router->add('GET', '/api/gamification/leaderboard', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $userXps = $db->query(
        'SELECT * FROM `UserXP` WHERE tenantId = :tenantId ORDER BY totalXP DESC LIMIT 20',
        ['tenantId' => $user['tenantId']]
    );
    $userIds = array_map(fn ($row) => $row['userId'], $userXps);
    if (!$userIds) {
        Router::json([]);
        return;
    }
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $users = $db->query("SELECT id, firstName, lastName FROM `User` WHERE id IN ($placeholders)", $userIds);
    $userMap = [];
    foreach ($users as $u) {
        $userMap[$u['id']] = $u;
    }
    $result = array_map(function ($xp) use ($userMap) {
        $u = $userMap[$xp['userId']] ?? [];
        return [
            'userId' => $xp['userId'],
            'firstName' => $u['firstName'] ?? '',
            'lastName' => $u['lastName'] ?? '',
            'totalXP' => $xp['totalXP'],
            'level' => $xp['level'],
        ];
    }, $userXps);
    Router::json($result);
});

$router->add('GET', '/api/gamification/achievements', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $achievements = $db->query('SELECT * FROM `Achievement`');
    $unlocked = $db->query('SELECT achievementId, unlockedAt FROM `UserAchievement` WHERE userId = :userId', ['userId' => $user['userId']]);
    $unlockedMap = [];
    foreach ($unlocked as $row) {
        $unlockedMap[$row['achievementId']] = $row['unlockedAt'];
    }
    $result = array_map(function ($achievement) use ($unlockedMap) {
        return [
            ...$achievement,
            'unlocked' => isset($unlockedMap[$achievement['id']]),
            'unlockedAt' => $unlockedMap[$achievement['id']] ?? null,
        ];
    }, $achievements);
    Router::json($result);
});

$router->add('POST', '/api/gamification/check-achievements', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $xp = $db->query(
        'SELECT totalXP FROM `UserXP` WHERE userId = :userId AND tenantId = :tenantId LIMIT 1',
        ['userId' => $user['userId'], 'tenantId' => $user['tenantId']]
    )[0] ?? null;
    $totalXP = (float) ($xp['totalXP'] ?? 0);

    $achievements = $db->query('SELECT * FROM `Achievement`');
    $unlocked = $db->query('SELECT achievementId FROM `UserAchievement` WHERE userId = :userId', ['userId' => $user['userId']]);
    $unlockedIds = array_column($unlocked, 'achievementId');
    $newlyUnlocked = [];
    foreach ($achievements as $achievement) {
        if (in_array($achievement['id'], $unlockedIds, true)) {
            continue;
        }
        $condition = $achievement['condition'] ? json_decode($achievement['condition'], true) : [];
        if (isset($condition['totalXP']) && $totalXP >= $condition['totalXP']) {
            $db->execute(
                'INSERT INTO `UserAchievement` (id, userId, achievementId, unlockedAt)
                 VALUES (:id, :userId, :achievementId, :unlockedAt)',
                [
                    'id' => generateUuid(),
                    'userId' => $user['userId'],
                    'achievementId' => $achievement['id'],
                    'unlockedAt' => date('Y-m-d H:i:s'),
                ]
            );
            $newlyUnlocked[] = $achievement['code'];
        }
    }

    Router::json(['newlyUnlocked' => $newlyUnlocked]);
});

$router->add('GET', '/api/gamification/my-achievements', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $rows = $db->query(
        'SELECT ua.unlockedAt, a.* FROM `UserAchievement` ua JOIN `Achievement` a ON a.id = ua.achievementId WHERE ua.userId = :userId ORDER BY ua.unlockedAt DESC',
        ['userId' => $user['userId']]
    );
    $result = array_map(function ($row) {
        $row['unlockedAt'] = $row['unlockedAt'];
        return $row;
    }, $rows);
    Router::json($result);
});
