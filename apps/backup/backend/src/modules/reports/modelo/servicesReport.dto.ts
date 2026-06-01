import { ApiProperty } from '@nestjs/swagger';

export class ServicesReportStatsDto {
  @ApiProperty()
  totalServices!: number;

  @ApiProperty()
  totalRevenue!: number;

  @ApiProperty()
  averagePrice!: number;

  @ApiProperty({ nullable: true })
  mostPopularService!: { name: string; usageCount: number } | null;
}

export class ServicesReportRowDto {
  @ApiProperty()
  serviceId!: number;

  @ApiProperty()
  name!: string;

  @ApiProperty()
  categoryName!: string;

  @ApiProperty()
  basePrice!: number;

  @ApiProperty()
  usageCount!: number;

  @ApiProperty()
  totalRevenue!: number;

  @ApiProperty()
  averagePrice!: number;
}

export class ServicesReportDto {
  @ApiProperty({ type: ServicesReportStatsDto })
  stats!: ServicesReportStatsDto;

  @ApiProperty({ type: [ServicesReportRowDto] })
  items!: ServicesReportRowDto[];
}

