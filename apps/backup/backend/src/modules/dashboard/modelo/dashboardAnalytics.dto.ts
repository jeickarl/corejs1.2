import { ApiProperty } from '@nestjs/swagger';

export class DashboardTopProductDto {
  @ApiProperty()
  productId!: number;

  @ApiProperty()
  name!: string;

  @ApiProperty()
  quantity!: number;

  @ApiProperty()
  revenue!: number;
}

export class DashboardTopClientDto {
  @ApiProperty()
  clientId!: number;

  @ApiProperty()
  name!: string;

  @ApiProperty()
  invoicesCount!: number;

  @ApiProperty()
  totalAmount!: number;
}

export class DashboardAlertsDto {
  @ApiProperty()
  lowStockCount!: number;

  @ApiProperty()
  waitingApprovalCount!: number;
}

export class DashboardAnalyticsDto {
  @ApiProperty({ type: [DashboardTopProductDto] })
  topProducts!: DashboardTopProductDto[];

  @ApiProperty({ type: [DashboardTopClientDto] })
  topClients!: DashboardTopClientDto[];

  @ApiProperty({ type: DashboardAlertsDto })
  alerts!: DashboardAlertsDto;
}

