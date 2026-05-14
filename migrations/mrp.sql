SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- CreateTable
CREATE TABLE `MrpRun` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `runNumber` VARCHAR(191) NOT NULL,
    `runDate` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `planningHorizonDays` INTEGER NOT NULL DEFAULT 90,
    `status` VARCHAR(191) NOT NULL DEFAULT 'draft',
    `parameters` VARCHAR(191) NULL,
    `results` VARCHAR(191) NULL,
    `createdBy` VARCHAR(191) NOT NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    UNIQUE INDEX `MrpRun_tenantId_runNumber_key`(`tenantId`, `runNumber`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `DemandForecast` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `materialId` VARCHAR(191) NOT NULL,
    `periodStart` DATETIME(3) NOT NULL,
    `periodEnd` DATETIME(3) NOT NULL,
    `forecastQty` DOUBLE NOT NULL,
    `actualQty` DOUBLE NULL,
    `method` VARCHAR(191) NOT NULL DEFAULT 'manual',
    `confidence` DOUBLE NULL,
    `createdBy` VARCHAR(191) NOT NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `PlannedOrder` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `mrpRunId` VARCHAR(191) NULL,
    `materialId` VARCHAR(191) NOT NULL,
    `orderType` VARCHAR(191) NOT NULL,
    `quantity` DOUBLE NOT NULL,
    `unit` VARCHAR(191) NOT NULL DEFAULT 'EA',
    `plannedDate` DATETIME(3) NOT NULL,
    `dueDate` DATETIME(3) NOT NULL,
    `status` VARCHAR(191) NOT NULL DEFAULT 'planned',
    `convertedTo` VARCHAR(191) NULL,
    `vendorId` VARCHAR(191) NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `InventoryPolicy` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `materialId` VARCHAR(191) NOT NULL,
    `policyType` VARCHAR(191) NOT NULL,
    `orderQuantity` DOUBLE NULL,
    `reorderPoint` DOUBLE NULL,
    `safetyStock` DOUBLE NULL,
    `minStock` DOUBLE NULL,
    `maxStock` DOUBLE NULL,
    `reviewPeriodDays` INTEGER NULL,
    `annualDemand` DOUBLE NULL,
    `orderingCost` DOUBLE NULL,
    `holdingCostPct` DOUBLE NULL,
    `serviceLevelPct` DOUBLE NULL,
    `abcClass` VARCHAR(191) NULL,
    `calculatedEOQ` DOUBLE NULL,
    `calculatedROP` DOUBLE NULL,
    `lastCalculated` DATETIME(3) NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updatedAt` DATETIME(3) NOT NULL,

    UNIQUE INDEX `InventoryPolicy_tenantId_materialId_key`(`tenantId`, `materialId`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
