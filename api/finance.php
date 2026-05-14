<?php

declare(strict_types=1);

use App\DB;
use App\Router;
use function App\generateUuid;
use function App\logAudit;
use function App\requireRoles;

/** @var Router $router */
/** @var array $request */

function financePagination(array $query): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(100, max(1, (int) ($query['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    return [$page, $limit, $offset];
}

function financeFilters(array $query, array $allowed): array
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

function financeBuildUpdate(array $data, array $allowed): array
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

function financeLoadCompanyCode(DB $db, ?string $companyCodeId): ?array
{
    if (!$companyCodeId) {
        return null;
    }
    $rows = $db->query('SELECT * FROM `CompanyCode` WHERE id = :id LIMIT 1', ['id' => $companyCodeId]);
    return $rows[0] ?? null;
}

function financeLoadJournalLineItems(DB $db, string $entryId): array
{
    $rows = $db->query(
        'SELECT li.*, ga.id AS gl_id, ga.tenantId AS gl_tenantId, ga.companyCodeId AS gl_companyCodeId,
                ga.accountNumber AS gl_accountNumber, ga.name AS gl_name, ga.type AS gl_type,
                ga.parentId AS gl_parentId, ga.isActive AS gl_isActive, ga.currency AS gl_currency,
                ga.createdAt AS gl_createdAt
         FROM `JournalLineItem` li
         JOIN `GLAccount` ga ON ga.id = li.glAccountId
         WHERE li.journalEntryId = :id
         ORDER BY li.lineNumber ASC',
        ['id' => $entryId]
    );

    $items = [];
    foreach ($rows as $row) {
        $glAccount = [
            'id' => $row['gl_id'],
            'tenantId' => $row['gl_tenantId'],
            'companyCodeId' => $row['gl_companyCodeId'],
            'accountNumber' => $row['gl_accountNumber'],
            'name' => $row['gl_name'],
            'type' => $row['gl_type'],
            'parentId' => $row['gl_parentId'],
            'isActive' => (bool) $row['gl_isActive'],
            'currency' => $row['gl_currency'],
            'createdAt' => $row['gl_createdAt'],
        ];

        unset(
            $row['gl_id'],
            $row['gl_tenantId'],
            $row['gl_companyCodeId'],
            $row['gl_accountNumber'],
            $row['gl_name'],
            $row['gl_type'],
            $row['gl_parentId'],
            $row['gl_isActive'],
            $row['gl_currency'],
            $row['gl_createdAt']
        );

        $row['glAccount'] = $glAccount;
        $items[] = $row;
    }

    return $items;
}

// GL Accounts
$router->add('GET', '/api/finance/gl-accounts', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = financePagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = financeFilters($query, ['companyCodeId', 'type', 'isActive']);

    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = '(accountNumber LIKE :search OR name LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $data = $db->query(
        "SELECT * FROM `GLAccount` $whereSql ORDER BY accountNumber ASC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($data as &$row) {
        $row['companyCode'] = financeLoadCompanyCode($db, $row['companyCodeId'] ?? null);
    }

    $countRows = $db->query(
        "SELECT COUNT(*) AS total FROM `GLAccount` $whereSql",
        $params
    );
    $total = (int) ($countRows[0]['total'] ?? 0);

    Router::json([
        'data' => $data,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ],
    ]);
});

$router->add('GET', '/api/finance/gl-accounts/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `GLAccount` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;

    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('gl_account not found', 404);
        return;
    }

    $record['companyCode'] = financeLoadCompanyCode($db, $record['companyCodeId']);
    Router::json($record);
});

$router->add('POST', '/api/finance/gl-accounts', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['companyCodeId', 'accountNumber', 'name', 'type'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid gl_account data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `GLAccount` WHERE tenantId = :tenantId AND companyCodeId = :companyCodeId AND accountNumber = :accountNumber LIMIT 1',
        [
            'tenantId' => $user['tenantId'],
            'companyCodeId' => $body['companyCodeId'],
            'accountNumber' => $body['accountNumber'],
        ]
    );
    if ($existing) {
        Router::error('gl_account already exists with that key', 409);
        return;
    }

    $id = generateUuid();
    $db->execute(
        'INSERT INTO `GLAccount` (id, tenantId, companyCodeId, accountNumber, name, type, parentId, isActive, currency, createdAt)
         VALUES (:id, :tenantId, :companyCodeId, :accountNumber, :name, :type, :parentId, :isActive, :currency, :createdAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'companyCodeId' => $body['companyCodeId'],
            'accountNumber' => $body['accountNumber'],
            'name' => $body['name'],
            'type' => $body['type'],
            'parentId' => $body['parentId'] ?? null,
            'isActive' => $body['isActive'] ?? 1,
            'currency' => $body['currency'] ?? 'USD',
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );

    $record = $db->query('SELECT * FROM `GLAccount` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($record) {
        $record['companyCode'] = financeLoadCompanyCode($db, $record['companyCodeId']);
        logAudit($user, 'finance', 'gl_account', 'CREATE', $id, null, $record);
        Router::json($record, 201);
        return;
    }

    Router::error('Failed to create gl_account', 500);
});

$router->add('PUT', '/api/finance/gl-accounts/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `GLAccount` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;
    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('gl_account not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    [$fields, $paramsUpdate] = financeBuildUpdate($body, ['companyCodeId', 'accountNumber', 'name', 'type', 'parentId', 'isActive', 'currency']);
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `GLAccount` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `GLAccount` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        $updated['companyCode'] = financeLoadCompanyCode($db, $updated['companyCodeId']);
        logAudit($user, 'finance', 'gl_account', 'UPDATE', $params['id'], $record, $updated);
        Router::json($updated);
        return;
    }

    Router::error('gl_account not found', 404);
});

$router->add('DELETE', '/api/finance/gl-accounts/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `GLAccount` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;
    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('gl_account not found', 404);
        return;
    }

    $db->execute('DELETE FROM `GLAccount` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'finance', 'gl_account', 'DELETE', $params['id'], $record, null);
    Router::json(['message' => 'gl_account deleted']);
});

// Company Codes
$router->add('GET', '/api/finance/company-codes', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = financePagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = financeFilters($query, ['currency', 'country', 'fiscalYear']);

    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = '(code LIKE :search OR name LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $data = $db->query(
        "SELECT * FROM `CompanyCode` $whereSql ORDER BY code ASC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    $countRows = $db->query(
        "SELECT COUNT(*) AS total FROM `CompanyCode` $whereSql",
        $params
    );
    $total = (int) ($countRows[0]['total'] ?? 0);

    Router::json([
        'data' => $data,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ],
    ]);
});

$router->add('GET', '/api/finance/company-codes/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `CompanyCode` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;

    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('company_code not found', 404);
        return;
    }

    Router::json($record);
});

$router->add('POST', '/api/finance/company-codes', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['code', 'name'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid company_code data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `CompanyCode` WHERE tenantId = :tenantId AND code = :code LIMIT 1',
        ['tenantId' => $user['tenantId'], 'code' => $body['code']]
    );
    if ($existing) {
        Router::error('company_code already exists with that key', 409);
        return;
    }

    $id = generateUuid();
    $db->execute(
        'INSERT INTO `CompanyCode` (id, tenantId, code, name, currency, country, fiscalYear, createdAt)
         VALUES (:id, :tenantId, :code, :name, :currency, :country, :fiscalYear, :createdAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'code' => $body['code'],
            'name' => $body['name'],
            'currency' => $body['currency'] ?? 'USD',
            'country' => $body['country'] ?? 'US',
            'fiscalYear' => $body['fiscalYear'] ?? 'calendar',
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );

    $record = $db->query('SELECT * FROM `CompanyCode` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($record) {
        logAudit($user, 'finance', 'company_code', 'CREATE', $id, null, $record);
        Router::json($record, 201);
        return;
    }

    Router::error('Failed to create company_code', 500);
});

$router->add('PUT', '/api/finance/company-codes/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `CompanyCode` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;
    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('company_code not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    [$fields, $paramsUpdate] = financeBuildUpdate($body, ['code', 'name', 'currency', 'country', 'fiscalYear']);
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `CompanyCode` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `CompanyCode` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        logAudit($user, 'finance', 'company_code', 'UPDATE', $params['id'], $record, $updated);
        Router::json($updated);
        return;
    }

    Router::error('company_code not found', 404);
});

$router->add('DELETE', '/api/finance/company-codes/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `CompanyCode` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;
    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('company_code not found', 404);
        return;
    }

    $db->execute('DELETE FROM `CompanyCode` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'finance', 'company_code', 'DELETE', $params['id'], $record, null);
    Router::json(['message' => 'company_code deleted']);
});

// Journal Entries
$router->add('GET', '/api/finance/journal-entries', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = financePagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $status = $query['status'] ?? null;

    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($status) {
        $where[] = 'status = :status';
        $params['status'] = $status;
    }
    if ($search !== '') {
        $where[] = '(documentNumber LIKE :search OR description LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $entries = $db->query(
        "SELECT * FROM `JournalEntry` $whereSql ORDER BY createdAt DESC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    foreach ($entries as &$entry) {
        $entry['lineItems'] = financeLoadJournalLineItems($db, $entry['id']);
        $entry['companyCode'] = financeLoadCompanyCode($db, $entry['companyCodeId']);
    }

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `JournalEntry` $whereSql", $params);
    $total = (int) ($countRows[0]['total'] ?? 0);

    Router::json([
        'data' => $entries,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ],
    ]);
});

$router->add('GET', '/api/finance/journal-entries/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `JournalEntry` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $entry = $rows[0] ?? null;
    if (!$entry || $entry['tenantId'] !== $user['tenantId']) {
        Router::error('Journal entry not found', 404);
        return;
    }

    $entry['lineItems'] = financeLoadJournalLineItems($db, $entry['id']);
    $entry['companyCode'] = financeLoadCompanyCode($db, $entry['companyCodeId']);

    Router::json($entry);
});

$router->add('POST', '/api/finance/journal-entries', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];
    $lineItems = $body['lineItems'] ?? [];

    if (!is_array($lineItems) || count($lineItems) < 2) {
        Router::error('At least two line items required', 400);
        return;
    }

    $totalDebit = array_reduce($lineItems, fn ($sum, $li) => $sum + (float) ($li['debit'] ?? 0), 0.0);
    $totalCredit = array_reduce($lineItems, fn ($sum, $li) => $sum + (float) ($li['credit'] ?? 0), 0.0);
    if (abs($totalDebit - $totalCredit) > 0.01) {
        Router::error(sprintf('Debits (%.2f) must equal credits (%.2f)', $totalDebit, $totalCredit), 400);
        return;
    }

    if (empty($body['companyCodeId'])) {
        Router::error('companyCodeId is required', 400);
        return;
    }

    $db = DB::getInstance();
    $countRows = $db->query('SELECT COUNT(*) AS total FROM `JournalEntry` WHERE tenantId = :tenantId', ['tenantId' => $user['tenantId']]);
    $count = (int) ($countRows[0]['total'] ?? 0);
    $docNum = sprintf('JE-%s', str_pad((string) ($count + 1), 7, '0', STR_PAD_LEFT));

    $entryId = generateUuid();
    $now = date('Y-m-d H:i:s');
    $db->transaction(function (DB $db) use ($body, $lineItems, $user, $entryId, $docNum, $now) {
        $db->execute(
            'INSERT INTO `JournalEntry` (id, tenantId, companyCodeId, documentNumber, postingDate, documentDate, description, reference, status, reversalOf, createdBy, createdAt)
             VALUES (:id, :tenantId, :companyCodeId, :documentNumber, :postingDate, :documentDate, :description, :reference, :status, :reversalOf, :createdBy, :createdAt)',
            [
                'id' => $entryId,
                'tenantId' => $user['tenantId'],
                'companyCodeId' => $body['companyCodeId'],
                'documentNumber' => $docNum,
                'postingDate' => $body['postingDate'] ?? $now,
                'documentDate' => $body['documentDate'] ?? $now,
                'description' => $body['description'] ?? null,
                'reference' => $body['reference'] ?? null,
                'status' => $body['status'] ?? 'draft',
                'reversalOf' => $body['reversalOf'] ?? null,
                'createdBy' => $user['userId'],
                'createdAt' => $now,
            ]
        );

        foreach ($lineItems as $index => $item) {
            $db->execute(
                'INSERT INTO `JournalLineItem` (id, journalEntryId, glAccountId, lineNumber, debit, credit, description, costCenterId, internalOrderId, profitCenterId)
                 VALUES (:id, :journalEntryId, :glAccountId, :lineNumber, :debit, :credit, :description, :costCenterId, :internalOrderId, :profitCenterId)',
                [
                    'id' => generateUuid(),
                    'journalEntryId' => $entryId,
                    'glAccountId' => $item['glAccountId'],
                    'lineNumber' => $index + 1,
                    'debit' => $item['debit'] ?? 0,
                    'credit' => $item['credit'] ?? 0,
                    'description' => $item['description'] ?? null,
                    'costCenterId' => $item['costCenterId'] ?? null,
                    'internalOrderId' => $item['internalOrderId'] ?? null,
                    'profitCenterId' => $item['profitCenterId'] ?? null,
                ]
            );
        }
    });

    $entry = $db->query('SELECT * FROM `JournalEntry` WHERE id = :id LIMIT 1', ['id' => $entryId])[0] ?? null;
    if ($entry) {
        $entry['lineItems'] = financeLoadJournalLineItems($db, $entryId);
        $entry['companyCode'] = financeLoadCompanyCode($db, $entry['companyCodeId']);
        logAudit($user, 'finance', 'journal_entry', 'CREATE', $entryId, null, $entry);
        Router::json($entry, 201);
        return;
    }

    Router::error('Failed to create journal entry', 500);
});

$router->add('POST', '/api/finance/journal-entries/{id}/post', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `JournalEntry` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $entry = $rows[0] ?? null;
    if (!$entry || $entry['tenantId'] !== $user['tenantId']) {
        Router::error('Journal entry not found', 404);
        return;
    }
    if ($entry['status'] !== 'draft') {
        Router::error('Only draft entries can be posted', 400);
        return;
    }

    $db->execute('UPDATE `JournalEntry` SET status = :status WHERE id = :id', ['status' => 'posted', 'id' => $entry['id']]);
    $updated = $db->query('SELECT * FROM `JournalEntry` WHERE id = :id LIMIT 1', ['id' => $entry['id']])[0] ?? null;
    if ($updated) {
        $updated['lineItems'] = financeLoadJournalLineItems($db, $updated['id']);
        $updated['companyCode'] = financeLoadCompanyCode($db, $updated['companyCodeId']);
        logAudit($user, 'finance', 'journal_entry', 'UPDATE', $updated['id'], $entry, $updated);
        Router::json($updated);
        return;
    }

    Router::error('Journal entry not found', 404);
});

$router->add('POST', '/api/finance/journal-entries/{id}/reverse', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `JournalEntry` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $entry = $rows[0] ?? null;
    if (!$entry || $entry['tenantId'] !== $user['tenantId']) {
        Router::error('Journal entry not found', 404);
        return;
    }
    if ($entry['status'] !== 'posted') {
        Router::error('Only posted entries can be reversed', 400);
        return;
    }

    $lineItems = $db->query('SELECT * FROM `JournalLineItem` WHERE journalEntryId = :id', ['id' => $entry['id']]);

    $countRows = $db->query('SELECT COUNT(*) AS total FROM `JournalEntry` WHERE tenantId = :tenantId', ['tenantId' => $user['tenantId']]);
    $count = (int) ($countRows[0]['total'] ?? 0);
    $docNum = sprintf('JE-%s', str_pad((string) ($count + 1), 7, '0', STR_PAD_LEFT));

    $reversalId = generateUuid();
    $now = date('Y-m-d H:i:s');

    $db->transaction(function (DB $db) use ($entry, $lineItems, $user, $reversalId, $docNum, $now) {
        $db->execute('UPDATE `JournalEntry` SET status = :status WHERE id = :id', ['status' => 'reversed', 'id' => $entry['id']]);

        $db->execute(
            'INSERT INTO `JournalEntry` (id, tenantId, companyCodeId, documentNumber, postingDate, documentDate, description, reference, status, reversalOf, createdBy, createdAt)
             VALUES (:id, :tenantId, :companyCodeId, :documentNumber, :postingDate, :documentDate, :description, :reference, :status, :reversalOf, :createdBy, :createdAt)',
            [
                'id' => $reversalId,
                'tenantId' => $entry['tenantId'],
                'companyCodeId' => $entry['companyCodeId'],
                'documentNumber' => $docNum,
                'postingDate' => $now,
                'documentDate' => $now,
                'description' => sprintf('Reversal of %s', $entry['documentNumber']),
                'reference' => null,
                'status' => 'posted',
                'reversalOf' => $entry['id'],
                'createdBy' => $user['userId'],
                'createdAt' => $now,
            ]
        );

        foreach ($lineItems as $item) {
            $db->execute(
                'INSERT INTO `JournalLineItem` (id, journalEntryId, glAccountId, lineNumber, debit, credit, description, costCenterId, internalOrderId, profitCenterId)
                 VALUES (:id, :journalEntryId, :glAccountId, :lineNumber, :debit, :credit, :description, :costCenterId, :internalOrderId, :profitCenterId)',
                [
                    'id' => generateUuid(),
                    'journalEntryId' => $reversalId,
                    'glAccountId' => $item['glAccountId'],
                    'lineNumber' => $item['lineNumber'],
                    'debit' => $item['credit'],
                    'credit' => $item['debit'],
                    'description' => sprintf('Reversal: %s', $item['description'] ?? ''),
                    'costCenterId' => $item['costCenterId'] ?? null,
                    'internalOrderId' => $item['internalOrderId'] ?? null,
                    'profitCenterId' => $item['profitCenterId'] ?? null,
                ]
            );
        }
    });

    $reversal = $db->query('SELECT * FROM `JournalEntry` WHERE id = :id LIMIT 1', ['id' => $reversalId])[0] ?? null;
    if ($reversal) {
        $reversal['lineItems'] = $db->query('SELECT * FROM `JournalLineItem` WHERE journalEntryId = :id', ['id' => $reversalId]);
        logAudit($user, 'finance', 'journal_entry', 'UPDATE', $reversalId, $entry, $reversal);
        Router::json($reversal, 201);
        return;
    }

    Router::error('Failed to reverse journal entry', 500);
});

// Vendors
$router->add('GET', '/api/finance/vendors', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = financePagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = financeFilters($query, ['paymentTerms', 'currency', 'isActive']);

    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = '(vendorNumber LIKE :search OR name LIKE :search OR email LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $data = $db->query(
        "SELECT * FROM `Vendor` $whereSql ORDER BY vendorNumber ASC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `Vendor` $whereSql", $params);
    $total = (int) ($countRows[0]['total'] ?? 0);

    Router::json([
        'data' => $data,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ],
    ]);
});

$router->add('GET', '/api/finance/vendors/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Vendor` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;

    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('vendor not found', 404);
        return;
    }

    Router::json($record);
});

$router->add('POST', '/api/finance/vendors', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['vendorNumber', 'name'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid vendor data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `Vendor` WHERE tenantId = :tenantId AND vendorNumber = :vendorNumber LIMIT 1',
        ['tenantId' => $user['tenantId'], 'vendorNumber' => $body['vendorNumber']]
    );
    if ($existing) {
        Router::error('vendor already exists with that key', 409);
        return;
    }

    $id = generateUuid();
    $now = date('Y-m-d H:i:s');

    $db->execute(
        'INSERT INTO `Vendor` (id, tenantId, vendorNumber, name, street, city, state, postalCode, country, phone, email, taxId, paymentTerms, currency, bankAccount, bankRouting, isActive, createdAt, updatedAt)
         VALUES (:id, :tenantId, :vendorNumber, :name, :street, :city, :state, :postalCode, :country, :phone, :email, :taxId, :paymentTerms, :currency, :bankAccount, :bankRouting, :isActive, :createdAt, :updatedAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'vendorNumber' => $body['vendorNumber'],
            'name' => $body['name'],
            'street' => $body['street'] ?? null,
            'city' => $body['city'] ?? null,
            'state' => $body['state'] ?? null,
            'postalCode' => $body['postalCode'] ?? null,
            'country' => $body['country'] ?? 'US',
            'phone' => $body['phone'] ?? null,
            'email' => $body['email'] ?? null,
            'taxId' => $body['taxId'] ?? null,
            'paymentTerms' => $body['paymentTerms'] ?? 'NET30',
            'currency' => $body['currency'] ?? 'USD',
            'bankAccount' => $body['bankAccount'] ?? null,
            'bankRouting' => $body['bankRouting'] ?? null,
            'isActive' => $body['isActive'] ?? 1,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]
    );

    $record = $db->query('SELECT * FROM `Vendor` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($record) {
        logAudit($user, 'finance', 'vendor', 'CREATE', $id, null, $record);
        Router::json($record, 201);
        return;
    }

    Router::error('Failed to create vendor', 500);
});

$router->add('PUT', '/api/finance/vendors/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Vendor` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;
    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('vendor not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = ['vendorNumber', 'name', 'street', 'city', 'state', 'postalCode', 'country', 'phone', 'email', 'taxId', 'paymentTerms', 'currency', 'bankAccount', 'bankRouting', 'isActive'];
    [$fields, $paramsUpdate] = financeBuildUpdate($body, $allowed);
    $paramsUpdate['updatedAt'] = date('Y-m-d H:i:s');
    $fields[] = '`updatedAt` = :updatedAt';
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `Vendor` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `Vendor` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        logAudit($user, 'finance', 'vendor', 'UPDATE', $params['id'], $record, $updated);
        Router::json($updated);
        return;
    }

    Router::error('vendor not found', 404);
});

$router->add('DELETE', '/api/finance/vendors/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Vendor` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;
    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('vendor not found', 404);
        return;
    }

    $db->execute('DELETE FROM `Vendor` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'finance', 'vendor', 'DELETE', $params['id'], $record, null);
    Router::json(['message' => 'vendor deleted']);
});

// Customers
$router->add('GET', '/api/finance/customers', function () use ($request) {
    $user = requireRoles([]);
    $query = $request['query'] ?? [];
    [$page, $limit, $offset] = financePagination($query);

    $db = DB::getInstance();
    $search = trim((string) ($query['search'] ?? ''));
    $filters = financeFilters($query, ['paymentTerms', 'currency', 'isActive']);

    $where = ['tenantId = :tenantId'];
    $params = ['tenantId' => $user['tenantId']];

    if ($search !== '') {
        $where[] = '(customerNumber LIKE :search OR name LIKE :search OR email LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    foreach ($filters as $field => $value) {
        $where[] = sprintf('`%s` = :%s', $field, $field);
        $params[$field] = $value;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $data = $db->query(
        "SELECT * FROM `Customer` $whereSql ORDER BY customerNumber ASC LIMIT :limit OFFSET :offset",
        $params + ['limit' => $limit, 'offset' => $offset]
    );

    $countRows = $db->query("SELECT COUNT(*) AS total FROM `Customer` $whereSql", $params);
    $total = (int) ($countRows[0]['total'] ?? 0);

    Router::json([
        'data' => $data,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ],
    ]);
});

$router->add('GET', '/api/finance/customers/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Customer` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;

    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('customer not found', 404);
        return;
    }

    Router::json($record);
});

$router->add('POST', '/api/finance/customers', function () use ($request) {
    $user = requireRoles([]);
    $body = $request['body'] ?? [];

    $required = ['customerNumber', 'name'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            Router::error('Invalid customer data', 400);
            return;
        }
    }

    $db = DB::getInstance();
    $existing = $db->query(
        'SELECT id FROM `Customer` WHERE tenantId = :tenantId AND customerNumber = :customerNumber LIMIT 1',
        ['tenantId' => $user['tenantId'], 'customerNumber' => $body['customerNumber']]
    );
    if ($existing) {
        Router::error('customer already exists with that key', 409);
        return;
    }

    $id = generateUuid();
    $now = date('Y-m-d H:i:s');

    $db->execute(
        'INSERT INTO `Customer` (id, tenantId, customerNumber, name, street, city, state, postalCode, country, phone, email, taxId, paymentTerms, creditLimit, currency, priceList, isActive, createdAt, updatedAt)
         VALUES (:id, :tenantId, :customerNumber, :name, :street, :city, :state, :postalCode, :country, :phone, :email, :taxId, :paymentTerms, :creditLimit, :currency, :priceList, :isActive, :createdAt, :updatedAt)',
        [
            'id' => $id,
            'tenantId' => $user['tenantId'],
            'customerNumber' => $body['customerNumber'],
            'name' => $body['name'],
            'street' => $body['street'] ?? null,
            'city' => $body['city'] ?? null,
            'state' => $body['state'] ?? null,
            'postalCode' => $body['postalCode'] ?? null,
            'country' => $body['country'] ?? 'US',
            'phone' => $body['phone'] ?? null,
            'email' => $body['email'] ?? null,
            'taxId' => $body['taxId'] ?? null,
            'paymentTerms' => $body['paymentTerms'] ?? 'NET30',
            'creditLimit' => $body['creditLimit'] ?? 0,
            'currency' => $body['currency'] ?? 'USD',
            'priceList' => $body['priceList'] ?? null,
            'isActive' => $body['isActive'] ?? 1,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]
    );

    $record = $db->query('SELECT * FROM `Customer` WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
    if ($record) {
        logAudit($user, 'finance', 'customer', 'CREATE', $id, null, $record);
        Router::json($record, 201);
        return;
    }

    Router::error('Failed to create customer', 500);
});

$router->add('PUT', '/api/finance/customers/{id}', function (array $params) use ($request) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Customer` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;
    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('customer not found', 404);
        return;
    }

    $body = $request['body'] ?? [];
    $allowed = ['customerNumber', 'name', 'street', 'city', 'state', 'postalCode', 'country', 'phone', 'email', 'taxId', 'paymentTerms', 'creditLimit', 'currency', 'priceList', 'isActive'];
    [$fields, $paramsUpdate] = financeBuildUpdate($body, $allowed);
    $paramsUpdate['updatedAt'] = date('Y-m-d H:i:s');
    $fields[] = '`updatedAt` = :updatedAt';
    if ($fields) {
        $paramsUpdate['id'] = $params['id'];
        $db->execute('UPDATE `Customer` SET ' . implode(', ', $fields) . ' WHERE id = :id', $paramsUpdate);
    }

    $updated = $db->query('SELECT * FROM `Customer` WHERE id = :id LIMIT 1', ['id' => $params['id']])[0] ?? null;
    if ($updated) {
        logAudit($user, 'finance', 'customer', 'UPDATE', $params['id'], $record, $updated);
        Router::json($updated);
        return;
    }

    Router::error('customer not found', 404);
});

$router->add('DELETE', '/api/finance/customers/{id}', function (array $params) {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query('SELECT * FROM `Customer` WHERE id = :id LIMIT 1', ['id' => $params['id']]);
    $record = $rows[0] ?? null;
    if (!$record || $record['tenantId'] !== $user['tenantId']) {
        Router::error('customer not found', 404);
        return;
    }

    $db->execute('DELETE FROM `Customer` WHERE id = :id', ['id' => $params['id']]);
    logAudit($user, 'finance', 'customer', 'DELETE', $params['id'], $record, null);
    Router::json(['message' => 'customer deleted']);
});

// Trial Balance
$router->add('GET', '/api/finance/trial-balance', function () {
    $user = requireRoles([]);
    $db = DB::getInstance();

    $rows = $db->query(
        'SELECT ga.accountNumber, ga.name, ga.type,
                COALESCE(SUM(li.debit), 0) AS debit,
                COALESCE(SUM(li.credit), 0) AS credit
         FROM `GLAccount` ga
         LEFT JOIN `JournalLineItem` li ON li.glAccountId = ga.id
         LEFT JOIN `JournalEntry` je ON je.id = li.journalEntryId AND je.status = :status AND je.tenantId = :tenantId
         WHERE ga.tenantId = :tenantId
         GROUP BY ga.id
         ORDER BY ga.accountNumber ASC',
        [
            'status' => 'posted',
            'tenantId' => $user['tenantId'],
        ]
    );

    $trialBalance = array_map(function ($row) {
        $debit = (float) ($row['debit'] ?? 0);
        $credit = (float) ($row['credit'] ?? 0);
        return [
            'accountNumber' => $row['accountNumber'],
            'name' => $row['name'],
            'type' => $row['type'],
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $debit - $credit,
        ];
    }, $rows);

    Router::json($trialBalance);
});
