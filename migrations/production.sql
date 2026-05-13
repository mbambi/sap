SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- CreateTable
CREATE TABLE `BillOfMaterial` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `bomNumber` VARCHAR(191) NOT NULL,
    `materialId` VARCHAR(191) NOT NULL,
    `description` VARCHAR(191) NULL,
    `version` INTEGER NOT NULL DEFAULT 1,
    `isActive` BOOLEAN NOT NULL DEFAULT true,
    `validFrom` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `validTo` DATETIME(3) NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    UNIQUE INDEX `BillOfMaterial_tenantId_bomNumber_key`(`tenantId`, `bomNumber`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `BOMComponent` (
    `id` VARCHAR(191) NOT NULL,
    `bomId` VARCHAR(191) NOT NULL,
    `materialId` VARCHAR(191) NOT NULL,
    `quantity` DOUBLE NOT NULL,
    `unit` VARCHAR(191) NOT NULL DEFAULT 'EA',
    `position` INTEGER NOT NULL,
    `isPhantom` BOOLEAN NOT NULL DEFAULT false,
    `scrapRate` DOUBLE NOT NULL DEFAULT 0,

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `Routing` (
    `id` VARCHAR(191) NOT NULL,
    `bomId` VARCHAR(191) NOT NULL,
    `stepNo` INTEGER NOT NULL,
    `workCenter` VARCHAR(191) NOT NULL,
    `operation` VARCHAR(191) NOT NULL,
    `description` VARCHAR(191) NULL,
    `setupTime` DOUBLE NOT NULL DEFAULT 0,
    `runTime` DOUBLE NOT NULL DEFAULT 0,
    `laborRate` DOUBLE NOT NULL DEFAULT 0,
    `machineRate` DOUBLE NOT NULL DEFAULT 0,

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `ProductionOrder` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `orderNumber` VARCHAR(191) NOT NULL,
    `materialId` VARCHAR(191) NOT NULL,
    `quantity` INTEGER NOT NULL,
    `unit` VARCHAR(191) NOT NULL DEFAULT 'EA',
    `plannedStart` DATETIME(3) NOT NULL,
    `plannedEnd` DATETIME(3) NOT NULL,
    `actualStart` DATETIME(3) NULL,
    `actualEnd` DATETIME(3) NULL,
    `status` VARCHAR(191) NOT NULL DEFAULT 'planned',
    `priority` INTEGER NOT NULL DEFAULT 5,
    `yieldQty` INTEGER NOT NULL DEFAULT 0,
    `scrapQty` INTEGER NOT NULL DEFAULT 0,
    `notes` VARCHAR(191) NULL,
    `createdBy` VARCHAR(191) NOT NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updatedAt` DATETIME(3) NOT NULL,

    UNIQUE INDEX `ProductionOrder_tenantId_orderNumber_key`(`tenantId`, `orderNumber`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `WorkCenter` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `code` VARCHAR(191) NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `type` VARCHAR(191) NOT NULL,
    `capacity` DOUBLE NOT NULL DEFAULT 8,
    `efficiency` DOUBLE NOT NULL DEFAULT 100,
    `costRate` DOUBLE NOT NULL DEFAULT 0,
    `status` VARCHAR(191) NOT NULL DEFAULT 'available',
    `plantId` VARCHAR(191) NULL,
    `isActive` BOOLEAN NOT NULL DEFAULT true,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    UNIQUE INDEX `WorkCenter_tenantId_code_key`(`tenantId`, `code`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `ProductionSchedule` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `productionOrderId` VARCHAR(191) NOT NULL,
    `workCenterId` VARCHAR(191) NOT NULL,
    `operation` VARCHAR(191) NOT NULL,
    `setupTime` DOUBLE NOT NULL DEFAULT 0,
    `runTime` DOUBLE NOT NULL DEFAULT 0,
    `plannedStart` DATETIME(3) NOT NULL,
    `plannedEnd` DATETIME(3) NOT NULL,
    `actualStart` DATETIME(3) NULL,
    `actualEnd` DATETIME(3) NULL,
    `status` VARCHAR(191) NOT NULL DEFAULT 'scheduled',
    `sequence` INTEGER NOT NULL DEFAULT 0,

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `CostEstimate` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `materialId` VARCHAR(191) NULL,
    `productionOrderId` VARCHAR(191) NULL,
    `materialCost` DOUBLE NOT NULL DEFAULT 0,
    `laborCost` DOUBLE NOT NULL DEFAULT 0,
    `overheadCost` DOUBLE NOT NULL DEFAULT 0,
    `totalCost` DOUBLE NOT NULL DEFAULT 0,
    `costPerUnit` DOUBLE NOT NULL DEFAULT 0,
    `quantity` DOUBLE NOT NULL DEFAULT 1,
    `breakdown` VARCHAR(191) NULL,
    `status` VARCHAR(191) NOT NULL DEFAULT 'estimated',
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updatedAt` DATETIME(3) NOT NULL,

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
