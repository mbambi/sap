SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- CreateTable
CREATE TABLE `SupplyChainNode` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `type` VARCHAR(191) NOT NULL,
    `latitude` DOUBLE NULL,
    `longitude` DOUBLE NULL,
    `capacity` DOUBLE NULL,
    `holdingCost` DOUBLE NULL,
    `fixedCost` DOUBLE NULL,
    `address` VARCHAR(191) NULL,
    `isActive` BOOLEAN NOT NULL DEFAULT true,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `SupplyChainLink` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `fromNodeId` VARCHAR(191) NOT NULL,
    `toNodeId` VARCHAR(191) NOT NULL,
    `transportMode` VARCHAR(191) NOT NULL DEFAULT 'truck',
    `distance` DOUBLE NULL,
    `costPerUnit` DOUBLE NOT NULL DEFAULT 0,
    `leadTimeDays` DOUBLE NOT NULL DEFAULT 1,
    `capacity` DOUBLE NULL,
    `isActive` BOOLEAN NOT NULL DEFAULT true,

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `Shipment` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `shipmentNumber` VARCHAR(191) NOT NULL,
    `type` VARCHAR(191) NOT NULL,
    `carrier` VARCHAR(191) NULL,
    `mode` VARCHAR(191) NOT NULL DEFAULT 'truck',
    `originAddress` VARCHAR(191) NULL,
    `destAddress` VARCHAR(191) NULL,
    `referenceDoc` VARCHAR(191) NULL,
    `referenceType` VARCHAR(191) NULL,
    `weight` DOUBLE NULL,
    `volume` DOUBLE NULL,
    `freightCost` DOUBLE NOT NULL DEFAULT 0,
    `insuranceCost` DOUBLE NOT NULL DEFAULT 0,
    `status` VARCHAR(191) NOT NULL DEFAULT 'planned',
    `plannedDate` DATETIME(3) NULL,
    `actualDate` DATETIME(3) NULL,
    `trackingNumber` VARCHAR(191) NULL,
    `notes` VARCHAR(191) NULL,
    `createdBy` VARCHAR(191) NOT NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updatedAt` DATETIME(3) NOT NULL,

    UNIQUE INDEX `Shipment_tenantId_shipmentNumber_key`(`tenantId`, `shipmentNumber`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
