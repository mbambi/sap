SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- CreateTable
CREATE TABLE `Warehouse` (
    `id` VARCHAR(191) NOT NULL,
    `tenantId` VARCHAR(191) NOT NULL,
    `plantId` VARCHAR(191) NOT NULL,
    `code` VARCHAR(191) NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `type` VARCHAR(191) NOT NULL DEFAULT 'standard',
    `isActive` BOOLEAN NOT NULL DEFAULT true,

    UNIQUE INDEX `Warehouse_tenantId_code_key`(`tenantId`, `code`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `WarehouseBin` (
    `id` VARCHAR(191) NOT NULL,
    `warehouseId` VARCHAR(191) NOT NULL,
    `binCode` VARCHAR(191) NOT NULL,
    `zone` VARCHAR(191) NULL,
    `aisle` VARCHAR(191) NULL,
    `rack` VARCHAR(191) NULL,
    `level` VARCHAR(191) NULL,
    `materialId` VARCHAR(191) NULL,
    `quantity` INTEGER NOT NULL DEFAULT 0,
    `maxCapacity` INTEGER NOT NULL DEFAULT 1000,
    `binType` VARCHAR(191) NOT NULL DEFAULT 'storage',

    UNIQUE INDEX `WarehouseBin_warehouseId_binCode_key`(`warehouseId`, `binCode`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
