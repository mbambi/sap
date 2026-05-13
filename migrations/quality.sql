SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- CreateTable
CREATE TABLE `InspectionLot` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `lotNumber` VARCHAR(191) NOT NULL,
    `materialId` VARCHAR(191) NOT NULL,
    `quantity` INTEGER NOT NULL,
    `origin` VARCHAR(191) NOT NULL,
    `referenceDoc` VARCHAR(191) NULL,
    `status` VARCHAR(191) NOT NULL DEFAULT 'created',
    `inspectedQty` INTEGER NOT NULL DEFAULT 0,
    `defectiveQty` INTEGER NOT NULL DEFAULT 0,
    `inspectedBy` VARCHAR(191) NULL,
    `inspectedAt` DATETIME(3) NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    UNIQUE INDEX `InspectionLot_tenantId_lotNumber_key`(`tenantId`, `lotNumber`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `InspectionResult` (
    `id` VARCHAR(191) NOT NULL,
    `inspectionLotId` VARCHAR(191) NOT NULL,
    `characteristic` VARCHAR(191) NOT NULL,
    `specification` VARCHAR(191) NULL,
    `measuredValue` VARCHAR(191) NULL,
    `result` VARCHAR(191) NOT NULL,
    `notes` VARCHAR(191) NULL,
    `inspectedBy` VARCHAR(191) NOT NULL,
    `inspectedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `NonConformance` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `ncNumber` VARCHAR(191) NOT NULL,
    `inspectionLotId` VARCHAR(191) NULL,
    `description` VARCHAR(191) NOT NULL,
    `severity` VARCHAR(191) NOT NULL DEFAULT 'minor',
    `status` VARCHAR(191) NOT NULL DEFAULT 'open',
    `rootCause` VARCHAR(191) NULL,
    `correctiveAction` VARCHAR(191) NULL,
    `assignedTo` VARCHAR(191) NULL,
    `dueDate` DATETIME(3) NULL,
    `closedAt` DATETIME(3) NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
