import { ApiProperty } from '@nestjs/swagger';

export class DashboardSalesChartKpiDto {
  @ApiProperty({ example: 1234 })
  avg!: number;

  @ApiProperty({ example: 9999 })
  max!: number;

  @ApiProperty({ example: 88888 })
  total!: number;
}

export class DashboardSalesChartDto {
  @ApiProperty({ type: [String] })
  labels!: string[];

  @ApiProperty({ type: [Number] })
  current!: number[];

  @ApiProperty({ type: [Number] })
  previous!: number[];

  @ApiProperty({ type: DashboardSalesChartKpiDto })
  kpi!: DashboardSalesChartKpiDto;
}

