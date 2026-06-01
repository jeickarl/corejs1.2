import { ApiProperty } from '@nestjs/swagger';

export class DashboardLowStockItemDto {
  @ApiProperty({ example: 'Cable USB' })
  name!: string;

  @ApiProperty({ example: 1 })
  currentStock!: number;

  @ApiProperty({ example: 5 })
  minStock!: number;
}

export class DashboardOrderDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'WO-0001' })
  orderNumber!: string;

  @ApiProperty({ example: 'Juan Perez' })
  clientName!: string;

  @ApiProperty({ example: '+57 3001234567' })
  phone!: string;

  @ApiProperty({ example: 'Samsung' })
  deviceBrand!: string;

  @ApiProperty({ example: 'A52' })
  deviceModel!: string;

  @ApiProperty({ example: 'pending' })
  status!: string;

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  createdAt!: string;

  @ApiProperty({ example: '2026-01-03T00:00:00.000Z' })
  completedAt!: string;

  @ApiProperty({ example: 0 })
  totalAmount!: number;

  @ApiProperty({ example: 4 })
  daysOpen!: number;

  @ApiProperty({ example: 'high' })
  priority!: string;

  @ApiProperty({ example: 'Cargador, Cable' })
  accessories!: string;
}

export class DashboardSummaryDto {
  @ApiProperty({ example: 100 })
  totalOrders!: number;

  @ApiProperty({ example: 12 })
  pendingOrders!: number;

  @ApiProperty({ example: 50 })
  totalClients!: number;

  @ApiProperty({ example: 123456 })
  revenue!: number;

  @ApiProperty({ example: 10.5 })
  ordersTrendPct!: number;

  @ApiProperty({ example: -2.2 })
  salesTrendPct!: number;

  @ApiProperty({ type: [DashboardLowStockItemDto] })
  lowStockItems!: DashboardLowStockItemDto[];

  @ApiProperty({ type: [DashboardOrderDto] })
  recentOrders!: DashboardOrderDto[];

  @ApiProperty({ type: [DashboardOrderDto] })
  stagnantOrders!: DashboardOrderDto[];

  @ApiProperty({ type: [DashboardOrderDto] })
  readyOrders!: DashboardOrderDto[];
}

