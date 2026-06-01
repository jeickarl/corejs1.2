import { ApiProperty } from '@nestjs/swagger';

export class DashboardOrdersChartDto {
  @ApiProperty({ type: [String] })
  labels!: string[];

  @ApiProperty({ type: [Number] })
  values!: number[];
}

