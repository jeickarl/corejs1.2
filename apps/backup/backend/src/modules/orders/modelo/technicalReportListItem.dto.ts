import { ApiProperty } from '@nestjs/swagger';

export class TechnicalReportListItemDto {
  @ApiProperty()
  id!: number;

  @ApiProperty()
  reportTitle!: string;

  @ApiProperty()
  createdAt!: string;

  @ApiProperty({ nullable: true })
  createdBy!: number | null;
}

