<?php

declare(strict_types=1);

use App\DB;
use Dotenv\Dotenv;
use function App\generateUuid;

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$db = DB::getInstance();

function findRow(DB $db, string $sql, array $params): ?array
{
    $rows = $db->query($sql, $params);
    return $rows[0] ?? null;
}

function ensureTenant(DB $db, array $data): array
{
    $tenant = findRow($db, 'SELECT * FROM `Tenant` WHERE slug = :slug LIMIT 1', ['slug' => $data['slug']]);
    if ($tenant) {
        return $tenant;
    }
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `Tenant` (id, name, slug, university, description, isActive, createdAt, updatedAt)
         VALUES (:id, :name, :slug, :university, :description, :isActive, :createdAt, :updatedAt)',
        [
            'id' => $id,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'university' => $data['university'] ?? null,
            'description' => $data['description'] ?? null,
            'isActive' => 1,
            'createdAt' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
        ]
    );
    return findRow($db, 'SELECT * FROM `Tenant` WHERE id = :id LIMIT 1', ['id' => $id]) ?? [];
}

function ensureRole(DB $db, string $tenantId, string $name): array
{
    $role = findRow($db, 'SELECT * FROM `Role` WHERE tenantId = :tenantId AND name = :name LIMIT 1', [
        'tenantId' => $tenantId,
        'name' => $name,
    ]);
    if ($role) {
        return $role;
    }
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `Role` (id, tenantId, name, isSystem, createdAt)
         VALUES (:id, :tenantId, :name, :isSystem, :createdAt)',
        [
            'id' => $id,
            'tenantId' => $tenantId,
            'name' => $name,
            'isSystem' => 1,
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );
    return findRow($db, 'SELECT * FROM `Role` WHERE id = :id LIMIT 1', ['id' => $id]) ?? [];
}

function ensureUser(DB $db, array $data, string $tenantId): array
{
    $user = findRow($db, 'SELECT * FROM `User` WHERE tenantId = :tenantId AND email = :email LIMIT 1', [
        'tenantId' => $tenantId,
        'email' => $data['email'],
    ]);
    if ($user) {
        return $user;
    }
    $id = generateUuid();
    $db->execute(
        'INSERT INTO `User` (id, tenantId, email, passwordHash, firstName, lastName, isActive, createdAt, updatedAt)
         VALUES (:id, :tenantId, :email, :passwordHash, :firstName, :lastName, :isActive, :createdAt, :updatedAt)',
        [
            'id' => $id,
            'tenantId' => $tenantId,
            'email' => $data['email'],
            'passwordHash' => password_hash($data['password'], PASSWORD_BCRYPT),
            'firstName' => $data['firstName'],
            'lastName' => $data['lastName'],
            'isActive' => 1,
            'createdAt' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
        ]
    );
    return findRow($db, 'SELECT * FROM `User` WHERE id = :id LIMIT 1', ['id' => $id]) ?? [];
}

function ensureUserRole(DB $db, string $userId, string $roleId): void
{
    $existing = findRow($db, 'SELECT id FROM `UserRole` WHERE userId = :userId AND roleId = :roleId LIMIT 1', [
        'userId' => $userId,
        'roleId' => $roleId,
    ]);
    if ($existing) {
        return;
    }
    $db->execute(
        'INSERT INTO `UserRole` (id, userId, roleId) VALUES (:id, :userId, :roleId)',
        [
            'id' => generateUuid(),
            'userId' => $userId,
            'roleId' => $roleId,
        ]
    );
}

$tenant = ensureTenant($db, [
    'name' => 'ENSAK SAP Training',
    'slug' => 'ensak',
    'university' => 'ENSAK',
    'description' => 'SAP learning sandbox',
]);
$tenantId = $tenant['id'] ?? null;
if (!$tenantId) {
    echo "Failed to create tenant\n";
    exit(1);
}

$roles = [];
foreach (['admin', 'instructor', 'student', 'auditor'] as $roleName) {
    $roles[$roleName] = ensureRole($db, $tenantId, $roleName);
}

$adminUser = ensureUser($db, [
    'email' => 'admin@bahlaq.com',
    'password' => 'password123',
    'firstName' => 'Admin',
    'lastName' => 'User',
], $tenantId);
ensureUserRole($db, $adminUser['id'], $roles['admin']['id']);

$instructorUser = ensureUser($db, [
    'email' => 'instructor@bahlaq.com',
    'password' => 'password123',
    'firstName' => 'Instructor',
    'lastName' => 'User',
], $tenantId);
ensureUserRole($db, $instructorUser['id'], $roles['instructor']['id']);

$companyCode = findRow($db, 'SELECT * FROM `CompanyCode` WHERE tenantId = :tenantId AND code = :code LIMIT 1', [
    'tenantId' => $tenantId,
    'code' => 'BH01',
]);
if (!$companyCode) {
    $companyId = generateUuid();
    $db->execute(
        'INSERT INTO `CompanyCode` (id, tenantId, code, name, currency, country, fiscalYear, createdAt)
         VALUES (:id, :tenantId, :code, :name, :currency, :country, :fiscalYear, :createdAt)',
        [
            'id' => $companyId,
            'tenantId' => $tenantId,
            'code' => 'BH01',
            'name' => 'Bahlaq Headquarters',
            'currency' => 'USD',
            'country' => 'US',
            'fiscalYear' => 'calendar',
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );
}

$glAccounts = [
    ['1000', 'Cash', 'asset'],
    ['1100', 'Accounts Receivable', 'asset'],
    ['1200', 'Inventory', 'asset'],
    ['2000', 'Accounts Payable', 'liability'],
    ['3000', 'Sales Revenue', 'revenue'],
    ['4000', 'Cost of Goods Sold', 'expense'],
    ['5000', 'Operating Expenses', 'expense'],
];

foreach ($glAccounts as [$number, $name, $type]) {
    $exists = findRow($db, 'SELECT id FROM `GLAccount` WHERE tenantId = :tenantId AND accountNumber = :accountNumber LIMIT 1', [
        'tenantId' => $tenantId,
        'accountNumber' => $number,
    ]);
    if ($exists) {
        continue;
    }
    $db->execute(
        'INSERT INTO `GLAccount` (id, tenantId, companyCodeId, accountNumber, name, type, isActive, currency, createdAt)
         VALUES (:id, :tenantId, :companyCodeId, :accountNumber, :name, :type, :isActive, :currency, :createdAt)',
        [
            'id' => generateUuid(),
            'tenantId' => $tenantId,
            'companyCodeId' => $companyId ?? ($companyCode['id'] ?? null),
            'accountNumber' => $number,
            'name' => $name,
            'type' => $type,
            'isActive' => 1,
            'currency' => 'USD',
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    );
}

$plant = findRow($db, 'SELECT * FROM `Plant` WHERE tenantId = :tenantId AND code = :code LIMIT 1', [
    'tenantId' => $tenantId,
    'code' => 'PL01',
]);
if (!$plant) {
    $plantId = generateUuid();
    $db->execute(
        'INSERT INTO `Plant` (id, tenantId, code, name, address, isActive)
         VALUES (:id, :tenantId, :code, :name, :address, :isActive)',
        [
            'id' => $plantId,
            'tenantId' => $tenantId,
            'code' => 'PL01',
            'name' => 'Main Plant',
            'address' => 'Bahlaq Industrial Zone',
            'isActive' => 1,
        ]
    );
    $plant = findRow($db, 'SELECT * FROM `Plant` WHERE id = :id LIMIT 1', ['id' => $plantId]);
}

$vendors = [
    ['V-1000', 'Atlas Components', 'atlas@example.com'],
    ['V-2000', 'Sahara Supplies', 'sahara@example.com'],
];
foreach ($vendors as [$number, $name, $email]) {
    $exists = findRow($db, 'SELECT id FROM `Vendor` WHERE tenantId = :tenantId AND vendorNumber = :vendorNumber LIMIT 1', [
        'tenantId' => $tenantId,
        'vendorNumber' => $number,
    ]);
    if ($exists) {
        continue;
    }
    $db->execute(
        'INSERT INTO `Vendor` (id, tenantId, vendorNumber, name, email, country, paymentTerms, currency, isActive, createdAt, updatedAt)
         VALUES (:id, :tenantId, :vendorNumber, :name, :email, :country, :paymentTerms, :currency, :isActive, :createdAt, :updatedAt)',
        [
            'id' => generateUuid(),
            'tenantId' => $tenantId,
            'vendorNumber' => $number,
            'name' => $name,
            'email' => $email,
            'country' => 'US',
            'paymentTerms' => 'NET30',
            'currency' => 'USD',
            'isActive' => 1,
            'createdAt' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
        ]
    );
}

$customers = [
    ['C-1000', 'Bahlaq Retail', 'retail@example.com'],
    ['C-2000', 'Atlas Markets', 'markets@example.com'],
];
foreach ($customers as [$number, $name, $email]) {
    $exists = findRow($db, 'SELECT id FROM `Customer` WHERE tenantId = :tenantId AND customerNumber = :customerNumber LIMIT 1', [
        'tenantId' => $tenantId,
        'customerNumber' => $number,
    ]);
    if ($exists) {
        continue;
    }
    $db->execute(
        'INSERT INTO `Customer` (id, tenantId, customerNumber, name, email, country, paymentTerms, currency, isActive, createdAt, updatedAt)
         VALUES (:id, :tenantId, :customerNumber, :name, :email, :country, :paymentTerms, :currency, :isActive, :createdAt, :updatedAt)',
        [
            'id' => generateUuid(),
            'tenantId' => $tenantId,
            'customerNumber' => $number,
            'name' => $name,
            'email' => $email,
            'country' => 'US',
            'paymentTerms' => 'NET30',
            'currency' => 'USD',
            'isActive' => 1,
            'createdAt' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
        ]
    );
}

$materials = [
    ['RM-STEEL', 'Steel Coil', 'raw'],
    ['RM-PLASTIC', 'Plastic Resin', 'raw'],
    ['FG-CHAIR', 'Office Chair', 'finished'],
];
foreach ($materials as [$number, $description, $type]) {
    $exists = findRow($db, 'SELECT id FROM `Material` WHERE tenantId = :tenantId AND materialNumber = :materialNumber LIMIT 1', [
        'tenantId' => $tenantId,
        'materialNumber' => $number,
    ]);
    if ($exists) {
        continue;
    }
    $db->execute(
        'INSERT INTO `Material` (id, tenantId, materialNumber, description, type, baseUnit, materialGroup, standardPrice, movingAvgPrice, lotSize, safetyStock, reorderPoint, leadTimeDays, stockQuantity, reservedQty, isActive, createdAt, updatedAt)
         VALUES (:id, :tenantId, :materialNumber, :description, :type, :baseUnit, :materialGroup, :standardPrice, :movingAvgPrice, :lotSize, :safetyStock, :reorderPoint, :leadTimeDays, :stockQuantity, :reservedQty, :isActive, :createdAt, :updatedAt)',
        [
            'id' => generateUuid(),
            'tenantId' => $tenantId,
            'materialNumber' => $number,
            'description' => $description,
            'type' => $type,
            'baseUnit' => 'EA',
            'materialGroup' => 'GENERAL',
            'standardPrice' => 50,
            'movingAvgPrice' => 45,
            'lotSize' => 10,
            'safetyStock' => 5,
            'reorderPoint' => 10,
            'leadTimeDays' => 7,
            'stockQuantity' => 100,
            'reservedQty' => 0,
            'isActive' => 1,
            'createdAt' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
        ]
    );
}

$warehouse = findRow($db, 'SELECT * FROM `Warehouse` WHERE tenantId = :tenantId AND code = :code LIMIT 1', [
    'tenantId' => $tenantId,
    'code' => 'WH01',
]);
if (!$warehouse) {
    $warehouseId = generateUuid();
    $db->execute(
        'INSERT INTO `Warehouse` (id, tenantId, plantId, code, name, type, isActive)
         VALUES (:id, :tenantId, :plantId, :code, :name, :type, :isActive)',
        [
            'id' => $warehouseId,
            'tenantId' => $tenantId,
            'plantId' => $plant['id'],
            'code' => 'WH01',
            'name' => 'Main Warehouse',
            'type' => 'standard',
            'isActive' => 1,
        ]
    );
    $warehouse = findRow($db, 'SELECT * FROM `Warehouse` WHERE id = :id LIMIT 1', ['id' => $warehouseId]);
}

$binCodes = ['A-01', 'A-02', 'B-01'];
foreach ($binCodes as $binCode) {
    $exists = findRow($db, 'SELECT id FROM `WarehouseBin` WHERE warehouseId = :warehouseId AND binCode = :binCode LIMIT 1', [
        'warehouseId' => $warehouse['id'],
        'binCode' => $binCode,
    ]);
    if ($exists) {
        continue;
    }
    $db->execute(
        'INSERT INTO `WarehouseBin` (id, warehouseId, binCode, zone, aisle, rack, level, materialId, quantity, maxCapacity, binType)
         VALUES (:id, :warehouseId, :binCode, :zone, :aisle, :rack, :level, :materialId, :quantity, :maxCapacity, :binType)',
        [
            'id' => generateUuid(),
            'warehouseId' => $warehouse['id'],
            'binCode' => $binCode,
            'zone' => 'A',
            'aisle' => '1',
            'rack' => 'R1',
            'level' => 'L1',
            'materialId' => null,
            'quantity' => 0,
            'maxCapacity' => 1000,
            'binType' => 'storage',
        ]
    );
}

echo "Seed data loaded.\n";
