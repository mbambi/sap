<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateUuid;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

// Exercises
$router->add('GET', '/api/learning/exercises', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $exercises = $db->query(
        'SELECT * FROM `Exercise` WHERE tenantId = :tenantId AND isActive = 1 ORDER BY sortOrder ASC, createdAt ASC',
        ['tenantId' => $user['tenantId']]
    );
    $progressRows = $db->query(
        'SELECT * FROM `ExerciseProgress` WHERE userId = :userId',
        ['userId' => $user['userId']]
    );
    $progressMap = [];
    foreach ($progressRows as $progress) {
        $progressMap[$progress['exerciseId']] = $progress;
    }

    $withProgress = array_map(function ($exercise) use ($progressMap) {
        $progress = $progressMap[$exercise['id']] ?? null;
        return [
            ...$exercise,
            'steps' => json_decode($exercise['steps'] ?? '[]', true),
            'hints' => $exercise['hints'] ? json_decode($exercise['hints'], true) : [],
            'progress' => $progress
                ? [
                    'currentStep' => $progress['currentStep'],
                    'status' => $progress['status'],
                    'score' => $progress['score'],
                ]
                : [
                    'currentStep' => 0,
                    'status' => 'not_started',
                    'score' => null,
                ],
        ];
    }, $exercises);

    Router::json($withProgress);
});

$router->add('GET', '/api/learning/exercises/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $exercise = $db->query(
        'SELECT * FROM `Exercise` WHERE id = :id LIMIT 1',
        ['id' => $params['id']]
    )[0] ?? null;
    if (!$exercise || $exercise['tenantId'] !== $user['tenantId']) {
        Router::error('Exercise not found', 404);
        return;
    }

    $progress = $db->query(
        'SELECT * FROM `ExerciseProgress` WHERE exerciseId = :exerciseId AND userId = :userId LIMIT 1',
        ['exerciseId' => $exercise['id'], 'userId' => $user['userId']]
    )[0] ?? null;

    Router::json([
        ...$exercise,
        'steps' => json_decode($exercise['steps'] ?? '[]', true),
        'hints' => $exercise['hints'] ? json_decode($exercise['hints'], true) : [],
        'solution' => null,
        'progress' => $progress ?: ['currentStep' => 0, 'status' => 'not_started'],
    ]);
});

$router->add('POST', '/api/learning/exercises/{id}/progress', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $exercise = $db->query(
        'SELECT * FROM `Exercise` WHERE id = :id LIMIT 1',
        ['id' => $params['id']]
    )[0] ?? null;
    if (!$exercise || $exercise['tenantId'] !== $user['tenantId']) {
        Router::error('Exercise not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $steps = json_decode($exercise['steps'] ?? '[]', true);
    $currentStep = (int) ($body['currentStep'] ?? 0);
    $status = $body['status'] ?? null;
    $isComplete = $status === 'completed' || $currentStep >= count($steps);

    $existing = $db->query(
        'SELECT * FROM `ExerciseProgress` WHERE exerciseId = :exerciseId AND userId = :userId LIMIT 1',
        ['exerciseId' => $exercise['id'], 'userId' => $user['userId']]
    )[0] ?? null;

    if ($existing) {
        $db->execute(
            'UPDATE `ExerciseProgress` SET currentStep = :currentStep, status = :status, completedAt = :completedAt, answers = :answers WHERE id = :id',
            [
                'currentStep' => $currentStep,
                'status' => $isComplete ? 'completed' : 'in_progress',
                'completedAt' => $isComplete ? date('Y-m-d H:i:s') : null,
                'answers' => isset($body['answers']) ? json_encode($body['answers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'id' => $existing['id'],
            ]
        );
    } else {
        $db->execute(
            'INSERT INTO `ExerciseProgress` (id, exerciseId, userId, currentStep, status, startedAt, completedAt, answers)
             VALUES (:id, :exerciseId, :userId, :currentStep, :status, :startedAt, :completedAt, :answers)',
            [
                'id' => generateUuid(),
                'exerciseId' => $exercise['id'],
                'userId' => $user['userId'],
                'currentStep' => $currentStep,
                'status' => $isComplete ? 'completed' : 'in_progress',
                'startedAt' => date('Y-m-d H:i:s'),
                'completedAt' => $isComplete ? date('Y-m-d H:i:s') : null,
                'answers' => isset($body['answers']) ? json_encode($body['answers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    }

    $progress = $db->query(
        'SELECT * FROM `ExerciseProgress` WHERE exerciseId = :exerciseId AND userId = :userId LIMIT 1',
        ['exerciseId' => $exercise['id'], 'userId' => $user['userId']]
    )[0] ?? null;

    Router::json($progress ?? []);
});

// Scenarios
$router->add('GET', '/api/learning/scenarios', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();
    $scenarios = $db->query(
        'SELECT * FROM `Scenario` WHERE tenantId = :tenantId AND isActive = 1 ORDER BY name ASC',
        ['tenantId' => $user['tenantId']]
    );
    $scenarios = array_map(function ($scenario) {
        $scenario['steps'] = json_decode($scenario['steps'] ?? '[]', true);
        $scenario['sampleData'] = $scenario['sampleData'] ? json_decode($scenario['sampleData'], true) : null;
        return $scenario;
    }, $scenarios);

    Router::json($scenarios);
});

// Admin create exercises/scenarios
$router->add('POST', '/api/learning/exercises', function () use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $body = $request['body'] ?? [];
    $steps = $body['steps'] ?? [];
    $hints = $body['hints'] ?? null;
    $solution = $body['solution'] ?? null;

    $db = DB::getInstance();
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `Exercise` (id, tenantId, title, description, module, difficulty, steps, hints, solution, estimatedMinutes, isActive, sortOrder, createdAt)
         VALUES (:id, :tenantId, :title, :description, :module, :difficulty, :steps, :hints, :solution, :estimatedMinutes, :isActive, :sortOrder, :createdAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'title' => $body['title'] ?? 'Untitled',
            'description' => $body['description'] ?? '',
            'module' => $body['module'] ?? 'general',
            'difficulty' => $body['difficulty'] ?? 'beginner',
            'steps' => json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'hints' => $hints ? json_encode($hints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'solution' => $solution ? json_encode($solution, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'estimatedMinutes' => $body['estimatedMinutes'] ?? 30,
            'isActive' => $body['isActive'] ?? 1,
            'sortOrder' => $body['sortOrder'] ?? 0,
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );

    $exercise = $db->query('SELECT * FROM `Exercise` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    Router::json($exercise ?? [], 201);
});

$router->add('POST', '/api/learning/scenarios', function () use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $body = $request['body'] ?? [];
    $steps = $body['steps'] ?? [];
    $sampleData = $body['sampleData'] ?? null;

    $db = DB::getInstance();
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `Scenario` (id, tenantId, name, description, type, steps, sampleData, isActive, createdAt)
         VALUES (:id, :tenantId, :name, :description, :type, :steps, :sampleData, :isActive, :createdAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'name' => $body['name'] ?? 'Scenario',
            'description' => $body['description'] ?? '',
            'type' => $body['type'] ?? 'procure_to_pay',
            'steps' => json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sampleData' => $sampleData ? json_encode($sampleData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'isActive' => $body['isActive'] ?? 1,
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );

    $scenario = $db->query('SELECT * FROM `Scenario` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    Router::json($scenario ?? [], 201);
});
