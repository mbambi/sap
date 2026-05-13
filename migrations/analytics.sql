SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- CreateTable
CREATE TABLE `ProcessEvent` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `caseId` VARCHAR(191) NOT NULL,
    `activity` VARCHAR(191) NOT NULL,
    `timestamp` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `resource` VARCHAR(191) NULL,
    `module` VARCHAR(191) NOT NULL,
    `documentId` VARCHAR(191) NULL,
    `attributes` VARCHAR(191) NULL,
    `duration` DOUBLE NULL,

    INDEX `ProcessEvent_tenantId_caseId_idx`(`tenantId`, `caseId`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `SavedReport` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `description` VARCHAR(191) NULL,
    `module` VARCHAR(191) NOT NULL,
    `reportType` VARCHAR(191) NOT NULL,
    `config` VARCHAR(191) NOT NULL,
    `isPublic` BOOLEAN NOT NULL DEFAULT false,
    `createdBy` VARCHAR(191) NOT NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updatedAt` DATETIME(3) NOT NULL,

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `FactSales` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `dateKey` VARCHAR(191) NOT NULL,
    `customerId` VARCHAR(191) NOT NULL,
    `materialId` VARCHAR(191) NOT NULL,
    `companyCode` VARCHAR(191) NULL,
    `quantity` DOUBLE NOT NULL,
    `revenue` DOUBLE NOT NULL,
    `cost` DOUBLE NOT NULL,
    `profit` DOUBLE NOT NULL,
    `discount` DOUBLE NOT NULL DEFAULT 0,
    `region` VARCHAR(191) NULL,

    INDEX `FactSales_tenantId_dateKey_idx`(`tenantId`, `dateKey`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `FactInventory` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `dateKey` VARCHAR(191) NOT NULL,
    `materialId` VARCHAR(191) NOT NULL,
    `warehouseId` VARCHAR(191) NULL,
    `stockQuantity` DOUBLE NOT NULL,
    `stockValue` DOUBLE NOT NULL,
    `inboundQty` DOUBLE NOT NULL DEFAULT 0,
    `outboundQty` DOUBLE NOT NULL DEFAULT 0,

    INDEX `FactInventory_tenantId_dateKey_idx`(`tenantId`, `dateKey`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `OperationsMetric` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `metricType` VARCHAR(191) NOT NULL,
    `workCenterId` VARCHAR(191) NULL,
    `materialId` VARCHAR(191) NULL,
    `periodStart` DATETIME(3) NOT NULL,
    `periodEnd` DATETIME(3) NOT NULL,
    `value` DOUBLE NOT NULL,
    `target` DOUBLE NULL,
    `unit` VARCHAR(191) NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `OptimizationRun` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `type` VARCHAR(191) NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `parameters` VARCHAR(191) NOT NULL,
    `algorithm` VARCHAR(191) NOT NULL,
    `status` VARCHAR(191) NOT NULL DEFAULT 'pending',
    `result` VARCHAR(191) NULL,
    `iterations` INTEGER NULL,
    `objectiveValue` DOUBLE NULL,
    `runtime` DOUBLE NULL,
    `createdBy` VARCHAR(191) NOT NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `completedAt` DATETIME(3) NULL,

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `BenchmarkRun` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `description` VARCHAR(191) NULL,
    `startDate` DATETIME(3) NOT NULL,
    `endDate` DATETIME(3) NOT NULL,
    `metrics` VARCHAR(191) NOT NULL,
    `status` VARCHAR(191) NOT NULL DEFAULT 'active',
    `results` VARCHAR(191) NULL,
    `createdBy` VARCHAR(191) NOT NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `DecisionImpact` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `decisionType` VARCHAR(191) NOT NULL,
    `parameters` VARCHAR(191) NOT NULL,
    `impacts` VARCHAR(191) NOT NULL,
    `tradeoffs` VARCHAR(191) NULL,
    `recommendation` VARCHAR(191) NULL,
    `createdBy` VARCHAR(191) NOT NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `StressTest` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `scenario` VARCHAR(191) NOT NULL,
    `description` VARCHAR(191) NULL,
    `config` VARCHAR(191) NOT NULL,
    `status` VARCHAR(191) NOT NULL DEFAULT 'ready',
    `events` VARCHAR(191) NULL,
    `studentActions` VARCHAR(191) NULL,
    `score` DOUBLE NULL,
    `startedAt` DATETIME(3) NULL,
    `completedAt` DATETIME(3) NULL,
    `userId` VARCHAR(191) NOT NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `SystemMetric` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NULL,
    `metricType` VARCHAR(191) NOT NULL,
    `endpoint` VARCHAR(191) NULL,
    `value` DOUBLE NOT NULL,
    `unit` VARCHAR(191) NULL,
    `tags` VARCHAR(191) NULL,
    `timestamp` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
