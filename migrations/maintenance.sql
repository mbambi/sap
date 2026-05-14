SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- CreateTable
CREATE TABLE `Equipment` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `equipmentNumber` VARCHAR(191) NOT NULL,
    `description` VARCHAR(191) NOT NULL,
    `category` VARCHAR(191) NOT NULL,
    `manufacturer` VARCHAR(191) NULL,
    `model` VARCHAR(191) NULL,
    `serialNumber` VARCHAR(191) NULL,
    `plantId` VARCHAR(191) NOT NULL,
    `location` VARCHAR(191) NULL,
    `installDate` DATETIME(3) NULL,
    `warrantyEnd` DATETIME(3) NULL,
    `status` VARCHAR(191) NOT NULL DEFAULT 'active',
    `criticality` VARCHAR(191) NOT NULL DEFAULT 'medium',
    `costCenterId` VARCHAR(191) NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updatedAt` DATETIME(3) NOT NULL,

    UNIQUE INDEX `Equipment_tenantId_equipmentNumber_key`(`tenantId`, `equipmentNumber`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `WorkOrder` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `woNumber` VARCHAR(191) NOT NULL,
    `equipmentId` VARCHAR(191) NOT NULL,
    `type` VARCHAR(191) NOT NULL,
    `priority` VARCHAR(191) NOT NULL DEFAULT 'medium',
    `description` VARCHAR(191) NOT NULL,
    `status` VARCHAR(191) NOT NULL DEFAULT 'open',
    `plannedStart` DATETIME(3) NULL,
    `plannedEnd` DATETIME(3) NULL,
    `actualStart` DATETIME(3) NULL,
    `actualEnd` DATETIME(3) NULL,
    `estimatedHours` DOUBLE NULL,
    `actualHours` DOUBLE NULL,
    `estimatedCost` DOUBLE NULL,
    `actualCost` DOUBLE NULL,
    `assignedTo` VARCHAR(191) NULL,
    `notes` VARCHAR(191) NULL,
    `createdBy` VARCHAR(191) NOT NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updatedAt` DATETIME(3) NOT NULL,

    UNIQUE INDEX `WorkOrder_tenantId_woNumber_key`(`tenantId`, `woNumber`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `MaintenancePlan` (
    `id` VARCHAR(191) NOT NULL,
    `equipmentId` VARCHAR(191) NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `type` VARCHAR(191) NOT NULL,
    `intervalDays` INTEGER NULL,
    `intervalHours` INTEGER NULL,
    `lastExecuted` DATETIME(3) NULL,
    `nextDue` DATETIME(3) NULL,
    `isActive` BOOLEAN NOT NULL DEFAULT true,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `Asset` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `assetNumber` VARCHAR(191) NOT NULL,
    `description` VARCHAR(191) NOT NULL,
    `category` VARCHAR(191) NOT NULL,
    `acquisitionDate` DATETIME(3) NOT NULL,
    `acquisitionCost` DOUBLE NOT NULL,
    `currentValue` DOUBLE NOT NULL,
    `usefulLifeMonths` INTEGER NOT NULL,
    `depreciationMethod` VARCHAR(191) NOT NULL DEFAULT 'straight_line',
    `salvageValue` DOUBLE NOT NULL DEFAULT 0,
    `accumulatedDepr` DOUBLE NOT NULL DEFAULT 0,
    `monthlyDepr` DOUBLE NOT NULL DEFAULT 0,
    `location` VARCHAR(191) NULL,
    `assignedTo` VARCHAR(191) NULL,
    `costCenterId` VARCHAR(191) NULL,
    `status` VARCHAR(191) NOT NULL DEFAULT 'active',
    `disposalDate` DATETIME(3) NULL,
    `disposalValue` DOUBLE NULL,
    `notes` VARCHAR(191) NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updatedAt` DATETIME(3) NOT NULL,

    UNIQUE INDEX `Asset_tenantId_assetNumber_key`(`tenantId`, `assetNumber`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `AssetDepreciation` (
    `id` VARCHAR(191) NOT NULL,
    `assetId` VARCHAR(191) NOT NULL,
    `period` VARCHAR(191) NOT NULL,
    `amount` DOUBLE NOT NULL,
    `bookValue` DOUBLE NOT NULL,
    `method` VARCHAR(191) NOT NULL,
    `postedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
