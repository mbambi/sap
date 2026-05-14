<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateUuid;
use function App\logAudit;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

function hrPagination(array $query): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(100, max(1, (int) ($query['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    return [$page, $limit, $offset];
}

function hrFilters(array $query, array $allowed): array
{
    $filters = [];
    foreach ($query as $key => $value) {
        if (in_array($key, ['page', 'limit', 'search', 'sort', 'order'], true)) {
            continue;
        }
        if ($value === '' || is_array($value)) {
            continue;
        }
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        $filters[$key] = $value;
    }

    return $filters;
}

function hrBuildUpdate(array $data, array $allowed): array
{
    $fields = [];
    $params = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $data)) {
            $fields[] = sprintf('`%s` = :%s', $field, $field);
            $params[$field] = $data[$field];
        }
    }

    return [$fields, $params];
}

function hrLoadPlant(DB $db, ?string $plantId): ?array
{
    if (!$plantId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `Plant` WHERE id = :id LIMIT 1', ['id' => $plantId]);
    return $rows[0] ?? null;
}

function hrLoadEmployee(DB $db, ?string $employeeId): ?array
{
    if (!$employeeId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `Employee` WHERE id = :id LIMIT 1', ['id' => $employeeId]);
    return $rows[0] ?? null;
}

function hrLoadOrgUnit(DB $db, ?string $orgUnitId): ?array
{
    if (!$orgUnitId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `OrgUnit` WHERE id = :id LIMIT 1', ['id' => $orgUnitId]);
    return $rows[0] ?? null;
}

function hrLoadOrgChildren(DB $db, string $orgUnitId): array
{
    return $db->query('SELECT * FROM `OrgUnit` WHERE parentId = :id', ['id' => $orgUnitId]);
}

// Employees
$router->add('GET', '/api/hr/employees', function () use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = hrPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = hrFilters($query, ['department', 'position', 'status', 'employmentType', 'plantId', 'orgUnitId']);

    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = '(employeeNumber LIKE :search OR firstName LIKE :search OR lastName LIKE :search OR email LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT * FROM `Employee` $whereSql ORDER BY employeeNumber ASC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['plant'] = hrLoadPlant($db, $row['plantId'] ?? null);
        $row['manager'] = hrLoadEmployee($db, $row['managerId'] ?? null);
        $row['orgUnit'] = hrLoadOrgUnit($db, $row['orgUnitId'] ?? null);
    }

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `Employee` $whereSql", $params);
    $total = (int) ($countRows[0]['total'] ?? 0);

    Router::json([
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ],
    ]);
});

$router->add('GET', '/api/hr/employees/{id}', function (array $params) {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Employee` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $employee = $rows[0] ?? null;
    if (!$employee || $employee['tenantId'] !== $user['tenantId']) {
        Router::error('employee not found', 404);
        return;
    }

    $employee['plant'] = hrLoadPlant($db, $employee['plantId'] ?? null);
    $employee['manager'] = hrLoadEmployee($db, $employee['managerId'] ?? null);
    $employee['orgUnit'] = hrLoadOrgUnit($db, $employee['orgUnitId'] ?? null);

    Router::json($employee);
});

$router->add('POST', '/api/hr/employees', function () use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $body = $request['body'] ?? [];

    $required = ['employeeNumber', 'firstName', 'lastName', 'hireDate'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid employee data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `Employee` WHERE tenantId = :tenantId AND employeeNumber = :employeeNumber LIMIT 1',
        ['tenantId' => $user['tenantId'], 'employeeNumber' => $body['employeeNumber']]
    );
    if ($existing) {
        Router::error('employee already exists with that key', 409);
        return;
    }

    $id = generateUuid();
    $now = date('Y-m-d H:i:s');
    $db->execute(
        'INSERT INTO `Employee` (id, tenantId, employeeNumber, firstName, lastName, email, phone, dateOfBirth, hireDate, terminationDate, department, position, managerId, plantId, orgUnitId, employmentType, status, salary, currency, createdAt, updatedAt)
         VALUES (:id, :tenantId, :employeeNumber, :firstName, :lastName, :email, :phone, :dateOfBirth, :hireDate, :terminationDate, :department, :position, :managerId, :plantId, :orgUnitId, :employmentType, :status, :salary, :currency, :createdAt, :updatedAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'employeeNumber' => $body['employeeNumber'],
            'firstName' => $body['firstName'],
            'lastName' => $body['lastName'],
            'email' => $body['email'] ?? null,
            'phone' => $body['phone'] ?? null,
            'dateOfBirth' => $body['dateOfBirth'] ?? null,
            'hireDate' => $body['hireDate'],
            'terminationDate' => $body['terminationDate'] ?? null,
            'department' => $body['department'] ?? null,
            'position' => $body['position'] ?? null,
            'managerId' => $body['managerId'] ?? null,
            'plantId' => $body['plantId'] ?? null,
            'orgUnitId' => $body['orgUnitId'] ?? null,
            'employmentType' => $body['employmentType'] ?? 'full_time',
            'status' => $body['status'] ?? 'active',
            'salary' => $body['salary'] ?? null,
            'currency' => $body['currency'] ?? 'USD',
            'createdAt' => $now,
            'updatedAt' => $now,
        ]
    );

    $employee = $db->query('SELECT * FROM `Employee` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($employee) {
        $employee['plant'] = hrLoadPlant($db, $employee['plantId'] ?? null);
        $employee['manager'] = hrLoadEmployee($db, $employee['managerId'] ?? null);
        $employee['orgUnit'] = hrLoadOrgUnit($db, $employee['orgUnitId'] ?? null);
        logAudit($user, 'hr', 'employee', 'CREATE', $id, null, $employee);
        Router::json($employee, 201);
        return;
    }

    Router::error('Failed to create employee', 500);
});

$router->add('PUT', '/api/hr/employees/{id}', function (array $params) use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Employee` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $employee = $rows[0] ?? null;
    if (!$employee || $employee['tenantId'] !== $user['tenantId']) {
        Router::error('employee not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = [
        'employeeNumber', 'firstName', 'lastName', 'email', 'phone', 'dateOfBirth', 'hireDate', 'terminationDate',
        'department', 'position', 'managerId', 'plantId', 'orgUnitId', 'employmentType', 'status', 'salary', 'currency',
    ];
    [$fields, $paramsUpdate] = hrBuildUpdate($body, $allowed);
    $paramsUpdate['updatedAt'] = date('Y-m-d H:i:s');
    $fields[] = '`updatedAt` = :updatedAt';
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `Employee` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `Employee` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        $updated['plant'] = hrLoadPlant($db, $updated['plantId'] ?? null);
        $updated['manager'] = hrLoadEmployee($db, $updated['managerId'] ?? null);
        $updated['orgUnit'] = hrLoadOrgUnit($db, $updated['orgUnitId'] ?? null);
        logAudit($user, 'hr', 'employee', 'UPDATE', $params['id'], $employee, $updated);
        Router::json($updated);
        return;
    }

    Router::error('employee not found', 404);
});

$router->add('DELETE', '/api/hr/employees/{id}', function (array $params) {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Employee` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $employee = $rows[0] ?? null;
    if (!$employee || $employee['tenantId'] !== $user['tenantId']) {
        Router::error('employee not found', 404);
        return;
    }

    $db->execute('DELETE FROM `Employee` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'hr', 'employee', 'DELETE', $params['id'], $employee, null);
    Router::json(['message' => 'employee deleted']);
});

// Org Units
$router->add('GET', '/api/hr/org-units', function () use ($request) {
    requireRoles(['admin', 'instructor']);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = hrPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = hrFilters($query, ['isActive', 'parentId']);

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(name LIKE :search OR code LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT * FROM `OrgUnit` $whereSql ORDER BY code ASC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['parent'] = hrLoadOrgUnit($db, $row['parentId'] ?? null);
        $row['children'] = hrLoadOrgChildren($db, $row['id']);
    }

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `OrgUnit` $whereSql", $params);
    $total = (int) ($countRows[0]['total'] ?? 0);

    Router::json([
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ],
    ]);
});

$router->add('GET', '/api/hr/org-units/{id}', function (array $params) {
    requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `OrgUnit` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $org = $rows[0] ?? null;
    if (!$org) {
        Router::error('org_unit not found', 404);
        return;
    }

    $org['parent'] = hrLoadOrgUnit($db, $org['parentId'] ?? null);
    $org['children'] = hrLoadOrgChildren($db, $org['id']);
    Router::json($org);
});

$router->add('POST', '/api/hr/org-units', function () use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $body = $request['body'] ?? [];

    $required = ['name', 'code'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid org_unit data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `OrgUnit` (id, name, code, parentId, managerId, isActive)
         VALUES (:id, :name, :code, :parentId, :managerId, :isActive)',
        [
            'id' => $id,
            'name' => $body['name'],
            'code' => $body['code'],
            'parentId' => $body['parentId'] ?? null,
            'managerId' => $body['managerId'] ?? null,
            'isActive' => $body['isActive'] ?? 1,
        ]
    );

    $org = $db->query('SELECT * FROM `OrgUnit` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($org) {
        $org['parent'] = hrLoadOrgUnit($db, $org['parentId'] ?? null);
        $org['children'] = hrLoadOrgChildren($db, $org['id']);
        logAudit($user, 'hr', 'org_unit', 'CREATE', $id, null, $org);
        Router::json($org, 201);
        return;
    }

    Router::error('Failed to create org_unit', 500);
});

$router->add('PUT', '/api/hr/org-units/{id}', function (array $params) use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `OrgUnit` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $org = $rows[0] ?? null;
    if (!$org) {
        Router::error('org_unit not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    [$fields, $paramsUpdate] = hrBuildUpdate($body, ['name', 'code', 'parentId', 'managerId', 'isActive']);
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `OrgUnit` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `OrgUnit` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        $updated['parent'] = hrLoadOrgUnit($db, $updated['parentId'] ?? null);
        $updated['children'] = hrLoadOrgChildren($db, $updated['id']);
        logAudit($user, 'hr', 'org_unit', 'UPDATE', $params['id'], $org, $updated);
        Router::json($updated);
        return;
    }

    Router::error('org_unit not found', 404);
});

$router->add('DELETE', '/api/hr/org-units/{id}', function (array $params) {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `OrgUnit` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $org = $rows[0] ?? null;
    if (!$org) {
        Router::error('org_unit not found', 404);
        return;
    }

    $db->execute('DELETE FROM `OrgUnit` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'hr', 'org_unit', 'DELETE', $params['id'], $org, null);
    Router::json(['message' => 'org_unit deleted']);
});

// Leave Requests
$router->add('GET', '/api/hr/leave-requests', function () use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = hrPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = hrFilters($query, ['status', 'leaveType', 'employeeId']);

    $where = ['e.tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = 'lr.leaveType LIKE :search';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('lr.`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT lr.* FROM `LeaveRequest` lr JOIN `Employee` e ON lr.employeeId = e.id $whereSql ORDER BY lr.createdAt DESC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['employee'] = hrLoadEmployee($db, $row['employeeId']);
    }

    $countRows = $db->query(
        "SELECT COUNT(*) AS total FROM `LeaveRequest` lr JOIN `Employee` e ON lr.employeeId = e.id $whereSql",
        $params
    );
    $total = (int) ($countRows[0]['total'] ?? 0);

    Router::json([
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ],
    ]);
});

$router->add('GET', '/api/hr/leave-requests/{id}', function (array $params) {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();

    $rows = $db->query(
        'SELECT lr.* FROM `LeaveRequest` lr JOIN `Employee` e ON lr.employeeId = e.id WHERE lr.id = :id AND e.tenantId = :tenantId LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    );
    $requestRow = $rows[0] ?? null;
    if (!$requestRow) {
        Router::error('leave_request not found', 404);
        return;
    }

    $requestRow['employee'] = hrLoadEmployee($db, $requestRow['employeeId']);
    Router::json($requestRow);
});

$router->add('POST', '/api/hr/leave-requests', function () use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $body = $request['body'] ?? [];

    $required = ['employeeId', 'leaveType', 'startDate', 'endDate', 'days'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid leave_request data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $employee = $db->query('SELECT * FROM `Employee` WHERE id = :id LIMIT 1', ['id' => $body['employeeId']]);
    if (!$employee || $employee[0]['tenantId'] !== $user['tenantId']) {
        Router::error('employee not found', 404);
        return;
    }

    $id = generateUuid();
    $db->execute(
        'INSERT INTO `LeaveRequest` (id, employeeId, leaveType, startDate, endDate, days, status, reason, approvedBy, approvedAt, createdAt)
         VALUES (:id, :employeeId, :leaveType, :startDate, :endDate, :days, :status, :reason, :approvedBy, :approvedAt, :createdAt)',
        [
            'id' => $id,
            'employeeId' => $body['employeeId'],
            'leaveType' => $body['leaveType'],
            'startDate' => $body['startDate'],
            'endDate' => $body['endDate'],
            'days' => $body['days'],
            'status' => $body['status'] ?? 'pending',
            'reason' => $body['reason'] ?? null,
            'approvedBy' => $body['approvedBy'] ?? null,
            'approvedAt' => $body['approvedAt'] ?? null,
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );

    $leave = $db->query('SELECT * FROM `LeaveRequest` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($leave) {
        $leave['employee'] = hrLoadEmployee($db, $leave['employeeId']);
        logAudit($user, 'hr', 'leave_request', 'CREATE', $id, null, $leave);
        Router::json($leave, 201);
        return;
    }

    Router::error('Failed to create leave_request', 500);
});

$router->add('PUT', '/api/hr/leave-requests/{id}', function (array $params) use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `LeaveRequest` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $leave = $rows[0] ?? null;
    if (!$leave) {
        Router::error('leave_request not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = ['employeeId', 'leaveType', 'startDate', 'endDate', 'days', 'status', 'reason', 'approvedBy', 'approvedAt'];
    [$fields, $paramsUpdate] = hrBuildUpdate($body, $allowed);
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `LeaveRequest` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `LeaveRequest` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        $updated['employee'] = hrLoadEmployee($db, $updated['employeeId']);
        logAudit($user, 'hr', 'leave_request', 'UPDATE', $params['id'], $leave, $updated);
        Router::json($updated);
        return;
    }

    Router::error('leave_request not found', 404);
});

$router->add('DELETE', '/api/hr/leave-requests/{id}', function (array $params) {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `LeaveRequest` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $leave = $rows[0] ?? null;
    if (!$leave) {
        Router::error('leave_request not found', 404);
        return;
    }

    $db->execute('DELETE FROM `LeaveRequest` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'hr', 'leave_request', 'DELETE', $params['id'], $leave, null);
    Router::json(['message' => 'leave_request deleted']);
});

// Time Entries
$router->add('GET', '/api/hr/time-entries', function () use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = hrPagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = hrFilters($query, ['status', 'employeeId']);

    $where = ['e.tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = '(te.project LIKE :search OR te.activity LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('te.`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = $db->query(
        "SELECT te.* FROM `TimeEntry` te JOIN `Employee` e ON te.employeeId = e.id $whereSql ORDER BY te.date DESC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($rows as &$row) {
        $row['employee'] = hrLoadEmployee($db, $row['employeeId']);
    }

    $countRows = $db->query(
        "SELECT COUNT(*) AS total FROM `TimeEntry` te JOIN `Employee` e ON te.employeeId = e.id $whereSql",
        $params
    );
    $total = (int) ($countRows[0]['total'] ?? 0);

    Router::json([
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ],
    ]);
});

$router->add('GET', '/api/hr/time-entries/{id}', function (array $params) {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();

    $rows = $db->query(
        'SELECT te.* FROM `TimeEntry` te JOIN `Employee` e ON te.employeeId = e.id WHERE te.id = :id AND e.tenantId = :tenantId LIMIT 1',
        ['id' => $params['id'], 'tenantId' => $user['tenantId']]
    );
    $entry = $rows[0] ?? null;
    if (!$entry) {
        Router::error('time_entry not found', 404);
        return;
    }

    $entry['employee'] = hrLoadEmployee($db, $entry['employeeId']);
    Router::json($entry);
});

$router->add('POST', '/api/hr/time-entries', function () use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $body = $request['body'] ?? [];

    $required = ['employeeId', 'date', 'hoursWorked'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid time_entry data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $employee = $db->query('SELECT * FROM `Employee` WHERE id = :id LIMIT 1', ['id' => $body['employeeId']]);
    if (!$employee || $employee[0]['tenantId'] !== $user['tenantId']) {
        Router::error('employee not found', 404);
        return;
    }

    $id = generateUuid();
    $db->execute(
        'INSERT INTO `TimeEntry` (id, employeeId, date, hoursWorked, overtime, project, activity, notes, status, createdAt)
         VALUES (:id, :employeeId, :date, :hoursWorked, :overtime, :project, :activity, :notes, :status, :createdAt)',
        [
            'id' => $id,
            'employeeId' => $body['employeeId'],
            'date' => $body['date'],
            'hoursWorked' => $body['hoursWorked'],
            'overtime' => $body['overtime'] ?? 0,
            'project' => $body['project'] ?? null,
            'activity' => $body['activity'] ?? null,
            'notes' => $body['notes'] ?? null,
            'status' => $body['status'] ?? 'draft',
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );

    $entry = $db->query('SELECT * FROM `TimeEntry` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($entry) {
        $entry['employee'] = hrLoadEmployee($db, $entry['employeeId']);
        logAudit($user, 'hr', 'time_entry', 'CREATE', $id, null, $entry);
        Router::json($entry, 201);
        return;
    }

    Router::error('Failed to create time_entry', 500);
});

$router->add('PUT', '/api/hr/time-entries/{id}', function (array $params) use ($request) {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `TimeEntry` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $entry = $rows[0] ?? null;
    if (!$entry) {
        Router::error('time_entry not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = ['employeeId', 'date', 'hoursWorked', 'overtime', 'project', 'activity', 'notes', 'status'];
    [$fields, $paramsUpdate] = hrBuildUpdate($body, $allowed);
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `TimeEntry` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `TimeEntry` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        $updated['employee'] = hrLoadEmployee($db, $updated['employeeId']);
        logAudit($user, 'hr', 'time_entry', 'UPDATE', $params['id'], $entry, $updated);
        Router::json($updated);
        return;
    }

    Router::error('time_entry not found', 404);
});

$router->add('DELETE', '/api/hr/time-entries/{id}', function (array $params) {
    $user = requireRoles(['admin', 'instructor']);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `TimeEntry` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $entry = $rows[0] ?? null;
    if (!$entry) {
        Router::error('time_entry not found', 404);
        return;
    }

    $db->execute('DELETE FROM `TimeEntry` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'hr', 'time_entry', 'DELETE', $params['id'], $entry, null);
    Router::json(['message' => 'time_entry deleted']);
});
