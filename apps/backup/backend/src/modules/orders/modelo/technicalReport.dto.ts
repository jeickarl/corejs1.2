import { ApiProperty } from '@nestjs/swagger';

export class TechnicalReportDto {
  @ApiProperty()
  id!: number;

  @ApiProperty()
  orderId!: number;

  @ApiProperty()
  reportTitle!: string;

  @ApiProperty()
  diagnosis!: string;

  @ApiProperty()
  procedureTaken!: string;

  @ApiProperty()
  introduction!: string;

  @ApiProperty()
  conclusion!: string;

  @ApiProperty({ nullable: true })
  photosJson!: string | null;

  @ApiProperty({ nullable: true })
  createdBy!: number | null;

  @ApiProperty()
  createdAt!: string;
}

